<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * THE basemap furniture icons: the few OpenStreetMap point classes on the
 * basemap that matter to a rider and that the basemap's own sprite does not
 * draw (docs/specs/map-and-search.md §4.7).
 *
 * The basemap style asks its sprite for an image named after each point's
 * OSM class. For most classes the sprite has nothing, so MapLibre logs a
 * warning and draws nothing. The map answers that "image missing" event from
 * this registry (`assets/map/map-init.js`, via `window.CC_BASEMAP_ICONS`),
 * and both legends render the same drawings (`partials/_basemap_icon.html.twig`,
 * via `cc_basemap_icons()`). Nothing else may draw one of these.
 *
 * These are not catalogue items and never reach the ladder: no pin, no
 * drawer, no confirmation. They are basemap marks a rider reads in passing,
 * the way a road shield is. The key lists them under "From the basemap".
 *
 * Each id is the OSM class as the basemap tile carries it. Each drawing is a
 * list of `paths` in a 24-box, monochrome ink with a paper halo so it reads
 * on any ground (owner: no coloured icons). Strings are
 * `legend.basemap_<id>_h` and `_t`.
 *
 * @api
 */
final class BasemapIcons
{
    public const string INK = '#14160E';
    public const string HALO = '#F3EBD8';

    /**
     * @return array<string, array{paths: list<array{d: string, fill: string, stroke?: string, width?: float}>}>
     */
    public static function set(): array
    {
        $ink = static fn (string $d): array => ['d' => $d, 'fill' => self::INK, 'stroke' => self::HALO, 'width' => 1.2];

        return [
            // A post in the road: a rounded cap on a stem, on a base plate.
            'bollard' => ['paths' => [
                $ink('M9 4a3 3 0 0 1 6 0v13H9z'),
                $ink('M6 17.5h12v3H6z'),
            ]],
            // Two posts and two bars: a gate a rider may have to open.
            'gate' => ['paths' => [
                $ink('M4 4h2.6v16H4z'),
                $ink('M17.4 4H20v16h-2.6z'),
                $ink('M6.6 8h10.8v2.4H6.6z'),
                $ink('M6.6 14h10.8v2.4H6.6z'),
            ]],
            // A Sheffield stand on the ground: where a bike can be locked.
            'bicycle_parking' => ['paths' => [
                $ink('M5.5 20V9.5a6.5 6.5 0 0 1 13 0V20h-2.6V9.5a3.9 3.9 0 0 0-7.8 0V20z'),
                $ink('M3 20h18v1.6H3z'),
            ]],
            // A chicane: two staggered bars on posts, passable, not at speed.
            'cycle_barrier' => ['paths' => [
                $ink('M3 4.5h2.6v6.6H3z'),
                $ink('M3 6.5h12v2.6H3z'),
                $ink('M18.4 12.4H21V19h-2.6z'),
                $ink('M9 14.4h12V17H9z'),
            ]],
        ];
    }
}
