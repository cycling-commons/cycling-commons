<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\ItemType;
use App\Catalog\LocationMode;
use PHPUnit\Framework\TestCase;

/**
 * The lettered catalog (practical A-M, experiential N-Z) is the single source of truth for the per-type item forms.
 * Design source: docs/specs/edit-items/<LETTER>-*.md + README.md.
 */
final class ItemTypeTest extends TestCase
{
    public function testCatalogHasTwelveEditableTypes(): void
    {
        // Practical A-G plus experiential N-R are the editable catalog
        // types (2026-08-25 renumbering). The ride heatmap is a derived
        // overlay without a letter, intentionally not editable (README.md).
        self::assertCount(12, ItemType::cases());
    }

    public function testLettersAreUniqueAndCoverAtoGPlusNtoR(): void
    {
        $letters = array_map(static fn (ItemType $t): string => $t->letter(), ItemType::cases());

        sort($letters);
        self::assertSame([...range('A', 'G'), ...range('N', 'R')], $letters);
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
        // The catalogue letter is the identifier the map layers carry (layer.letter),
        // so links can deep-link by letter too — case-insensitively.
        self::assertSame(ItemType::WhereToSleep, ItemType::fromParam('O'));
        self::assertSame(ItemType::WhereToSleep, ItemType::fromParam('o'));
        self::assertSame(ItemType::QualityRides, ItemType::fromParam('R'));
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
        // README funnel table: votable = N, O, P, Q, R; utility = A, B, C, D, E, F, G.
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

    public function testEveryPlaceARiderCanStandNextToIsConfirmable(): void
    {
        /* The first cut of this excluded climbs — "a mountain does not go
           anywhere" — and the owner pointed at a castle, which does not go
           anywhere either and was confirmable (2026-08-12). "Could it vanish"
           is the wrong test.

           A confirmation is a rider saying *I was there and this is right*:
           that the place exists, that it is where we say, that it is what we
           call it. A climb can be wrong about all three. */
        foreach (ItemType::cases() as $type) {
            if (ItemType::QualityRides === $type) {
                continue;   // a ride is voted on, never confirmed
            }
            self::assertTrue($type->isConfirmable(), $type->value.' should be confirmable');
        }
        // A stretch joined 2026-08-13: a rider who rode it is exactly who can
        // vouch it is as described — the drawer said "not confirmed yet" to a
        // second rider with no way to answer (owner-reported).
        self::assertTrue(ItemType::RoadSurface->isConfirmable());
    }

    public function testConfirmingAndVotingAreDifferentQuestions(): void
    {
        // Voting ranks a region's best; confirming vouches for the entry. Most
        // letters want both, and one never implies the other.
        self::assertTrue(ItemType::Climbs->isVotable());
        self::assertTrue(ItemType::Climbs->isConfirmable());
        self::assertFalse(ItemType::WaterFood->isVotable(), 'completeness, not quality');
        self::assertTrue(ItemType::WaterFood->isConfirmable());
    }

    /** Owner 2026-08-25: one icon set for the whole system, letter-keyed; since 2026-09-09 every type draws its own path ("no coloured icons"). */
    public function testIconSetCoversEveryTypeOnce(): void
    {
        $set = ItemType::iconSet();
        self::assertCount(\count(ItemType::cases()), $set);
        foreach (ItemType::cases() as $t) {
            self::assertArrayHasKey($t->letter(), $set);
            self::assertNotSame('', $set[$t->letter()]['glyph']);
        }
        self::assertNotNull($set['N']['svg'], 'climbs draw a mountain');
        self::assertNotNull($set['P']['svg'], 'scenic views draw a camera');
        self::assertNotNull($set['C']['svg'], 'toilets draw their sign');
        self::assertNotNull($set['B']['svg'], 'water draws a drop');
        self::assertNotNull($set['R']['svg'], 'rides draw a route across the land');
        foreach ($set as $letter => $icon) {
            self::assertNotNull($icon['svg'], sprintf('%s draws its own icon; no emoji on the map or the page', $letter));
        }
        self::assertSame('📷', ItemType::ScenicViews->icon(), 'the map and the server agree on the camera');
    }
}
