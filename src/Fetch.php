<?php
/**
 * The outbound HTTP GET the weather sources share.
 *
 * This was the body of Providers\Rest::request(). It moved here when a second
 * caller appeared — the National Weather Service forecast — because the parts
 * worth getting right are not the parts that look hard: reading a response
 * against a wall clock rather than a per-read timeout, stopping at a byte cap
 * instead of truncating afterwards, and working on a host that has cURL
 * compiled out. Re-typing all of that for each caller is how one of the copies
 * ends up without it.
 */
declare(strict_types=1);

namespace StormWatch;

final class Fetch
{
    public const DEFAULT_TIMEOUT = 20;

    public const DEFAULT_MAX_BYTES = 4 * 1024 * 1024;

    /**
     * Perform a GET.
     *
     * Never throws: a transport problem comes back as the error string so the
     * caller can put it in front of an operator rather than on to a stack
     * trace nobody will read.
     *
     * @param array<int,string> $headers
     * @return array{0:string,1:int,2:string|null,3:array<string,string>} body, status, error, response headers
     */
    public static function get(
        string $url,
        array $headers = [],
        int $timeout = self::DEFAULT_TIMEOUT,
        int $maxBytes = self::DEFAULT_MAX_BYTES
    ): array {
        if (function_exists('curl_init')) {
            $received = [];
            // Stop reading at the cap rather than truncating afterwards: a
            // misconfigured or hostile endpoint streaming hundreds of megabytes
            // would otherwise exhaust the memory limit and kill the cron run
            // that was meant to be watching for lightning.
            $body = '';
            $overLimit = false;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$overLimit, $maxBytes): int {
                    $length = strlen($chunk);
                    if (strlen($body) + $length > $maxBytes) {
                        $overLimit = true;
                        return 0;   // a short write aborts the transfer
                    }
                    $body .= $chunk;
                    return $length;
                },
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => 'StormWatch/' . SW_VERSION,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_BUFFERSIZE => 65536,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;
            curl_close($ch);
            if ($overLimit) {
                // The abort surfaces as a write error; say what it really was.
                $error = sprintf(
                    'The endpoint returned more than %d MB, which is far more than the answer should be. '
                    . 'Check the endpoint URL and any limit parameter on it.',
                    max(1, (int) round($maxBytes / (1024 * 1024)))
                );
            }
            return [$body, $status, $error, $received];
        }

        if (!ini_get('allow_url_fopen')) {
            return ['', 0, 'Neither cURL nor allow_url_fopen is available on this server.', []];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $timeout,
                'ignore_errors' => true,
                'user_agent' => 'StormWatch/' . SW_VERSION,
            ],
        ]);

        // The context's 'timeout' bounds a single read, not the call. An
        // endpoint dribbling a byte every few seconds satisfies it for ever,
        // and file_get_contents would sit there — holding the tick lock, so
        // every later cron minute skips out at the lock and never re-evaluates
        // the alert state. On a REST feed that is the whole alerting path
        // stopped by one slow server. Read it ourselves against a wall clock.
        $startedAt = microtime(true);
        $handle = @fopen($url, 'rb', false, $context);

        $status = 0;
        $received = [];
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
                continue;
            }
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $received[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        if ($handle === false) {
            return ['', $status, 'The request could not be completed.', $received];
        }

        stream_set_timeout($handle, 5);
        $body = '';
        $timedOut = false;
        while (!feof($handle)) {
            if ((microtime(true) - $startedAt) > $timeout) {
                $timedOut = true;
                break;
            }
            $chunk = fread($handle, 65536);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($handle);
                if (!empty($meta['timed_out'])) {
                    $timedOut = true;
                }
                break;
            }
            $body .= $chunk;
            if (strlen($body) >= $maxBytes) {
                break;
            }
        }
        fclose($handle);

        if ($timedOut) {
            return [
                '',
                $status,
                sprintf('The endpoint did not finish answering within %d seconds.', $timeout),
                $received,
            ];
        }
        return [substr($body, 0, $maxBytes), $status, null, $received];
    }
}
