<?php
class NoSMTP {
	public $settings = array(
		'verbose' 		=> 0, 				// 0 - no logging / 1 - log only errors / 2 - log every sent & received message from server
		'log_file' 		=> 'nosmtp.log', 	// where to save log messages
		'throw_errors' 	=> false 			// true to throw catchable errors
	);

	private static $version = '1.0';
	
	private $to = array();
	private $subject = '';
	private $message = '';
	private $headers =  array();

	private $domain_settings = array();
	
	private $has_attachments = false;
	private $files = array();

	private $boundries = array();
	private $parts = array();

	public function __construct($options = array(), $subject = null, $message = null, $headers = array()) {
		if (is_array($options)) {
			if (isset($options['to'])) {
				if (is_array($options['to'])) {
					$this->to = $options['to'];
				} else if (is_object($options['to'])) {
					$this->to = (array)$options['to'];
				} else if (is_string($options['to'])) {
					$this->to = array($options['to']);
				} else {
					$this->throwError('Invalid TO parameter, should be array, object or string');
				}
			}

			if (isset($options['subject']) && is_string($options['subject'])) {
				$this->subject = $options['subject'];
			} else {
				$this->throwError('Invalid SUBJECT parameter, should be string');
			}

			if (isset($options['message']) && is_string($options['message'])) {
				$this->message = $options['message'];
			} else {
				$this->throwError('Invalid MESSAGE parameter, should be string');
			}

			if (isset($options['headers']) && is_array($options['headers'])) {
				$this->headers = $options['headers'];
			} else {
				$this->throwError('Invalid HEADERS parameter, should be array');
			}

		} else if (is_string($options)) {
			$this->to = array($options);
			
			if (is_string($subject)) {
				$this->subject = $subject;
			} else {
				$this->throwError('Invalid SUBJECT parameter, should be string');
			}

			if (is_string($message)) {
				$this->message = $message;
			} else {
				$this->throwError('Invalid MESSAGE parameter, should be string');
			}

			if (is_array($headers)) {
				$this->headers = $headers;
			} else {
				$this->throwError('Invalid HEADERS parameter, should be array');
			}
		}
	}

	public function send() {
		// Parse each "To" email address and create an array of unique domains found in addresses
		$this->parseToDomains();

		// Get the MX records for each domain found
		$this->getMXForDomains();

		// Generate the default email headers and append the custom ones if any
		$this->createHeaders($this->headers);

		// Append the Subject email header
		$this->setHeader('Subject', $this->subject);

		// Send all emails to each domain
		$this->sendToDomains();
	}
	
	public function setHeader($key, $value) {
		$this->headers[$key] = $value;
	}

	public function addFile($filepath) {
		if (file_exists($filepath)) {
			$this->has_attachments = true;
			$this->files[] = $filepath;
		}
	}

	/* ---------------------------------------------------------------------------------------------------------- */

	private function getDomain($address) {
		$explode = explode('@', $address);
		if (count($explode) == 2) {
			return $explode[1];
		} else {
			$this->throwError('Invalid TO address <'.$address.'>');
		}
	}

	private function parseToDomains() {
		foreach ($this->to as $key => $address) {
			$domain = $this->getDomain($address);

			if (!isset($this->domain_settings[$domain])) {
				$this->domain_settings[$domain] = array();
				$this->domain_settings[$domain]['addresses'] = array();
			}

			$this->domain_settings[$domain]['addresses'][] = $address;
		}
	}

	private function getMX($domain) {
		if (getmxrr($domain, $mx_hosts, $mx_weight)) {
			$mx_response = array();
			for ($i=0; $i < count($mx_hosts); $i++) { 
				$mx_response[] = array(
					'server' => $mx_hosts[$i],
					'priority' => $mx_weight[$i]
				);
			}

			usort($mx_response, function($a, $b) {
				return $a['priority'] > $b['priority'];
			});

			return $mx_response;
		} else {
			$this->throwError('Could not get MX record for <'.$domain.'>');
		}
	}

	private function getMXForDomains() {
		foreach ($this->domain_settings as $domain => $settings) {
			if (!isset($this->domain_settings[$domain]['mx'])) {
				$this->domain_settings[$domain]['mx'] = array();
			}

			$this->domain_settings[$domain]['mx'] = $this->getMX($domain);
		}
	}

