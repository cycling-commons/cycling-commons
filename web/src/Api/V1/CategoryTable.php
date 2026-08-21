<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Api\V1;

/**
 * Public API rendering metadata (docs/specs/public-api.md §2.2).
 * Mirrored from the map client; CategoryTableSyncTest pins both sides.
 */
final class CategoryTable
{
    /** Required consumer attribution (docs/specs/public-api.md §5). */
    public const string ATTRIBUTION = '© Cycling Commons contributors (ODbL) · © OpenStreetMap contributors';

    /**
     * English fallback only — this cacheable plane must never be locale-bound.
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
     * @var list<array{key: string, nets: list<string>, color: string}>
     */
    public const array ROUTE_STYLE_GROUPS = [
        ['key' => 'national', 'nets' => ['icn', 'ncn'], 'color' => '#C84E64'],
        ['key' => 'regional', 'nets' => ['rcn', 'lcn', 'other'], 'color' => '#7A4FCF'],
        ['key' => 'mtb', 'nets' => ['mtb'], 'color' => '#8A5A32'],
    ];

    /** Zoom from which the tiles carry knooppunt badge points (routes-tiles.js BADGE_MIN_ZOOM). */
    public const int ROUTE_BADGE_MIN_ZOOM = 10;

    /** @var list<string> */
    public const array COVERAGE_LETTERS = ['c', 'd', 'e', 'g', 'h', 'i', 'j', 'm'];

    /** Unstamped coverage source-layer; consumers append it to the country list. */
    public const string COVERAGE_UNSTAMPED_BUCKET = 'zz';

    /** Zoom from which the coverage artifact carries individual points (coverage.js icon minzoom). */
    public const int COVERAGE_MIN_ZOOM = 9;
}
