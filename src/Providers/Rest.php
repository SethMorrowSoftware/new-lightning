<?php
/**
 * Generic JSON-over-HTTPS lightning provider.
 *
 * Commercial feeds (Tomorrow.io, Weatherbit, Earth Networks, Vaisala, an
 * in-house aggregator) all return "a list of strikes" in slightly different
 * shapes, so rather than hard-code each one this provider is driven by a
 * dot-path field mapping the operator fills in once.
 */
declare(strict_types=1);

namespace StormWatch\Providers;

use StormWatch\ApiBudget;
use StormWatch\Geo;
use StormWatch\Ingest;
use StormWatch\Settings;

final class Rest
{
    private const TIMEOUT = 20;
    private const MAX_BODY_BYTES = 4 * 1024 * 1024;

    /** Key the metered usage is recorded under. */
    public const METER = 'rest';

    /**
     * What a call cost, according to the provider.
     *
     * Xweather returns X-Cost-Tokens, and the figure is not always 1 — it is
     * the product of endpoint, area and time-range multipliers. Counting
     * requests instead would quietly under-report the allowance being spent.
     *
     * @param array<string,string> $headers
     */
    private static function costOf(array $headers): int
    {
        foreach (['x-cost-tokens', 'x-cost', 'x-usage-cost'] as $name) {
            if (isset($headers[$name]) && is_numeric($headers[$name])) {
                return max(1, (int) round((float) $headers[$name]));
            }
        }
        return 1;
    }

    /**
     * Fetch and store the current strike list.
     *
     * @return array{ok:bool,ingested:int,message:string,http_status:int,records:int}
     */
    public static function poll(): array
    {
        $result = self::fetch();
        if (!$result['ok']) {
            return [
                'ok' => false,
                'ingested' => 0,
                'records' => 0,
                'http_status' => $result['http_status'],
                'message' => $result['message'],
            ];
        }

        $stored = Ingest::recordMany($result['records'], 'rest');

        return [
            'ok' => true,
            'ingested' => $stored,
            'records' => count($result['records']),
            'http_status' => $result['http_status'],
            'message' => sprintf(
                'Read %d record%s from the endpoint; stored %d new strike%s inside the display radius.',
                count($result['records']),
                count($result['records']) === 1 ? '' : 's',
                $stored,
                $stored === 1 ? '' : 's'
            ),
        ];
    }

