<?php
/**
 * Blitzortung.org live strike feed.
 *
 * Blitzortung is a volunteer-run detection network. Its live feed is a
 * WebSocket that streams every detected strike worldwide as an LZW-compressed
 * JSON frame; there is no key and no documented contract, so this provider is
 * written to fail gracefully and say why.
 *
 * The worker connects for a bounded number of seconds and exits, which is what
 * a once-a-minute cron job on shared hosting can actually sustain.
 */
declare(strict_types=1);

namespace StormWatch\Providers;

use StormWatch\Geo;
use StormWatch\Ingest;
use StormWatch\Settings;
use StormWatch\WebSocket\Client;
use StormWatch\WebSocket\Lzw;

final class Blitzortung
{
    private const PORT = 3000;
    private const ORIGIN = 'https://map.blitzortung.org';

    /**
     * Connect and ingest strikes until the time budget runs out.
     *
     * @return array{ok:bool,ingested:int,frames:int,message:string,server:string}
     */
    public static function stream(int $seconds, ?callable $onStrike = null): array
    {
        $servers = self::servers();
        $attempts = min(count($servers), 3);
        $deadline = microtime(true) + max(5, $seconds);
        $lastError = 'No Blitzortung servers configured.';
        $totalIngested = 0;
        $totalFrames = 0;
        $serverUsed = '';

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $remaining = $deadline - microtime(true);
            if ($remaining < 3) {
                break;
            }
            $server = $servers[self::nextServerIndex() % count($servers)];
            $serverUsed = $server;
            $url = sprintf('wss://%s.blitzortung.org:%d/', $server, self::PORT);

            try {
                $client = Client::connect($url, [
                    'origin' => self::ORIGIN,
                    'timeout' => min(10.0, max(3.0, $remaining)),
                ]);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                self::rotateServer();
                continue;
            }

            try {
                $client->sendText(self::initPayload());

                while (microtime(true) < $deadline) {
                    $message = $client->receive(min(5.0, max(0.1, $deadline - microtime(true))));
                    if ($message === null) {
                        continue;
                    }
                    if ($message['opcode'] !== Client::OP_TEXT) {
                        continue;
                    }
                    $totalFrames++;
                    $record = self::decodeFrame($message['payload']);
                    if ($record === null) {
                        continue;
                    }
                    if (Ingest::recordOne($record['lat'], $record['lon'], $record['ts'], 'blitzortung')) {
                        $totalIngested++;
                        if ($onStrike !== null) {
                            $onStrike($record);
                        }
                    }
                }

                $client->close();
                // Health is "did frames arrive", not "were any of them near
                // us". Blitzortung carries every detection on earth, so a
                // whole minute of silence is the feed being broken, not the
                // weather being calm — and reporting that as success leaves a
                // green tick over a dead feed for as long as it stays dead.
                return [
                    'ok' => $totalFrames > 0,
                    'ingested' => $totalIngested,
                    'frames' => $totalFrames,
                    'server' => $serverUsed,
                    'message' => $totalFrames > 0
                        ? sprintf(
                            'Streamed %d frame%s from %s; stored %d strike%s inside the display radius.',
                            $totalFrames,
                            $totalFrames === 1 ? '' : 's',
                            $server,
                            $totalIngested,
                            $totalIngested === 1 ? '' : 's'
                        )
                        : sprintf(
                            'Connected to %s but it sent nothing for %d seconds. This feed carries strikes from the '
                            . 'whole world, so silence is the feed being down rather than calm weather. Try another '
                            . 'server in the list, or switch to the browser relay.',
                            $server,
                            max(5, $seconds)
                        ),
                ];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                try {
                    $client->close();
                } catch (\Throwable $ignored) {
                }
                self::rotateServer();
                // A mid-stream drop is normal for this feed; keep whatever we
                // ingested and try the next server with the time that is left.
                continue;
            }
        }

