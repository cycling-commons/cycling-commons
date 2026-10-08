<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * P · Scenic views and Q · History & culture: what a place IS. Every OSM tag
 * we harvest converts to exactly one kind, so what we add can go back to OSM
 * as that tag. An own kind has no OSM tag: a nice road through a forest
 * lives here only.
 *
 * The kind is the stored Type (`attributes.type`), the coverage point's
 * `kind`, and the map glyph (KindIcons). Order matters: a point carrying two
 * tags is the first kind in this list, as in the tiles' `t` label.
 *
 * @see docs/specs/osm-data-architecture.md §5a
 *
 * @api
 */
final class PlaceKind
{
    /** @var list<string> The letters whose kinds are drawn as map glyphs (KindIcons) and asked of a Scout tag. */
    public const array LETTERS = ['G', 'P', 'Q'];

    /**
     * Every letter with a kind Type. O · Where to sleep has one, but no map
     * glyphs yet: its icons stay on the ballot (StayKind, owner 2026-10-03).
     *
     * @var list<string>
     */
    public const array TYPED_LETTERS = ['G', 'O', 'P', 'Q'];

    /**
     * Letter → kind → [OSM tag or null, English label (its own translation key), harvested].
     * Not harvested: a kind for our own places only. A null tag: no OSM tag names it.
     *
     * @var array<string, array<string, array{?string, string, bool}>>
     */
    private const array KINDS = [
        // Shelters: OSM's shelter_type values, in the contract's order (owner 2026-10-08).
        'G' => [
            'basic_hut' => ['shelter_type=basic_hut', 'Basic hut', true],
            'dugout' => ['shelter_type=dugout', 'Dugout', true],
            'field_shelter' => ['shelter_type=field_shelter', 'Field shelter', true],
            'gazebo' => ['shelter_type=gazebo', 'Gazebo', true],
            'lean_to' => ['shelter_type=lean_to', 'Lean-to', true],
            'pavilion' => ['shelter_type=pavilion', 'Pavilion', true],
            'picnic_shelter' => ['shelter_type=picnic_shelter', 'Picnic shelter', true],
            'rock_shelter' => ['shelter_type=rock_shelter', 'Rock shelter', true],
            'sun_shelter' => ['shelter_type=sun_shelter', 'Sun shelter', true],
            'weather_shelter' => ['shelter_type=weather_shelter', 'Weather shelter', true],
            'wildlife_hide' => ['shelter_type=wildlife_hide', 'Wildlife hide', true],
            // Not harvested: a bus stop is everywhere, a defibrillator is ours to add.
            'bus_shelter' => ['shelter_type=public_transport', 'Bus shelter', false],
            'defibrillator' => ['emergency=defibrillator', 'Defibrillator', false],
        ],
        'O' => [
            // One type per OSM tag, its label naming the words riders know it by
            // (owner 2026-10-08): a B&B is a guest house in OSM, a gîte a chalet.
            'hotel' => ['tourism=hotel', 'Hotel', true],
            'motel' => ['tourism=motel', 'Motel', true],
            'guest_house' => ['tourism=guest_house', 'Guest house / B&B', true],
            'apartment' => ['tourism=apartment', 'Holiday rental', false],
            'hostel' => ['tourism=hostel', 'Hostel', true],
            'camp' => ['tourism=camp_site', 'Campsite', true],
            'chalet' => ['tourism=chalet', 'Chalet / gîte', true],
            'alpine_hut' => ['tourism=alpine_hut', 'Mountain hut', true],
            'wilderness_hut' => ['tourism=wilderness_hut', 'Wilderness hut', true],
        ],
        'P' => [
            'viewpoint' => ['tourism=viewpoint', 'Viewpoint', true],
            // A peak's point is its summit, where no rider is (docs/specs/scenic-views.md rule 1): our own places only.
            'peak' => ['natural=peak', 'Peak', false],
            'waterfall' => ['waterway=waterfall', 'Waterfall', true],
            'rapids' => ['waterway=rapids', 'Rapids', true],
            'cliff' => ['natural=cliff', 'Cliff', true],
            'cave' => ['natural=cave_entrance', 'Cave entrance', true],
            'arch' => ['natural=arch', 'Rock arch', true],
            'rock' => ['natural=rock', 'Rock', true],
            'stone' => ['natural=stone', 'Boulder', true],
            // A beautiful stretch to ride, a forest or a valley: ours only.
            'nature' => [null, 'Natural feature', false],
        ],
        'Q' => [
            'castle' => ['historic=castle', 'Castle', true],
            'fort' => ['historic=fort', 'Fort', true],
            'ruins' => ['historic=ruins', 'Ruins', true],
            'monument' => ['historic=monument', 'Monument', true],
            'memorial' => ['historic=memorial', 'Memorial', true],
            'archaeological' => ['historic=archaeological_site', 'Archaeological site', true],
            'manor' => ['historic=manor', 'Manor', true],
            'monastery' => ['historic=monastery', 'Monastery', true],
            // Left out of the harvest (edit-items/Q-history-culture.md): our own places only.
            'museum' => ['tourism=museum', 'Museum', false],
            'worship' => ['amenity=place_of_worship', 'Place of worship', false],
            'heritage' => [null, 'Heritage site', false],
            'architecture' => [null, 'Architecture', false],
        ],
    ];

