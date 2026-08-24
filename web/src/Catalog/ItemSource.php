<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Provenance of a catalog row. `Manual` is seeded/hand-authored and never upserted by harvest import.
 *
 * @see docs/specs/catalog-data-model.md §5
 *
 * @api
 */
enum ItemSource: string
{
    case Osm = 'osm';
    case Pivot = 'pivot';
    case Wikidata = 'wikidata';
    case User = 'user';
    /** How it arrived, not verification — the server never saw the ride file. docs/specs/moderation-and-contribution.md (Scout intake) */
    case Scout = 'scout';
    case Manual = 'manual';
    case Auto = 'auto';

    /**
     * Which row to keep when two describe the same place (higher wins).
     *
     * Used ONLY by the duplicate guard (`App\Catalog\Import\DuplicateGuard`) to
     * pick a keeper. It is not a quality score and says nothing about a row's
     * lifecycle state: an `unverified` manual pin still outranks a `verified`
     * OSM one for this one question, because the question is "which of these
     * two records of one place is ours to keep", not "which is better".
     *
     * The order:
     *  - `manual` is hand-authored by us and never upserted by a harvest
     *    (catalog-data-model.md §5), so nothing may displace it.
     *  - `user` and `scout` came from a person who was there, and a human has
     *    already spent moderation time on them.
     *  - `pivot` is canonical for accommodation and carries its own CC-BY
     *    attribution (coverage-provider.md). Losing a PIVOT row to an OSM row
     *    that imported first is the exact bug this ordering fixes.
     *  - `wikidata` is a reviewed harvest artifact; `osm` is the raw one.
     *  - `auto` is machine-generated and delete-and-replaced wholesale
     *    (catalog-data-model.md §4), so it never wins anything.
     */
    public function dedupeRank(): int
    {
        return match ($this) {
            self::Manual => 60,
            self::User => 50,
            self::Scout => 40,
            self::Pivot => 30,
            self::Wikidata => 20,
            self::Osm => 10,
            self::Auto => 0,
        };
    }
}
