<?php
/**
 * Application settings: everything an operator can change without editing a
 * file. Values live in the `settings` table; the schema below defines types,
 * defaults and validation so that a bad value can never reach the alert path.
 */
declare(strict_types=1);

namespace StormWatch;

final class Settings
{
    /** Sentinel a form posts to blank out a stored secret. */
    public const CLEAR_SECRET = '__sw_clear__';

    /**
     * How much of a provider's data window a poll interval may use. The slack
     * absorbs a cron run that fires late, which on shared hosting is routine.
     */
    public const POLL_WINDOW_MARGIN = 0.5;

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /**
     * type: string|text|int|float|bool|enum|secret|csv
     *
     * @return array<string,array<string,mixed>>
     */
    public static function schema(): array
    {
        return [
            // ---- Venue ----
            'venue_name'      => ['type' => 'string', 'default' => 'Castle Fun Center', 'max' => 120],
            'venue_address'   => ['type' => 'string', 'default' => '109 Brookside Ave, Chester, NY 10918', 'max' => 200],
            'venue_lat'       => ['type' => 'float', 'default' => 41.3608, 'min' => -90, 'max' => 90],
            'venue_lon'       => ['type' => 'float', 'default' => -74.2854, 'min' => -180, 'max' => 180],
            'timezone'        => ['type' => 'string', 'default' => 'America/New_York', 'max' => 64],
            'units'           => ['type' => 'enum', 'default' => 'mi', 'options' => ['mi', 'km']],

            // ---- Alert thresholds ----
            'alert_radius_mi'   => ['type' => 'float', 'default' => 10.0, 'min' => 0.5, 'max' => 200],
            'watch_radius_mi'   => ['type' => 'float', 'default' => 20.0, 'min' => 0.5, 'max' => 300],
            'display_radius_mi' => ['type' => 'float', 'default' => 30.0, 'min' => 1, 'max' => 400],
            // The cooldown: after an alert, stay silent until this many minutes
            // have passed with no qualifying strike, then post the all clear.
            'all_clear_minutes' => ['type' => 'int', 'default' => 30, 'min' => 1, 'max' => 240],
            'cooldown_scope'    => ['type' => 'enum', 'default' => 'alert', 'options' => ['alert', 'watch', 'display']],
            // Both default to off: repeating during a storm is the spam an
            // operator asking for a cooldown is trying to avoid.
            'realert_minutes'   => ['type' => 'int', 'default' => 0, 'min' => 0, 'max' => 240],
            'closer_delta_mi'   => ['type' => 'float', 'default' => 0.0, 'min' => 0, 'max' => 100],
            // Operating hours. Off by default: a venue that has not configured
            // its hours should be monitored around the clock, never less.
            'monitor_schedule_enabled' => ['type' => 'bool', 'default' => false],
            'monitor_schedule'  => ['type' => 'text', 'default' => '', 'max' => 2000],
            'monitor_lead_minutes'  => ['type' => 'int', 'default' => 30, 'min' => 0, 'max' => 480],
            'monitor_trail_minutes' => ['type' => 'int', 'default' => 30, 'min' => 0, 'max' => 480],

            'notify_watch'      => ['type' => 'bool', 'default' => false],
            'notify_all_clear'  => ['type' => 'bool', 'default' => true],
            'notify_errors'     => ['type' => 'bool', 'default' => true],

            // ---- Slack ----
            'slack_enabled'    => ['type' => 'bool', 'default' => false],
            'slack_mode'       => ['type' => 'enum', 'default' => 'bot', 'options' => ['bot', 'webhook']],
            'slack_bot_token'  => ['type' => 'secret', 'default' => ''],
            'slack_webhook_url' => ['type' => 'secret', 'default' => ''],
            'slack_channel'    => ['type' => 'string', 'default' => '', 'max' => 120],
            'slack_mention'    => ['type' => 'enum', 'default' => 'none', 'options' => ['none', 'here', 'channel']],
            'slack_extra_mention' => ['type' => 'string', 'default' => '', 'max' => 200],
            'slack_username'   => ['type' => 'string', 'default' => '', 'max' => 80],
            'slack_icon_emoji' => ['type' => 'string', 'default' => '', 'max' => 40],

            // ---- Email ----
            'email_enabled'   => ['type' => 'bool', 'default' => false],
            'email_to'        => ['type' => 'csv', 'default' => ''],
            'email_from'      => ['type' => 'string', 'default' => '', 'max' => 160],
            'email_from_name' => ['type' => 'string', 'default' => 'Storm Watch', 'max' => 80],
            'email_transport' => ['type' => 'enum', 'default' => 'mail', 'options' => ['mail', 'smtp']],
            'smtp_host'       => ['type' => 'string', 'default' => '', 'max' => 160],
            'smtp_port'       => ['type' => 'int', 'default' => 587, 'min' => 1, 'max' => 65535],
            'smtp_secure'     => ['type' => 'enum', 'default' => 'tls', 'options' => ['none', 'tls', 'ssl']],
            'smtp_user'       => ['type' => 'string', 'default' => '', 'max' => 160],
            'smtp_pass'       => ['type' => 'secret', 'default' => ''],

            // ---- Data source ----
            'provider'         => ['type' => 'enum', 'default' => 'simulator', 'options' => ['blitzortung', 'rest', 'relay', 'simulator']],
            'worker_seconds'   => ['type' => 'int', 'default' => 50, 'min' => 5, 'max' => 300],
            'blitz_servers'    => ['type' => 'csv', 'default' => 'ws1,ws7,ws8'],
            'blitz_init_json'  => ['type' => 'string', 'default' => '{"a":111}', 'max' => 200],
            'rest_endpoint'    => ['type' => 'string', 'default' => '', 'max' => 500],
            'rest_api_key'     => ['type' => 'secret', 'default' => ''],
            // Xweather and similar issue a pair of credentials rather than one key.
            'rest_api_secret'  => ['type' => 'secret', 'default' => ''],
            'rest_auth_mode'   => ['type' => 'enum', 'default' => 'query', 'options' => ['none', 'query', 'header', 'bearer', 'client_pair']],
            'rest_auth_name'   => ['type' => 'string', 'default' => 'apikey', 'max' => 80],
            'rest_auth_secret_name' => ['type' => 'string', 'default' => 'client_secret', 'max' => 80],
            'rest_extra_headers' => ['type' => 'text', 'default' => '', 'max' => 2000],
            // Idle cadence. During a storm the active cadence below is used —
            // that is what keeps a metered plan affordable without being slow
            // to notice the first strike.
            'rest_poll_seconds' => ['type' => 'int', 'default' => 300, 'min' => 15, 'max' => 3600],
            'rest_active_poll_seconds' => ['type' => 'int', 'default' => 60, 'min' => 15, 'max' => 3600],
            // Records older than this are ignored, so an endpoint that returns
            // history cannot raise an alert for a storm that has long passed.
            'rest_max_age_minutes' => ['type' => 'int', 'default' => 20, 'min' => 1, 'max' => 1440],
            // Some endpoints cap how large a radius they will answer for —
            // Xweather's lightning/flash refuses anything over 40km. 0 means no
            // cap. The request radius is clamped to this rather than rejected,
            // because a too-large display radius should degrade to less map
            // coverage, not to a failed poll.
            'rest_max_radius_mi' => ['type' => 'float', 'default' => 0.0, 'min' => 0, 'max' => 5000],
            // How far back the endpoint will serve. 0 means unknown/unlimited.
            // When it is known, polling slower than this window drops strikes
            // that fall between two polls, so it is validated against the idle
            // cadence below.
            'rest_data_window_minutes' => ['type' => 'int', 'default' => 0, 'min' => 0, 'max' => 1440],
            // 0 means unmetered. 15,000 is the Xweather free tier.
            'api_monthly_budget' => ['type' => 'int', 'default' => 15000, 'min' => 0, 'max' => 100000000],
            'rest_map_root'    => ['type' => 'string', 'default' => 'data', 'max' => 120],
            'rest_map_lat'     => ['type' => 'string', 'default' => 'lat', 'max' => 120],
            'rest_map_lon'     => ['type' => 'string', 'default' => 'lon', 'max' => 120],
            'rest_map_time'    => ['type' => 'string', 'default' => 'time', 'max' => 120],
            'rest_time_format' => ['type' => 'enum', 'default' => 'auto', 'options' => ['auto', 'iso8601', 'epoch_s', 'epoch_ms', 'epoch_ns']],

            // ---- Retention / access ----
            'retention_hours'  => ['type' => 'int', 'default' => 72, 'min' => 1, 'max' => 8760],
            'dashboard_public' => ['type' => 'bool', 'default' => false],
            // The address alerts should link to. Cron has no request to infer
            // one from, and an install that later moves needs a way to correct
            // it without hand-editing config.php.
            'public_base_url'  => ['type' => 'string', 'default' => '', 'max' => 300],
            'ingest_token'     => ['type' => 'secret', 'default' => ''],
            'kiosk_token'      => ['type' => 'secret', 'default' => ''],
            'cron_token'       => ['type' => 'secret', 'default' => ''],

            // ---- Presentation ----
            'ui_refresh_seconds' => ['type' => 'int', 'default' => 10, 'min' => 3, 'max' => 300],
            'map_zoom'         => ['type' => 'int', 'default' => 10, 'min' => 3, 'max' => 16],
            // Dark Matter looks the part but reads poorly in daylight, and this
            // map gets looked at outdoors on a bright afternoon.
            'map_style'        => ['type' => 'enum', 'default' => 'muted', 'options' => ['dark', 'muted', 'light']],
            'radar_overlay'    => ['type' => 'enum', 'default' => 'rainviewer', 'options' => ['none', 'rainviewer', 'nexrad']],
            'radar_opacity'    => ['type' => 'int', 'default' => 55, 'min' => 5, 'max' => 100],
            'marker_ttl_minutes' => ['type' => 'int', 'default' => 60, 'min' => 1, 'max' => 720],

            // ---- Forecast (National Weather Service) ----
            // On by default: knowing a line of storms is due at four o'clock is
            // what lets a venue move a birthday party indoors before it arrives,
            // rather than when the first strike lands. Free, no account, United
            // States and its territories only — outside that coverage the card
            // says so and stops asking.
            'nws_enabled'      => ['type' => 'bool', 'default' => true],
            // The forecast text is reissued about hourly and amended in between;
            // watches and warnings appear the moment they are issued, and they
            // are fetched on this same cadence, so it is deliberately short.
            'nws_refresh_minutes' => ['type' => 'int', 'default' => 10, 'min' => 5, 'max' => 180],
            // api.weather.gov asks every caller to identify itself with a way to
            // get in touch, so they can warn you before blocking you.
            'nws_contact'      => ['type' => 'string', 'default' => '', 'max' => 160],
        ];
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::schema() as $key => $meta) {
            $out[$key] = $meta['default'];
        }
        return $out;
    }

    public static function flushCache(): void
    {
        self::$cache = null;
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $values = self::defaults();
        $schema = self::schema();

        try {
            $rows = Database::instance()->all('SELECT skey, svalue FROM settings');
        } catch (\Throwable $e) {
            // Before installation there is no settings table and the defaults
            // are the right answer. Afterwards a failed read is not "everything
            // happens to be at its default": those defaults move the venue to
            // the shipped demo coordinates, so every distance is measured from
            // the wrong place, and set the provider to the simulator, which
            // fabricates strikes. A blocked query would quietly become an
            // invented storm at somebody else's address.
            if (Config::isInstalled()) {
                throw new \RuntimeException(
                    'The settings could not be read from the database: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
            return $values;
        }

        foreach ($rows as $row) {
            $key = (string) $row['skey'];
            if (!isset($schema[$key])) {
                continue;
            }
            $raw = $row['svalue'];
            if ($raw === null) {
                continue;
            }
            $values[$key] = self::decode($schema[$key], (string) $raw);
        }

        self::$cache = $values;
        return $values;
    }

    /** @return mixed */
    public static function get(string $key, $default = null)
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function getString(string $key): string
    {
        return (string) self::get($key, '');
    }

    public static function getInt(string $key): int
    {
        return (int) self::get($key, 0);
    }

    public static function getFloat(string $key): float
    {
        return (float) self::get($key, 0.0);
    }

    public static function getBool(string $key): bool
    {
        return (bool) self::get($key, false);
    }

    /** @return array<int,string> */
    public static function getList(string $key): array
    {
        $value = (string) self::get($key, '');
        if (trim($value) === '') {
            return [];
        }
        $parts = preg_split('/[,\s;]+/', $value) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $p): bool => $p !== ''));
    }

    /**
     * Values safe to hand to a browser: secrets become a boolean "is it set".
     *
     * @return array<string,mixed>
     */
    public static function safe(): array
    {
        $all = self::all();
        $out = [];
        foreach (self::schema() as $key => $meta) {
            if ($meta['type'] === 'secret') {
                $out[$key . '_set'] = ($all[$key] ?? '') !== '';
                continue;
            }
            $out[$key] = $all[$key] ?? $meta['default'];
        }
        return $out;
    }

    /**
     * Validate and persist a batch of settings.
     *
     * Secrets are left untouched when the posted value is an empty string
     * (the form shows a masked placeholder, not the real token). Post
     * Settings::CLEAR_SECRET to blank one out.
     *
     * @param array<string,mixed> $input
     * @return array<string,string> field => error message; empty means saved
     */
    public static function put(array $input): array
    {
        $schema = self::schema();
        $errors = [];
        $clean = [];

        foreach ($input as $key => $value) {
            if (!isset($schema[$key])) {
                continue;
            }
            $meta = $schema[$key];

            if ($meta['type'] === 'secret') {
                if (is_string($value) && $value === self::CLEAR_SECRET) {
                    $clean[$key] = '';
                    continue;
                }
                if (!is_string($value) || trim($value) === '') {
                    continue; // leave the stored secret alone
                }
                $clean[$key] = trim($value);
                continue;
            }

            try {
                $clean[$key] = self::coerce($key, $meta, $value);
            } catch (\InvalidArgumentException $e) {
                $errors[$key] = $e->getMessage();
            }
        }

        // Cross-field rules: the rings have to nest, or the map lies to people.
        $merged = array_merge(self::all(), $clean);
        if ((float) $merged['watch_radius_mi'] < (float) $merged['alert_radius_mi']) {
            $errors['watch_radius_mi'] = 'The watch radius must be at least as large as the alert radius.';
        }
        if ((float) $merged['display_radius_mi'] < (float) $merged['watch_radius_mi']) {
            $errors['display_radius_mi'] = 'The display radius must be at least as large as the watch radius.';
        }
        if (isset($clean['timezone']) && !in_array($clean['timezone'], timezone_identifiers_list(), true)) {
            $errors['timezone'] = 'Unknown time zone. Use an identifier such as America/New_York.';
        }
        // Strike history is not just a map: it is where the cooldown reads
        // "has anything struck recently". Keep less of it than the cooldown
        // spans and the answer becomes "nothing on record", which reads as
        // "nothing happened" — an all clear issued mid-storm. Strikes::prune()
        // enforces a floor regardless, but silently keeping more than was asked
        // for is its own kind of wrong, so say so instead.
        $cooldownHours = (int) ceil(((int) $merged['all_clear_minutes']) / 60);
        if ((int) $merged['retention_hours'] < $cooldownHours) {
            $field = isset($clean['all_clear_minutes']) ? 'all_clear_minutes' : 'retention_hours';
            $errors[$field] = sprintf(
                'Strike history is kept for %d hour%s but the cooldown spans %d minutes. The all clear is decided '
                . 'from stored strikes, so a shorter history would end the hold early. Keep history for at least '
                . '%d hour%s (System tab), or shorten the cooldown.',
                (int) $merged['retention_hours'],
                (int) $merged['retention_hours'] === 1 ? '' : 's',
                (int) $merged['all_clear_minutes'],
                $cooldownHours,
                $cooldownHours === 1 ? '' : 's'
            );
        }
        // A watch ring larger than the endpoint will answer for is not a
        // cosmetic problem: strikes in the gap are never fetched, so the system
        // stays green through a storm it cannot see. The display ring may
        // exceed the cap — that only costs map coverage — but this may not.
        $cap = (float) $merged['rest_max_radius_mi'];
        if ($merged['provider'] === 'rest' && $cap > 0 && (float) $merged['watch_radius_mi'] > $cap) {
            $errors['watch_radius_mi'] = sprintf(
                'This data source only answers out to %s miles, so a %s mile watch radius would never see '
                . 'the strikes it is meant to catch. Reduce the watch radius, or raise the endpoint limit on '
                . 'the Data source tab if the provider allows more.',
                rtrim(rtrim(number_format($cap, 1), '0'), '.'),
                rtrim(rtrim(number_format((float) $merged['watch_radius_mi'], 1), '0'), '.')
            );
        }

        // An endpoint that only serves the last N minutes has to be polled
        // faster than N, or strikes land and expire between two polls and are
        // never seen. Silence from a safety system reads as "all clear", so
        // this is refused rather than warned about.
        $window = (int) $merged['rest_data_window_minutes'];
        if ($window > 0) {
            $margin = (int) floor($window * 60 * self::POLL_WINDOW_MARGIN);
            foreach (['rest_poll_seconds' => 'idle', 'rest_active_poll_seconds' => 'storm'] as $key => $label) {
                if ((int) $merged[$key] > $margin) {
                    $errors[$key] = sprintf(
                        'This endpoint only serves the last %d minutes, so the %s poll must be %ds or less '
                        . '(%d%% of the window, leaving room for a late cron run). Strikes arriving between '
                        . 'two slower polls would never be seen.',
                        $window,
                        $label,
                        $margin,
                        (int) round(self::POLL_WINDOW_MARGIN * 100)
                    );
                }
            }
        }
        if (!empty($merged['slack_enabled'])) {
            if ($merged['slack_mode'] === 'bot') {
                if (($merged['slack_bot_token'] ?? '') === '') {
                    $errors['slack_bot_token'] = 'A bot token is required when Slack alerts use bot mode.';
                }
                if (trim((string) $merged['slack_channel']) === '') {
                    $errors['slack_channel'] = 'Choose a channel for Slack alerts.';
                }
            } elseif (($merged['slack_webhook_url'] ?? '') === '') {
                $errors['slack_webhook_url'] = 'An incoming webhook URL is required when Slack alerts use webhook mode.';
            }
        }
        if (!empty($merged['email_enabled'])) {
            $addresses = self::listFrom((string) $merged['email_to']);
            if ($addresses === []) {
                $errors['email_to'] = 'Add at least one recipient address.';
            } else {
                // The mailer drops anything that will not validate, so a typo
                // here becomes an address that is never written to and never
                // mentioned again — while the dashboard goes on reporting email
                // ready. Refuse it at the point somebody can still see it.
                $bad = array_values(array_filter(
                    $addresses,
                    static fn(string $a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) === false
                ));
                if ($bad !== []) {
                    $errors['email_to'] = sprintf(
                        'These do not look like email addresses: %s. Alerts sent to them would be dropped silently.',
                        implode(', ', $bad)
                    );
                }
            }
            if (trim((string) $merged['email_from']) !== ''
                && filter_var(trim((string) $merged['email_from']), FILTER_VALIDATE_EMAIL) === false) {
                $errors['email_from'] = 'The "from" address is not a valid email address, so nothing would be sent.';
            }
            if ($merged['email_transport'] === 'smtp' && trim((string) $merged['smtp_host']) === '') {
                $errors['smtp_host'] = 'An SMTP host is required when the SMTP transport is selected.';
            }
        }
        if (($merged['provider'] ?? '') === 'rest' && trim((string) $merged['rest_endpoint']) === '') {
            $errors['rest_endpoint'] = 'A REST endpoint URL is required for the REST provider.';
        }

        if ($errors !== []) {
            return $errors;
        }

        // Noted before the write, so the comparison is against what was stored.
        $venueMoved = (isset($clean['venue_lat']) && (float) $clean['venue_lat'] !== self::getFloat('venue_lat'))
            || (isset($clean['venue_lon']) && (float) $clean['venue_lon'] !== self::getFloat('venue_lon'));

        $db = Database::instance();
        $now = time();
        foreach ($clean as $key => $value) {
            $db->upsert('settings', [
                'skey' => $key,
                'svalue' => self::encode($schema[$key], $value),
                'updated_at' => $now,
            ], 'skey');
        }
        self::flushCache();

        // Every strike carries the distance it was measured at when it arrived.
        // Correcting a mistyped coordinate would otherwise leave the alert
        // engine holding a warning for lightning nowhere near the new location.
        if ($venueMoved) {
            try {
                Strikes::recomputeDistances();
            } catch (\Throwable $e) {
                Events::log(
                    'system.error',
                    Events::SEVERITY_ERROR,
                    'The venue moved but the stored strike distances could not be recalculated: ' . $e->getMessage(),
                    []
                );
            }
        }

        return [];
    }

    /** Persist a single value, bypassing cross-field validation (internal use). */
    public static function putRaw(string $key, $value): void
    {
        $schema = self::schema();
        if (!isset($schema[$key])) {
            throw new \InvalidArgumentException('Unknown setting: ' . $key);
        }
        Database::instance()->upsert('settings', [
            'skey' => $key,
            'svalue' => self::encode($schema[$key], $value),
            'updated_at' => time(),
        ], 'skey');
        self::flushCache();
    }

    /**
     * Make sure the machine-generated tokens exist. Called after install and
     * on demand from the settings screen.
     */
    public static function ensureTokens(): void
    {
        foreach (['ingest_token', 'kiosk_token', 'cron_token'] as $key) {
            if ((string) self::get($key, '') === '') {
                self::putRaw($key, Crypto::randomToken());
            }
        }
    }

    /** @param array<string,mixed> $meta */
    private static function coerce(string $key, array $meta, $value)
    {
        switch ($meta['type']) {
            case 'bool':
                if (is_bool($value)) {
                    return $value;
                }
                if (is_string($value)) {
                    return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
                }
                return (bool) $value;

            case 'int':
                if (!is_numeric($value)) {
                    throw new \InvalidArgumentException('Enter a whole number.');
                }
                $int = (int) round((float) $value);
                if (isset($meta['min']) && $int < $meta['min']) {
                    throw new \InvalidArgumentException(sprintf('Must be %s or more.', $meta['min']));
                }
                if (isset($meta['max']) && $int > $meta['max']) {
                    throw new \InvalidArgumentException(sprintf('Must be %s or less.', $meta['max']));
                }
                return $int;

            case 'float':
                if (!is_numeric($value)) {
                    throw new \InvalidArgumentException('Enter a number.');
                }
                $float = (float) $value;
                if (isset($meta['min']) && $float < $meta['min']) {
                    throw new \InvalidArgumentException(sprintf('Must be %s or more.', $meta['min']));
                }
                if (isset($meta['max']) && $float > $meta['max']) {
                    throw new \InvalidArgumentException(sprintf('Must be %s or less.', $meta['max']));
                }
                return $float;

            case 'enum':
                $string = is_scalar($value) ? (string) $value : '';
                if (!in_array($string, $meta['options'], true)) {
                    throw new \InvalidArgumentException('Choose one of: ' . implode(', ', $meta['options']) . '.');
                }
                return $string;

            case 'csv':
                $string = is_array($value) ? implode(',', $value) : (string) $value;
                return implode(',', self::listFrom($string));

            case 'text':
            case 'string':
            default:
                $string = is_scalar($value) ? trim((string) $value) : '';
                $max = (int) ($meta['max'] ?? 255);
                if (mb_strlen($string) > $max) {
                    throw new \InvalidArgumentException(sprintf('Keep this under %d characters.', $max));
                }
                return $string;
        }
    }

    /** @return array<int,string> */
    private static function listFrom(string $value): array
    {
        $parts = preg_split('/[,\s;]+/', $value) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $p): bool => $p !== ''));
    }

    /** @param array<string,mixed> $meta */
    private static function encode(array $meta, $value): string
    {
        if ($meta['type'] === 'secret') {
            return $value === '' ? '' : Crypto::encrypt((string) $value);
        }
        if ($meta['type'] === 'bool') {
            return $value ? '1' : '0';
        }
        return (string) $value;
    }

    /** @param array<string,mixed> $meta */
    private static function decode(array $meta, string $raw)
    {
        switch ($meta['type']) {
            case 'secret':
                return $raw === '' ? '' : Crypto::decrypt($raw);
            case 'bool':
                return $raw === '1';
            case 'int':
                return (int) $raw;
            case 'float':
                return (float) $raw;
            default:
                return $raw;
        }
    }
}