    /**
     * Type labels used before kinds, where one label is one kind. A label
     * that names several OSM tags is absent, so a curator decides: Viewpoint
     * / high point (a viewpoint or a peak), Natural feature, Heritage site,
     * Religious site (a monastery or a place of worship), Architecture.
     *
     * @var array<string, array<string, string>>
     */
    private const array LEGACY_LABELS = [
        'G' => ['Picnic hut' => 'picnic_shelter'],
        'O' => [
            'Guest house' => 'guest_house', 'B&B' => 'guest_house', 'Gîte / guesthouse' => 'guest_house',
            'Chalet' => 'chalet', 'Gîte' => 'chalet',
            'Budget stay' => 'hostel', 'Furnished rental' => 'apartment',
        ],
        'Q' => ['Museum / culture' => 'museum'],
    ];

    /** @return array<string, string> kind → English label, in list order */
    public static function labels(string $letter): array
    {
        return array_map(static fn (array $k): string => $k[1], self::KINDS[$letter] ?? []);
    }

    public static function osmTag(string $letter, string $kind): ?string
    {
        return self::KINDS[$letter][$kind][0] ?? null;
    }

    public static function label(string $letter, string $kind): ?string
    {
        return self::KINDS[$letter][$kind][1] ?? null;
    }

    /** @param array<string, mixed> $tags raw OSM tags */
    public static function fromOsmTags(string $letter, array $tags): ?string
    {
        foreach (self::KINDS[$letter] ?? [] as $kind => [$tag]) {
            if (null === $tag) {
                continue;
            }
            [$key, $value] = explode('=', $tag, 2);
            if (($tags[$key] ?? null) === $value) {
                return $kind;
            }
        }

        return null;
    }

    /** A kind from an English label: the kind's own, or an exact older Type. */
    public static function fromLabel(string $letter, ?string $label): ?string
    {
        if (null === $label) {
            return null;
        }
        $kind = array_search($label, self::labels($letter), true);

        return false !== $kind ? $kind : (self::LEGACY_LABELS[$letter][$label] ?? null);
    }

    /**
     * The harvest's rules, `key=value` → kind; PHP copy of the contract's
     * `placeKind` so CoverageContractTest can pin it.
     *
     * @return array<string, string>
     */
    public static function harvestRules(string $letter): array
    {
        $rules = [];
        foreach (self::KINDS[$letter] ?? [] as $kind => [$tag, , $harvested]) {
            // A harvested kind always has its tag (PlaceKindTest).
            if ($harvested) {
                $rules[(string) $tag] = $kind;
            }
        }

        return $rules;
    }
}
