<?php
/**
 * Request/response helpers shared by the pages and the JSON API.
 */
declare(strict_types=1);

namespace StormWatch;

final class Http
{
    /** The session is open right now, and its lock is held. */
    private static bool $sessionStarted = false;

    /** $_SESSION has been read this request, whether or not it is still open. */
    private static bool $sessionLoaded = false;

    private static ?string $basePath = null;

    public static function startSession(): void
    {
        if (self::$sessionStarted || PHP_SAPI === 'cli') {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::$sessionStarted = true;
            self::$sessionLoaded = true;
            return;
        }
        // Subfolder hosting means sharing a domain with whatever else lives
        // there, so tie the cookie name to the mount point: two installs on
        // one domain then cannot overwrite each other's session.
        session_name(self::sessionName());
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => self::basePath() ?: '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$sessionStarted = true;
        self::$sessionLoaded = true;
    }

    /**
     * Make $_SESSION readable without necessarily holding the lock.
     *
     * PHP leaves $_SESSION populated after the session is written back, so
     * anything that only reads it can go straight to the array. Re-opening
     * for a read would take the lock again and undo closeSession().
     */
    private static function loadSession(): void
    {
        if (self::$sessionLoaded) {
            return;
        }
        self::startSession();
    }

    /**
     * Write the session back and let go of its lock.
     *
     * PHP's file session handler holds an exclusive lock for as long as the
     * request runs, and every request for the same signed-in operator queues
     * behind it. That is invisible until something slow holds the lock: a
     * connection test or a mapping check talks to a weather API for up to a
     * minute, and for that whole minute the dashboard's polls sit waiting and
     * the page shows nothing but its "Loading…" placeholders.
     *
     * Endpoints that only read the session should therefore hand it back as
     * soon as the caller has been identified. Anything that needs it again
     * re-opens it through startSession() and takes the lock properly.
     */
    public static function closeSession(): void
    {
        if (!self::$sessionStarted || PHP_SAPI === 'cli') {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        self::$sessionStarted = false;
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        if (Config::get('trusted_proxy') && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return false;
    }

    /**
     * The URL path the app is mounted at, as the browser sees it —
     * "/stormwatch" for a subfolder install, "" at a domain root.
     *
     * Getting this right is what makes subfolder hosting work: it drives every
     * generated link, every asset URL, and the session cookie path. If the
     * cookie path does not match the URLs people actually visit, the browser
     * stops sending the session and sign-in fails with "session expired".
     */
    public static function basePath(): string
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }

        // An explicit setting always wins — the escape hatch for a rewrite
        // arrangement this cannot infer.
        $configured = (string) Config::get('base_path', '');
        if ($configured !== '') {
            $trimmed = trim($configured, '/');
            return self::$basePath = ($trimmed === '' ? '' : '/' . $trimmed);
        }

        // With no request being served there is no mount point, and on the
        // command line SCRIPT_NAME is a filesystem path that would turn every
        // generated URL into nonsense. Cron links come from base_url instead.
        if (PHP_SAPI === 'cli' && empty($_SERVER['REQUEST_URI'])) {
            return self::$basePath = '';
        }

        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

        // API endpoints live one level deeper; normalise back to the app root.
        // Check the file really is in the app's api directory, so an app
        // mounted at a folder called "api" is not silently relocated.
        if (substr($dir, -4) === '/api' && self::scriptIsInApiDirectory()) {
            $dir = substr($dir, 0, -4);
        }

        // Subfolder install: the .htaccess rewrites /mount/x to /mount/public/x,
        // so SCRIPT_NAME carries a "/public" segment the visitor never sees.
        // Drop it — but only when the request confirms the visitor is not
        // genuinely browsing a directory that really is called "public".
        if ($dir === '/public' || substr($dir, -7) === '/public') {
            $requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
            if ($requestPath !== $dir && strpos($requestPath, $dir . '/') !== 0) {
                $dir = substr($dir, 0, -7);
            }
        }

        $dir = rtrim($dir, '/');
        return self::$basePath = (($dir === '' || $dir === '.') ? '' : $dir);
    }

    /** Forget the cached mount point. For tests. */
    public static function resetBasePath(): void
    {
        self::$basePath = null;
    }

    /** Is the running script really public/api/…, or just a folder named "api"? */
    private static function scriptIsInApiDirectory(): bool
    {
        $file = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        if ($file === '') {
            // Nothing to check against; the common case is a real API call.
            return true;
        }
        $expected = realpath(SW_ROOT . '/public/api');
        $actual = realpath(dirname($file));
        return $expected === false || $actual === false || $actual === $expected;
    }

