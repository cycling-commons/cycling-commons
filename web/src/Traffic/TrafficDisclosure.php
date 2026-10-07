<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

/**
 * The rules before any traffic number is shown (docs/specs/traffic-measurements.md §4.4).
 *
 * A quiet road with one regular rider is the case these exist for: a number
 * there would say that somebody rides it, and when. So a group (one road
 * piece, one direction, one time group of the active scheme) is shown only
 * with several riders, on several days, none of them dominant, over enough
 * distance that one car cannot swing it. Below the rules the group does not
 * appear at all; there is no "too few riders" entry that would itself say
 * someone rides there. Riders and days come out as coarse bands, never counts.
 *
 * Public copy never states these numbers.
 *
 * @api
 */
final class TrafficDisclosure
{
    public const int MIN_RIDERS = 5;
    public const int MIN_DAYS = 3;
    public const float MAX_SHARE = 0.5;
    public const int MIN_DISTANCE_M = 2000;
    /** Measured car speeds a group needs before its typical speed is shown. */
    public const int MIN_SPEED_PASSES = 5;

    public const array SCHEMES = ['all', 'daytype', 'daytype_band'];

    /** Slot (local quarter hour) upper bounds of the bands, exclusive. */
    private const array BANDS = [24 => 'night', 36 => 'morning', 64 => 'day', 76 => 'evening', 96 => 'late'];

    /**
     * @param iterable<array<string, mixed>>                                                $cells
     * @param list<array{way: int, buckets: array<string, array{d: int, days: list<int>}>}> $riders
     * @param \Closure(array<string, mixed>): string                                        $bucketOf the key a cell is filed under in rider rows
     *
     * @return list<array{way: int, dir: string, label: string, group: string, carsPerKm: float, nearbyPerKm: float, carSpeedBand: int|null, riders: string, days: string}>
     */
    public function evaluate(iterable $cells, array $riders, string $scheme, string $sinceQuarter, \Closure $bucketOf): array
    {
        if (!\in_array($scheme, self::SCHEMES, true)) {
            $scheme = 'daytype';
        }

        $groups = [];
        $bucketGroup = [];
        foreach ($cells as $cell) {
            if (strcmp((string) $cell['quarter'], $sinceQuarter) < 0) {
                continue;
            }
            $group = self::groupOf($cell, $scheme);
            $id = $cell['way'].'|'.$cell['dir'].'|'.$group;
            $g = $groups[$id] ??= [
                'way' => (int) $cell['way'], 'dir' => (string) $cell['dir'], 'group' => $group,
                'distance' => 0, 'passes' => 0, 'nearby' => 0, 'speedPasses' => 0, 'bins' => array_fill(0, TrafficStore::SPEED_BINS, 0),
                'labels' => [],
            ];
            $g['distance'] += $cell['distanceM'];
            $g['passes'] += $cell['passes'];
            $g['nearby'] += $cell['nearby'] ?? 0;
            $g['speedPasses'] += $cell['speedPasses'];
            foreach ($cell['bins'] as $i => $n) {
                $g['bins'][$i] += $n;
            }
            $g['labels'][$cell['label']] = ($g['labels'][$cell['label']] ?? 0) + $cell['distanceM'];
            $groups[$id] = $g;
            $bucketGroup[$bucketOf($cell)] = $id;
        }

        // Per group: each rider row's distance and dates inside it.
        $perRider = [];
        foreach ($riders as $r => $row) {
            foreach ($row['buckets'] as $bucket => $entry) {
                $id = $bucketGroup[$bucket] ?? null;
                if (null === $id || $entry['d'] <= 0) {
                    continue;
                }
                $perRider[$id][$r]['d'] = ($perRider[$id][$r]['d'] ?? 0) + $entry['d'];
                foreach ($entry['days'] as $day) {
                    $perRider[$id][$r]['days'][$day] = true;
                }
            }
        }

        $shown = [];
        foreach ($groups as $id => $g) {
            $contributors = $perRider[$id] ?? [];
            $days = [];
            $riderDistance = 0;
            $largest = 0;
            foreach ($contributors as $c) {
                $days += $c['days'] ?? [];
                $riderDistance += $c['d'];
                $largest = max($largest, $c['d']);
            }
            // Distance with no rider row left (an account deleted since) is
            // one more contributor: it may not dominate either.
            $orphaned = max(0, $g['distance'] - $riderDistance);
            $largest = max($largest, $orphaned);
            if (\count($contributors) < self::MIN_RIDERS || \count($days) < self::MIN_DAYS
                || $g['distance'] < self::MIN_DISTANCE_M
                || $largest / max($g['distance'], $riderDistance) > self::MAX_SHARE) {
                continue;
            }
            arsort($g['labels']);
            $shown[] = [
                'way' => $g['way'],
                'dir' => $g['dir'],
                'label' => (string) array_key_first($g['labels']),
                'group' => $g['group'],
                // One decimal: finer would let two views be subtracted into one upload.
                'carsPerKm' => round($g['passes'] / ($g['distance'] / 1000), 1),
                // Cars on the road beside a cycle path: noise, not safety.
                'nearbyPerKm' => round($g['nearby'] / ($g['distance'] / 1000), 1),
                'carSpeedBand' => $g['speedPasses'] >= self::MIN_SPEED_PASSES ? self::medianBand($g['bins']) : null,
                'riders' => self::riderBand(\count($contributors)),
                'days' => self::dayBand(\count($days)),
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
                static fn (string $day): array => array_map(static fn (string $band): string => $day.'|'.$band, array_values(self::BANDS)),
                ['workday', 'weekend'],
            )),
            default => ['workday', 'weekend'],
        };
    }

    public static function riderBand(int $riders): string
    {
        return $riders >= 20 ? '20+' : ($riders >= 10 ? '10-19' : '5-9');
    }

    public static function dayBand(int $days): string
    {
        return $days >= 10 ? '10+' : '3-9';
    }

    /** @param array<string, mixed> $cell */
    private static function groupOf(array $cell, string $scheme): string
    {
        if ('all' === $scheme) {
            return 'all';
        }
        if ('daytype' === $scheme) {
            return (string) $cell['dayType'];
        }
        foreach (self::BANDS as $upper => $band) {
            if ($cell['slot'] < $upper) {
                return $cell['dayType'].'|'.$band;
            }
        }

        return $cell['dayType'].'|late';
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
