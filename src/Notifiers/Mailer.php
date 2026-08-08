<?php
/**
 * Mail transport: PHP's mail() for the simple case, or SMTP for hosts where
 * local mail lands in spam (which, on shared hosting, is most of them).
 */
declare(strict_types=1);

namespace StormWatch\Notifiers;

use StormWatch\Settings;

final class Mailer
{
    private const TIMEOUT = 20;

    /**
     * @param array<int,string> $recipients
     * @return array{ok:bool,message:string}
     */
    public static function send(array $recipients, string $subject, string $textBody, string $htmlBody): array
    {
        $recipients = array_values(array_filter(
            $recipients,
            static fn(string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false
        ));
        if ($recipients === []) {
            return ['ok' => false, 'message' => 'No valid recipient addresses are configured.'];
        }

        $from = trim(Settings::getString('email_from'));
        if ($from === '' || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'Set a valid "from" address before sending mail.'];
        }

        if (Settings::getString('email_transport') === 'smtp') {
            return self::sendSmtp($recipients, $from, $subject, $textBody, $htmlBody);
        }
        return self::sendMailFunction($recipients, $from, $subject, $textBody, $htmlBody);
    }

    /**
     * @param array<int,string> $recipients
     * @return array{ok:bool,message:string}
     */
    private static function sendMailFunction(array $recipients, string $from, string $subject, string $text, string $html): array
    {
        if (!function_exists('mail')) {
            return ['ok' => false, 'message' => 'This server has PHP mail() disabled. Switch the transport to SMTP.'];
        }
        $boundary = 'sw-' . bin2hex(random_bytes(12));
        $headers = implode("\r\n", [
            'From: ' . self::formatFrom($from),
            'Reply-To: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: StormWatch/' . SW_VERSION,
            'X-Priority: 1',
        ]);
        $body = self::multipartBody($boundary, $text, $html);

        $failed = [];
        foreach ($recipients as $recipient) {
            if (!@mail($recipient, self::encodeHeader($subject), $body, $headers, '-f' . $from)) {
                $failed[] = $recipient;
            }
        }
        if ($failed !== []) {
            return [
                'ok' => false,
                'message' => 'PHP mail() refused these recipients: ' . implode(', ', $failed)
                    . '. Shared hosts often require the "from" address to be a real mailbox on the same domain.',
            ];
        }
        return ['ok' => true, 'message' => sprintf('Handed %d message(s) to the local mail server.', count($recipients))];
    }

    /**
     * Minimal ESMTP client: EHLO, optional STARTTLS, optional AUTH LOGIN/PLAIN,
     * then a single message to all recipients.
     *
     * @param array<int,string> $recipients
     * @return array{ok:bool,message:string}
     */
    private static function sendSmtp(array $recipients, string $from, string $subject, string $text, string $html): array
    {
        $host = trim(Settings::getString('smtp_host'));
        $port = Settings::getInt('smtp_port');
        $secure = Settings::getString('smtp_secure');
        $user = Settings::getString('smtp_user');
        $pass = Settings::getString('smtp_pass');

        if ($host === '') {
            return ['ok' => false, 'message' => 'No SMTP host is configured.'];
        }

        $transport = $secure === 'ssl' ? 'ssl://' : 'tcp://';
        $errno = 0;
        $errstr = '';
        $context = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            self::TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            return ['ok' => false, 'message' => sprintf('Could not connect to %s:%d (%s).', $host, $port, $errstr ?: ('error ' . $errno))];
        }
        stream_set_timeout($socket, self::TIMEOUT);

        try {
            self::expect($socket, 220);
            $ehloName = self::ehloName();
            self::command($socket, 'EHLO ' . $ehloName, 250);

            if ($secure === 'tls') {
                self::command($socket, 'STARTTLS', 220);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS negotiation failed.');
                }
                self::command($socket, 'EHLO ' . $ehloName, 250);
            }

            if ($user !== '') {
                try {
                    self::command($socket, 'AUTH LOGIN', 334);
                    self::command($socket, base64_encode($user), 334);
                    self::command($socket, base64_encode($pass), 235);
                } catch (\RuntimeException $e) {
                    throw new \RuntimeException('SMTP authentication failed: ' . $e->getMessage());
                }
            }

            self::command($socket, 'MAIL FROM:<' . $from . '>', 250);
            foreach ($recipients as $recipient) {
                self::command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            }
            self::command($socket, 'DATA', 354);

            $boundary = 'sw-' . bin2hex(random_bytes(12));
            $message = implode("\r\n", [
                'Date: ' . date('r'),
                'From: ' . self::formatFrom($from),
                'To: ' . implode(', ', $recipients),
                'Subject: ' . self::encodeHeader($subject),
                'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $ehloName . '>',
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
                'X-Mailer: StormWatch/' . SW_VERSION,
                '',
                self::multipartBody($boundary, $text, $html),
            ]);
            // Dot-stuffing, per RFC 5321.
            $message = preg_replace('/^\./m', '..', $message) ?? $message;
            self::write($socket, $message . "\r\n.");
            self::expect($socket, 250);
            self::command($socket, 'QUIT', [221, 250]);
        } catch (\Throwable $e) {
            @fclose($socket);
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        @fclose($socket);
        return ['ok' => true, 'message' => sprintf('Sent to %d recipient(s) via %s.', count($recipients), $host)];
    }

    private static function multipartBody(string $boundary, string $text, string $html): string
    {
        return implode("\r\n", [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($text)),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($html)),
            '--' . $boundary . '--',
            '',
        ]);
    }

    private static function formatFrom(string $from): string
    {
        $name = trim(Settings::getString('email_from_name'));
        if ($name === '') {
            return $from;
        }
        return sprintf('%s <%s>', self::encodeHeader($name), $from);
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function ehloName(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            $host = (string) (gethostname() ?: 'localhost');
        }
        $host = preg_replace('/[^A-Za-z0-9.\-]/', '', explode(':', $host)[0]) ?? 'localhost';
        return $host !== '' ? $host : 'localhost';
    }

    /**
     * @param resource $socket
     * @param int|array<int,int> $expected
     */
    private static function command($socket, string $command, $expected): string
    {
        self::write($socket, $command);
        return self::expect($socket, $expected);
    }

    /** @param resource $socket */
    private static function write($socket, string $line): void
    {
        if (@fwrite($socket, $line . "\r\n") === false) {
            throw new \RuntimeException('Writing to the SMTP server failed.');
        }
    }

    /**
     * @param resource $socket
     * @param int|array<int,int> $expected
     */
    private static function expect($socket, $expected): string
    {
        $expected = is_array($expected) ? $expected : [$expected];
        $response = '';
        while (true) {
            $line = @fgets($socket, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                throw new \RuntimeException(
                    !empty($meta['timed_out'])
                        ? 'The SMTP server stopped responding.'
                        : 'The SMTP connection dropped.'
                );
            }
            $response .= $line;
            // A multi-line reply has a dash after the code; the last line has a space.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new \RuntimeException(sprintf('SMTP server replied "%s".', trim($response)));
        }
        return $response;
    }
}
