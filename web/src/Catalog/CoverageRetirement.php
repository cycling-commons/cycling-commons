<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Retirement predicate: imported OSM, unverified, zero human touches. Shared by payload exclusion, refs mirror, and the DELETE command — do not fork the SQL.
 *
 * @see docs/specs/coverage-provider.md §9
 */
final class CoverageRetirement
{
    /**
     * Point-POI letters the coverage artifact serves. A (not in the artifact) and N (wikidata) must never match.
     *
     * @see docs/specs/coverage-provider.md §7
     */
    public const array LETTERS = ['B', 'D', 'F', 'G', 'O', 'P', 'Q'];

    /** SQL tuple of LETTERS for `letter IN (...)` guards. */
    public static function lettersSqlTuple(): string
    {
        return "('".implode("', '", self::LETTERS)."')";
    }

    /**
     * TRUE for a row the coverage cache now serves. Callers compose the letter scope themselves.
     */
    public static function untouchedOsmSql(string $alias): string
    {
        return sprintf(
            "%s.source = 'osm' AND %s.state = 'unverified'
                AND NOT EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = %s.id)
                AND NOT EXISTS (SELECT 1 FROM item_confirmation ic WHERE ic.item_id = %s.id)
                AND NOT EXISTS (SELECT 1 FROM submission sb WHERE sb.item_id = %s.id)",
            $alias,
            $alias,
            $alias,
            $alias,
            $alias,
        );
    }
}
