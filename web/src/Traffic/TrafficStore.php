<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The plain traffic totals and the dedupe codes
 * (docs/specs/traffic-measurements.md §4.3).
 *
 * A totals row sums the lines of one road, direction, part of the day, day
 * type and quarter, with the day groups they came from. No rider is in it:
 * lines reach it only from the waiting room (TrafficPool), a block at a time.
 * The dedupe codes are HMACs of the browser's block fingerprints, kept for
 * good so the same ride never counts twice.
 *
 * @api
 */
final class TrafficStore
{
    public const int SPEED_BINS = 16;

    public function __construct(
        private readonly Connection $db,
        private readonly TrafficKeys $keys,
    ) {
    }

    /**
     * Claims the codes for this request and returns those it claimed. A code
     * another request already holds, committed or still in flight, is not
     * returned: the insert waits for that request and then finds the row.
     *
     * @param list<string> $codes 64-hex codes from the browser
     *
     * @return list<string> the codes this request inserted, as given
     */
    public function claimCodes(array $codes): array
    {
        $claimed = [];
        $unique = array_values(array_unique($codes));
        sort($unique);   // one lock order for every request
        foreach ($unique as $code) {
            $inserted = $this->db->executeStatement('INSERT INTO traffic_seen (code) VALUES (?) ON CONFLICT DO NOTHING',
                [$this->keys->seenCode($code)], [ParameterType::BINARY]);
            if (1 === $inserted) {
                $claimed[] = $code;
            }
        }

        return $claimed;
    }

    /**
     * Adds one line to its totals row. The row is created empty first and then
     * locked, so two writers queue on one lock instead of both starting from
     * zero. Callers add a block's lines in key order.
     *
     * @param array<string, mixed> $line a validated line (TrafficLine)
     */
    public function addToTotal(array $line): void
    {
        $key = [$line['way'], $line['dir'], $line['band'], $line['dayType'], $line['quarter']];
        $this->db->executeStatement('INSERT INTO traffic_total (way, dir, band, day_type, quarter, label) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT DO NOTHING',
            [...$key, $line['label']]);
        $row = $this->db->fetchAssociative('SELECT region, bins, speed_passes FROM traffic_total WHERE way = ? AND dir = ? AND band = ? AND day_type = ? AND quarter = ? FOR UPDATE', $key);
        \assert(\is_array($row));

        $bins = json_decode((string) $row['bins'], true);
        $bins = \is_array($bins) && self::SPEED_BINS === \count($bins) ? array_map(intval(...), $bins) : array_fill(0, self::SPEED_BINS, 0);
        $speedCars = 0;
        if (\is_array($line['carSpeedBins'] ?? null)) {
            foreach ($line['carSpeedBins'] as $i => $n) {
                $bins[$i] += (int) $n;
                $speedCars += (int) $n;
            }
        }

        $this->db->executeStatement('UPDATE traffic_total SET region = COALESCE(region, ?), distance_m = distance_m + ?, time_s = time_s + ?,
            passes = passes + ?, nearby = nearby + ?, speed_sum = speed_sum + ?, speed_passes = speed_passes + ?, bins = ?,
            lines = lines + 1, days = days | ?
            WHERE way = ? AND dir = ? AND band = ? AND day_type = ? AND quarter = ?', [
            $line['region'] ?? null, $line['distanceM'], $line['timeS'], $line['passes'], $line['nearby'] ?? 0,
            (float) ($line['avgSpeedKmh'] ?? 0) * $line['timeS'], $speedCars, json_encode($bins, \JSON_THROW_ON_ERROR),
            1 << (int) $line['dayGroup'], ...$key,
        ]);
    }

    /**
     * Every totals row.
     *
     * @return iterable<array{way: int, dir: string, label: string, band: int, dayType: string, quarter: string, region: int|null, distanceM: int, timeS: int, passes: int, nearby: int, speedSum: float, speedPasses: int, bins: list<int>, lines: int, days: int}>
     */
    public function totals(): iterable
    {
        foreach ($this->db->iterateAssociative('SELECT * FROM traffic_total ORDER BY way, dir, band, day_type, quarter') as $r) {
            $bins = json_decode((string) $r['bins'], true);
            yield [
                'way' => (int) $r['way'], 'dir' => (string) $r['dir'], 'label' => (string) $r['label'], 'band' => (int) $r['band'],
                'dayType' => (string) $r['day_type'], 'quarter' => (string) $r['quarter'],
                'region' => null === $r['region'] ? null : (int) $r['region'],
                'distanceM' => (int) $r['distance_m'], 'timeS' => (int) $r['time_s'], 'passes' => (int) $r['passes'],
                'nearby' => (int) $r['nearby'], 'speedSum' => (float) $r['speed_sum'], 'speedPasses' => (int) $r['speed_passes'],
                'bins' => \is_array($bins) && self::SPEED_BINS === \count($bins) ? array_map(intval(...), array_values($bins)) : array_fill(0, self::SPEED_BINS, 0),
                'lines' => (int) $r['lines'], 'days' => (int) $r['days'],
            ];
        }
    }
}