    /**
     * Perform the request and map the response, without storing anything.
     * Used by poll() and by the "Test connection" button.
     *
     * @return array{ok:bool,records:array<int,array{lat:float,lon:float,ts:int}>,message:string,http_status:int,sample:mixed}
     */
    public static function fetch(): array
    {
        $endpoint = trim(Settings::getString('rest_endpoint'));
        if ($endpoint === '') {
            return self::failure('No endpoint URL is configured for the REST provider.', 0);
        }
        if (!preg_match('#^https?://#i', $endpoint)) {
            return self::failure('The endpoint URL must start with http:// or https://.', 0);
        }

        // Refuse to spend an allowance that has already run out — but say so
        // loudly, because an alerting system that has quietly stopped fetching
        // data is indistinguishable from clear weather.
        if (ApiBudget::isExhausted(self::METER)) {
            $usage = ApiBudget::usage(self::METER);
            return self::failure(sprintf(
                'This month\'s API allowance is used up (%s of %s accesses for %s). Polling is paused until the '
                . 'billing period rolls over. Raise the monthly budget on the Data source tab, or slow the poll '
                . 'intervals down.',
                number_format($usage['tokens']),
                number_format(ApiBudget::budget()),
                $usage['period']
            ), 0);
        }

        $apiKey = Settings::getString('rest_api_key');
        $apiSecret = Settings::getString('rest_api_secret');
        $authMode = Settings::getString('rest_auth_mode');
        $authName = trim(Settings::getString('rest_auth_name')) ?: 'apikey';
        $secretName = trim(Settings::getString('rest_auth_secret_name')) ?: 'client_secret';

        $url = self::substitute($endpoint);
        $headers = self::extraHeaders();

        if ($authMode === 'client_pair') {
            if ($apiKey === '' || $apiSecret === '') {
                return self::failure('This provider needs both an ID and a secret. Add them on the Data source tab.', 0);
            }
            $url .= (strpos($url, '?') === false ? '?' : '&')
                . rawurlencode($authName) . '=' . rawurlencode($apiKey)
                . '&' . rawurlencode($secretName) . '=' . rawurlencode($apiSecret);
        } elseif ($apiKey !== '') {
            if ($authMode === 'query') {
                $url .= (strpos($url, '?') === false ? '?' : '&')
                    . rawurlencode($authName) . '=' . rawurlencode($apiKey);
            } elseif ($authMode === 'header') {
                $headers[] = $authName . ': ' . $apiKey;
            } elseif ($authMode === 'bearer') {
                $headers[] = 'Authorization: Bearer ' . $apiKey;
            }
        }

        [$body, $status, $error, $responseHeaders] = self::request($url, $headers);

        // Bill the call whether or not it produced usable data: the provider
        // charged for it either way.
        if ($status > 0) {
            ApiBudget::record(self::METER, self::costOf($responseHeaders));
        }

        if ($error !== null) {
            return self::failure('The request failed: ' . $error, $status);
        }
        if ($status < 200 || $status >= 300) {
            return self::failure(self::explainStatus($status, $body, $url), $status);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return self::failure('The endpoint did not return JSON that could be decoded.', $status);
        }

        $rootPath = Settings::getString('rest_map_root');
        $list = Ingest::dotPath($decoded, $rootPath);
        if (!is_array($list)) {
            return self::failure(
                sprintf(
                    'No list of strikes was found at "%s". Check the list path against the response shape.',
                    $rootPath === '' ? '(root)' : $rootPath
                ),
                $status
            );
        }

        $latPath = Settings::getString('rest_map_lat');
        $lonPath = Settings::getString('rest_map_lon');
        $timePath = Settings::getString('rest_map_time');
        $timeFormat = Settings::getString('rest_time_format');

        $maxAge = max(60, Settings::getInt('rest_max_age_minutes') * 60);
        $oldest = time() - $maxAge;

        $records = [];
        $skipped = 0;
        $stale = 0;
        foreach ($list as $item) {
            if (!is_array($item)) {
                $skipped++;
                continue;
            }
            $lat = Ingest::dotPath($item, $latPath);
            $lon = Ingest::dotPath($item, $lonPath);
            if (!is_numeric($lat) || !is_numeric($lon)) {
                $skipped++;
                continue;
            }
            $lat = (float) $lat;
            $lon = (float) $lon;
            if (!Geo::isValidLatitude($lat) || !Geo::isValidLongitude($lon)) {
                $skipped++;
                continue;
            }
            $timestamp = Ingest::parseTimestamp(Ingest::dotPath($item, $timePath), $timeFormat);

            // Some endpoints happily return history. Storing it would raise an
            // alert for a storm that finished hours ago.
            if ($timestamp < $oldest) {
                $stale++;
                continue;
            }

            $records[] = ['lat' => $lat, 'lon' => $lon, 'ts' => $timestamp];
        }

        if ($records === [] && $list !== []) {
            if ($stale > 0 && $skipped === 0) {
                // Not a mapping problem — the feed simply had nothing recent.
                return [
                    'ok' => true,
                    'records' => [],
                    'http_status' => $status,
                    'sample' => $list[0] ?? null,
                    'message' => sprintf(
                        'The endpoint answered with %d strike%s, but all were older than the %d minute freshness limit. '
                        . 'That is normal in calm weather; raise the limit or add a time filter to the endpoint if it looks wrong.',
                        $stale,
                        $stale === 1 ? '' : 's',
                        (int) ($maxAge / 60)
                    ),
                ];
            }
            return self::failure(
                sprintf(
                    'Found %d item%s at "%s", but none had usable numbers at "%s" / "%s". Check the latitude and longitude paths.',
                    count($list),
                    count($list) === 1 ? '' : 's',
                    $rootPath === '' ? '(root)' : $rootPath,
                    $latPath,
                    $lonPath
                ),
                $status
            );
        }

        return [
            'ok' => true,
            'records' => $records,
            'http_status' => $status,
            'sample' => $list[0] ?? null,
            'message' => sprintf(
                'Mapped %d strike%s from the response%s%s.',
                count($records),
                count($records) === 1 ? '' : 's',
                $skipped > 0 ? sprintf(' (%d item(s) skipped as unusable)', $skipped) : '',
                $stale > 0 ? sprintf(' (%d older than %d minutes, ignored)', $stale, (int) ($maxAge / 60)) : ''
            ),
        ];
    }

