<?php
/**
 * Metering for paid weather APIs that sell a monthly allowance.
 *
 * Xweather's free tier is 15,000 accesses a month, and a request does not
 * necessarily cost one: the price is the product of endpoint, area and time
 * range, reported back in the X-Cost-Tokens response header. So this counts
 * what the provider says it charged rather than what we assume, and refuses to
 * poll once the allowance is gone.
 *
 * Running out is never allowed to be silent — an alerting system that has
 * quietly stopped fetching data looks exactly like clear weather.
 */
declare(strict_types=1);

namespace StormWatch;

final class ApiBudget
{
    /** Billing periods are calendar months, in UTC. */
    public static function period(?int $timestamp = null): string
    {
        return gmdate('Y-m', $timestamp ?? time());
    }

    /**
     * Record what a call cost.
     *
     * @param int $tokens the provider's own figure; 1 when it did not say
     */
    public static function record(string $provider, int $tokens = 1, ?int $timestamp = null): void
    {
        $period = self::period($timestamp);
        $db = Database::instance();
        $tokens = max(0, $tokens);

        try {
            $updated = $db->run(
                'UPDATE api_usage SET requests = requests + 1, tokens = tokens + ?, updated_at = ?
                 WHERE provider = ? AND period = ?',
                [$tokens, time(), $provider, $period]
            )->rowCount();

            if ($updated === 0) {
                $db->insertIgnore('api_usage', [
                    'provider' => $provider,
                    'period' => $period,
                    'requests' => 1,
                    'tokens' => $tokens,
                    'updated_at' => time(),
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[stormwatch] could not record API usage: ' . $e->getMessage());
        }
    }

    /**
     * @return array{requests:int,tokens:int,period:string}
     */
    public static function usage(string $provider, ?string $period = null): array
    {
        $period ??= self::period();
        try {
            $row = Database::instance()->first(
                'SELECT requests, tokens FROM api_usage WHERE provider = ? AND period = ?',
                [$provider, $period]
            );
        } catch (\Throwable $e) {
            $row = null;
        }
        return [
            'requests' => (int) ($row['requests'] ?? 0),
            'tokens' => (int) ($row['tokens'] ?? 0),
            'period' => $period,
        ];
    }

    public static function budget(): int
    {
        return max(0, Settings::getInt('api_monthly_budget'));
    }

    /** Allowance left this month, or null when no budget is set. */
    public static function remaining(string $provider): ?int
    {
        $budget = self::budget();
        if ($budget === 0) {
            return null;
        }
        return max(0, $budget - self::usage($provider)['tokens']);
    }

    public static function isExhausted(string $provider): bool
    {
        $remaining = self::remaining($provider);
        return $remaining !== null && $remaining <= 0;
    }

    /**
     * Everything the settings screen needs to show where the month stands,
     * including a projection so the operator can see a problem coming rather
     * than discover it when the allowance runs out.
     *
     * @return array<string,mixed>
     */
    public static function summary(string $provider): array
    {
        $usage = self::usage($provider);
        $budget = self::budget();
        $now = time();
        $daysInMonth = (int) gmdate('t', $now);
        $dayOfMonth = (int) gmdate('j', $now);
        $elapsed = max(1 / 24, ($dayOfMonth - 1) + ((int) gmdate('G', $now) / 24));

        $projected = (int) round(($usage['tokens'] / $elapsed) * $daysInMonth);

        return [
            'period' => $usage['period'],
            'requests' => $usage['requests'],
            'tokens' => $usage['tokens'],
            'budget' => $budget,
            'remaining' => self::remaining($provider),
            'projected_month' => $usage['tokens'] > 0 ? $projected : 0,
            'over_projection' => $budget > 0 && $usage['tokens'] > 0 && $projected > $budget,
        ];
    }

    /**
     * How many accesses a given polling pattern would use in a month, so the
     * settings screen can answer "will this fit?" before it is saved.
     */
    public static function projectMonthlyCost(int $idleSeconds, int $activeSeconds, float $activeHoursPerMonth = 10.0): int
    {
        $idleSeconds = max(1, $idleSeconds);
        $activeSeconds = max(1, $activeSeconds);
        $hoursPerMonth = 24 * 30;
        $idleHours = max(0.0, $hoursPerMonth - $activeHoursPerMonth);

        return (int) round(
            ($idleHours * 3600 / $idleSeconds) + ($activeHoursPerMonth * 3600 / $activeSeconds)
        );
    }
}
