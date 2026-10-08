<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * THE kind glyphs: what a pin IS within its category, one drawing per kind.
 *
 * The pin grammar (docs/specs/data-provider-hierarchy.md §6.3, ruled
 * 2026-09-04): kind lives in the glyph and is never compared across
 * categories; state is two shared badges; everything else is drawer content.
 * A non-potable tap is a different KIND of thing, not a broken one, which is
 * why potability is drawn here and not as a colour.
 *
 * One definition feeds three renderers: the map mints tile icons from it on a
 * canvas (`assets/map/icons.js`, via `window.CC_KIND_ICONS`), the DOM pins and
 * both legends render it as inline SVG (`partials/_kind_icon.html.twig`, via
 * `cc_kind_icons()`). Nothing else may draw a kind.
 *
 * A kind's strings are `legend.kind_<letter>_<kind>_h` (name) and `_t`
 * (one line), the letter lower-cased; both legends compose that key.
 *
 * A kind is either a list of `paths` in a 24-box (each `d` with a `fill`, an
 * optional `stroke` and stroke `width`) or a text `glyph`. Two colour tokens
 * are resolved by the renderer: `@cat` is the category's own colour and `@ink`
 * is the glyph colour that reads on it (dark on a light fill, white on a dark
 * one). Every other colour is literal, so the water blue exists once, here.
 */
final class KindIcons
{
    public const string CATEGORY_FILL = '@cat';
    public const string INK_ON_CATEGORY = '@ink';

    /** The water blue and its outline, the drop's own colours. */
    public const string WATER_BLUE = '#3E8FB0';
    public const string WATER_INK = '#0d2b3a';
    public const string PAPER = '#F3EBD8';
    /** The "not usable" red: the "!" state badge and a waiting pin's border (pins.css). */
    public const string NOT_USABLE_RED = '#D92D20';

    /** The drop, in the 24-box; the same curve the tiles drew in a 14×18 box. */
    private const string DROP = 'M12 1.3C20 11.1 17.6 22.7 12 22.7C6.4 22.7 4 11.1 12 1.3Z';
    /** A baseline disc, as miniIcon() draws every other coverage category. */
    private const string DISC = 'M12 2.5a9.5 9.5 0 1 1-.01 0Z';
    private const string FORK = 'M6.5 6h1v3.5h.6V6h1v3.5h.6V6h1v4.2a1.9 1.9 0 0 1-1.2 1.75V18H8.3v-6.05A1.9 1.9 0 0 1 6.5 10.2z';
    private const string KNIFE = 'M14.8 6c1.6 0 2.7 2.2 2.7 4.6 0 1.5-.6 2.5-1.4 2.9V18h-1.3v-4.5c-.5-.6-.9-1.6-.9-3.2C13.9 8 14.2 6 14.8 6z';
    /** The drop at 42%, tucked into the top-right of the disc. */
    private const string SMALL_DROP = 'M17.5.8C20.9 4.9 19.9 9.7 17.5 9.7C15.2 9.7 14.2 4.9 17.5.8Z';

