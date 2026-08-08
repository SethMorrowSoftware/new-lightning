<?php
/**
 * Session authentication for the dashboard and settings screens.
 *
 * Deliberately small: username + password against a `users` table, with
 * per-IP throttling so a shared-host login page is not a free brute-force
 * target.
 */
declare(strict_types=1);

namespace StormWatch;

final class Auth
{
    private const MAX_ATTEMPTS = 8;
    private const WINDOW_SECONDS = 900; // 15 minutes

    /** @var array<string,mixed>|null */
    private static ?array $currentUser = null;

    public static function userCount(): int
    {
        try {
            return (int) Database::instance()->value('SELECT COUNT(*) FROM users');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }
        Http::startSession();
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $user = Database::instance()->first(
            'SELECT id, username, display_name, role FROM users WHERE id = ?',
            [$id]
        );
        if ($user === null) {
            unset($_SESSION['user_id']);
            return null;
        }
        self::$currentUser = $user;
        return $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** Send the visitor to the login page unless they are signed in. */
    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user === null) {
            // Store the path relative to the mount point. Http::redirect()
            // adds the mount back on, and a full path here would end up with
            // it twice — "/stormwatch/stormwatch/settings.php".
            $target = Http::relativeUri();
            Http::redirect('login.php' . ($target !== '' ? '?next=' . rawurlencode($target) : ''));
        }
        return $user;
    }

    /** JSON equivalent of requireLogin() for API endpoints. */
    public static function requireApiLogin(): array
    {
        $user = self::user();
        if ($user === null) {
            Http::jsonError('Sign in required.', 401);
        }
        return $user;
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    public static function attemptLogin(string $username, string $password): array
    {
        $ip = Http::clientIp();
        if (self::isThrottled($ip)) {
            return ['ok' => false, 'error' => 'Too many sign-in attempts. Wait 15 minutes and try again.'];
        }

        $username = trim($username);
        $user = Database::instance()->first(
            'SELECT id, username, password_hash, display_name, role FROM users WHERE username = ?',
            [$username]
        );

        // Always run a hash comparison so a missing account and a wrong
        // password take the same amount of time.
        $hash = (string) ($user['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin');
        $valid = password_verify($password, $hash) && $user !== null;

        self::recordAttempt($ip, $username, $valid);

        if (!$valid) {
            return ['ok' => false, 'error' => 'That username and password did not match.'];
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Database::instance()->run('UPDATE users SET password_hash = ? WHERE id = ?', [
                password_hash($password, PASSWORD_DEFAULT),
                (int) $user['id'],
            ]);
        }

        Http::startSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        self::$currentUser = null;

        Database::instance()->run('UPDATE users SET last_login_at = ? WHERE id = ?', [time(), (int) $user['id']]);
        Events::log('auth.login', 'info', sprintf('%s signed in.', $user['username']), ['ip' => $ip]);

        return ['ok' => true];
    }

    public static function logout(): void
    {
        Http::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        self::$currentUser = null;
    }

    /**
     * @return array{ok:bool,error?:string,id?:int}
     */
    public static function createUser(string $username, string $password, string $displayName = '', string $role = 'admin'): array
    {
        $username = trim($username);
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
            return ['ok' => false, 'error' => 'Usernames must be 3–64 characters: letters, numbers, dot, dash or underscore.'];
        }
        $problem = self::passwordProblem($password);
        if ($problem !== null) {
            return ['ok' => false, 'error' => $problem];
        }
        $db = Database::instance();
        $exists = $db->value('SELECT COUNT(*) FROM users WHERE username = ?', [$username]);
        if ((int) $exists > 0) {
            return ['ok' => false, 'error' => 'That username is already taken.'];
        }
        $db->run(
            'INSERT INTO users (username, password_hash, display_name, role, created_at) VALUES (?, ?, ?, ?, ?)',
            [$username, password_hash($password, PASSWORD_DEFAULT), trim($displayName), $role, time()]
        );
        return ['ok' => true, 'id' => $db->lastInsertId()];
    }

    /** @return array{ok:bool,error?:string} */
    public static function changePassword(int $userId, string $current, string $new): array
    {
        $db = Database::instance();
        $user = $db->first('SELECT id, password_hash FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            return ['ok' => false, 'error' => 'That account no longer exists.'];
        }
        if (!password_verify($current, (string) $user['password_hash'])) {
            return ['ok' => false, 'error' => 'Your current password is not correct.'];
        }
        $problem = self::passwordProblem($new);
        if ($problem !== null) {
            return ['ok' => false, 'error' => $problem];
        }
        $db->run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $userId]);
        Events::log('auth.password_changed', 'info', 'Password changed.', ['user_id' => $userId]);
        return ['ok' => true];
    }

    public static function passwordProblem(string $password): ?string
    {
        if (strlen($password) < 10) {
            return 'Use a password of at least 10 characters.';
        }
        if (preg_match('/^[0-9]+$/', $password)) {
            return 'Use something less guessable than digits alone.';
        }
        return null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function listUsers(): array
    {
        return Database::instance()->all(
            'SELECT id, username, display_name, role, created_at, last_login_at FROM users ORDER BY username'
        );
    }

    /** @return array{ok:bool,error?:string} */
    public static function deleteUser(int $userId, int $actingUserId): array
    {
        if ($userId === $actingUserId) {
            return ['ok' => false, 'error' => 'You cannot delete the account you are signed in with.'];
        }
        if (self::userCount() <= 1) {
            return ['ok' => false, 'error' => 'There has to be at least one account.'];
        }
        Database::instance()->run('DELETE FROM users WHERE id = ?', [$userId]);
        return ['ok' => true];
    }

    private static function isThrottled(string $ip): bool
    {
        $since = time() - self::WINDOW_SECONDS;
        $failures = (int) Database::instance()->value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND ok = 0 AND attempted_at > ?',
            [$ip, $since]
        );
        return $failures >= self::MAX_ATTEMPTS;
    }

    private static function recordAttempt(string $ip, string $username, bool $ok): void
    {
        $db = Database::instance();
        $db->run(
            'INSERT INTO login_attempts (ip, username, attempted_at, ok) VALUES (?, ?, ?, ?)',
            [$ip, mb_substr($username, 0, 64), time(), $ok ? 1 : 0]
        );
        // Opportunistic cleanup so the table cannot grow without bound.
        if (random_int(1, 20) === 1) {
            $db->run('DELETE FROM login_attempts WHERE attempted_at < ?', [time() - 86400]);
        }
    }
}
