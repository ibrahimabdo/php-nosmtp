# php-nosmtp
Send emails through PHP without using a relay SMTP server

## Advantages:
- No local SMTP required
- No remote SMTP required
- No sendmail required

## Requirements:
- PHP >= 5.3.0
- Outgoing TCP port 25 must be opened since there is no way to declare custom ports in MX records

# Usage:
```
// Creating an object #1:
$mail = new NoSMTP(string $to, string $subject, string $message);

// Creating an object #2:
$mail = new NoSMTP(array(
	'to' => <string or array with addresses>,
	'subject' => <string>,
	'message' => <string>,
	'headers' => <array with headers (key - value pairs)>
));

// Send the email:
$mail->send();
```
## Example:
```
$mail = new NoSMTP(array(
	'to' => 'destination@example.com',
	'subject' => 'Hello there',
	'message' => 'Lorem ipsum dolor sit amet, consetetur sadipscing elitr.',
	'headers' => array(
		'From' => 'me@example.com'
	)
));

$mail->send();
```

## Settings:
```
'verbose' 		=> 0, 				// 0 - no logging / 1 - log only errors / 2 - log every sent & received message from server
'log_file' 		=> 'nosmtp.log', 	// where to save log messages
'throw_errors' 	=> false 			// true to throw catchable errors
```

Changing a setting:
```
$mail->settings['verbose'] = 1;
```

# Future TODOs:
- Add STARTTLS encryption
- Add possibility to authenticate on target SMTP (may be required for local testing)
- Option to check if destination email address exists on the server
- ...
