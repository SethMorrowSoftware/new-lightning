<?php
/**
 * Append-only activity log: alerts sent, delivery failures, sign-ins, worker
 * problems. This is what the History screen reads, and it is the first place
 * to look when someone asks "did Slack actually get the alert?".
 */
declare(strict_types=1);

namespace StormWatch;

final class Events
{
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_ERROR = 'error';

    /** @param array<string,mixed> $context */
    public static function log(string $type, string $severity, string $message, array $context = []): void
    {
        try {
            Database::instance()->run(
                'INSERT INTO events (created_at, type, severity, message, context) VALUES (?, ?, ?, ?, ?)',
                [
                    time(),
                    mb_substr($type, 0, 40),
                    mb_substr($severity, 0, 20),
                    mb_substr($message, 0, 2000),
                    $context === [] ? null : json_encode($context, JSON_UNESCAPED_SLASHES),
                ]
            );
        } catch (\Throwable $e) {
            // Logging must never take down the alert path.
            error_log('[stormwatch] event log failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function recent(int $limit = 100, ?string $type = null, ?string $severity = null): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT id, created_at, type, severity, message, context FROM events';
        $where = [];
        $params = [];
        if ($type !== null && $type !== '') {
            $where[] = 'type LIKE ?';
            $params[] = $type . '%';
        }
        if ($severity !== null && $severity !== '') {
            $where[] = 'severity = ?';
            $params[] = $severity;
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        return Database::instance()->all($sql, $params);
    }

    public static function prune(int $keepDays = 30): int
    {
        $cutoff = time() - ($keepDays * 86400);
        $stmt = Database::instance()->run('DELETE FROM events WHERE created_at < ?', [$cutoff]);
        return $stmt->rowCount();
    }

    /** Distinct event types present in the log, for the History filter. @return array<int,string> */
    public static function types(): array
    {
        $rows = Database::instance()->all('SELECT DISTINCT type FROM events ORDER BY type');
        return array_map(static fn(array $r): string => (string) $r['type'], $rows);
    }
}
