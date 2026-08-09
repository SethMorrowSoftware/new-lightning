<?php
/**
 * The National Weather Service forecast shown at the top of the dashboard.
 *
 * Lightning detection answers "is it dangerous right now". Staff also have to
 * decide things an hour ahead — whether to run the four o'clock party outside,
 * whether to start closing the water slides — and for that they need to know
 * what is coming. api.weather.gov gives that away for free, with no account:
 * the hour-by-hour forecast, the day/night outlook, and the watches, warnings
 * and advisories in force for this exact point.
 *
 * Two rules shape everything below.
 *
 * The dashboard never waits on the National Weather Service. Every fetch
 * happens in the cron tick and lands in a cache table; a page load and a poll
 * only ever read that table. A kiosk polling every ten seconds must not turn
 * into ten-second polling of somebody else's public API either, which is the
 * same rule from the other side.
 *
 * And the card says how old it is. A forecast that quietly stopped updating
 * three hours ago, still showing yesterday evening's "clear", is worse than no
 * card at all — this is a screen people make outdoor-safety decisions in front
 * of.
 */
declare(strict_types=1);

namespace StormWatch;

final class Forecast
{
    private const BASE = 'https://api.weather.gov';

    /** Short on purpose: this runs on a cron minute that has other work to do. */
    private const TIMEOUT = 12;

    private const MAX_BODY_BYTES = 2 * 1024 * 1024;

    /** A grid square only moves when the venue does, so the lookup is cached hard. */
    private const GRID_TTL = 30 * 86400;

    /** After a failure, try again sooner than the normal cadence. */
    private const RETRY_SECONDS = 120;

    /**
     * Outside the coverage area the answer will not change today, or this year.
     * Asking every ten minutes for ever would be rude and pointless in equal
     * measure.
     */
    private const UNCOVERED_RETRY_SECONDS = 6 * 3600;

    /** Kept in the cache; more than is shown, so expiry still leaves a full row. */
    private const STORE_HOURS = 18;
    private const STORE_PERIODS = 8;

    /** Handed to the dashboard after the past has been filtered off. */
    private const SHOW_HOURS = 12;
    private const SHOW_PERIODS = 5;

    private const MAX_ALERTS = 4;

    /** Severity ordering for the alert list — worst first. */
    private const SEVERITY_RANK = ['extreme' => 0, 'severe' => 1, 'moderate' => 2, 'minor' => 3, 'unknown' => 4];

    public static function isEnabled(): bool
    {
        return Settings::getBool('nws_enabled');
    }

    /** Forget the row read earlier in this request. For tests. */
    public static function flushCache(): void
    {
        self::$cachedRow = false;
    }

    public static function refreshSeconds(): int
    {
        return max(300, Settings::getInt('nws_refresh_minutes') * 60);
    }

    // ------------------------------------------------------------- refresh --

    /**
     * Fetch if the cache has aged out. Called from the cron tick.
     *
     * @return array{ran:bool,ok:bool,message:string}
     */
    public static function refreshIfDue(): array
    {
        if (!self::isEnabled()) {
            return ['ran' => false, 'ok' => true, 'message' => 'The forecast card is switched off.'];
        }

        $row = self::row();
        $now = time();
        $attempted = (int) ($row['attempted_at'] ?? 0);
        $ok = (int) ($row['ok'] ?? 0) === 1;
        $covered = (int) ($row['covered'] ?? 1) === 1;
        $samePoint = $row !== null && (string) $row['point'] === self::pointKey();

        $due = $ok ? self::refreshSeconds() : min(self::refreshSeconds(), self::RETRY_SECONDS);
        if (!$covered && $samePoint) {
            $due = self::UNCOVERED_RETRY_SECONDS;
        }
        // The venue moving invalidates everything, however recently it was read.
        if ($samePoint && $attempted > 0) {
            $age = $now - $attempted;
            // A negative age means the clock moved backwards; treat it as due
            // rather than as "not for another century".
            if ($age >= 0 && $age < $due) {
                return ['ran' => false, 'ok' => $ok, 'message' => 'The cached forecast is still current.'];
            }
        }

        $result = self::refresh();
        return ['ran' => true, 'ok' => $result['ok'], 'message' => $result['message']];
    }

