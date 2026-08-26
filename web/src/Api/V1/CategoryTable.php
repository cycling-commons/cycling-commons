<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Api\V1;

use App\Catalog\ItemType;

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
     * The glyph is deliberately NOT here: it is ItemType::icon(), the one
     * category icon set in the system (owner 2026-08-25); {@see categories()}
     * adds it for the API payload.
     *
     * @var list<array{letter: string, key: string, label: string, color: string, kind: string, bestOf: bool}>
     */
    public const array CATEGORIES = [
        ['letter' => 'A', 'key' => 'surface', 'label' => 'Road surface', 'color' => '#4E8C84', 'kind' => 'surface', 'bestOf' => true],
        ['letter' => 'N', 'key' => 'climbs', 'label' => 'Climbs', 'color' => '#6A2C8F', 'kind' => 'point', 'bestOf' => true],
        ['letter' => 'B', 'key' => 'water', 'label' => 'Water & food', 'color' => '#8FB6A8', 'kind' => 'point', 'bestOf' => false],
        ['letter' => 'C', 'key' => 'toilets', 'label' => 'Public toilets', 'color' => '#4E6E8C', 'kind' => 'point', 'bestOf' => false],
        ['letter' => 'D', 'key' => 'services', 'label' => 'Bike services', 'color' => '#6b6f5e', 'kind' => 'point', 'bestOf' => false],
        ['letter' => 'O', 'key' => 'stays', 'label' => 'Where to sleep', 'color' => '#B5532E', 'kind' => 'point', 'bestOf' => true],
        ['letter' => 'E', 'key' => 'hazards', 'label' => 'Hazards & conditions', 'color' => '#C8923A', 'kind' => 'point', 'bestOf' => false],
        ['letter' => 'F', 'key' => 'transit', 'label' => 'Getting there', 'color' => '#3E7D8C', 'kind' => 'point', 'bestOf' => false],
        ['letter' => 'G', 'key' => 'shelter', 'label' => 'Shelter', 'color' => '#9A8FB6', 'kind' => 'point', 'bestOf' => false],
        ['letter' => 'P', 'key' => 'scenic', 'label' => 'Scenic views', 'color' => '#2C5440', 'kind' => 'point', 'bestOf' => true],
        ['letter' => 'Q', 'key' => 'history', 'label' => 'History & culture', 'color' => '#6E5849', 'kind' => 'point', 'bestOf' => true],
        ['letter' => 'R', 'key' => 'experience', 'label' => 'Recommended routes', 'color' => '#FF5A1F', 'kind' => 'line', 'bestOf' => false],
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
    public const array COVERAGE_LETTERS = ['b', 'c', 'd', 'f', 'g', 'o', 'p', 'q'];

    /** Unstamped coverage source-layer; consumers append it to the country list. */
    public const string COVERAGE_UNSTAMPED_BUCKET = 'zz';

    /** Zoom from which the coverage artifact carries individual points (coverage.js icon minzoom). */
    public const int COVERAGE_MIN_ZOOM = 9;

    /**
     * The category table as the API publishes it: CATEGORIES plus the glyph
     * from ItemType::iconSet(), so the API, the map and every page agree.
     *
     * @return list<array{letter: string, key: string, label: string, color: string, glyph: string, kind: string, bestOf: bool}>
     */
    public static function categories(): array
    {
        $icons = ItemType::iconSet();

        return array_map(static fn (array $c): array => [
            'letter' => $c['letter'],
            'key' => $c['key'],
            'label' => $c['label'],
            'color' => $c['color'],
            'glyph' => $icons[$c['letter']]['glyph'],
            'kind' => $c['kind'],
            'bestOf' => $c['bestOf'],
        ], self::CATEGORIES);
    }
}