        // A mid-stream drop is routine for this feed and is not a fault as long
        // as data was flowing. Judging that on strikes *stored* instead meant
        // every quiet local hour ended in a "cannot reach the Blitzortung feed"
        // alert — which is how an operator learns to ignore feed alerts.
        return [
            'ok' => $totalFrames > 0,
            'ingested' => $totalIngested,
            'frames' => $totalFrames,
            'server' => $serverUsed,
            'message' => $totalFrames > 0
                ? sprintf(
                    'Streamed %d frame%s and stored %d strike%s inside the display radius, then the connection '
                    . 'dropped — normal for this feed, and the next run reconnects. Last error: %s',
                    $totalFrames,
                    $totalFrames === 1 ? '' : 's',
                    $totalIngested,
                    $totalIngested === 1 ? '' : 's',
                    $lastError
                )
                : 'Could not stream from Blitzortung: ' . $lastError,
        ];
    }

    /**
     * Short connection used by the "Test connection" button.
     *
     * @return array{ok:bool,message:string,detail:array<string,mixed>}
     */
    public static function test(int $seconds = 10): array
    {
        $servers = self::servers();
        if ($servers === []) {
            return ['ok' => false, 'message' => 'No Blitzortung servers are configured.', 'detail' => []];
        }
        $server = $servers[0];
        $host = $server . '.blitzortung.org';
        $startedAt = microtime(true);

        // Cheap reachability check first. A host that blocks outbound port 3000
        // is the common failure, and it should be reported in a couple of
        // seconds rather than after a full listening window — waiting twenty
        // seconds for a verdict is indistinguishable from the page hanging.
        $reachable = self::portIsReachable($host, self::PORT, 4.0);
        if ($reachable !== true) {
            return [
                'ok' => false,
                'message' => sprintf('Could not reach %s on port %d: %s', $host, self::PORT, $reachable),
                'detail' => [
                    'server' => $server,
                    'elapsed_seconds' => round(microtime(true) - $startedAt, 1),
                    'hint' => 'Blitzortung streams on port ' . self::PORT . ', and many shared hosts allow only 80 and 443 '
                        . 'outbound. Ask your host to open it, or switch the provider to "Browser relay" — same free data, '
                        . 'fetched by a browser tab instead of the server.',
                ],
            ];
        }

        $url = sprintf('wss://%s:%d/', $host, self::PORT);
        $deadline = microtime(true) + $seconds;

        try {
            $client = Client::connect($url, ['origin' => self::ORIGIN, 'timeout' => 6.0]);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'The port is open but the connection failed: ' . $e->getMessage(),
                'detail' => [
                    'server' => $server,
                    'elapsed_seconds' => round(microtime(true) - $startedAt, 1),
                    'hint' => 'The TCP port answered, so this is not a firewall. It is more likely TLS interception or a '
                        . 'change to the feed\'s undocumented protocol. Try another server in the list, or use "Browser relay".',
                ],
            ];
        }

        $frames = 0;
        $sample = null;
        $nearby = 0;
        try {
            $client->sendText(self::initPayload());
            while (microtime(true) < $deadline && $frames < 200) {
                $message = $client->receive(min(4.0, max(0.1, $deadline - microtime(true))));
                if ($message === null) {
                    continue;
                }
                if ($message['opcode'] !== Client::OP_TEXT) {
                    continue;
                }
                $frames++;
                $record = self::decodeFrame($message['payload'], false);
                if ($record !== null && $sample === null) {
                    $sample = $record;
                }
                if ($record !== null && $record['distance_mi'] !== null
                    && $record['distance_mi'] <= Settings::getFloat('display_radius_mi')) {
                    $nearby++;
                }
            }
            $client->close();
        } catch (\Throwable $e) {
            try {
                $client->close();
            } catch (\Throwable $ignored) {
            }
            if ($frames === 0) {
                return [
                    'ok' => false,
                    'message' => 'Connected, but the stream failed before any data arrived: ' . $e->getMessage(),
                    'detail' => ['server' => $server],
                ];
            }
        }

        if ($frames === 0) {
            return [
                'ok' => false,
                'message' => sprintf('Connected to %s but received no strike frames in %.0f seconds.', $server, microtime(true) - $startedAt),
                'detail' => ['server' => $server, 'hint' => 'The feed only carries live strikes. During calm weather worldwide this can genuinely be quiet, but it is usually busy.'],
            ];
        }

        return [
            'ok' => true,
            'message' => sprintf(
                'Connected to %s and read %d live strike%s in %.0f seconds (%d within your display radius).',
                $server,
                $frames,
                $frames === 1 ? '' : 's',
                microtime(true) - $startedAt,
                $nearby
            ),
            'detail' => [
                'server' => $server,
                'frames' => $frames,
                'elapsed_seconds' => round(microtime(true) - $startedAt, 1),
                'sample' => $sample,
            ],
        ];
    }

    /**
     * Decode one compressed frame into a strike.
     *
     * @return array{lat:float,lon:float,ts:int,distance_mi:float|null}|null
     */
    public static function decodeFrame(string $payload, bool $filterRadius = true): ?array
    {
        $json = Lzw::decode($payload);
        if ($json === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['lat'], $data['lon'])) {
            return null;
        }
        $lat = (float) $data['lat'];
        $lon = (float) $data['lon'];
        if (!Geo::isValidLatitude($lat) || !Geo::isValidLongitude($lon)) {
            return null;
        }
        $ts = Ingest::parseTimestamp($data['time'] ?? null, 'auto');

        $distance = Geo::distanceMiles(
            Settings::getFloat('venue_lat'),
            Settings::getFloat('venue_lon'),
            $lat,
            $lon
        );

        if ($filterRadius && $distance > Settings::getFloat('display_radius_mi')) {
            return null;
        }

        return ['lat' => $lat, 'lon' => $lon, 'ts' => $ts, 'distance_mi' => $distance];
    }

    /**
     * Can we open a plain TCP connection to the feed's port?
     *
     * @return true|string true, or the reason it failed
     */
    public static function portIsReachable(string $host, int $port, float $timeout)
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $host, $port),
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            if ($errno === 0 && $errstr === '') {
                // A silent failure at the timeout is what a firewall that
                // drops packets rather than refusing them looks like.
                return sprintf('no response within %.0f seconds (the connection was not refused, it was ignored)', $timeout);
            }
            return $errstr !== '' ? $errstr : ('error ' . $errno);
        }
        fclose($socket);
        return true;
    }

    /** @return array<int,string> */
    public static function servers(): array
    {
        $servers = Settings::getList('blitz_servers');
        $clean = [];
        foreach ($servers as $server) {
            $server = strtolower(trim($server));
            if (preg_match('/^ws\d{1,2}$/', $server)) {
                $clean[] = $server;
            }
        }
        return $clean === [] ? ['ws1', 'ws7', 'ws8'] : array_values(array_unique($clean));
    }

    private static function initPayload(): string
    {
        $configured = trim(Settings::getString('blitz_init_json'));
        if ($configured === '') {
            return '{"a":111}';
        }
        // Guard against a typo in settings breaking the handshake silently.
        json_decode($configured, true);
        return json_last_error() === JSON_ERROR_NONE ? $configured : '{"a":111}';
    }

    private static function stateFile(): string
    {
        return SW_DATA . '/blitzortung-server.json';
    }

    private static function nextServerIndex(): int
    {
        $file = self::stateFile();
        if (is_file($file)) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (is_array($data) && isset($data['index'])) {
                return (int) $data['index'];
            }
        }
        return 0;
    }

    private static function rotateServer(): void
    {
        $next = self::nextServerIndex() + 1;
        @file_put_contents(self::stateFile(), json_encode(['index' => $next % 100]), LOCK_EX);
    }
}
