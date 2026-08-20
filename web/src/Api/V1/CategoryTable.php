<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Api\V1;

/**
 * The rendering metadata the public API hands external consumers
 * (public-api.md §2.2 PoC; wiki/developers/api/serving-map-data.md):
 * the category table and the route-network style groups.
 *
 * Hand-mirrored from the map client's own constants (CATALOG in
 * assets/map/catalog.js, GROUPS/BADGE_MIN_ZOOM in assets/map/routes-tiles.js)
 * because those are browser ES modules the server cannot read at runtime.
 * tests/Api/CategoryTableSyncTest.php regex-parses both JS files and fails on
 * any divergence, so editing either side without the other breaks CI, not prod.
 */
final class CategoryTable
{
    /**
     * The required consumer attribution (public-api.md §5): Commons data is
     * ODbL, coverage-derived rows carry OSM provenance, so both credits ride
     * every public response and the map-config bootstrap.
     */
    public const string ATTRIBUTION = '© Cycling Commons contributors (ODbL) · © OpenStreetMap contributors';

    /**
     * One row per catalogue letter, in catalog.js draw order. `label` is the
     * English fallback only: localised labels are a v1 concern, and the
     * internal i18n dict is session/locale-bound, which this anonymous
     * cacheable plane must never be.
     *
     * @var list<array{letter: string, key: string, label: string, color: string, glyph: string, kind: string, bestOf: bool}>
     */
    public const array CATEGORIES = [
        ['letter' => 'A', 'key' => 'surface', 'label' => 'Road surface', 'color' => '#4E8C84', 'glyph' => '▰', 'kind' => 'surface', 'bestOf' => true],
        ['letter' => 'B', 'key' => 'climbs', 'label' => 'Climbs', 'color' => '#6A2C8F', 'glyph' => '⛰', 'kind' => 'point', 'bestOf' => true],
        ['letter' => 'C', 'key' => 'water', 'label' => 'Water & food', 'color' => '#8FB6A8', 'glyph' => '💧', 'kind' => 'point', 'bestOf' => false],
        ['letter' => 'M', 'key' => 'toilets', 'label' => 'Public toilets', 'color' => '#4E6E8C', 'glyph' => '🚻', 'kind' => 'point', 'bestOf' => false],
        ['letter' => 'D', 'key' => 'services', 'label' => 'Bike services', 'color' => '#6b6f5e', 'glyph' => '⚙', 'kind' => 'point', 'bestOf' => false],
        ['letter' => 'E', 'key' => 'stays', 'label' => 'Where to sleep', 'color' => '#B5532E', 'glyph' => '⛺', 'kind' => 'point', 'bestOf' => true],
        ['letter' => 'F', 'key' => 'hazards', 'label' => 'Hazards & conditions', 'color' => '#C8923A', 'glyph' => '⚠', 'kind' => 'point', 'bestOf' => false],
        ['letter' => 'G', 'key' => 'transit', 'label' => 'Getting there', 'color' => '#3E7D8C', 'glyph' => '🚆', 'kind' => 'point', 'bestOf' => false],
        ['letter' => 'H', 'key' => 'shelter', 'label' => 'Shelter', 'color' => '#9A8FB6', 'glyph' => '⛑', 'kind' => 'point', 'bestOf' => false],
        ['letter' => 'I', 'key' => 'scenic', 'label' => 'Scenic views', 'color' => '#2C5440', 'glyph' => '📷', 'kind' => 'point', 'bestOf' => true],
        ['letter' => 'J', 'key' => 'history', 'label' => 'History & culture', 'color' => '#6E5849', 'glyph' => '🏛', 'kind' => 'point', 'bestOf' => true],
        ['letter' => 'K', 'key' => 'experience', 'label' => 'Recommended routes', 'color' => '#FF5A1F', 'glyph' => '★', 'kind' => 'line', 'bestOf' => false],
    ];

    /**
     * Route-network style groups (routes-tiles.js GROUPS): how the Commons
     * paints the corridor tiles, offered so a consumer can match the look
     * without copying constants by hand. Data-only tiles carry no styling, so
     * this table is the only place the pairing exists server-side.
     *
     * @var list<array{key: string, nets: list<string>, color: string}>
     */
    public const array ROUTE_STYLE_GROUPS = [
        ['key' => 'national', 'nets' => ['icn', 'ncn'], 'color' => '#C84E64'],
        ['key' => 'regional', 'nets' => ['rcn', 'lcn', 'other'], 'color' => '#7A4FCF'],
        ['key' => 'mtb', 'nets' => ['mtb'], 'color' => '#8A5A32'],
    ];

    /** Zoom from which the tiles carry knooppunt badge points (routes-tiles.js BADGE_MIN_ZOOM). */
    public const int ROUTE_BADGE_MIN_ZOOM = 10;

    /**
     * The catalogue letters the coverage artifact carries, lowercase because
     * they name its source-layers ('<letter>_<cc>'). Mirrored from
     * assets/map/coverage.js COVERAGE_KEYS and pinned by the sync test.
     *
     * @var list<string>
     */
    public const array COVERAGE_LETTERS = ['c', 'd', 'e', 'g', 'h', 'i', 'j', 'm'];

    /**
     * The 'zz' source-layer bucket holds rows not stamped with a country;
     * consumers append it to the country list so those rows still render.
     */
    public const string COVERAGE_UNSTAMPED_BUCKET = 'zz';

    /** Zoom from which the coverage artifact carries individual points (coverage.js icon minzoom). */
    public const int COVERAGE_MIN_ZOOM = 9;
}
