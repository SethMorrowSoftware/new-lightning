<?php
/**
 * A throwaway WebSocket server used by the test suite to exercise
 * StormWatch\WebSocket\Client against the real protocol: handshake, masked
 * client frames, ping/pong, fragmentation and close.
 *
 * Usage: php tests/support/ws_server.php <port>
 * Prints "READY" on stdout once it is listening.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use StormWatch\WebSocket\Lzw;

$port = (int) ($argv[1] ?? 0);
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

// ---- handshake ----
$request = '';
while (($line = fgets($client, 2048)) !== false) {
    $request .= $line;
    if (substr($request, -4) === "\r\n\r\n") {
        break;
    }
}
if (!preg_match('/Sec-WebSocket-Key:\s*(\S+)/i', $request, $m)) {
    fwrite($client, "HTTP/1.1 400 Bad Request\r\n\r\n");
    exit(1);
}
$accept = base64_encode(sha1(trim($m[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
fwrite($client, implode("\r\n", [
    'HTTP/1.1 101 Switching Protocols',
    'Upgrade: websocket',
    'Connection: Upgrade',
    'Sec-WebSocket-Accept: ' . $accept,
    '',
    '',
]));

/** Read one frame from the client and return [opcode, payload]. */
function readFrame($stream): ?array
{
    $header = fread($stream, 2);
    if ($header === false || strlen($header) < 2) {
        return null;
    }
    $opcode = ord($header[0]) & 0x0F;
    $masked = (ord($header[1]) & 0x80) !== 0;
    $length = ord($header[1]) & 0x7F;
    if ($length === 126) {
        $length = unpack('n', (string) fread($stream, 2))[1];
    } elseif ($length === 127) {
        $length = unpack('J', (string) fread($stream, 8))[1];
    }
    $mask = $masked ? (string) fread($stream, 4) : '';
    $payload = $length > 0 ? (string) fread($stream, $length) : '';
    if ($masked) {
        $unmasked = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $unmasked .= $payload[$i] ^ $mask[$i % 4];
        }
        $payload = $unmasked;
    }
    return ['opcode' => $opcode, 'payload' => $payload, 'masked' => $masked];
}

/** Server frames are never masked. */
function writeFrame($stream, int $opcode, string $payload, bool $fin = true): void
{
    $frame = chr(($fin ? 0x80 : 0x00) | $opcode);
    $length = strlen($payload);
    if ($length < 126) {
        $frame .= chr($length);
    } elseif ($length <= 0xFFFF) {
        $frame .= chr(126) . pack('n', $length);
    } else {
        $frame .= chr(127) . pack('J', $length);
    }
    fwrite($stream, $frame . $payload);
}

// The client is expected to subscribe first; echo what it sent back so the
// test can assert the masking round-tripped correctly.
$subscribe = readFrame($client);
writeFrame($client, 0x1, json_encode([
    'echo' => $subscribe['payload'] ?? '',
    'masked' => $subscribe['masked'] ?? false,
]));

// A normal strike frame, LZW-compressed exactly as the live feed sends it.
$strike = json_encode([
    'time' => 1700000000000000000,
    'lat' => 41.40,
    'lon' => -74.30,
    'alt' => 0,
]);
writeFrame($client, 0x1, Lzw::encode((string) $strike));

// A ping: the client must answer with a pong before the next message.
writeFrame($client, 0x9, 'health');

// A fragmented text message, to prove reassembly works.
$second = (string) json_encode(['time' => 1700000001000000000, 'lat' => 41.30, 'lon' => -74.20]);
$compressed = Lzw::encode($second);
$half = (int) floor(mb_strlen($compressed, '8bit') / 2);
writeFrame($client, 0x1, substr($compressed, 0, $half), false);
writeFrame($client, 0x0, substr($compressed, $half), true);

// Give the client a moment to answer the ping, then record whether it did.
stream_set_timeout($client, 3);
$pong = readFrame($client);
$pongSeen = $pong !== null && $pong['opcode'] === 0xA ? '1' : '0';
writeFrame($client, 0x1, (string) json_encode(['pong_received' => $pongSeen]));

// A large frame that needs the 16-bit length path.
writeFrame($client, 0x1, (string) json_encode(['filler' => str_repeat('x', 400)]));

writeFrame($client, 0x8, pack('n', 1000));
fclose($client);
fclose($server);
