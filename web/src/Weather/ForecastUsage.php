<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Weather;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * What the planner's weather costs (route-planner.md section 8), in
 * `forecast_usage`, one row per minute with any ask: the asks that reached
 * {@see Forecast}, the ones our cache answered, the requests sent to
 * Open-Meteo and what they count as there (`weight`, {@see Forecast::callWeight()}),
 * the ones that failed, the ones Open-Meteo refused as over its limit, and the
 * asks our own limit refused before they reached the forecast. Counts only: no
 * point, no date, no rider. The admin's Weather usage page reads them against
 * Open-Meteo's free tier. Times are UTC.
 *
 * Recording never stands in a forecast's way: a failed write is logged and
 * the forecast goes on.
 *
 * @api
 */
final readonly class ForecastUsage
{
    /** Open-Meteo's free tier, per server address (open-meteo.com/en/pricing, read 2026-10-23). */
    public const int LIMIT_MINUTE = 600;

    public const int LIMIT_HOUR = 5000;

    public const int LIMIT_DAY = 10000;

    public const int LIMIT_MONTH = 300000;

    public function __construct(private Connection $db, private LoggerInterface $logger, private ClockInterface $clock)
    {
    }

    /** One ask that reached the forecast: answered by our cache, or sent to Open-Meteo with its outcome. */
    public function asked(bool $fromCache, float $weight = 0.0, bool $failed = false, bool $refused = false): void
    {
        $this->add([
            'asked' => 1,
            'hits' => $fromCache ? 1 : 0,
            'calls' => $fromCache ? 0 : 1,
            'weight' => $fromCache ? 0.0 : $weight,
            'failures' => !$fromCache && $failed ? 1 : 0,
            'refused' => !$fromCache && $refused ? 1 : 0,
        ]);
    }

    /** One ask our own limit refused (the planner's `plan_weather` limiters). */
    public function limited(): void
    {
        $this->add(['limited' => 1]);
    }

    /**
     * Where Open-Meteo's limits stand now: what this minute's hour, today and
     * this month counted there, the busiest minute today, and today's asks.
     *
     * @return array{hour: float, day: float, month: float, peakMinute: float, asked: int, hits: int, calls: int, failures: int, refused: int, limited: int}
     */
    public function now(): array
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $row = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT COALESCE(SUM(weight) FILTER (WHERE minute >= :hour), 0) AS hour,
                       COALESCE(SUM(weight) FILTER (WHERE minute >= :day), 0) AS day,
                       COALESCE(SUM(weight), 0) AS month,
                       COALESCE(MAX(weight) FILTER (WHERE minute >= :day), 0) AS peak_minute,
                       COALESCE(SUM(asked) FILTER (WHERE minute >= :day), 0) AS asked,
                       COALESCE(SUM(hits) FILTER (WHERE minute >= :day), 0) AS hits,
                       COALESCE(SUM(calls) FILTER (WHERE minute >= :day), 0) AS calls,
                       COALESCE(SUM(failures) FILTER (WHERE minute >= :day), 0) AS failures,
                       COALESCE(SUM(refused) FILTER (WHERE minute >= :day), 0) AS refused,
                       COALESCE(SUM(limited) FILTER (WHERE minute >= :day), 0) AS limited
                  FROM forecast_usage
                 WHERE minute >= :month
                SQL,
            [
                'hour' => $now->format('Y-m-d H:00:00+00'),
                'day' => $now->format('Y-m-d 00:00:00+00'),
                'month' => $now->format('Y-m-01 00:00:00+00'),
            ],
        );
        $row = false === $row ? [] : $row;

        return [
            'hour' => (float) ($row['hour'] ?? 0),
            'day' => (float) ($row['day'] ?? 0),
            'month' => (float) ($row['month'] ?? 0),
            'peakMinute' => (float) ($row['peak_minute'] ?? 0),
            'asked' => (int) ($row['asked'] ?? 0),
            'hits' => (int) ($row['hits'] ?? 0),
            'calls' => (int) ($row['calls'] ?? 0),
            'failures' => (int) ($row['failures'] ?? 0),
            'refused' => (int) ($row['refused'] ?? 0),
            'limited' => (int) ($row['limited'] ?? 0),
        ];
    }

    /**
     * One row per UTC day of the last `$days`, today last, empty days included.
     *
     * @return list<array{at: string, asked: int, hits: int, calls: int, weight: float, failures: int, refused: int, limited: int}>
     */
    public function days(int $days): array
    {
        return $this->series('day', $days, 'P1D', 'Y-m-d');
    }

    /**
     * One row per UTC hour of the last `$hours`, this hour last, empty hours included.
     *
     * @return list<array{at: string, asked: int, hits: int, calls: int, weight: float, failures: int, refused: int, limited: int}>
     */
    public function hours(int $hours): array
    {
        return $this->series('hour', $hours, 'PT1H', 'Y-m-d H:00');
    }

    /**
     * @return list<array{at: string, asked: int, hits: int, calls: int, weight: float, failures: int, refused: int, limited: int}>
     */
    private function series(string $unit, int $count, string $step, string $format): array
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
        $last = 'day' === $unit ? $now->setTime(0, 0) : $now->setTime((int) $now->format('H'), 0);
        $first = $last->sub(new \DateInterval('day' === $unit ? 'P'.($count - 1).'D' : 'PT'.($count - 1).'H'));
        $rows = $this->db->fetchAllAssociativeIndexed(
            sprintf(<<<'SQL'
                SELECT to_char(date_trunc('%1$s', minute AT TIME ZONE 'UTC'), :fmt) AS at,
                       SUM(asked) AS asked, SUM(hits) AS hits, SUM(calls) AS calls, SUM(weight) AS weight,
                       SUM(failures) AS failures, SUM(refused) AS refused, SUM(limited) AS limited
                  FROM forecast_usage
                 WHERE minute >= :first
                 GROUP BY 1
                SQL, $unit),
            ['fmt' => 'day' === $unit ? 'YYYY-MM-DD' : 'YYYY-MM-DD HH24:00', 'first' => $first->format('Y-m-d H:i:sP')],
        );
        $out = [];
        for ($at = $first; $at <= $last; $at = $at->add(new \DateInterval($step))) {
            $key = $at->format($format);
            $r = $rows[$key] ?? [];
            $out[] = [
                'at' => $key,
                'asked' => (int) ($r['asked'] ?? 0),
                'hits' => (int) ($r['hits'] ?? 0),
                'calls' => (int) ($r['calls'] ?? 0),
                'weight' => (float) ($r['weight'] ?? 0),
                'failures' => (int) ($r['failures'] ?? 0),
                'refused' => (int) ($r['refused'] ?? 0),
                'limited' => (int) ($r['limited'] ?? 0),
            ];
        }

        return $out;
    }

    /** @param array<string, int|float> $counts */
    private function add(array $counts): void
    {
        $columns = array_keys($counts);
        try {
            $this->db->executeStatement(
                sprintf(
                    "INSERT INTO forecast_usage (minute, %s) VALUES (date_trunc('minute', CAST(:at AS timestamptz)), %s)
                     ON CONFLICT (minute) DO UPDATE SET %s",
                    implode(', ', $columns),
                    implode(', ', array_map(static fn (string $c): string => ':'.$c, $columns)),
                    implode(', ', array_map(static fn (string $c): string => $c.' = forecast_usage.'.$c.' + EXCLUDED.'.$c, $columns)),
                ),
                ['at' => $this->clock->now()->format('Y-m-d H:i:sP')] + $counts,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('forecast usage not recorded', ['error' => $e->getMessage()]);
        }
    }
}
