<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\ItemType;
use App\Catalog\LocationMode;
use PHPUnit\Framework\TestCase;

/**
 * The A–K catalog is the single source of truth for the per-type item forms.
 * Design source: docs/specs/edit-items/<LETTER>-*.md + README.md.
 */
final class ItemTypeTest extends TestCase
{
    public function testCatalogHasTwelveEditableTypes(): void
    {
        // A–K plus M · Public toilets (2026-07-30) are the editable catalog
        // types. L · Ride heatmap is a derived overlay, intentionally not
        // editable (README.md), which is why M skips over it.
        self::assertCount(12, ItemType::cases());
    }

    public function testLettersAreUniqueAndCoverAtoKPlusM(): void
    {
        $letters = array_map(static fn (ItemType $t): string => $t->letter(), ItemType::cases());

        self::assertSame([...range('A', 'K'), 'M'], $letters);
        self::assertCount(12, array_unique($letters));
    }

    public function testSlugsAreUnique(): void
    {
        $slugs = array_map(static fn (ItemType $t): string => $t->value, ItemType::cases());

        self::assertCount(12, array_unique($slugs));
    }

    public function testFromParamResolvesCanonicalSlug(): void
    {
        self::assertSame(ItemType::WaterFood, ItemType::fromParam('water-food'));
        self::assertSame(ItemType::RoadSurface, ItemType::fromParam('road-surface'));
    }

    public function testFromParamResolvesCatalogLetter(): void
    {
        // The A–K letter is the identifier the map layers carry (layer.letter),
        // so links can deep-link by letter too — case-insensitively.
        self::assertSame(ItemType::WhereToSleep, ItemType::fromParam('E'));
        self::assertSame(ItemType::WhereToSleep, ItemType::fromParam('e'));
        self::assertSame(ItemType::QualityRides, ItemType::fromParam('K'));
    }

    public function testFromParamFallsBackToDefaultForUnknown(): void
    {
        // The demo falls back to the service station (D · bike services) for a
        // bare improve page with no/unknown item — edit-item-and-profile §3.1.
        self::assertSame(ItemType::BikeServices, ItemType::fromParam('nope-not-a-type'));
        self::assertSame(ItemType::BikeServices, ItemType::fromParam(''));
        self::assertSame(ItemType::BikeServices, ItemType::fromParam(null));
        self::assertSame(ItemType::BikeServices, ItemType::default());
    }

    public function testLocationModePerType(): void
    {
        // Road surface is a drawn segment; a quality ride's GPX/FIT track sets
        // the whole route (no pin); everything else drops a single point.
        self::assertSame(LocationMode::Segment, ItemType::RoadSurface->locationMode());
        self::assertSame(LocationMode::None, ItemType::QualityRides->locationMode());
        self::assertSame(LocationMode::Point, ItemType::WaterFood->locationMode());
        self::assertSame(LocationMode::Point, ItemType::Climbs->locationMode());
    }

    public function testVotableTypesMatchTheFunnelTable(): void
    {
        // README funnel table: votable = B, E, I, J, K; utility = A, C, D, F, G, H.
        $votable = array_values(array_filter(
            ItemType::cases(),
            static fn (ItemType $t): bool => $t->isVotable(),
        ));

        self::assertSame(
            [
                ItemType::Climbs,
                ItemType::WhereToSleep,
                ItemType::ScenicViews,
                ItemType::HistoryCulture,
                ItemType::QualityRides,
            ],
            $votable,
        );
    }

    public function testOnlyRidesOfferATrackUpload(): void
    {
        self::assertTrue(ItemType::QualityRides->hasTrackUpload());
        self::assertFalse(ItemType::WaterFood->hasTrackUpload());
        self::assertFalse(ItemType::Climbs->hasTrackUpload());
    }

    public function testAPlaceThatCanBeGoneCanBeConfirmed(): void
    {
        /* "Is it still here?" is not a question only utilities can be asked
           (owner 2026-08-12: a second rider could do nothing at a viewpoint).
           A view gets built out, a monument fenced off, a gîte closed — and the
           rider standing there is the only one who knows.

           Confirming is not voting. Voting ranks a region's best; confirming
           says the place still exists, and a letter can want both. */
        foreach ([ItemType::ScenicViews, ItemType::HistoryCulture, ItemType::WhereToSleep] as $type) {
            self::assertTrue($type->isConfirmable(), $type->value.' is a place that can vanish');
            self::assertTrue($type->isVotable(), 'and one worth ranking — both, not either');
        }
    }

    public function testAMountainIsNotConfirmable(): void
    {
        // A climb does not go anywhere. Offering "still here?" on one would be
        // asking a question with only one possible answer.
        self::assertFalse(ItemType::Climbs->isConfirmable());
        self::assertTrue(ItemType::Climbs->isVotable());
    }
}
