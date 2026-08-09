<?php
/**
 * Shared start-up for the web entry points: make sure the app is installed and
 * migrated, apply security headers, and enforce access rules.
 */
declare(strict_types=1);

namespace StormWatch;

final class App
{
    private static bool $booted = false;

    /**
     * Prepare a normal page or API request.
     *
     * @param string $access 'auth'   — a signed-in user is required
     *                       'view'   — signed in, unless the dashboard is public
     *                       'public' — no check (login page, setup, kiosk)
     */
    public static function boot(string $access = 'auth', bool $json = false): void
    {
        Http::securityHeaders();
        self::protectDataDirectory();

        if (!Config::isInstalled()) {
            self::notInstalled($json);
        }

        try {
            self::migrateIfNeeded();
            // Read the settings here too, so a database that fails halfway
            // through lands on the page below rather than part-way down a
            // rendered dashboard.
            Settings::all();
        } catch (\Throwable $e) {
            // The driver's own words name the database, the user and the host
            // it tried — "Access denied for user 'venue_sw'@'localhost'" — and
            // this page is reached before anyone has signed in. Keep the detail
            // for the error log, where the operator can get at it.
            error_log('[stormwatch] database unreachable: ' . $e->getMessage());
            self::fail(
                'The database is not reachable, so Storm Watch cannot start. The details are in the PHP error log '
                . '(data/php-error.log on a standard install). Lightning alerts are not being sent until this is fixed.',
                $json
            );
        }

        if (Auth::userCount() === 0) {
            self::notInstalled($json);
        }

        // A token-authenticated JSON endpoint identifies its caller from the
        // token, so opening a session only leaves a file behind — once a
        // minute, for ever, on a host with a shared /tmp.
        if (!($json && $access === 'public')) {
            Http::startSession();
        }

        if ($access === 'auth') {
            $json ? Auth::requireApiLogin() : Auth::requireLogin();
        } elseif ($access === 'view' && !Settings::getBool('dashboard_public')) {
            if (!self::hasKioskToken()) {
                $json ? Auth::requireApiLogin() : Auth::requireLogin();
            }
        }

        // The JSON endpoints read the session to identify the caller and never
        // write to it again, so release its lock here. Holding it would make
        // the dashboard's polls queue behind whichever slow action the operator
        // last started — see Http::closeSession().
        if ($json) {
            Http::closeSession();
        }

        self::$booted = true;
    }

    /**
     * A wall display can be pinned to the dashboard with ?kiosk=<token>
     * instead of leaving a session signed in forever.
     */
    public static function hasKioskToken(): bool
    {
        return self::activeKioskToken() !== null;
    }

    /**
     * The kiosk token on this request, when it is the real one.
     *
     * Returned rather than merely checked because the dashboard has to hand it
     * on. A kiosk viewer has no session, and the page it is shown is only the
     * shell — every number on it arrives from api/state.php a few seconds
     * later. If the token does not travel with those polls they are refused,
     * and the wall display gives up and shows a login form instead of the
     * weather.
     */
    public static function activeKioskToken(): ?string
    {
        $provided = (string) ($_GET['kiosk'] ?? $_SERVER['HTTP_X_KIOSK_TOKEN'] ?? '');
        if ($provided === '') {
            return null;
        }
        $expected = Settings::getString('kiosk_token');
        return ($expected !== '' && hash_equals($expected, $provided)) ? $provided : null;
    }

    /**
     * Keep the deny-all guard in data/ present.
     *
     * In the simple deployment the data directory sits under the web root, so
     * that file is what stands between the internet and the database. It is
     * easy to lose to a partial upload or an over-eager clean-up, so put it
     * back rather than assume it is there.
     */
    public static function protectDataDirectory(): void
    {
        $deny = implode("\n", [
            '<IfModule mod_authz_core.c>',
            '  Require all denied',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            '  Order allow,deny',
            '  Deny from all',
            '</IfModule>',
            '',
            'Options -Indexes -ExecCGI',
            'RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8',
            '',
        ]);

        if (!is_dir(SW_DATA) && !@mkdir(SW_DATA, 0755, true) && !is_dir(SW_DATA)) {
            return;
        }

        // In the subfolder deployment these files sit under the web root, so
        // they are what stands between the internet and the database, the
        // config and the source. Put any of them back if it goes missing.
        foreach ([SW_DATA, SW_ROOT . '/config', SW_ROOT . '/src', SW_ROOT . '/bin', SW_ROOT . '/tests'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $guard = $dir . '/.htaccess';
            if (!is_file($guard)) {
                @file_put_contents($guard, $deny, LOCK_EX);
            }
        }
    }

    /** Run pending migrations. Cheap: one query when there is nothing to do. */
    public static function migrateIfNeeded(): void
    {
        $db = Database::instance();
        if (!$db->tableExists('schema_migrations')) {
            Migrations::run($db);
            return;
        }
        $current = (int) ($db->value('SELECT MAX(version) FROM schema_migrations') ?? 0);
        if ($current < Migrations::latestVersion()) {
            Migrations::run($db);
        }
    }

    public static function isBooted(): bool
    {
        return self::$booted;
    }

    private static function notInstalled(bool $json): void
    {
        if ($json) {
            Http::jsonError('Storm Watch has not been set up yet. Open setup.php in a browser.', 503);
        }
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script !== 'setup.php') {
            Http::redirect('setup.php');
        }
    }

    private static function fail(string $message, bool $json): void
    {
        if ($json) {
            Http::jsonError($message, 500);
        }
        http_response_code(500);
        echo '<!doctype html><meta charset="utf-8"><title>Storm Watch</title>'
            . '<body style="font-family:system-ui;background:#080C16;color:#F3F5FA;padding:40px;">'
            . '<h1 style="font-size:20px;">Storm Watch could not start</h1><p style="color:#9AA4C0;">'
            . Http::e($message) . '</p></body>';
        exit;
    }
}
