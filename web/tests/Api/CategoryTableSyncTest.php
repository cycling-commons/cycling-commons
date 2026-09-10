<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\V1\CategoryTable;
use App\Catalog\ItemType;
use PHPUnit\Framework\TestCase;

/**
 * Pins CategoryTable to the browser modules it hand-mirrors
 * (assets/map/catalog.js CATALOG, assets/map/routes-tiles.js GROUPS +
 * BADGE_MIN_ZOOM). The server cannot read ES modules at runtime, so the
 * pairing is duplicated on purpose, and this test is what makes editing one
 * side without the other a CI failure instead of a quiet drift between the
 * site map and what /v1/map-config tells external consumers.
 */
final class CategoryTableSyncTest extends TestCase
{
    public function testCategoriesMirrorCatalogJs(): void
    {
        $js = (string) file_get_contents(__DIR__.'/../../assets/map/catalog.js');

        preg_match_all(
            "/key:'(?<key>\\w+)',\\s*letter:'(?<letter>[A-Z])',\\s*label:LAYER_L10N\\.\\w+\\|\\|'(?<label>[^']+)',\\s*color:'(?<color>#[0-9A-Fa-f]{6})',\\s*icon:TYPE_ICON\\('(?<icon>[A-Z])'\\),\\s*kind:'(?<kind>\\w+)',\\s*exp:(?<exp>true|false)/u",
            $js,
            $matches,
            \PREG_SET_ORDER,
        );

        $fromJs = array_map(static fn (array $m): array => [
            'letter' => $m['letter'],
            'key' => $m['key'],
            'label' => $m['label'],
            'color' => $m['color'],
            // catalog.js asks the shared set by letter; the API resolves the same set.
            'glyph' => ItemType::iconSet()[$m['icon']]['glyph'],
            'kind' => $m['kind'],
            // catalog.js `exp` is what the map's Best of mode SHOWS; the API
            // publishes it under the consumer-facing name.
            'bestOf' => 'true' === $m['exp'],
        ], $matches);

        self::assertNotEmpty($fromJs, 'the CATALOG regex no longer matches catalog.js: update the parser AND check CategoryTable');
        self::assertSame($fromJs, CategoryTable::categories(), 'CategoryTable::categories() drifted from assets/map/catalog.js CATALOG');
    }

    public function testRouteStyleGroupsMirrorRoutesTilesJs(): void
    {
        $js = (string) file_get_contents(__DIR__.'/../../assets/map/routes-tiles.js');

        preg_match_all(
            "/\\{\\s*key:\\s*'(?<key>\\w+)',\\s*nets:\\s*\\[(?<nets>[^\\]]+)\\],\\s*color:\\s*'(?<color>#[0-9A-Fa-f]{6})'\\s*\\}/",
            $js,
            $matches,
            \PREG_SET_ORDER,
        );

        $fromJs = array_map(static function (array $m): array {
            preg_match_all("/'(\\w+)'/", $m['nets'], $nets);

            return ['key' => $m['key'], 'nets' => $nets[1], 'color' => $m['color']];
        }, $matches);

        self::assertNotEmpty($fromJs, 'the GROUPS regex no longer matches routes-tiles.js: update the parser AND check CategoryTable');
        self::assertSame($fromJs, CategoryTable::ROUTE_STYLE_GROUPS, 'CategoryTable::ROUTE_STYLE_GROUPS drifted from assets/map/routes-tiles.js GROUPS');

        self::assertSame(1, preg_match('/const BADGE_MIN_ZOOM = (\d+)/', $js, $badge));
        self::assertSame(CategoryTable::ROUTE_BADGE_MIN_ZOOM, (int) $badge[1], 'ROUTE_BADGE_MIN_ZOOM drifted from routes-tiles.js BADGE_MIN_ZOOM');
    }

    public function testCoverageLettersMirrorCoverageJs(): void
    {
        $js = (string) file_get_contents(__DIR__.'/../../assets/map/coverage.js');

        self::assertSame(1, preg_match('/export const COVERAGE_KEYS=\[(.+?)\];/', $js, $keys));
        preg_match_all("/\\['\\w+','([a-z])'\\]/", $keys[1], $letters);

        self::assertSame($letters[1], CategoryTable::COVERAGE_LETTERS, 'COVERAGE_LETTERS drifted from assets/map/coverage.js COVERAGE_KEYS');
    }
}