	private function openSocket($server, $port) {
		$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if ($sock) {
			$connect = socket_connect($sock, $server, $port);
			if ($connect) {
				return $sock;
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	private function writeToSocket($socket, $data) {
		socket_send($socket, $data, strlen($data), MSG_EOF);

		if ($this->settings['verbose'] >= 2) {
			$this->logMessage('SENT: '.$data);
		}
	}

	private function getSocketData($socket) {
		$data = '';

		do {
			$received = socket_recv($socket, $buff, 1024, MSG_DONTWAIT);
			if ($received) {
				$data .= $buff;
				usleep(10000);
			}
		} while ($data == '' || $received);

		if ($this->settings['verbose'] >= 2) {
			$this->logMessage('RECV: '.$data);
		}

		return $data;
	}

	private function terminateSocket($socket) {
		socket_close($socket);
	}

	private function send_to($domain, $address) {
		$this->setHeader('To', $address);

		foreach ($this->domain_settings[$domain]['mx'] as $key => $mx) {
			$socket = $this->openSocket($mx['server'], 25);
			if ($socket) {

				/* ---------------------------------------------------------------------------------------------------------- */
				// Receive welcome message

				$recv = $this->getSocketData($socket);
				if ($this->parseResponse($recv)->code[0] != '2') $this->throwError('Server Throwed Exception: '.$recv);
				
				/* ---------------------------------------------------------------------------------------------------------- */
				// Send Hello command

				$this->writeToSocket($socket, "HELO localhost\r\n");
				$recv = $this->getSocketData($socket);
				if ($this->parseResponse($recv)->code[0] != '2') $this->throwError('Server Throwed Exception: '.$recv);
				
				/* ---------------------------------------------------------------------------------------------------------- */
				// Tell server the Sender address

				$this->writeToSocket($socket, "MAIL FROM:<".$this->getHeader('From').">\r\n");
				$recv = $this->getSocketData($socket);
				if ($this->parseResponse($recv)->code[0] != '2') $this->throwError('Server Throwed Exception: '.$recv);
				
				/* ---------------------------------------------------------------------------------------------------------- */
				// Tell server the destination address

				$this->writeToSocket($socket, "RCPT TO:<".$this->getHeader('To').">\r\n");
				$recv = $this->getSocketData($socket);
				if ($this->parseResponse($recv)->code[0] != '2') $this->throwError('Server Throwed Exception: '.$recv);

				/* ---------------------------------------------------------------------------------------------------------- */
				// Tell server we are going to send the message next 

				$this->writeToSocket($socket, "DATA\r\n");
				$recv = $this->getSocketData($socket);
				if ($this->parseResponse($recv)->code[0] != '3' && $this->parseResponse($recv)->code[0] != '2') $this->throwError('Server Throwed Exception: '.$recv);

				/* ---------------------------------------------------------------------------------------------------------- */
				// Send the message to server

				$this->writeToSocket($socket, $this->createMessage());
				$recv = $this->getSocketData($socket);
				if ($this->parseResponse($recv)->code[0] != '2') $this->throwError('Server Throwed Exception: '.$recv);

				/* ---------------------------------------------------------------------------------------------------------- */
				// If everything is fine, close the connection

				$this->writeToSocket($socket, "QUIT\r\n");
				$recv = $this->getSocketData($socket);
				if ($this->parseResponse($recv)->code[0] == '2') {
					$this->terminateSocket($socket);
				} else {
					$this->throwError('Server Throwed Exception: '.$recv);
				}

				break;
			}
		}
	}

	private function parseResponse($response) {
		$response_array = explode(' ', $response, 2);

		$return_array = array(
			'code' => $response_array[0],
			'message' => $response_array[1]
		);

		return (object)$return_array;
	}

	private function sendToDomains() {
		foreach ($this->domain_settings as $domain => $settings) {
			foreach ($settings['addresses'] as $address) {
				$this->send_to($domain, $address);
			}
		}
	}

	private function logMessage($message, $label = 'Info') {
		file_put_contents($this->settings['log_file'], '['.date('r').'] <'.$label.'>: '.$message, FILE_APPEND);
	}

	private function throwError($message) {
		if ($this->settings['verbose'] >= 1) {
			$this->logMessage($message.PHP_EOL, 'Exception');
		}

		if ($this->settings['throw_errors']) {
			throw new Exception($message);
		}
	}

	private function createHeaders($headers = array()) {
		$default_headers = array(
			'MIME-Version' => '1.0',
			'Content-Type' => ($this->has_attachments ? "multipart/mixed" : "multipart/alternative").";\r\n boundary=\"=_".$this->getBoundry()."\"",
			'User-Agent' => 'PHP-NoSMTP/'.self::$version,
			'Date' => date('r'),
			'From' => !empty($_SERVER['SERVER_ADMIN']) ? $_SERVER['SERVER_ADMIN'] : '',
		);

		$this->headers = array_merge($default_headers, $headers);
	}

	private function getHeader($key) {
		if (isset($this->headers[$key])) {
			return $this->headers[$key];
		} else {
			return null;
		}
	}

	private function convertTo($data, $encoding) {
		if ($encoding == '7bit') {
			$data = wordwrap($data, 72, " \r\n", false);
		} else if ($encoding == 'quoted-printable') {
			$data = quoted_printable_encode($data);
		} else if ($encoding == 'base64') {
			$data = base64_encode($data);
			$data = wordwrap($data, 76, " \r\n", true);
		}

		return $data;
	}

	private function generateBoundry($parent = '') {
		$boundry = md5(rand().uniqid());
		$this->boundries[$parent] = $boundry;

		return $boundry;
	}

	private function hasBoundry($parent = '') {
		return isset($this->boundries[$parent]);
	}

	private function getBoundry($parent = '') {
		if (!$this->hasBoundry($parent)) {
			$boundry = $this->generateBoundry($parent);
		} else {
			$boundry = $this->boundries[$parent];
		}

		return $boundry;
	}

	private function addPart($data, $type, $file = false) {
		if (!$this->hasBoundry()) {
			$this->generateBoundry();
		}

		if (!$file) {
			if ($type == 'text/plain') {
				$part = array(
					'headers' => array(
						'Content-Transfer-Encoding: 7bit',
						'Content-Type: text/plain; charset=US-ASCII'
					),
					'data' => $this->convertTo($data, '7bit')
				);
			} else if ($type == 'text/html') {
				$part = array(
					'headers' => array(
						'Content-Transfer-Encoding: quoted-printable',
						'Content-Type: text/html; charset=UTF-8'
					),
					'data' => $this->convertTo($data, 'quoted-printable')
				);
			}

			if ($this->has_attachments) {
				if (!isset($this->parts[0])) {
					$this->parts[0] = array(
						'headers' => array(
							'Content-Type: multipart/alternative; boundary="=_'.$this->generateBoundry($this->getBoundry()).'"',
						),
						'data' => array()
					);
				}

				$this->parts[0]['data'][] = $part;
			} else {
				$this->parts[] = $part;
			}

		} else {
			$part = array(
				'headers' => array(
					'Content-Transfer-Encoding: base64',
					'Content-type: '.$type.'; name='.basename($data),
					'Content-Disposition: attachment; filename='.basename($data).'; size='.filesize($data)
				),
				'data' => $this->convertTo(file_get_contents($data), 'base64')
			);

			$this->parts[] = $part;
		}
	}

	private function createMessage() {
		$message = '';

		foreach ($this->headers as $header => $value) {
			$message .= $header.": ".$value."\r\n";
		}
		$message .= "\r\n";

		$this->addPart(strip_tags($this->message), 'text/plain');
		$this->addPart($this->message, 'text/html');
		foreach ($this->files as $file) {
			$this->addPart($file, 'application/octet-stream', true);
		}

		foreach ($this->parts as $part) {
			$message .= '--=_'.$this->getBoundry()."\r\n";
			foreach ($part['headers'] as $header) {
				$message .= $header."\r\n";
			}

			$message .= "\r\n";
			if (is_array($part['data'])) {

				foreach ($part['data'] as $sub_part) {
					$message .= '--=_'.$this->getBoundry($this->getBoundry())."\r\n";
					foreach ($sub_part['headers'] as $sub_header) {
						$message .= $sub_header."\r\n";
					}

					$message .= "\r\n";
					$message .= $sub_part['data'];
					$message .= "\r\n";
				}
				$message .= '--=_'.$this->getBoundry($this->getBoundry())."--\r\n";


			} else {
				$message .= $part['data'];
			}
			$message .= "\r\n\r\n";
		}
		
		$message .= '--=_'.$this->getBoundry()."--\r\n";

		$message .= "\r\n.\r\n";

		return $message;
	}
}
