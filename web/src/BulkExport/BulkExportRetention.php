<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\BulkExport;

/**
 * Which bulk export snapshots stay (docs/specs/api-strategy.md §3.1).
 *
 * The newest {@see RECENT} snapshots, and the first snapshot of every
 * calendar month (UTC), for good: a monthly archive that stays small, because
 * every kept snapshot is one more copy a takedown has to reach.
 *
 * Stamps sort as their build time, so "first of a month" is the smallest
 * stamp with that `YYYYMM` prefix.
 */
final class BulkExportRetention
{
    /** How many of the newest snapshots stay, whatever their month. */
    public const int RECENT = 4;

    /**
     * @param list<string> $published the stamps that hold a manifest, oldest first
     *
     * @return list<string> the stamps to keep, oldest first
     */
    public static function kept(array $published): array
    {
        $keep = \array_slice($published, -self::RECENT);
        foreach (self::firsts($published) as $stamp) {
            $keep[] = $stamp;
        }
        $keep = array_values(array_unique($keep));
        sort($keep, \SORT_STRING);

        return $keep;
    }

    /** @param list<string> $published */
    public static function isMonthlyFirst(string $stamp, array $published): bool
    {
        return \in_array($stamp, self::firsts($published), true);
    }

    /**
     * @param list<string> $published
     *
     * @return array<string, string> month (`YYYYMM`) => its first stamp
     */
    private static function firsts(array $published): array
    {
        $firsts = [];
        foreach ($published as $stamp) {
            $month = substr($stamp, 0, 6);
            if (!isset($firsts[$month]) || $stamp < $firsts[$month]) {
                $firsts[$month] = $stamp;
            }
        }

        return $firsts;
    }
}
