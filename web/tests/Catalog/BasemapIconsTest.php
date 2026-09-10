<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\BasemapIcons;
use PHPUnit\Framework\TestCase;

/**
 * The basemap furniture registry (map-and-search.md §4.7): OSM classes the
 * basemap sprite does not draw, drawn once here for the map and both keys.
 */
final class BasemapIconsTest extends TestCase
{
    public function testEveryIdIsAnOsmClassWithADrawing(): void
    {
        $set = BasemapIcons::set();

        self::assertSame(['bollard', 'gate', 'bicycle_parking', 'cycle_barrier'], array_keys($set));
        foreach ($set as $id => $def) {
            self::assertMatchesRegularExpression('/^[a-z_]+$/', $id, 'an id is the OSM class as the tile carries it');
            self::assertNotEmpty($def['paths'], "{$id} has a drawing");
            foreach ($def['paths'] as $path) {
                self::assertStringStartsWith('M', $path['d']);
                self::assertSame(BasemapIcons::INK, $path['fill'], "{$id}: monochrome ink, never a colour");
                self::assertSame(BasemapIcons::HALO, $path['stroke'] ?? null, "{$id}: a paper halo so it reads on any ground");
            }
        }
    }
}
