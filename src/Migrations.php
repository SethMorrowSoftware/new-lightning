<?php
/**
 * Schema creation and upgrades.
 *
 * Migrations are numbered and recorded in the `schema_migrations` table, so
 * running this repeatedly (the app calls it on boot when cheap to do so, and
 * the installer calls it explicitly) is safe.
 */
declare(strict_types=1);

namespace StormWatch;

final class Migrations
{
    /**
     * Each migration is [version, description, callable(Database): void].
     *
     * @return array<int,array{0:int,1:string,2:callable}>
     */
    private static function definitions(): array
    {
        return [
            [1, 'initial schema', static function (Database $db): void {
                $sqlite = $db->driver() === 'sqlite';
                $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
                $float = $sqlite ? 'REAL' : 'DOUBLE';
                $int = $sqlite ? 'INTEGER' : 'BIGINT';
                $text = $sqlite ? 'TEXT' : 'LONGTEXT';
                $suffix = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

                $db->run("CREATE TABLE IF NOT EXISTS settings (
                    skey VARCHAR(64) PRIMARY KEY,
                    svalue $text NULL,
                    updated_at $int NOT NULL DEFAULT 0
                )$suffix");

                $db->run("CREATE TABLE IF NOT EXISTS users (
                    id $pk,
                    username VARCHAR(64) NOT NULL UNIQUE,
                    password_hash VARCHAR(255) NOT NULL,
                    display_name VARCHAR(120) NOT NULL DEFAULT '',
                    role VARCHAR(20) NOT NULL DEFAULT 'admin',
                    created_at $int NOT NULL DEFAULT 0,
                    last_login_at $int NULL
                )$suffix");

                $db->run("CREATE TABLE IF NOT EXISTS strikes (
                    id $pk,
                    uid VARCHAR(80) NOT NULL UNIQUE,
                    struck_at $int NOT NULL,
                    received_at $int NOT NULL,
                    lat $float NOT NULL,
                    lon $float NOT NULL,
                    distance_mi $float NOT NULL,
                    bearing_deg $float NOT NULL,
                    source VARCHAR(32) NOT NULL DEFAULT 'unknown'
                )$suffix");
                self::index($db, 'idx_strikes_struck_at', 'strikes (struck_at)');
                self::index($db, 'idx_strikes_distance', 'strikes (distance_mi)');

                $db->run("CREATE TABLE IF NOT EXISTS events (
                    id $pk,
                    created_at $int NOT NULL,
                    type VARCHAR(40) NOT NULL,
                    severity VARCHAR(20) NOT NULL DEFAULT 'info',
                    message $text NOT NULL,
                    context $text NULL
                )$suffix");
                self::index($db, 'idx_events_created_at', 'events (created_at)');
                self::index($db, 'idx_events_type', 'events (type)');

                $db->run("CREATE TABLE IF NOT EXISTS alert_state (
                    id INTEGER PRIMARY KEY,
                    level VARCHAR(20) NOT NULL DEFAULT 'clear',
                    since $int NOT NULL DEFAULT 0,
                    nearest_mi $float NULL,
                    nearest_at $int NULL,
                    notified_level VARCHAR(20) NULL,
                    notified_at $int NULL,
                    notified_nearest_mi $float NULL,
                    muted_until $int NULL
                )$suffix");

                $db->run("CREATE TABLE IF NOT EXISTS runs (
                    id $pk,
                    started_at $int NOT NULL,
                    finished_at $int NULL,
                    job VARCHAR(32) NOT NULL,
                    provider VARCHAR(32) NOT NULL DEFAULT '',
                    ok TINYINT NOT NULL DEFAULT 0,
                    ingested INTEGER NOT NULL DEFAULT 0,
                    message $text NULL
                )$suffix");
                self::index($db, 'idx_runs_started_at', 'runs (started_at)');

                $db->run("CREATE TABLE IF NOT EXISTS login_attempts (
                    id $pk,
                    ip VARCHAR(64) NOT NULL,
                    username VARCHAR(64) NOT NULL DEFAULT '',
                    attempted_at $int NOT NULL,
                    ok TINYINT NOT NULL DEFAULT 0
                )$suffix");
                self::index($db, 'idx_login_attempts_ip', 'login_attempts (ip, attempted_at)');
            }],

            [2, 'metered API usage', static function (Database $db): void {
                $sqlite = $db->driver() === 'sqlite';
                $int = $sqlite ? 'INTEGER' : 'BIGINT';
                $suffix = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

                // One row per provider per billing period, so a paid plan's
                // quota can be tracked without touching the strike tables.
                $db->run("CREATE TABLE IF NOT EXISTS api_usage (
                    provider VARCHAR(32) NOT NULL,
                    period VARCHAR(16) NOT NULL,
                    requests $int NOT NULL DEFAULT 0,
                    tokens $int NOT NULL DEFAULT 0,
                    updated_at $int NOT NULL DEFAULT 0,
                    PRIMARY KEY (provider, period)
                )$suffix");
            }],
        ];
    }

    /**
     * Create an index idempotently. MySQL has no CREATE INDEX IF NOT EXISTS
     * (MariaDB does, SQLite does), so swallow the "already exists" error
     * rather than branching on server flavour.
     */
    private static function index(Database $db, string $name, string $target): void
    {
        try {
            $db->run(sprintf('CREATE INDEX %s ON %s', $name, $target));
        } catch (\PDOException $e) {
            $message = strtolower($e->getMessage());
            if (strpos($message, 'exist') === false && strpos($message, 'duplicate') === false) {
                throw $e;
            }
        }
    }

    public static function run(Database $db): void
    {
        $sqlite = $db->driver() === 'sqlite';
        $int = $sqlite ? 'INTEGER' : 'BIGINT';
        $suffix = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $db->run("CREATE TABLE IF NOT EXISTS schema_migrations (
            version INTEGER PRIMARY KEY,
            applied_at $int NOT NULL
        )$suffix");

        $applied = [];
        foreach ($db->all('SELECT version FROM schema_migrations') as $row) {
            $applied[(int) $row['version']] = true;
        }

        foreach (self::definitions() as [$version, $description, $migrate]) {
            if (isset($applied[$version])) {
                continue;
            }
            $migrate($db);
            $db->run('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)', [$version, time()]);
        }

        // The alert state is a single row; make sure it exists.
        if ((int) $db->value('SELECT COUNT(*) FROM alert_state WHERE id = 1') === 0) {
            $db->run('INSERT INTO alert_state (id, level, since) VALUES (1, ?, ?)', ['clear', time()]);
        }
    }

    /** Highest migration version this code knows about. */
    public static function latestVersion(): int
    {
        $versions = array_map(static fn(array $d): int => $d[0], self::definitions());
        return $versions === [] ? 0 : max($versions);
    }
}
