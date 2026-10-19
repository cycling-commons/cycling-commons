<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

use Doctrine\DBAL\Connection;

/**
 * The plain felt-safety counts (docs/specs/traffic-measurements.md §10).
 *
 * A row counts the safe and the unsafe marks of one road, part of the day,
 * day type and quarter. No rider is in it: marks reach it only from the
 * waiting room (SafetyPool), a block at a time. A row keeps the label and
 * region of its first mark.
 *
 * @api
 */
final class SafetyStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Adds marks to their rows, one statement per row, in key order: a block
     * of marks touches its rows in one order whoever releases it.
     *
     * @param list<array<string, mixed>> $marks validated marks (SafetyMark)
     */
    public function addToTotals(array $marks): void
    {
        $rows = [];
        foreach ($marks as $m) {
            $key = $m['way'].'|'.$m['band'].'|'.$m['dayType'].'|'.$m['quarter'];
            $rows[$key] ??= ['mark' => $m, 'safe' => 0, 'unsafe' => 0];
            ++$rows[$key]['safe' === $m['felt'] ? 'safe' : 'unsafe'];
        }
        ksort($rows, \SORT_STRING);
        foreach ($rows as $r) {
            $m = $r['mark'];
            $this->db->executeStatement('INSERT INTO safety_total (way, band, day_type, quarter, label, region, safe, unsafe) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (way, band, day_type, quarter) DO UPDATE SET region = COALESCE(safety_total.region, EXCLUDED.region),
                    safe = safety_total.safe + EXCLUDED.safe, unsafe = safety_total.unsafe + EXCLUDED.unsafe',
                [$m['way'], $m['band'], $m['dayType'], $m['quarter'], $m['label'], $m['region'] ?? null, $r['safe'], $r['unsafe']]);
        }
    }

    /**
     * Every counts row.
     *
     * @return iterable<array{way: int, band: int, dayType: string, quarter: string, label: string, region: int|null, safe: int, unsafe: int}>
     */
    public function totals(): iterable
    {
        foreach ($this->db->iterateAssociative('SELECT * FROM safety_total ORDER BY way, band, day_type, quarter') as $r) {
            yield [
                'way' => (int) $r['way'], 'band' => (int) $r['band'], 'dayType' => (string) $r['day_type'], 'quarter' => (string) $r['quarter'],
                'label' => (string) $r['label'], 'region' => null === $r['region'] ? null : (int) $r['region'],
                'safe' => (int) $r['safe'], 'unsafe' => (int) $r['unsafe'],
            ];
        }
    }
}
