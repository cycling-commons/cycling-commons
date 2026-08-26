<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\ItemSource;
use PHPUnit\Framework\TestCase;

/**
 * `ItemSource::dedupeRank()` — which of two records of one place we keep.
 *
 * A bare list of enum cases would be a test worth deleting. These are not
 * that: each one pins a decision with a consequence, and the ordering is the
 * only thing standing between a canonical row and whichever harvest happened
 * to run first.
 *
 * @see docs/specs/catalog-data-model.md §5
 */
final class ItemSourceRankTest extends TestCase
{
    public function testPivotOutranksOsm(): void
    {
        // The live bug this ordering fixes: five PIVOT accommodation rows and
        // five OSM rows for the same five buildings. PIVOT is canonical for O
        // and carries its own CC-BY attribution, so losing it to an OSM row
        // that imported first would drop the attribution with it.
        self::assertGreaterThan(ItemSource::Osm->dedupeRank(), ItemSource::Pivot->dedupeRank());
    }

    public function testWikidataOutranksOsm(): void
    {
        // A reviewed harvest artifact beats the raw one.
        self::assertGreaterThan(ItemSource::Osm->dedupeRank(), ItemSource::Wikidata->dedupeRank());
    }

    public function testManualOutranksEveryHarvest(): void
    {
        // Hand-authored by us and never upserted by a harvest
        // (catalog-data-model.md §5). Nothing may displace it.
        foreach ([ItemSource::Osm, ItemSource::Pivot, ItemSource::Wikidata, ItemSource::Scout, ItemSource::User, ItemSource::Auto] as $other) {
            self::assertGreaterThan(
                $other->dedupeRank(),
                ItemSource::Manual->dedupeRank(),
                $other->value.' outranks manual',
            );
        }
    }

    public function testRiderSourcesOutrankHarvests(): void
    {
        // Someone was there, and a moderator has already spent time on it.
        foreach ([ItemSource::User, ItemSource::Scout] as $rider) {
            self::assertGreaterThan(ItemSource::Pivot->dedupeRank(), $rider->dedupeRank());
        }
    }

    public function testAutoNeverWins(): void
    {
        // `auto` rows are machine-generated and delete-and-replaced wholesale
        // (catalog-data-model.md §4), so keeping one over anything else would
        // hand the place to a row that vanishes on the next import.
        foreach (ItemSource::cases() as $source) {
            if (ItemSource::Auto !== $source) {
                self::assertGreaterThan(ItemSource::Auto->dedupeRank(), $source->dedupeRank());
            }
        }
    }

    public function testEveryRankIsDistinct(): void
    {
        // A tie would make the keeper depend on row order, which is the
        // accident the ranking exists to remove.
        $ranks = array_map(static fn (ItemSource $s): int => $s->dedupeRank(), ItemSource::cases());

        self::assertCount(\count($ranks), array_unique($ranks));
    }
}
