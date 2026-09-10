<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Provenance of a catalog row. `Manual` is seeded/hand-authored and never upserted by harvest import.
 *
 * @see docs/specs/catalog-data-model.md §5
 * @see docs/specs/data-provider-hierarchy.md §2
 *
 * @api
 */
enum ItemSource: string
{
    case Osm = 'osm';
    /**
     * A dataset whose publisher is the body of record for the thing mapped.
     *
     * Named for the rank it earns, not for a member of the bucket: it was
     * `pivot` until 2026-09-04, after the first such dataset we ingested
     * (Geoportail Wallonie), which said nothing about what the value meant.
     * Which authority a row came from is `item.provider`, a row in
     * `data_provider` ({@see \App\Provider\Entity\DataProvider}).
     */
    case Authority = 'authority';
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
     *  - `authority` is a publisher of record and carries its own licence
     *    and attribution (data-provider-hierarchy.md §2). Losing such a row
     *    to an OSM row that imported first is the exact bug this ordering
     *    fixes. Its registry row also carries a `rank`, which will replace
     *    the single step here once more than one authority exists
     *    (data-provider-hierarchy.md §4); until then every authority sits on
     *    the one rung `pivot` sat on, so nothing moves.
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
            self::Authority => 30,
            self::Wikidata => 20,
            self::Osm => 10,
            self::Auto => 0,
        };
    }
}
