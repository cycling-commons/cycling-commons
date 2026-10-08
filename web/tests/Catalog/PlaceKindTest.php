<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogFormRegistry;
use App\Catalog\ItemType;
use App\Catalog\PlaceKind;
use PHPUnit\Framework\TestCase;

/**
 * Every OSM tag we show converts to exactly one P or Q kind, so what we add
 * can go back to OSM. Our own kinds may have no OSM tag
 * (docs/specs/osm-data-architecture.md §5a).
 */
final class PlaceKindTest extends TestCase
{
    public function testEveryOsmTagIsOneKindAndOwnKindsHaveNone(): void
    {
        $tags = [];
        foreach (PlaceKind::TYPED_LETTERS as $letter) {
            self::assertNotEmpty(PlaceKind::labels($letter));
            foreach (array_keys(PlaceKind::labels($letter)) as $kind) {
                self::assertLessThanOrEqual(16, \strlen($kind), 'coverage_poi.kind is varchar(16)');
                $tag = PlaceKind::osmTag($letter, $kind);
                if (null === $tag) {
                    self::assertNotContains($kind, PlaceKind::harvestRules($letter), "$kind is ours only and never harvested");
                    continue;
                }
                self::assertMatchesRegularExpression('/^[a-z_:]+=[a-z_]+$/', $tag);
                $tags[] = $tag;
            }
        }
        self::assertSame($tags, array_values(array_unique($tags)), 'no OSM tag stands for two kinds');
        self::assertNull(PlaceKind::osmTag('P', 'nature'), 'a nice forest road is ours only');
    }

    public function testOsmTagsGiveTheKind(): void
    {
        self::assertSame('waterfall', PlaceKind::fromOsmTags('P', ['waterway' => 'waterfall', 'name' => 'Cascade de Coo']));
        self::assertSame('peak', PlaceKind::fromOsmTags('P', ['natural' => 'peak']));
        self::assertSame('museum', PlaceKind::fromOsmTags('Q', ['tourism' => 'museum']));
        self::assertNull(PlaceKind::fromOsmTags('P', ['historic' => 'castle']), 'a castle is not a scenic kind');
        // Contract order: the first matching rule wins, as in the tiles' label.
        self::assertSame('castle', PlaceKind::fromOsmTags('Q', ['historic' => 'castle', 'tourism' => 'museum']));
    }

    public function testOldLabelsMapOnlyWhereTheyAreExact(): void
    {
        self::assertSame('viewpoint', PlaceKind::fromLabel('P', 'Viewpoint'));
        self::assertSame('castle', PlaceKind::fromLabel('Q', 'Castle'));
        self::assertSame('museum', PlaceKind::fromLabel('Q', 'Museum / culture'));
        self::assertSame('monument', PlaceKind::fromLabel('Q', 'Monument'));
        self::assertNull(PlaceKind::fromLabel('P', 'Viewpoint / high point'), 'a viewpoint or a peak');
        self::assertNull(PlaceKind::fromLabel('Q', 'Religious site'), 'a monastery or a place of worship');
        self::assertSame('nature', PlaceKind::fromLabel('P', 'Natural feature'), 'an own kind keeps its label');
        self::assertSame('heritage', PlaceKind::fromLabel('Q', 'Heritage site'));
        self::assertSame('architecture', PlaceKind::fromLabel('Q', 'Architecture'));
    }

    public function testTheTypeFieldIsTheKindList(): void
    {
        $registry = new CatalogFormRegistry();
        foreach ([ItemType::ScenicViews, ItemType::HistoryCulture, ItemType::WhereToSleep, ItemType::Shelter] as $type) {
            $field = null;
            foreach ($registry->for($type)->all() as $f) {
                if ('type' === $f->name) {
                    $field = $f;
                }
            }
            self::assertNotNull($field);
            // Name, then Type, on every form (owner 2026-10-08). The form adds the
            // name itself when the field set has none, so: the first field after it.
            $names = array_values(array_filter(array_map(static fn ($f) => $f->name, $registry->for($type)->all()), static fn (string $n): bool => 'name' !== $n));
            self::assertSame('type', $names[0], $type->letter().': Type is the second field');
            self::assertSame(array_keys(PlaceKind::labels($type->letter())), $field->choices, 'stored values are kinds');
            self::assertSame(PlaceKind::labels($type->letter()), $field->choiceLabels, 'shown as their labels');
        }
    }

    public function testAPlaceToSleepHasATypeEveryOsmTagConvertsTo(): void
    {
        // An OSM hotel converts to our hotel. Every stay type is one OSM tag:
        // a B&B is a guest house, a gîte a chalet, one type with both words.
        self::assertSame('hotel', PlaceKind::fromOsmTags('O', ['tourism' => 'hotel']));
        self::assertSame('camp', PlaceKind::fromOsmTags('O', ['tourism' => 'camp_site']));
        foreach (array_keys(PlaceKind::labels('O')) as $kind) {
            self::assertNotNull(PlaceKind::osmTag('O', $kind), "$kind goes back to OSM");
        }
        self::assertSame('Guest house / B&B', PlaceKind::label('O', 'guest_house'));
        self::assertSame('guest_house', PlaceKind::fromLabel('O', 'B&B'), 'the authority label');
        self::assertSame('guest_house', PlaceKind::fromLabel('O', 'Guest house'), 'the tile label');
        self::assertSame('chalet', PlaceKind::fromLabel('O', 'Gîte'));
        self::assertSame('hostel', PlaceKind::fromLabel('O', 'Budget stay'));
        self::assertSame('apartment', PlaceKind::fromLabel('O', 'Furnished rental'));
        self::assertSame('camp', PlaceKind::fromLabel('O', 'Campsite'));
        self::assertNotContains('O', PlaceKind::LETTERS, 'no stay glyphs on the map yet (StayKind)');
        self::assertContains('O', PlaceKind::TYPED_LETTERS);
    }

    public function testAShelterHasTheTypeItsOsmTagNames(): void
    {
        // A picnic shelter in OSM is a picnic shelter here, with its own glyph (owner 2026-10-08).
        self::assertSame('picnic_shelter', PlaceKind::fromOsmTags('G', ['shelter_type' => 'picnic_shelter']));
        self::assertSame('Picnic shelter', PlaceKind::label('G', 'picnic_shelter'));
        self::assertSame('picnic_shelter', PlaceKind::fromLabel('G', 'Picnic hut'), 'the old Type');
        self::assertSame('defibrillator', PlaceKind::fromOsmTags('G', ['emergency' => 'defibrillator']));
        self::assertArrayNotHasKey('shelter_type=public_transport', PlaceKind::harvestRules('G'), 'bus stops stay out of the harvest');
        self::assertContains('G', PlaceKind::LETTERS, 'shelters draw their kind on the map');
    }

    public function testHarvestRulesCoverEveryHarvestedKind(): void
    {
        self::assertSame('viewpoint', PlaceKind::harvestRules('P')['tourism=viewpoint']);
        self::assertArrayNotHasKey('amenity=place_of_worship', PlaceKind::harvestRules('Q'), 'every church in Europe is too many pins');
        self::assertArrayNotHasKey('natural=peak', PlaceKind::harvestRules('P'), 'a summit is where no rider is (scenic-views.md rule 1)');
        self::assertArrayNotHasKey('tourism=museum', PlaceKind::harvestRules('Q'), 'left out of the harvest (Q-history-culture.md)');
        self::assertSame('worship', PlaceKind::fromOsmTags('Q', ['amenity' => 'place_of_worship']), 'still a kind for our own places');
    }
}