    /**
     * Letter → kind → drawing. Only the categories whose pins carry a kind.
     *
     * @return array<string, array<string, array{paths?: list<array{d: string, fill: string, stroke?: string, width?: float}>, glyph?: string}>>
     */
    public static function set(): array
    {
        $drop = ['d' => self::DROP, 'fill' => self::WATER_BLUE, 'stroke' => self::WATER_INK, 'width' => 1.9];
        $disc = ['d' => self::DISC, 'fill' => self::CATEGORY_FILL, 'stroke' => 'rgba(20,22,14,.85)', 'width' => 1.6];
        $fork = ['d' => self::FORK, 'fill' => self::INK_ON_CATEGORY];
        $knife = ['d' => self::KNIFE, 'fill' => self::INK_ON_CATEGORY];

        return [
            ItemType::WaterFood->letter() => [
                // Drinkable as far as anyone knows: OSM says so, or maps it as
                // a drinking-water tap with nothing said against it.
                'tap' => ['paths' => [$drop]],
                // Not for drinking. A red bar across the drop, not a grey
                // fill: the bar is the signal and reads without colour; the
                // red is the site's "not usable" red, the one on the "!"
                // badge (owner 2026-09-28). Grey is not a kind.
                'no' => ['paths' => [
                    $drop,
                    ['d' => 'M4.2 5.6 6.3 3.5 20 17.2 17.9 19.3Z', 'fill' => self::NOT_USABLE_RED, 'stroke' => self::PAPER, 'width' => 1.2],
                ]],
                // A tap nobody has said anything about: the drop, unfilled.
                // Pure white, not paper: on the basemap paper read as grey
                // beside the blue outline (owner 2026-09-06).
                'unk' => ['paths' => [
                    ['d' => self::DROP, 'fill' => '#FFFFFF', 'stroke' => self::WATER_BLUE, 'width' => 2.4],
                ]],
                // A shop or an eatery. The baseline disc, so it sits with the
                // other coverage categories, and the fork-and-knife for kind.
                'food' => ['paths' => [$disc, $fork, $knife]],
                // A food stop that also gives water (owner 2026-09-04: "a
                // bakery can have food and waterdrop"). Rare, and a special
                // case on purpose: 127 of 123,870 food POIs carry the tag.
                'food_water' => ['paths' => [
                    $disc, $fork, $knife,
                    ['d' => self::SMALL_DROP, 'fill' => self::WATER_BLUE, 'stroke' => self::WATER_INK, 'width' => 1.2],
                ]],
            ],
            ItemType::BikeServices->letter() => [
                // BMP symbols, not colour emoji: the silhouette filter renders
                // emoji as tofu (icons.js).
                'shop' => ['glyph' => '⚙'],
                'station' => ['glyph' => '⚒'],
                'pump' => ['glyph' => '⊕'],
            ],
            // Scenic views and history & culture: one glyph per kind, one OSM
            // tag per kind (PlaceKind, osm-data-architecture.md §5a). Drawn on
            // the category disc, so they sit with the other coverage discs.
            // Shelters: one glyph per OSM shelter_type (PlaceKind, osm-data-architecture.md §5a).
            ItemType::Shelter->letter() => [
                // A small hut with a door.
                'basic_hut' => ['paths' => [$disc, ['d' => 'M6 18v-6.5L12 7l6 4.5V18Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M10.8 18v-3.6h2.4V18Z', 'fill' => self::CATEGORY_FILL]]],
                // A mound with an opening.
                'dugout' => ['paths' => [$disc, ['d' => 'M4.8 18c1.2-4.2 4-6.6 7.2-6.6s6 2.4 7.2 6.6Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M10.4 18v-2.2a1.6 1.6 0 0 1 3.2 0V18Z', 'fill' => self::CATEGORY_FILL]]],
                // An open-fronted shed.
                'field_shelter' => ['paths' => [$disc, ['d' => 'M5 9.5 19 7v2L5 11.5Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M6 11h1.6v7H6Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M16.4 9.2H18V18h-1.6Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M5 18h14v1.2H5Z', 'fill' => self::INK_ON_CATEGORY]]],
                // A roof on posts.
                'gazebo' => ['paths' => [$disc, ['d' => 'M5 11 12 5.5 19 11Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M6.4 11h1.4v7H6.4Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M11.3 11h1.4v7h-1.4Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M16.2 11h1.4v7h-1.4Z', 'fill' => self::INK_ON_CATEGORY]]],
                // A roof sloping to the ground.
                'lean_to' => ['paths' => [$disc, ['d' => 'M5 18 18 7.5V18Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M10 18 15.8 12.6V18Z', 'fill' => self::CATEGORY_FILL]]],
                // A wide roof on columns.
                'pavilion' => ['paths' => [$disc, ['d' => 'M4.5 10.5 12 6l7.5 4.5Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M5.5 10.5h13v1.3h-13Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M6.6 11.8H8v6.2H6.6Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M16 11.8h1.4v6.2H16Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M5 18h14v1.2H5Z', 'fill' => self::INK_ON_CATEGORY]]],
                // A roof over a picnic table.
                'picnic_shelter' => ['paths' => [$disc, ['d' => 'M4.5 10 12 5.5l7.5 4.5Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M6 10h1.3v8.5H6Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M16.7 10H18v8.5h-1.3Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M9 13.6h6v1.2H9Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M10 14.8h1v3.7h-1Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M13 14.8h1v3.7h-1Z', 'fill' => self::INK_ON_CATEGORY]]],
                // A rock overhang.
                'rock_shelter' => ['paths' => [$disc, ['d' => 'M4.8 18V9.5c2-2.8 5.4-4 8.6-3.6 3 .4 5.4 2.4 5.8 5.6l-5.4 1.4c-2-.6-4 .2-5 2.2V18Z', 'fill' => self::INK_ON_CATEGORY]]],
                // A sunshade.
                'sun_shelter' => ['paths' => [$disc, ['d' => 'M4.6 11.5A7.4 6 0 0 1 19.4 11.5Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M11.3 11.5h1.4v7h-1.4Z', 'fill' => self::INK_ON_CATEGORY]]],
                // A closed shelter with a bench.
                'weather_shelter' => ['paths' => [$disc, ['d' => 'M5.5 18V10l6.5-4.5 6.5 4.5v8Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M8.2 18v-5.2h7.6V18Z', 'fill' => self::CATEGORY_FILL], ['d' => 'M9 15.6h6v1.1H9Z', 'fill' => self::INK_ON_CATEGORY]]],
                // A hide on legs with a viewing slit.
                'wildlife_hide' => ['paths' => [$disc, ['d' => 'M6 7.5h12v6H6Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M8 9.8h8v1.2H8Z', 'fill' => self::CATEGORY_FILL], ['d' => 'M7 13.5h1.4V19H7Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M15.6 13.5H17V19h-1.6Z', 'fill' => self::INK_ON_CATEGORY]]],
                // A bus stop sign.
                'bus_shelter' => ['paths' => [$disc, ['d' => 'M7.5 5h9v6h-9Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M11.3 11h1.4v8h-1.4Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M9.4 7.2h5.2v1.6H9.4Z', 'fill' => self::CATEGORY_FILL]]],
                // A heart with a bolt.
                'defibrillator' => ['paths' => [$disc, ['d' => 'M12 18.4 6 12.6a3.4 3.4 0 0 1 6-4.4 3.4 3.4 0 0 1 6 4.4Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M12.6 9.4 10.4 13h1.8l-.8 3.4L14 12.4h-1.8l.4-3Z', 'fill' => self::CATEGORY_FILL]]],
            ],
            ItemType::ScenicViews->letter() => [
                // Binoculars: a place to stop and look.
                'viewpoint' => ['paths' => [$disc, ['d' => 'M6 14.5a3 3 0 1 0 6 0a3 3 0 1 0-6 0Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M12 14.5a3 3 0 1 0 6 0a3 3 0 1 0-6 0Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M7.4 7.5h3.2v5H7.4Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M13.4 7.5h3.2v5h-3.2Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M10.4 9.5h3.2v2h-3.2Z', 'fill' => self::INK_ON_CATEGORY]]],
                // A summit.
                'peak' => ['paths' => [$disc, ['d' => 'M5 17.5 10.2 7.5 12.6 11.6 14.2 9.2 19 17.5Z', 'fill' => self::INK_ON_CATEGORY]]],
                // Water falling over a ledge.
                'waterfall' => ['paths' => [$disc, ['d' => 'M5.5 6.5h13v1.8h-13Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M7.6 8.3h1.8v7.4H7.6Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M11.1 8.3h1.8v9.2h-1.8Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M14.6 8.3h1.8v7.4h-1.8Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M6 16.6h12v1.6H6Z', 'fill' => self::INK_ON_CATEGORY]]],
                // Broken water.
                'rapids' => ['paths' => [$disc, ['d' => 'M6 8.5L8 7.1L10 8.5L12 7.1L14 8.5L16 7.1L18 8.5L18 10.2L16 8.8L14 10.2L12 8.8L10 10.2L8 8.8L6 10.2Z M6 12.5L8 11.1L10 12.5L12 11.1L14 12.5L16 11.1L18 12.5L18 14.2L16 12.8L14 14.2L12 12.8L10 14.2L8 12.8L6 14.2Z M6 16.5L8 15.1L10 16.5L12 15.1L14 16.5L16 15.1L18 16.5L18 18.2L16 16.8L14 18.2L12 16.8L10 18.2L8 16.8L6 18.2Z', 'fill' => self::INK_ON_CATEGORY]]],
                // A rock face.
                'cliff' => ['paths' => [$disc, ['d' => 'M5 18V7.5h4.5l1.2 3.5h2.8l1 3H19v4Z', 'fill' => self::INK_ON_CATEGORY]]],
                // A dark mouth in the rock.
                'cave' => ['paths' => [$disc, ['d' => 'M4.8 18c.9-6.6 3.8-10.8 7.2-10.8s6.3 4.2 7.2 10.8Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M9.2 18c0-3 1.2-5 2.8-5s2.8 2 2.8 5Z', 'fill' => self::CATEGORY_FILL]]],
                // A natural arch.
                'arch' => ['paths' => [$disc, ['d' => 'M5 18v-5.5a7 6.3 0 0 1 14 0V18Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M8.6 18v-4.6a3.4 3.4 0 0 1 6.8 0V18Z', 'fill' => self::CATEGORY_FILL]]],
                // A notable rock.
                'rock' => ['paths' => [$disc, ['d' => 'M5 17.5 6.8 11.6 10.6 8 15.6 8.8 18.8 13.6 17.8 17.5Z', 'fill' => self::INK_ON_CATEGORY]]],
                // A lone boulder on the ground.
                'stone' => ['paths' => [$disc, ['d' => 'M6.5 14.8a5.5 4.4 0 1 0 11 0a5.5 4.4 0 1 0-11 0Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M5 17.6h14v1.3H5Z', 'fill' => self::INK_ON_CATEGORY]]],
                // A tree: a beautiful stretch to ride, ours only.
                'nature' => ['paths' => [$disc, ['d' => 'M12 5 16.8 11.5h-2.6l3.4 4.5H6.4l3.4-4.5H7.2Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M11 16h2v2.6h-2Z', 'fill' => self::INK_ON_CATEGORY]]],
            ],
            ItemType::HistoryCulture->letter() => [
                // A tower with battlements.
                'castle' => ['paths' => [$disc, ['d' => 'M6 18V7h2.4v2h2.4V7h2.4v2h2.4V7H18v11Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M10.5 18v-3.3a1.5 1.5 0 0 1 3 0V18Z', 'fill' => self::CATEGORY_FILL]]],
                // Walls with bastions.
                'fort' => ['paths' => [$disc, ['d' => 'M12 5.5 18.6 10.2 16.1 18H7.9L5.4 10.2Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M12 9.6 14.6 11.5 13.6 14.6H10.4L9.4 11.5Z', 'fill' => self::CATEGORY_FILL]]],
                // A broken wall.
                'ruins' => ['paths' => [$disc, ['d' => 'M5 18v-7.5h2.6V8H10v4h2V9.4h2.6V18Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M15.8 18v-2.6h3.2V18Z', 'fill' => self::INK_ON_CATEGORY]]],
                // An obelisk.
                'monument' => ['paths' => [$disc, ['d' => 'M10.6 15.6 11.3 7.2 12 5.4 12.7 7.2 13.4 15.6Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M8.5 15.6h7v2.8h-7Z', 'fill' => self::INK_ON_CATEGORY]]],
                // A memorial stone.
                'memorial' => ['paths' => [$disc, ['d' => 'M8.2 17V9.2a3.8 3.8 0 0 1 7.6 0V17Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M6.5 17h11v1.6h-11Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M11.3 9.8h1.4v4.4h-1.4Z', 'fill' => self::CATEGORY_FILL], ['d' => 'M9.9 11h4.2v1.3H9.9Z', 'fill' => self::CATEGORY_FILL]]],
                // An amphora.
                'archaeological' => ['paths' => [$disc, ['d' => 'M10 5.5h4v1.5h-.8v1.5c2.2.9 3.3 2.9 3.3 5 0 2.7-1.7 4.8-4.5 4.8s-4.5-2.1-4.5-4.8c0-2.1 1.1-4.1 3.3-5V7H10Z', 'fill' => self::INK_ON_CATEGORY]]],
                // A large house.
                'manor' => ['paths' => [$disc, ['d' => 'M4.8 11.2 12 6.4l7.2 4.8Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M6.2 11.2h11.6V18H6.2Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M8 12.8h2v2H8Z', 'fill' => self::CATEGORY_FILL], ['d' => 'M14 12.8h2v2h-2Z', 'fill' => self::CATEGORY_FILL], ['d' => 'M11 15h2v3h-2Z', 'fill' => self::CATEGORY_FILL]]],
                // A cloister with its bell tower.
                'monastery' => ['paths' => [$disc, ['d' => 'M5 18v-5.4h8.4V18Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M13.6 18V8.2l2.7-2.6 2.7 2.6V18Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M15.6 10h1.4v2.2h-1.4Z', 'fill' => self::CATEGORY_FILL], ['d' => 'M6.8 14.2h1.6v2h-1.6Z', 'fill' => self::CATEGORY_FILL], ['d' => 'M10 14.2h1.6v2H10Z', 'fill' => self::CATEGORY_FILL]]],
                // A temple front.
                'museum' => ['paths' => [$disc, ['d' => 'M4.6 9.6 12 5.6l7.4 4Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M6.4 10.6h1.8v5.8H6.4Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M9.5 10.6h1.8v5.8H9.5Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M12.7 10.6h1.8v5.8h-1.8Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M15.8 10.6h1.8v5.8h-1.8Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M5 16.8h14v1.6H5Z', 'fill' => self::INK_ON_CATEGORY]]],
                // A church with its spire.
                'worship' => ['paths' => [$disc, ['d' => 'M7.2 18v-6.4L12 8.4l4.8 3.2V18Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M11.3 3.8h1.4v1.5h1.4v1.3h-1.4V8.6h-1.4V6.6H9.9V5.3h1.4Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M11 14.6a1 1 0 0 1 2 0V18h-2Z', 'fill' => self::CATEGORY_FILL]]],
                // A shield: heritage, ours only.
                'heritage' => ['paths' => [$disc, ['d' => 'M12 5.2 17.6 7.2v4.4c0 3.4-2.4 5.7-5.6 7-3.2-1.3-5.6-3.6-5.6-7V7.2Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M12 8.4 14.6 9.4v2.3c0 1.7-1.1 2.9-2.6 3.6-1.5-.7-2.6-1.9-2.6-3.6V9.4Z', 'fill' => self::CATEGORY_FILL]]],
                // A facade with arched windows: architecture, ours only.
                'architecture' => ['paths' => [$disc, ['d' => 'M5.5 18V8.6L12 5.4l6.5 3.2V18Z', 'fill' => self::INK_ON_CATEGORY], ['d' => 'M7.8 18v-4a1.4 1.4 0 0 1 2.8 0v4Z', 'fill' => self::CATEGORY_FILL], ['d' => 'M13.4 18v-4a1.4 1.4 0 0 1 2.8 0v4Z', 'fill' => self::CATEGORY_FILL], ['d' => 'M11 9.8a1 1 0 1 0 2 0a1 1 0 1 0-2 0Z', 'fill' => self::CATEGORY_FILL]]],
            ],
        ];
    }
}
