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
        } catch (\Throwable $e) {
            self::fail('The database is not reachable: ' . $e->getMessage(), $json);
        }

        if (Auth::userCount() === 0) {
            self::notInstalled($json);
        }

        Http::startSession();

        if ($access === 'auth') {
            $json ? Auth::requireApiLogin() : Auth::requireLogin();
        } elseif ($access === 'view' && !Settings::getBool('dashboard_public')) {
            if (!self::hasKioskToken()) {
                $json ? Auth::requireApiLogin() : Auth::requireLogin();
            }
        }

        self::$booted = true;
    }

    /**
     * A wall display can be pinned to the dashboard with ?kiosk=<token>
     * instead of leaving a session signed in forever.
     */
    public static function hasKioskToken(): bool
    {
        $provided = (string) ($_GET['kiosk'] ?? $_SERVER['HTTP_X_KIOSK_TOKEN'] ?? '');
        if ($provided === '') {
            return false;
        }
        $expected = Settings::getString('kiosk_token');
        return $expected !== '' && hash_equals($expected, $provided);
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
        $guard = SW_DATA . '/.htaccess';
        if (is_file($guard)) {
            return;
        }
        if (!is_dir(SW_DATA) && !@mkdir(SW_DATA, 0755, true) && !is_dir(SW_DATA)) {
            return;
        }
        @file_put_contents($guard, implode("\n", [
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
        ]), LOCK_EX);
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