    /**
     * @return array{ok:bool,message:string,detail:array<string,mixed>}
     */
    public static function test(): array
    {
        $result = self::fetch();
        $venueLat = Settings::getFloat('venue_lat');
        $venueLon = Settings::getFloat('venue_lon');
        $displayRadius = Settings::getFloat('display_radius_mi');

        $inRange = 0;
        foreach ($result['records'] as $record) {
            if (Geo::distanceMiles($venueLat, $venueLon, $record['lat'], $record['lon']) <= $displayRadius) {
                $inRange++;
            }
        }

        return [
            'ok' => $result['ok'],
            'message' => $result['ok']
                ? $result['message'] . sprintf(' %d of them fall inside your %s mile display radius.', $inRange, rtrim(rtrim(number_format($displayRadius, 1), '0'), '.'))
                : $result['message'],
            'detail' => [
                'http_status' => $result['http_status'],
                'records' => count($result['records']),
                'in_range' => $inRange,
                'sample' => $result['sample'] ?? null,
            ],
        ];
    }

    /**
     * Replace the placeholders an endpoint template may contain.
     */
    private static function substitute(string $url): string
    {
        $radius = self::requestRadiusMi();
        return strtr($url, [
            '{lat}' => (string) Settings::getFloat('venue_lat'),
            '{lon}' => (string) Settings::getFloat('venue_lon'),
            '{radius_mi}' => (string) $radius,
            '{radius_km}' => (string) round($radius * Geo::MILES_TO_KM, 3),
            '{now}' => (string) time(),
            '{now_iso}' => gmdate('c'),
            '{since}' => (string) (time() - 3600),
            '{since_iso}' => gmdate('c', time() - 3600),
        ]);
    }

    /**
     * The radius to ask the endpoint for: the display radius, clamped to
     * whatever the provider will actually answer for. Asking Xweather's
     * lightning/flash for more than 40km is an error, not a wider answer, so a
     * 30 mile display radius would otherwise fail every poll.
     */
    public static function requestRadiusMi(): float
    {
        $radius = Settings::getFloat('display_radius_mi');
        $cap = Settings::getFloat('rest_max_radius_mi');
        return $cap > 0 ? min($radius, $cap) : $radius;
    }

    /**
     * Strip credentials out of a URL so it can be shown on screen or written to
     * a log. The endpoint template carries the client ID and secret as query
     * parameters, so this has to happen before the URL is quoted anywhere.
     */
    public static function redactUrl(string $url): string
    {
        $sensitive = ['client_secret', 'client_id', 'secret', 'apikey', 'api_key', 'key',
                      'token', 'access_token', 'password', 'pass', 'auth'];
        return (string) preg_replace_callback(
            '/([?&])([^=&]+)=([^&]*)/',
            static function (array $m) use ($sensitive): string {
                return in_array(strtolower(rawurldecode($m[2])), $sensitive, true)
                    ? $m[1] . $m[2] . '=***'
                    : $m[1] . $m[2] . '=' . $m[3];
            },
            $url
        );
    }

    /**
     * Turn a failed HTTP status into something a person can act on. The raw
     * body is kept on the end, but the leading sentence should already say what
     * went wrong — an operator reading this is usually not the person who set
     * the credentials up.
     *
     * The URL is quoted back because "your plan does not cover this endpoint"
     * is useless without knowing which endpoint was asked for. Credentials are
     * stripped out of it first.
     */
    public static function explainStatus(int $status, string $body, string $url = ''): string
    {
        $snippet = trim(mb_substr(strip_tags($body), 0, 200));
        $decoded = json_decode($body, true);
        $code = '';
        $description = '';
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $code = is_string($decoded['error']['code'] ?? null) ? $decoded['error']['code'] : '';
            $description = is_string($decoded['error']['description'] ?? null) ? $decoded['error']['description'] : '';
        }
        $where = $url === '' ? '' : ' Requested: ' . self::redactUrl($url);

