<?php
/**
 * A small RFC 6455 client, written against PHP's stream functions so it runs
 * on a stock shared host with no extensions beyond openssl.
 *
 * It implements exactly what the Blitzortung feed needs: a TLS connection, the
 * HTTP upgrade handshake, masked client frames, fragment reassembly and
 * automatic ping/pong. It is not a general-purpose library.
 */
declare(strict_types=1);

namespace StormWatch\WebSocket;

final class Client
{
    public const OP_CONTINUATION = 0x0;
    public const OP_TEXT = 0x1;
    public const OP_BINARY = 0x2;
    public const OP_CLOSE = 0x8;
    public const OP_PING = 0x9;
    public const OP_PONG = 0xA;

    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var resource */
    private $stream;

    private bool $closed = false;

    private string $fragmentBuffer = '';

    private int $fragmentOpcode = 0;

    /** @param resource $stream */
    private function __construct($stream)
    {
        $this->stream = $stream;
    }

    /**
     * @param array{origin?:string,timeout?:float,verify_peer?:bool,headers?:array<string,string>} $options
     * @throws \RuntimeException
     */
    public static function connect(string $url, array $options = []): self
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new \RuntimeException('Could not parse the WebSocket URL: ' . $url);
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'wss'));
        $secure = $scheme === 'wss';
        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? ($secure ? 443 : 80));
        $path = (string) ($parts['path'] ?? '/');
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        $timeout = (float) ($options['timeout'] ?? 10.0);
        $verify = (bool) ($options['verify_peer'] ?? true);

        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $host,
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
                'SNI_enabled' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
            ],
        ]);

        $target = sprintf('%s://%s:%d', $secure ? 'ssl' : 'tcp', $host, $port);
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new \RuntimeException(sprintf(
                'Could not open a connection to %s:%d (%s). Shared hosts often block outbound ports other than 80 and 443.',
                $host,
                $port,
                $errstr !== '' ? $errstr : ('error ' . $errno)
            ));
        }
        stream_set_timeout($stream, (int) $timeout);

        $client = new self($stream);
        try {
            $client->handshake($host, $port, $path, $options);
        } catch (\Throwable $e) {
            $client->closeStream();
            throw $e;
        }
        return $client;
    }

    /**
     * @param array{origin?:string,headers?:array<string,string>} $options
     */
    private function handshake(string $host, int $port, string $path, array $options): void
    {
        $key = base64_encode(random_bytes(16));
        $headers = [
            'GET ' . $path . ' HTTP/1.1',
            'Host: ' . $host . (($port === 443 || $port === 80) ? '' : ':' . $port),
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . $key,
            'Sec-WebSocket-Version: 13',
            'User-Agent: StormWatch/' . SW_VERSION,
        ];
        if (!empty($options['origin'])) {
            $headers[] = 'Origin: ' . $options['origin'];
        }
        foreach (($options['headers'] ?? []) as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $request = implode("\r\n", $headers) . "\r\n\r\n";

        if (@fwrite($this->stream, $request) === false) {
            throw new \RuntimeException('Could not send the WebSocket handshake.');
        }

        $response = '';
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $line = @fgets($this->stream, 2048);
            if ($line === false) {
                break;
            }
            $response .= $line;
            if (substr($response, -4) === "\r\n\r\n") {
                break;
            }
        }

        if (!preg_match('#^HTTP/1\.[01] (\d{3})#', $response, $status)) {
            throw new \RuntimeException('The server did not return an HTTP response to the WebSocket handshake.');
        }
        if ((int) $status[1] !== 101) {
            throw new \RuntimeException('The server refused the WebSocket upgrade (HTTP ' . $status[1] . ').');
        }
        if (!preg_match('/Sec-WebSocket-Accept:\s*(\S+)/i', $response, $accept)) {
            throw new \RuntimeException('The handshake response is missing Sec-WebSocket-Accept.');
        }
        $expected = base64_encode(sha1($key . self::GUID, true));
        if (!hash_equals($expected, trim($accept[1]))) {
            throw new \RuntimeException('The WebSocket handshake failed verification.');
        }
    }

    public function sendText(string $payload): void
    {
        $this->sendFrame(self::OP_TEXT, $payload);
    }

    public function sendFrame(int $opcode, string $payload): void
    {
        if ($this->closed) {
            throw new \RuntimeException('The WebSocket connection is closed.');
        }
        $length = strlen($payload);
        $frame = chr(0x80 | $opcode);

        if ($length < 126) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 0xFFFF) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $length);
        }

        // Client-to-server frames must be masked.
        $mask = random_bytes(4);
        $frame .= $mask;
        for ($i = 0; $i < $length; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        $written = 0;
        $total = strlen($frame);
        while ($written < $total) {
            $bytes = @fwrite($this->stream, substr($frame, $written));
            if ($bytes === false || $bytes === 0) {
                throw new \RuntimeException('Writing to the WebSocket failed.');
            }
            $written += $bytes;
        }
    }

    /**
     * Wait for the next application message.
     *
     * Control frames are handled internally. Returns null when the timeout
     * expires with nothing to read, and throws when the peer disconnects.
     *
     * @return array{opcode:int,payload:string}|null
     */
    public function receive(float $timeoutSeconds): ?array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $remaining = $deadline - microtime(true);
            if (!$this->waitReadable($remaining)) {
                return null;
            }

            $header = $this->readBytes(2, $deadline);
            if ($header === null) {
                return null;
            }

            $byte1 = ord($header[0]);
            $byte2 = ord($header[1]);
            $fin = ($byte1 & 0x80) !== 0;
            $opcode = $byte1 & 0x0F;
            $masked = ($byte2 & 0x80) !== 0;
            $length = $byte2 & 0x7F;

            if ($length === 126) {
                $ext = $this->readBytes(2, $deadline);
                if ($ext === null) {
                    return null;
                }
                $length = unpack('n', $ext)[1];
            } elseif ($length === 127) {
                $ext = $this->readBytes(8, $deadline);
                if ($ext === null) {
                    return null;
                }
                $unpacked = unpack('J', $ext)[1];
                if ($unpacked > 8 * 1024 * 1024) {
                    throw new \RuntimeException('The server sent an implausibly large frame; dropping the connection.');
                }
                $length = (int) $unpacked;
            }

            $maskKey = '';
            if ($masked) {
                $maskKey = $this->readBytes(4, $deadline) ?? '';
                if ($maskKey === '') {
                    return null;
                }
            }

            $payload = $length > 0 ? $this->readBytes($length, $deadline) : '';
            if ($payload === null) {
                return null;
            }
            if ($masked && $payload !== '') {
                $unmasked = '';
                for ($i = 0, $n = strlen($payload); $i < $n; $i++) {
                    $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
                }
                $payload = $unmasked;
            }

            switch ($opcode) {
                case self::OP_PING:
                    $this->sendFrame(self::OP_PONG, $payload);
                    continue 2;

                case self::OP_PONG:
                    continue 2;

                case self::OP_CLOSE:
                    $this->closed = true;
                    $this->closeStream();
                    $code = strlen($payload) >= 2 ? unpack('n', substr($payload, 0, 2))[1] : 1005;
                    throw new \RuntimeException('The server closed the connection (code ' . $code . ').');

                case self::OP_CONTINUATION:
                    $this->fragmentBuffer .= $payload;
                    if ($fin) {
                        $message = ['opcode' => $this->fragmentOpcode, 'payload' => $this->fragmentBuffer];
                        $this->fragmentBuffer = '';
                        $this->fragmentOpcode = 0;
                        return $message;
                    }
                    continue 2;

                default:
                    if ($fin) {
                        return ['opcode' => $opcode, 'payload' => $payload];
                    }
                    $this->fragmentOpcode = $opcode;
                    $this->fragmentBuffer = $payload;
                    continue 2;
            }
        }

        return null;
    }

    private function waitReadable(float $seconds): bool
    {
        if ($seconds <= 0) {
            return false;
        }
        $read = [$this->stream];
        $write = null;
        $except = null;
        $sec = (int) floor($seconds);
        $usec = (int) (($seconds - $sec) * 1000000);
        $ready = @stream_select($read, $write, $except, $sec, $usec);
        return $ready !== false && $ready > 0;
    }

    /** Read exactly $length bytes, or null if the deadline passes first. */
    private function readBytes(int $length, float $deadline): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            if (microtime(true) >= $deadline) {
                return null;
            }
            if (feof($this->stream)) {
                throw new \RuntimeException('The WebSocket connection dropped.');
            }
            if (!$this->waitReadable($deadline - microtime(true))) {
                return null;
            }
            $chunk = @fread($this->stream, $length - strlen($buffer));
            if ($chunk === false) {
                throw new \RuntimeException('Reading from the WebSocket failed.');
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($this->stream);
                if (!empty($meta['timed_out'])) {
                    continue;
                }
                if (feof($this->stream)) {
                    throw new \RuntimeException('The WebSocket connection dropped.');
                }
                continue;
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    public function close(): void
    {
        if (!$this->closed) {
            try {
                $this->sendFrame(self::OP_CLOSE, pack('n', 1000));
            } catch (\Throwable $e) {
                // The peer may already be gone; nothing useful to do.
            }
            $this->closed = true;
        }
        $this->closeStream();
    }

    private function closeStream(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
    }
}
