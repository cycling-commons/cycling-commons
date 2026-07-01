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
    public function testCatalogHasElevenEditableTypes(): void
    {
        // A–K are the editable catalog types (L · Ride heatmap is a derived
        // overlay, intentionally not editable — README.md).
        self::assertCount(11, ItemType::cases());
    }

    public function testLettersAreUniqueAndCoverAtoK(): void
    {
        $letters = array_map(static fn (ItemType $t): string => $t->letter(), ItemType::cases());

        self::assertSame(range('A', 'K'), $letters);
        self::assertCount(11, array_unique($letters));
    }

    public function testSlugsAreUnique(): void
    {
        $slugs = array_map(static fn (ItemType $t): string => $t->value, ItemType::cases());

        self::assertCount(11, array_unique($slugs));
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
}