        // Xweather answers 401 for both "who are you" and "not on your plan",
        // which is why these have to be told apart by the error code.
        if ($code === 'insufficient_scope' || stripos($description, 'subscription level') !== false) {
            // Whether this is fixable by changing the endpoint depends entirely
            // on which one was asked for, so say which case this is rather than
            // offering advice that may already have been followed.
            $isFlash = stripos($url, '/lightning/flash') !== false;
            $advice = $isFlash
                ? 'That is lightning/flash, the endpoint with the widest availability — so lightning data is not '
                    . 'included on this account at all, and no change of endpoint will fix it. Either add the '
                    . 'Lightning add-on to the Xweather account, or switch to a free source: Blitzortung, or the '
                    . 'browser relay if this host blocks outbound connections.'
                : 'That is the plain lightning endpoint. Try the "lightning flash" option from Quick setup on the '
                    . 'Data source tab, which is available on more plans. If flash fails the same way, lightning '
                    . 'data is not included on this account and needs the paid Lightning add-on.';
            return 'The credentials were accepted, but this account\'s plan does not cover this endpoint '
                . '(HTTP ' . $status . ' insufficient_scope).' . $where . ' ' . $advice . ' Response: ' . $snippet;
        }
        if ($code === 'invalid_client' || $code === 'unauthorized' || $status === 401 || $status === 403) {
            return 'The endpoint rejected the credentials (HTTP ' . $status . ($code !== '' ? ' ' . $code : '') . '). '
                . 'Check the ID and secret on the Data source tab, and that the account is active.' . $where
                . ' Response: ' . $snippet;
        }
        if ($status === 429) {
            return 'The endpoint is rate limiting these requests (HTTP 429). Slow the poll intervals down on the '
                . 'Data source tab.' . $where . ' Response: ' . $snippet;
        }
        if ($status >= 500) {
            return 'The provider had a server error (HTTP ' . $status . '). This is their end, not the '
                . 'configuration; polling will retry.' . $where . ' Response: ' . $snippet;
        }
        return sprintf(
            'The endpoint returned HTTP %d.%s%s',
            $status,
            $where,
            $snippet !== '' ? ' Response: ' . $snippet : ''
        );
    }

    /** @return array<int,string> */
    private static function extraHeaders(): array
    {
        $raw = Settings::getString('rest_extra_headers');
        $headers = ['Accept: application/json'];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            $headers[] = $line;
        }
        return $headers;
    }

    /**
     * @param array<int,string> $headers
     * @return array{0:string,1:int,2:string|null,3:array<string,string>} body, status, error, response headers
     */
    private static function request(string $url, array $headers): array
    {
        if (function_exists('curl_init')) {
            $received = [];
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 10,
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
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;
            curl_close($ch);
            return [is_string($body) ? substr($body, 0, self::MAX_BODY_BYTES) : '', $status, $error, $received];
        }

        if (!ini_get('allow_url_fopen')) {
            return ['', 0, 'Neither cURL nor allow_url_fopen is available on this server.', []];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => self::TIMEOUT,
                'ignore_errors' => true,
                'user_agent' => 'StormWatch/' . SW_VERSION,
            ],
        ]);
        $body = @file_get_contents($url, false, $context, 0, self::MAX_BODY_BYTES);
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
        if ($body === false) {
            return ['', $status, 'The request could not be completed.', $received];
        }
        return [$body, $status, null, $received];
    }

    /**
     * @return array{ok:false,records:array<int,array{lat:float,lon:float,ts:int}>,message:string,http_status:int,sample:null}
     */
    private static function failure(string $message, int $status): array
    {
        return ['ok' => false, 'records' => [], 'message' => $message, 'http_status' => $status, 'sample' => null];
    }
}
