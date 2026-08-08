<?php
/**
 * Thin PDO wrapper that keeps the app portable between SQLite and MySQL —
 * the two databases you can count on having on a shared cPanel host.
 */
declare(strict_types=1);

namespace StormWatch;

use PDO;
use PDOException;

final class Database
{
    private static ?Database $instance = null;

    private PDO $pdo;

    private string $driver;

    private function __construct(PDO $pdo, string $driver)
    {
        $this->pdo = $pdo;
        $this->driver = $driver;
    }

    public static function instance(): Database
    {
        if (self::$instance === null) {
            self::$instance = self::connect();
        }
        return self::$instance;
    }

    /** Drop the cached connection (used by the installer after writing config). */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Open a connection using the installed config, or an explicit set of
     * credentials when the setup wizard is testing user input.
     *
     * @param array<string,mixed>|null $config
     */
    public static function connect(?array $config = null): Database
    {
        $config ??= Config::all();
        $driver = (string) ($config['db_driver'] ?? 'sqlite');

        if ($driver === 'sqlite') {
            $path = (string) ($config['db_path'] ?? SW_DATA . '/stormwatch.sqlite');
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create the data directory: ' . $dir);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // WAL keeps the dashboard readable while a cron worker is inserting.
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA foreign_keys = ON');
            return new self($pdo, 'sqlite');
        }

        if ($driver !== 'mysql') {
            throw new \RuntimeException('Unsupported database driver: ' . $driver);
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($config['db_host'] ?? '127.0.0.1'),
            (int) ($config['db_port'] ?? 3306),
            (string) ($config['db_name'] ?? ''),
            (string) ($config['db_charset'] ?? 'utf8mb4')
        );
        $pdo = new PDO($dsn, (string) ($config['db_user'] ?? ''), (string) ($config['db_pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return new self($pdo, 'mysql');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /** @param array<string|int,mixed> $params */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return mixed
     */
    public function value(string $sql, array $params = [])
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    /**
     * Insert, silently skipping rows that collide with a unique index.
     * Used for strike de-duplication, where a repeat is expected and boring.
     *
     * @param array<string,mixed> $data
     * @return bool true when a row was actually written
     */
    public function insertIgnore(string $table, array $data): bool
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);
        $verb = $this->driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $sql = sprintf(
            '%s INTO %s (%s) VALUES (%s)',
            $verb,
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        try {
            $stmt = $this->run($sql, $data);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            // 23000 = integrity constraint; treat as "already have it".
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Insert or update by primary key.
     *
     * @param array<string,mixed> $data
     */
    public function upsert(string $table, array $data, string $conflictColumn): void
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);
        $updates = [];
        foreach ($columns as $column) {
            if ($column === $conflictColumn) {
                continue;
            }
            $updates[] = $this->driver === 'sqlite'
                ? sprintf('%s = excluded.%s', $column, $column)
                : sprintf('%s = VALUES(%s)', $column, $column);
        }

        if ($this->driver === 'sqlite') {
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT(%s) DO UPDATE SET %s',
                $table,
                implode(', ', $columns),
                implode(', ', $placeholders),
                $conflictColumn,
                implode(', ', $updates)
            );
        } else {
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
                $table,
                implode(', ', $columns),
                implode(', ', $placeholders),
                implode(', ', $updates)
            );
        }
        $this->run($sql, $data);
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function tableExists(string $table): bool
    {
        try {
            if ($this->driver === 'sqlite') {
                return $this->value(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
                    [$table]
                ) > 0;
            }
            return $this->value(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table]
            ) > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}