    /**
     * Fetch now, whatever the cache says.
     *
     * @return array{ok:bool,message:string}
     */
    public static function refresh(): array
    {
        if (!self::isEnabled()) {
            return ['ok' => false, 'message' => 'The forecast card is switched off in Settings → Venue & map.'];
        }
        // A refresh from the dashboard button and one from the cron minute can
        // land together; one of them is enough.
        if (!Runner::acquireLock('forecast')) {
            return ['ok' => true, 'message' => 'A forecast refresh is already running.'];
        }
        try {
            return self::fetchAndStore();
        } catch (\Throwable $e) {
            $message = 'The forecast could not be updated: ' . $e->getMessage();
            try {
                self::store(['attempted_at' => time(), 'ok' => 0, 'message' => $message]);
            } catch (\Throwable $ignored) {
                // The cache is a convenience; failing to write it is not worth
                // taking the cron minute down over.
            }
            return ['ok' => false, 'message' => $message];
        } finally {
            Runner::releaseLock('forecast');
        }
    }

    /** @return array{ok:bool,message:string} */
    private static function fetchAndStore(): array
    {
        $now = time();
        $point = self::pointKey();
        $row = self::row();
        $previous = self::payloadOf($row);

        $grid = self::cachedGrid($row, $point, $now);
        if ($grid === null) {
            $resolved = self::resolveGrid();
            if (!$resolved['ok']) {
                self::store([
                    'point' => $point,
                    'covered' => $resolved['covered'] ? 1 : 0,
                    'attempted_at' => $now,
                    'ok' => 0,
                    'message' => $resolved['message'],
                ]);
                return ['ok' => false, 'message' => $resolved['message']];
            }
            $grid = $resolved['grid'];
            self::store([
                'point' => $point,
                'covered' => 1,
                'grid' => (string) json_encode($grid, JSON_UNESCAPED_SLASHES),
                'grid_at' => $now,
            ]);
        }

        [$lat, $lon] = self::coordinates();
        $problems = [];

        $alerts = self::fetchJson(self::BASE . '/alerts/active?point=' . $lat . ',' . $lon);
        $periods = self::fetchJson(self::withUnits((string) $grid['forecast']));
        $hourly = $grid['hourly'] !== ''
            ? self::fetchJson(self::withUnits((string) $grid['hourly']))
            : ['ok' => false, 'data' => null, 'message' => '', 'status' => 0];

        if (!$alerts['ok']) {
            $problems[] = 'watches and warnings (' . $alerts['message'] . ')';
        }
        if (!$periods['ok']) {
            $problems[] = 'the day-by-day forecast (' . $periods['message'] . ')';
        }
        if (!$hourly['ok'] && $grid['hourly'] !== '') {
            $problems[] = 'the hourly forecast (' . $hourly['message'] . ')';
        }

        // All three down is a network or an outage, and there is nothing new to
        // record beyond that. Keep whatever was last known and say why it has
        // stopped moving.
        if (!$alerts['ok'] && !$periods['ok'] && !$hourly['ok']) {
            $message = 'The National Weather Service could not be reached. ' . $periods['message'];
            self::store(['point' => $point, 'attempted_at' => $now, 'ok' => 0, 'message' => $message]);
            return ['ok' => false, 'message' => $message];
        }

        $payload = self::payloadFrom(
            $grid,
            $alerts['ok'] ? $alerts['data'] : null,
            $periods['ok'] ? $periods['data'] : null,
            $hourly['ok'] ? $hourly['data'] : null,
            $previous
        );

        $ok = $problems === [];
        $message = $ok
            ? ''
            : 'Part of the forecast could not be refreshed: ' . implode('; ', $problems) . '.';

        self::store([
            'point' => $point,
            'covered' => 1,
            'payload' => (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            // Only a clean read moves the "updated" clock. A partial one leaves
            // it where it was, so the card keeps admitting how old it is.
            'fetched_at' => $ok ? $now : (int) ($row['fetched_at'] ?? 0),
            'attempted_at' => $now,
            'ok' => $ok ? 1 : 0,
            'message' => $message,
        ]);

        return [
            'ok' => $ok,
            'message' => $ok
                ? sprintf(
                    'Forecast updated for %s: %d period%s, %d hour%s, %d active alert%s.',
                    $payload['place'] !== '' ? $payload['place'] : 'the venue',
                    count($payload['periods']),
                    count($payload['periods']) === 1 ? '' : 's',
                    count($payload['hours']),
                    count($payload['hours']) === 1 ? '' : 's',
                    count($payload['alerts']),
                    count($payload['alerts']) === 1 ? '' : 's'
                )
                : $message,
        ];
    }

    /**
     * The grid square, forecast URLs and place name for the venue.
     *
     * api.weather.gov works in grid squares rather than coordinates, so the
     * first call turns a latitude and longitude into the URLs for everything
     * else. It is the same answer every time for a fixed venue, so it is only
     * asked for once a month.
     *
     * @return array{ok:bool,covered:bool,grid:array<string,string>,message:string}
     */
    private static function resolveGrid(): array
    {
        [$lat, $lon] = self::coordinates();
        $result = self::fetchJson(self::BASE . '/points/' . $lat . ',' . $lon);

        if (!$result['ok']) {
            // 404 here is not a fault: it is how the service says "that is not
            // one of my grid squares". Everywhere outside the United States and
            // its territories answers this way.
            if ($result['status'] === 404) {
                return [
                    'ok' => false,
                    'covered' => false,
                    'grid' => [],
                    'message' => sprintf(
                        'The National Weather Service does not cover %s, %s — its forecasts stop at the United States '
                        . 'and its territories. Switch the forecast card off on the Venue & map tab, and use the radar '
                        . 'overlay on the map instead.',
                        $lat,
                        $lon
                    ),
                ];
            }
            return ['ok' => false, 'covered' => true, 'grid' => [], 'message' => $result['message']];
        }

        $properties = is_array($result['data']['properties'] ?? null) ? $result['data']['properties'] : [];
        $forecast = trim((string) ($properties['forecast'] ?? ''));
        if ($forecast === '' || !preg_match('#^https://api\.weather\.gov/#i', $forecast)) {
            return [
                'ok' => false,
                'covered' => true,
                'grid' => [],
                'message' => 'api.weather.gov answered, but without a forecast address for this location. '
                    . 'That is usually temporary; the next refresh will try again.',
            ];
        }

        $hourly = trim((string) ($properties['forecastHourly'] ?? ''));
        if ($hourly !== '' && !preg_match('#^https://api\.weather\.gov/#i', $hourly)) {
            $hourly = '';
        }

        $relative = is_array($properties['relativeLocation']['properties'] ?? null)
            ? $properties['relativeLocation']['properties']
            : [];
        $city = trim((string) ($relative['city'] ?? ''));
        $state = trim((string) ($relative['state'] ?? ''));

        return [
            'ok' => true,
            'covered' => true,
            'message' => '',
            'grid' => [
                'forecast' => $forecast,
                'hourly' => $hourly,
                'office' => trim((string) ($properties['gridId'] ?? '')),
                'place' => $city !== '' && $state !== '' ? $city . ', ' . $state : ($city !== '' ? $city : $state),
            ],
        ];
    }

    // ------------------------------------------------------------- reading --

    /**
     * The block the dashboard renders. Null when the card is switched off.
     *
     * Everything expired is filtered here rather than when it was stored, so a
     * cache that has stopped updating shrinks visibly instead of showing hours
     * that have already been and gone.
     *
     * @return array<string,mixed>|null
     */
    public static function summary(): ?array
    {
        if (!self::isEnabled()) {
            return null;
        }

        $row = self::row();
        $now = time();

        if ($row === null) {
            return [
                'stamp' => '0.0',
                'ok' => false,
                'covered' => true,
                'never' => true,
                'message' => 'The forecast has not been fetched yet. It arrives with the next scheduled task run.',
                'updated_at' => null,
                'stale_after' => self::staleAfter(),
                'place' => '',
                'office' => '',
                'alerts' => [],
                'hours' => [],
                'periods' => [],
            ];
        }

        $payload = self::payloadOf($row);

        $alerts = [];
        foreach ((array) ($payload['alerts'] ?? []) as $alert) {
            if (!is_array($alert)) {
                continue;
            }
            // A warning that has run out is not a warning. Anything without an
            // expiry is kept: the service does issue open-ended advisories, and
            // dropping those would be the wrong kind of tidy.
            $expires = isset($alert['expires']) && is_int($alert['expires']) ? $alert['expires'] : null;
            if ($expires !== null && $expires <= $now) {
                continue;
            }
            $alerts[] = $alert;
        }

        return [
            'stamp' => self::stampOf($row),
            'ok' => (int) $row['ok'] === 1,
            'covered' => (int) $row['covered'] === 1,
            'never' => (int) $row['fetched_at'] === 0,
            'message' => (string) ($row['message'] ?? ''),
            'updated_at' => (int) $row['fetched_at'] > 0 ? (int) $row['fetched_at'] : null,
            'stale_after' => self::staleAfter(),
            'place' => (string) ($payload['place'] ?? ''),
            'office' => (string) ($payload['office'] ?? ''),
            'alerts' => array_slice($alerts, 0, self::MAX_ALERTS),
            'hours' => self::stillAhead((array) ($payload['hours'] ?? []), $now, self::SHOW_HOURS),
            'periods' => self::stillAhead((array) ($payload['periods'] ?? []), $now, self::SHOW_PERIODS),
        ];
    }

    /**
     * The summary, but only when the caller's copy is out of date.
     *
     * The dashboard poll carries the stamp of what it is already showing. The
     * forecast changes every few minutes and the poll runs every few seconds,
     * so sending it every time would be several megabytes a day down a wall
     * display's connection to say nothing new.
     *
     * @return array<string,mixed>|null
     */
    public static function summaryIfChanged(?string $stamp): ?array
    {
        if (!self::isEnabled()) {
            return null;
        }
        if ($stamp !== null && $stamp !== '' && $stamp === self::stamp()) {
            return null;
        }
        return self::summary();
    }

    /** Identifies the cached copy: changes on every refresh attempt. */
    public static function stamp(): string
    {
        if (!self::isEnabled()) {
            return '';
        }
        return self::stampOf(self::row());
    }

    /** One line for the command line and the settings screen. */
    public static function describe(): string
    {
        if (!self::isEnabled()) {
            return 'off';
        }
        $row = self::row();
        if ($row === null || (int) $row['attempted_at'] === 0) {
            return 'never fetched';
        }
        $now = time();
        $age = Runner::humanAge(max(0, $now - (int) $row['fetched_at']));
        if ((int) $row['fetched_at'] === 0) {
            return 'never fetched — ' . (string) $row['message'];
        }
        return ((int) $row['ok'] === 1 ? 'ok' : 'PROBLEM')
            . ', updated ' . $age . ' ago'
            . ((int) $row['ok'] === 1 ? '' : ' — ' . (string) $row['message']);
    }

    // ------------------------------------------------------------- helpers --

    /** @param array<int,mixed> $items @return array<int,array<string,mixed>> */
    private static function stillAhead(array $items, int $now, int $limit): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $end = isset($item['end']) && is_int($item['end']) ? $item['end'] : null;
            if ($end !== null && $end <= $now) {
                continue;
            }
            $out[] = $item;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Assemble the cached payload from three decoded api.weather.gov responses.
     *
     * Public because this — not the HTTP around it — is the part that can be
     * wrong, and it is proved in the test suite against recorded responses. Any
     * machine that cannot reach api.weather.gov, which includes most build
     * boxes and any venue behind a strict firewall, has no other way to check
     * that a response shape is still being read correctly.
     *
     * Any of the three may be null, meaning that request failed. Each then
     * falls back to the matching part of the previous payload rather than to
     * nothing: a warning in force is worth keeping on screen even when the
     * outlook beside it did not come back with it.
     *
     * @param array<string,string> $grid
     * @param array<string,mixed>|null $alerts   /alerts/active
     * @param array<string,mixed>|null $periods  the day/night forecast
     * @param array<string,mixed>|null $hourly   the hourly forecast
     * @param array<string,mixed> $previous      the last payload stored
     * @return array<string,mixed>
     */
    public static function payloadFrom(
        array $grid,
        ?array $alerts,
        ?array $periods,
        ?array $hourly,
        array $previous = []
    ): array {
        return [
            'place' => (string) ($grid['place'] ?? ''),
            'office' => (string) ($grid['office'] ?? ''),
            'alerts' => $alerts !== null
                ? self::distilAlerts(is_array($alerts['features'] ?? null) ? $alerts['features'] : [])
                : (array) ($previous['alerts'] ?? []),
            'periods' => $periods !== null
                ? self::distilPeriods(self::periodsOf($periods), self::STORE_PERIODS, false)
                : (array) ($previous['periods'] ?? []),
            'hours' => $hourly !== null
                ? self::distilPeriods(self::periodsOf($hourly), self::STORE_HOURS, true)
                : (array) ($previous['hours'] ?? []),
        ];
    }

    /**
     * Turn the service's period list into the few fields the card draws.
     *
     * @param array<int,mixed> $periods
     * @return array<int,array<string,mixed>>
     */
    private static function distilPeriods(array $periods, int $limit, bool $hourly): array
    {
        $out = [];
        foreach ($periods as $period) {
            if (!is_array($period)) {
                continue;
            }
            $start = self::timestamp($period['startTime'] ?? null);
            if ($start === null) {
                continue;
            }
            $end = self::timestamp($period['endTime'] ?? null) ?? ($start + ($hourly ? 3600 : 12 * 3600));
            $short = self::text($period['shortForecast'] ?? '', 90);
            $detail = $hourly ? '' : self::text($period['detailedForecast'] ?? '', 400);

            $entry = [
                'start' => $start,
                'end' => $end,
                'day' => (bool) ($period['isDaytime'] ?? true),
                'temp' => is_numeric($period['temperature'] ?? null)
                    ? (int) round((float) $period['temperature'])
                    : null,
                'unit' => strtoupper(self::text($period['temperatureUnit'] ?? 'F', 4)),
                'pop' => self::percentage($period['probabilityOfPrecipitation'] ?? null),
                'short' => $short,
                'wind' => self::text($period['windSpeed'] ?? '', 30),
                'dir' => self::text($period['windDirection'] ?? '', 4),
                // What the card highlights. The detailed text is searched too:
                // "Chance of showers" as the headline with thunderstorms in the
                // body is a normal way for the service to word an afternoon.
                'storm' => self::isStormy($short . ' ' . $detail),
            ];
            if (!$hourly) {
                $entry['name'] = self::text($period['name'] ?? '', 40);
                $entry['detail'] = $detail;
            }

            $out[] = $entry;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * The watches, warnings and advisories in force at the venue.
     *
     * @param array<int,mixed> $features
     * @return array<int,array<string,mixed>>
     */
    private static function distilAlerts(array $features): array
    {
        $out = [];
        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : null;
            if ($properties === null) {
                continue;
            }
            // Exercises and tests are published through the same feed as the
            // real thing, and this card is read by people deciding whether to
            // clear a midway.
            $status = strtolower(self::text($properties['status'] ?? 'Actual', 20));
            if ($status !== '' && $status !== 'actual') {
                continue;
            }
            if (strtolower(self::text($properties['messageType'] ?? '', 20)) === 'cancel') {
                continue;
            }
            $event = self::text($properties['event'] ?? '', 80);
            if ($event === '') {
                continue;
            }

            $out[] = [
                'event' => $event,
                'severity' => strtolower(self::text($properties['severity'] ?? 'Unknown', 20)),
                'urgency' => strtolower(self::text($properties['urgency'] ?? '', 20)),
                'headline' => self::text($properties['headline'] ?? '', 200),
                'instruction' => self::text($properties['instruction'] ?? '', 400),
                'area' => self::text($properties['areaDesc'] ?? '', 160),
                'sender' => self::text($properties['senderName'] ?? '', 80),
                'onset' => self::timestamp($properties['onset'] ?? $properties['effective'] ?? null),
                'expires' => self::timestamp($properties['ends'] ?? null)
                    ?? self::timestamp($properties['expires'] ?? null),
            ];
        }

        // Worst first, then whichever ends soonest — the order somebody scanning
        // the top of the card needs them in.
        usort($out, static function (array $a, array $b): int {
            $rank = (self::SEVERITY_RANK[$a['severity']] ?? 4) <=> (self::SEVERITY_RANK[$b['severity']] ?? 4);
            if ($rank !== 0) {
                return $rank;
            }
            return ($a['expires'] ?? PHP_INT_MAX) <=> ($b['expires'] ?? PHP_INT_MAX);
        });

        // The point query can return the same event from more than one zone.
        // They all cover this venue, so one row each is what is wanted.
        $seen = [];
        $unique = [];
        foreach ($out as $alert) {
            $key = strtolower($alert['event']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $alert;
        }

        return array_slice($unique, 0, self::MAX_ALERTS);
    }

    public static function isStormy(string $text): bool
    {
        return preg_match('/thunder|t-?storms?\b|tstm|squall|tornado|hail|lightning/i', $text) === 1;
    }

    /** @param array<string,mixed> $data @return array<int,mixed> */
    private static function periodsOf(array $data): array
    {
        $periods = $data['properties']['periods'] ?? null;
        return is_array($periods) ? $periods : [];
    }

    /**
     * Ask for Celsius when the venue is set to kilometres. Somebody who reads
     * distances in kilometres does not want the temperature in Fahrenheit.
     */
    private static function withUnits(string $url): string
    {
        $units = Settings::getString('units') === 'km' ? 'si' : 'us';
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'units=' . $units;
    }

    /** @return array{0:string,1:string} latitude, longitude as the API wants them */
    private static function coordinates(): array
    {
        // More than four decimal places earns a redirect to the rounded form.
        return [
            (string) round(Settings::getFloat('venue_lat'), 4),
            (string) round(Settings::getFloat('venue_lon'), 4),
        ];
    }

    private static function pointKey(): string
    {
        [$lat, $lon] = self::coordinates();
        return $lat . ',' . $lon;
    }

    /**
     * api.weather.gov asks callers to identify themselves and leave a way to be
     * contacted, and says it would rather warn a noisy client than block one.
     * Give it something real to warn.
     */
    private static function userAgent(): string
    {
        $contact = trim(Settings::getString('nws_contact'));
        if ($contact === '') {
            $contact = trim(Settings::getString('email_from'));
        }
        if ($contact === '') {
            $contact = rtrim(trim(Settings::getString('public_base_url')), '/');
        }
        if ($contact === '') {
            $contact = 'no contact configured';
        }
        // A header is one line: anything that could start a second one goes.
        $contact = (string) preg_replace('/[^\x20-\x7E]/', '', $contact);
        return 'StormWatch/' . SW_VERSION . ' (' . mb_substr(trim($contact), 0, 120) . ')';
    }

    /**
     * @return array{ok:bool,status:int,data:array<string,mixed>|null,message:string}
     */
    private static function fetchJson(string $url): array
    {
        [$body, $status, $error] = Fetch::get(
            $url,
            ['Accept: application/geo+json', 'User-Agent: ' . self::userAgent()],
            self::TIMEOUT,
            self::MAX_BODY_BYTES
        );

        if ($error !== null) {
            return [
                'ok' => false,
                'status' => $status,
                'data' => null,
                'message' => 'api.weather.gov could not be reached: ' . $error,
            ];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => $status, 'data' => null, 'message' => self::explainStatus($status, $body)];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'status' => $status,
                'data' => null,
                'message' => 'api.weather.gov did not return JSON that could be decoded.',
            ];
        }
        return ['ok' => true, 'status' => $status, 'data' => $decoded, 'message' => ''];
    }

    public static function explainStatus(int $status, string $body): string
    {
        // The service answers errors as RFC 7807 problem documents, and the
        // "detail" line is normally the whole diagnosis.
        $detail = '';
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['detail']) && is_string($decoded['detail'])) {
            $detail = ' ' . trim($decoded['detail']);
        }
        if ($detail === '') {
            $snippet = trim(mb_substr(strip_tags($body), 0, 160));
            $detail = $snippet === '' ? '' : ' ' . $snippet;
        }

