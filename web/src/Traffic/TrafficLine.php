<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

/**
 * One summary line of a traffic request (docs/specs/traffic-measurements.md §4.1).
 *
 * Two outcomes for a bad line. A key that could carry a track, or any key the
 * contract does not name, refuses the whole request: the browser never sends
 * one, so something other than the review sent it. A line that is well formed
 * but implausible (a speed no bike rides, more cars than a road can hold, a
 * date in the future) is dropped and counted, because a rider's device can
 * produce one and the rest of the ride is still worth having.
 *
 * No car drives on a cycle path. What the radar sees beside one drove on the
 * road next to it: a cycle-path line carries those cars as `nearby` (noise,
 * not safety) and never as `passes`; a lane or road line never as `nearby`.
 *
 * @api
 */
final class TrafficLine
{
    /** Keys that could carry a position, a time or a track. */
    public const array TRACK_KEYS = ['track', 'points', 'trkpt', 'polyline', 'records', 'route', 'coordinates',
        'gpx', 'fit', 'lat', 'lng', 'lon', 'time', 'timestamp'];

    private const array KEYS = ['way', 'region', 'dir', 'label', 'slot', 'dayType', 'season', 'quarter', 'day',
        'distanceM', 'timeS', 'passes', 'nearby', 'avgSpeedKmh', 'carSpeedBins', 'blocks'];
    private const array LABELS = ['p', 'l', 'r'];
    private const array DIRS = ['f', 'b'];
    private const array DAY_TYPES = ['workday', 'weekend'];
    private const array SEASONS = ['spring', 'summer', 'autumn', 'winter'];
    private const string FIRST_QUARTER = '2010-Q1';
    /** A quarter hour plus the one interval a slot boundary hands to the later line. */
    private const int MAX_TIME_S = 905;
    private const int MAX_DISTANCE_M = 20000;
    private const float MIN_KMH = 3.0;
    private const float MAX_KMH = 80.0;
    private const int MAX_PASSES = 300;
    private const int MAX_PASSES_PER_KM = 60;
    private const int MAX_BLOCKS = 3;

    /**
     * The line, normalised, or null when it is implausible.
     *
     * @param array<array-key, mixed> $line
     *
     * @return array{way: int, region: int|null, dir: string, label: string, slot: int, dayType: string, season: string, quarter: string, day: int, distanceM: int, timeS: int, passes: int, nearby: int, avgSpeedKmh: float, carSpeedBins: list<int>|null, blocks: list<string>}|null
     *
     * @throws TrafficPayloadRefused
     */
    public static function accept(array $line, \DateTimeImmutable $now): ?array
    {
        foreach (array_keys($line) as $key) {
            if (\in_array($key, self::TRACK_KEYS, true)) {
                throw new TrafficPayloadRefused('track_not_accepted');
            }
            if (!\in_array($key, self::KEYS, true)) {
                throw new TrafficPayloadRefused('invalid');
            }
        }

        $way = $line['way'] ?? null;
        $slot = $line['slot'] ?? null;
        $day = $line['day'] ?? null;
        $distance = $line['distanceM'] ?? null;
        $time = $line['timeS'] ?? null;
        $passes = $line['passes'] ?? null;
        $nearby = $line['nearby'] ?? 0;
        // The region the road piece's tile names: absent when the tiles had none.
        $region = $line['region'] ?? null;
        if (null !== $region && (!\is_int($region) || $region < 1)) {
            return null;
        }
        $quarter = $line['quarter'] ?? null;
        $q = [];
        if (!\is_string($quarter) || 1 !== preg_match('/^(\d{4})-Q([1-4])$/D', $quarter, $q)) {
            return null;
        }
        if (!\is_int($way) || $way < 1 || !\is_int($slot) || $slot < 0 || $slot > 95
            || !\is_int($day) || !\is_int($distance) || !\is_int($time) || !\is_int($passes) || !\is_int($nearby)
            || !\in_array($line['dir'] ?? null, self::DIRS, true)
            || !\in_array($line['label'] ?? null, self::LABELS, true)
            || !\in_array($line['dayType'] ?? null, self::DAY_TYPES, true)
            || !\in_array($line['season'] ?? null, self::SEASONS, true)) {
            return null;
        }

        if ($time < 1 || $time > self::MAX_TIME_S || $distance < 1 || $distance > self::MAX_DISTANCE_M) {
            return null;
        }
        $kmh = $distance / $time * 3.6;
        if ($kmh < self::MIN_KMH || $kmh > self::MAX_KMH) {
            return null;
        }
        $perKm = (int) ceil($distance / 1000 * self::MAX_PASSES_PER_KM);
        foreach ([$passes, $nearby] as $cars) {
            if ($cars < 0 || $cars > self::MAX_PASSES || $cars > $perKm) {
                return null;
            }
        }
        if ('p' === $line['label'] ? $passes > 0 : $nearby > 0) {
            return null;
        }

        // The local date may run a day ahead of UTC (east of Greenwich), never
        // more, and so may its quarter on the first day of one.
        $latestDay = intdiv($now->getTimestamp(), 86400) + 1;
        $latest = new \DateTimeImmutable('@'.($latestDay * 86400));
        $latestQuarter = $latest->format('Y').'-Q'.(intdiv((int) $latest->format('n') - 1, 3) + 1);
        if (strcmp($quarter, self::FIRST_QUARTER) < 0 || strcmp($quarter, $latestQuarter) > 0 || $day > $latestDay) {
            return null;
        }
        $date = new \DateTimeImmutable('@'.($day * 86400));
        if ($date->format('Y') !== $q[1] || (string) (intdiv((int) $date->format('n') - 1, 3) + 1) !== $q[2]) {
            return null;
        }

        $bins = $line['carSpeedBins'] ?? null;
        if (null !== $bins) {
            if (!\is_array($bins) || !array_is_list($bins) || TrafficStore::SPEED_BINS !== \count($bins)) {
                return null;
            }
            foreach ($bins as $n) {
                if (!\is_int($n) || $n < 0) {
                    return null;
                }
            }
            if (array_sum($bins) > $passes + $nearby) {
                return null;
            }
        }

        $blocks = $line['blocks'] ?? null;
        if (!\is_array($blocks) || !array_is_list($blocks) || [] === $blocks || \count($blocks) > self::MAX_BLOCKS) {
            return null;
        }
        foreach ($blocks as $code) {
            if (!\is_string($code) || 1 !== preg_match('/^[0-9a-f]{64}$/D', $code)) {
                return null;
            }
        }

        return [
            'way' => $way, 'region' => $region, 'dir' => $line['dir'], 'label' => $line['label'], 'slot' => $slot,
            'dayType' => $line['dayType'], 'season' => $line['season'], 'quarter' => $quarter, 'day' => $day,
            'distanceM' => $distance, 'timeS' => $time, 'passes' => $passes, 'nearby' => $nearby, 'avgSpeedKmh' => $kmh,
            'carSpeedBins' => $bins, 'blocks' => array_values(array_unique($blocks)),
        ];
    }
}
