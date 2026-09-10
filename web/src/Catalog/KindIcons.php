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
                // Not for drinking. A bar across the drop, not a grey fill:
                // colour is never the only signal, and grey is not a kind.
                'no' => ['paths' => [
                    $drop,
                    ['d' => 'M4.2 5.6 6.3 3.5 20 17.2 17.9 19.3Z', 'fill' => self::WATER_INK, 'stroke' => self::PAPER, 'width' => 1.2],
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
        ];
    }
}