        if ($status === 404) {
            return 'api.weather.gov has no forecast for this location (HTTP 404).' . $detail;
        }
        if ($status === 429) {
            return 'api.weather.gov is rate limiting these requests (HTTP 429). Raise the forecast refresh '
                . 'interval on the Venue & map tab.' . $detail;
        }
        if ($status >= 500) {
            return 'api.weather.gov had a server error (HTTP ' . $status . '). That is their end; the next '
                . 'refresh will try again.' . $detail;
        }
        return 'api.weather.gov returned HTTP ' . $status . '.' . $detail;
    }

    // --------------------------------------------------------------- store --

    /**
     * @var array<string,mixed>|null|false false means "not read this request"
     */
    private static $cachedRow = false;

    /**
     * The cache row, read once per request.
     *
     * A dashboard poll asks for it twice — once to compare stamps and once to
     * build the answer — and that poll arrives every few seconds from every
     * open tab and wall display in the building.
     *
     * @return array<string,mixed>|null
     */
    private static function row(): ?array
    {
        if (self::$cachedRow !== false) {
            return self::$cachedRow;
        }
        try {
            return self::$cachedRow = Database::instance()->first('SELECT * FROM forecast_cache WHERE id = 1');
        } catch (\Throwable $e) {
            // Before the migration has run, or on a database that has just gone
            // away. The dashboard has bigger problems than a missing forecast.
            return self::$cachedRow = null;
        }
    }

    /** @param array<string,mixed> $columns */
    private static function store(array $columns): void
    {
        Database::instance()->upsert('forecast_cache', array_merge(['id' => 1], $columns), 'id');
        // api/action.php refreshes and then reads the result back in the same
        // request; a memo left in place there would hand back the copy from
        // before the fetch.
        self::$cachedRow = false;
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed> */
    private static function payloadOf(?array $row): array
    {
        if ($row === null || ($row['payload'] ?? null) === null || (string) $row['payload'] === '') {
            return [];
        }
        $decoded = json_decode((string) $row['payload'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The cached grid, when it is still the right one.
     *
     * @param array<string,mixed>|null $row
     * @return array<string,string>|null
     */
    private static function cachedGrid(?array $row, string $point, int $now): ?array
    {
        if ($row === null || (string) $row['point'] !== $point) {
            return null;
        }
        if (($now - (int) $row['grid_at']) >= self::GRID_TTL) {
            return null;
        }
        $grid = json_decode((string) ($row['grid'] ?? ''), true);
        if (!is_array($grid) || trim((string) ($grid['forecast'] ?? '')) === '') {
            return null;
        }
        return [
            'forecast' => (string) $grid['forecast'],
            'hourly' => (string) ($grid['hourly'] ?? ''),
            'office' => (string) ($grid['office'] ?? ''),
            'place' => (string) ($grid['place'] ?? ''),
        ];
    }

    /** @param array<string,mixed>|null $row */
    private static function stampOf(?array $row): string
    {
        if ($row === null) {
            return '0.0';
        }
        return (int) $row['fetched_at'] . '.' . (int) $row['attempted_at'];
    }

    /**
     * How long the card waits before calling itself out of date. Three cycles,
     * so one missed cron minute is not an alarm, and never less than an hour —
     * the underlying forecast is only reissued about that often anyway.
     */
    private static function staleAfter(): int
    {
        return max(3600, self::refreshSeconds() * 3);
    }

    private static function timestamp($value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $parsed = strtotime($value);
        return $parsed === false ? null : $parsed;
    }

    private static function text($value, int $max): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        // The service wraps its detailed text at column 65 with real newlines.
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        return mb_substr($clean, 0, $max);
    }

    /** A probabilityOfPrecipitation object, or anything else, as 0-100 or null. */
    private static function percentage($value): ?int
    {
        if (is_array($value)) {
            $value = $value['value'] ?? null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return max(0, min(100, (int) round((float) $value)));
    }
}
