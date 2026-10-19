<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

/**
 * The rule before any felt safety is shown (docs/specs/traffic-measurements.md
 * §10).
 *
 * A group is the counts rows of one road under the active grouping scheme of
 * the measured traffic (TrafficDisclosure::groupOf()), so a road's felt
 * safety and its measured traffic line up. It is shown only with MIN_MARKS
 * marks, and only as a band: safe when at least two thirds felt safe, unsafe
 * when at least two thirds felt unsafe, mixed between. Below the rule the
 * group does not appear at all.
 *
 * Public copy never states these numbers.
 *
 * @api
 */
final class SafetyDisclosure
{
    public const int MIN_MARKS = 5;

    /**
     * @param iterable<array<string, mixed>> $rows counts rows (SafetyStore::totals())
     *
     * @return list<array{way: int, group: string, felt: string}>
     */
    public function evaluate(iterable $rows, string $scheme, string $sinceQuarter): array
    {
        if (!\in_array($scheme, TrafficDisclosure::SCHEMES, true)) {
            $scheme = 'daytype';
        }

        $groups = [];
        foreach ($rows as $row) {
            if (strcmp((string) $row['quarter'], $sinceQuarter) < 0) {
                continue;
            }
            $group = TrafficDisclosure::groupOf($row, $scheme);
            $id = $row['way'].'|'.$group;
            $groups[$id] ??= ['way' => (int) $row['way'], 'group' => $group, 'safe' => 0, 'unsafe' => 0];
            $groups[$id]['safe'] += (int) $row['safe'];
            $groups[$id]['unsafe'] += (int) $row['unsafe'];
        }

        $shown = [];
        foreach ($groups as $g) {
            $marks = $g['safe'] + $g['unsafe'];
            if ($marks < self::MIN_MARKS) {
                continue;
            }
            $shown[] = ['way' => $g['way'], 'group' => $g['group'], 'felt' => self::band($g['safe'], $g['unsafe'])];
        }

        return $shown;
    }

    /** Safe or unsafe from two thirds of the marks, mixed between. */
    public static function band(int $safe, int $unsafe): string
    {
        $marks = $safe + $unsafe;

        return 3 * $safe >= 2 * $marks ? 'safe' : (3 * $unsafe >= 2 * $marks ? 'unsafe' : 'mixed');
    }
}
