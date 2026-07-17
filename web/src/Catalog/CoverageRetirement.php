<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Single owner of the coverage-retirement predicate (coverage-provider.md
 * §9): an item row the coverage cache serves — imported OSM, unverified,
 * and ZERO human touches (no change_history, no item_confirmation, no
 * submission referencing it). Anything a human ever touched stays canonical.
 *
 * Three call sites consume it and MUST stay in sync — that is why the SQL
 * lives here and nowhere else (drift on a DELETE predicate is the failure
 * mode this class exists to prevent):
 *  - CatalogProvider::itemRows() — the unconditional payload exclusion
 *    (coverage-provider.md §8),
 *  - CatalogProvider::curatedRefs() — the refs mirror
 *    (coverage-provider.md §6),
 *  - RetireLegacyOsmCommand — the owner-gated DELETE
 *    (coverage-provider.md §9).
 *
 * LETTERS scopes all three call sites' letter guards: A (road surface)
 * never entered the coverage artifact (coverage-provider.md §7) and B
 * (climbs) is wikidata-sourced — neither may ever match retirement.
 */
final class CoverageRetirement
{
    /**
     * The point-POI letters the coverage artifact serves
     * (coverage-provider.md §7) — the only letters retirement may touch.
     */
    public const array LETTERS = ['C', 'D', 'E', 'G', 'H', 'I', 'J'];

    /** SQL tuple of LETTERS for `letter IN (...)` guards. */
    public static function lettersSqlTuple(): string
    {
        return "('".implode("', '", self::LETTERS)."')";
    }

    /**
     * The touch-predicate: TRUE for a row the coverage cache now serves.
     * Callers compose the letter scope themselves (the command's hard guard,
     * curatedRefs()'s mirror scope); $alias is the `item` table's alias in
     * the caller's query.
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
