<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

/**
 * The rules before any traffic is shown (docs/specs/traffic-measurements.md §4.4).
 *
 * A group (one road piece, one direction, one time group of the active scheme)
 * is shown only with enough lines, on enough day groups, over enough distance
 * that one car cannot swing it, and only as a band: quiet, moderate or busy.
 * Never a number, so one ride cannot be read off a value. Below the rules the
 * group does not appear at all; there is no "too few rides" entry that would
 * itself say someone rides there. Days come out as a coarse band too.
 *
 * Public copy never states these numbers.
 *
 * @api
 */
final class TrafficDisclosure
{
    public const int MIN_LINES = 5;
    public const int MIN_DAY_GROUPS = 3;
    public const int MIN_DISTANCE_M = 2000;
    /** Measured car speeds a group needs before its typical speed is shown. */
    public const int MIN_SPEED_PASSES = 5;
    /** Cars per km from which a road is moderate, and from which it is busy. */
    public const float MODERATE_FROM = 1.0;
    public const float BUSY_FROM = 3.0;

    public const array SCHEMES = ['all', 'daytype', 'daytype_band'];

    /** The five parts of the day, by band index (traffic-measurements.md §3.4). */
    private const array BANDS = ['night', 'morning', 'day', 'evening', 'late'];

    /**
     * @param iterable<array<string, mixed>> $rows totals rows (TrafficStore::totals())
     *
     * @return list<array{way: int, dir: string, label: string, group: string, traffic: string, nearby: string, carSpeedBand: int|null, days: string}>
     */
    public function evaluate(iterable $rows, string $scheme, string $sinceQuarter): array
    {
        if (!\in_array($scheme, self::SCHEMES, true)) {
            $scheme = 'daytype';
        }

        $groups = [];
        foreach ($rows as $row) {
            if (strcmp((string) $row['quarter'], $sinceQuarter) < 0) {
                continue;
            }
            $group = self::groupOf($row, $scheme);
            $id = $row['way'].'|'.$row['dir'].'|'.$group;
            $g = $groups[$id] ??= [
                'way' => (int) $row['way'], 'dir' => (string) $row['dir'], 'group' => $group,
                'distance' => 0, 'passes' => 0, 'nearby' => 0, 'lines' => 0, 'speedPasses' => 0,
                'bins' => array_fill(0, TrafficStore::SPEED_BINS, 0), 'labels' => [], 'days' => [],
            ];
            $g['distance'] += (int) $row['distanceM'];
            $g['passes'] += (int) $row['passes'];
            $g['nearby'] += (int) $row['nearby'];
            $g['lines'] += (int) $row['lines'];
            $g['speedPasses'] += (int) $row['speedPasses'];
            foreach ($row['bins'] as $i => $n) {
                $g['bins'][$i] += (int) $n;
            }
            $g['labels'][$row['label']] = ($g['labels'][$row['label']] ?? 0) + (int) $row['distanceM'];
            // A day group is one of 16 per quarter: the same group in two rows
            // of one quarter may be one day, two quarters never share a day.
            $g['days'][$row['quarter']] = ($g['days'][$row['quarter']] ?? 0) | (int) $row['days'];
            $groups[$id] = $g;
        }

        $shown = [];
        foreach ($groups as $g) {
            $days = array_sum(array_map(static fn (int $bits): int => substr_count(decbin($bits), '1'), $g['days']));
            if ($g['lines'] < self::MIN_LINES || $days < self::MIN_DAY_GROUPS || $g['distance'] < self::MIN_DISTANCE_M) {
                continue;
            }
            arsort($g['labels']);
            $km = $g['distance'] / 1000;
            $shown[] = [
                'way' => $g['way'],
                'dir' => $g['dir'],
                'label' => (string) array_key_first($g['labels']),
                'group' => $g['group'],
                'traffic' => self::level($g['passes'] / $km),
                // Cars on the road beside a cycle path: noise, not safety.
                'nearby' => self::level($g['nearby'] / $km),
                'carSpeedBand' => $g['speedPasses'] >= self::MIN_SPEED_PASSES ? self::medianBand($g['bins']) : null,
                'days' => self::dayBand($days),
            ];
        }

        return $shown;
    }

    /**
     * The groups of the active scheme, in display order.
     *
     * @return list<string>
     */
    public static function groupsOf(string $scheme): array
    {
        return match ($scheme) {
            'all' => ['all'],
            'daytype_band' => array_merge(...array_map(
                static fn (string $day): array => array_map(static fn (string $band): string => $day.'|'.$band, self::BANDS),
                ['workday', 'weekend'],
            )),
            default => ['workday', 'weekend'],
        };
    }

    /** Quiet, moderate or busy, for a number of cars per km. */
    public static function level(float $carsPerKm): string
    {
        return $carsPerKm >= self::BUSY_FROM ? 'busy' : ($carsPerKm >= self::MODERATE_FROM ? 'moderate' : 'quiet');
    }

    public static function dayBand(int $days): string
    {
        return $days >= 10 ? '10+' : '3-9';
    }

    /** @param array<string, mixed> $row */
    private static function groupOf(array $row, string $scheme): string
    {
        if ('all' === $scheme) {
            return 'all';
        }
        if ('daytype' === $scheme) {
            return (string) $row['dayType'];
        }

        return $row['dayType'].'|'.(self::BANDS[(int) $row['band']] ?? 'late');
    }

    /**
     * Lower bound in km/h of the band holding the median measured speed.
     *
     * @param list<int> $bins
     */
    private static function medianBand(array $bins): int
    {
        $half = array_sum($bins) / 2;
        $running = 0;
        foreach ($bins as $i => $n) {
            $running += $n;
            if ($running >= $half) {
                return $i * 10;
            }
        }

        return (\count($bins) - 1) * 10;
    }
}
