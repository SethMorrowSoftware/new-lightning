<?php
/**
 * A throwaway SMTP server used by the test suite to exercise
 * StormWatch\Notifiers\Mailer against the real protocol: the greeting, EHLO,
 * AUTH, the recipient list, DATA and dot-stuffing.
 *
 * The alert email is the channel that reaches people who are not in Slack, and
 * the client that sends it is hand-rolled. Pointing it at something that
 * answers like a mail server is the only way to know it works.
 *
 * Usage: php tests/support/smtp_server.php <port> <transcript-file> [mode]
 *
 *   mode "ok"          accept everything (the default)
 *   mode "reject-one"  answer 550 for any recipient containing "bad"
 *   mode "auth-plain"  advertise only AUTH PLAIN, and refuse AUTH LOGIN
 *
 * Prints "READY" on stdout once it is listening, then serves one connection and
 * writes what it received to the transcript file.
 */
declare(strict_types=1);

$port = (int) ($argv[1] ?? 0);
$transcript = (string) ($argv[2] ?? '');
$mode = (string) ($argv[3] ?? 'ok');

$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}

echo "READY\n";
flush();

$client = @stream_socket_accept($server, 10);
if ($client === false) {
    exit(1);
}
stream_set_timeout($client, 10);

$log = [];
$accepted = [];   // recipients this server agreed to take
$body = '';
$inData = false;

$say = static function (string $line) use ($client): void {
    fwrite($client, $line . "\r\n");
};

$say('220 mock.test ESMTP ready');

while (($line = fgets($client, 4096)) !== false) {
    if ($inData) {
        if (rtrim($line, "\r\n") === '.') {
            $inData = false;
            $say('250 2.0.0 Ok: queued');
            continue;
        }
        $body .= $line;
        continue;
    }

    $command = rtrim($line, "\r\n");
    $log[] = $command;
    $verb = strtoupper(strtok($command, ' ') ?: '');

    switch ($verb) {
        case 'EHLO':
            $say('250-mock.test');
            $say($mode === 'auth-plain' ? '250-AUTH PLAIN' : '250-AUTH LOGIN PLAIN');
            $say('250 SIZE 10240000');
            break;

        case 'AUTH':
            if ($mode === 'auth-plain' && stripos($command, 'LOGIN') !== false) {
                $say('504 5.5.4 Unrecognized authentication type');
                break;
            }
            if (stripos($command, 'PLAIN') !== false && strlen($command) > 11) {
                $say('235 2.7.0 Authentication successful');   // single-step PLAIN
                break;
            }
            $say('334 VXNlcm5hbWU6');
            $user = fgets($client, 4096);
            $log[] = 'AUTH-STEP ' . trim((string) $user);
            if (stripos($command, 'PLAIN') !== false) {
                $say('235 2.7.0 Authentication successful');
                break;
            }
            $say('334 UGFzc3dvcmQ6');
            $pass = fgets($client, 4096);
            $log[] = 'AUTH-STEP ' . trim((string) $pass);
            $say('235 2.7.0 Authentication successful');
            break;

        case 'MAIL':
            $say('250 2.1.0 Ok');
            break;

        case 'RCPT':
            if ($mode === 'reject-one' && stripos($command, 'bad') !== false) {
                $say('550 5.1.1 No such user here');
                break;
            }
            if (preg_match('/<([^>]*)>/', $command, $m) === 1) {
                $accepted[] = $m[1];
            }
            $say('250 2.1.5 Ok');
            break;

        case 'DATA':
            $inData = true;
            $say('354 End data with <CR><LF>.<CR><LF>');
            break;

        case 'QUIT':
            $say('221 2.0.0 Bye');
            break 2;

        default:
            $say('250 2.0.0 Ok');
            break;
    }
}

@fclose($client);
@fclose($server);

if ($transcript !== '') {
    file_put_contents($transcript, (string) json_encode([
        'commands' => $log,
        'accepted' => $accepted,
        'body' => $body,
    ]));
}