    /**
     * The current request path with the mount point removed, so it can be
     * handed back to url() later without the mount being applied twice.
     */
    public static function relativeUri(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '') {
            return '';
        }
        $base = self::basePath();
        if ($base !== '' && strpos($uri, $base . '/') === 0) {
            $uri = substr($uri, strlen($base));
        } elseif ($base !== '' && $uri === $base) {
            $uri = '/';
        }
        return $uri === '' ? '/' : $uri;
    }

    /** Session cookie name, scoped to this mount point. */
    public static function sessionName(): string
    {
        $base = self::basePath();
        return $base === ''
            ? 'stormwatch_session'
            : 'stormwatch_' . substr(hash('sha256', $base), 0, 8);
    }

    /**
     * Prefix for browser-local storage keys, so a second install on the same
     * domain does not read the first one's preferences.
     */
    public static function storagePrefix(): string
    {
        return 'sw_' . substr(hash('sha256', self::basePath()), 0, 8) . '_';
    }

    public static function url(string $path = ''): string
    {
        return self::basePath() . '/' . ltrim($path, '/');
    }

    /**
     * Absolute URL to the app — used in Slack and email alerts, which are sent
     * from cron where there is no request to work one out from.
     *
     * The setting wins over the installed config so an app that moves can be
     * corrected from the settings screen.
     */
    public static function absoluteUrl(string $path = ''): string
    {
        $configured = '';
        try {
            $configured = rtrim(trim((string) Settings::get('public_base_url', '')), '/');
        } catch (\Throwable $e) {
            // Settings need a database; fall through to the installed config.
        }
        if ($configured === '') {
            $configured = rtrim((string) Config::get('base_url', ''), '/');
        }
        if ($configured !== '') {
            return $configured . '/' . ltrim($path, '/');
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return '/' . ltrim($path, '/');
        }
        return (self::isHttps() ? 'https://' : 'http://') . $host . self::url($path);
    }

    /**
     * The address to attribute a request to, used for the sign-in throttle.
     *
     * X-Forwarded-For is a list, and only its last entry was written by the
     * proxy in front of this app — everything to the left of it came from the
     * client and can say anything. Reading the leftmost entry made the throttle
     * both useless and dangerous: a different forged address on each attempt
     * never trips it, and eight attempts carrying the duty manager's real
     * address lock the duty manager out.
     *
     * Only consulted when trusted_proxy is set, because without a proxy in
     * front the header is entirely the client's invention.
     */
    public static function clientIp(): string
    {
        if (Config::get('trusted_proxy')) {
            $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                $hops = array_map('trim', explode(',', $forwarded));
                for ($i = count($hops) - 1; $i >= 0; $i--) {
                    if (filter_var($hops[$i], FILTER_VALIDATE_IP)) {
                        return $hops[$i];
                    }
                }
            }
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $ip !== '' ? $ip : '0.0.0.0';
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    /** Security headers appropriate for an admin dashboard. */
    public static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Robots-Tag: noindex, nofollow');
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    // ---- CSRF ----

    public static function csrfToken(): string
    {
        self::loadSession();
        if (empty($_SESSION['csrf_token'])) {
            // Minting a token is a write, so the session has to be open for it
            // to survive the request.
            self::startSession();
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf_token'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . self::e(self::csrfToken()) . '">';
    }

    public static function checkCsrf(?string $token = null): bool
    {
        // A read: never re-take the lock for it. api/action.php checks the
        // token and then makes a provider call that can run for a minute, so
        // re-opening here would hold the session for that whole call and stall
        // the dashboard — the exact problem closeSession() exists to avoid.
        self::loadSession();
        $token ??= (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        return $expected !== '' && $token !== '' && hash_equals($expected, $token);
    }

    // ---- Responses ----

    /** @param array<string,mixed> $payload */
    public static function json(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    public static function jsonError(string $message, int $status = 400, array $extra = []): void
    {
        self::json(array_merge(['ok' => false, 'error' => $message], $extra), $status);
    }

    public static function redirect(string $path): void
    {
        $target = preg_match('#^https?://#i', $path) === 1 ? $path : self::url($path);
        if (!headers_sent()) {
            header('Location: ' . $target, true, 302);
        }
        exit;
    }

    /** Read the JSON request body, if any. @return array<string,mixed> */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
