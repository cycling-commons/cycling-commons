<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\RegionRegistryProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The client-side region registry (map-and-search.md §4.5 Phase 2):
 * id/slug/countryCode/bbox per region, fed to window.CCScope. `countryCode`
 * (not `cc`) matches the scope-object contract.
 */
final class RegionRegistryProviderTest extends KernelTestCase
{
    public function testAllReturnsRegionsWithCountryCodeAndBbox(): void
    {
        self::bootKernel();

        $dir = sys_get_temp_dir().'/region-registry-'.getmypid();
        @mkdir($dir, 0777, true);
        copy(__DIR__.'/../fixtures/catalog/region-square.geojson', $dir.'/region-square.geojson');
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:import'));
        $tester->execute(['dir' => $dir]);
        $tester->assertCommandIsSuccessful();

        $regions = static::getContainer()->get(RegionRegistryProvider::class)->all();
        $square = null;
        foreach ($regions as $r) {
            if ('test-square' === $r['slug']) {
                $square = $r;
            }
        }

        self::assertNotNull($square, 'the imported region is in the registry');
        self::assertIsInt($square['id']);
        self::assertSame('BE', $square['countryCode']);       // scope-object field name, not `cc`
        // The view-mode flag rides the registry too.
        // False on import: a
        // region opens in Everything until a moderator earns it otherwise.
        // The mode a region OPENS in, as the map toggle's own token — every
        // region starts in Everything ('all') until a curator raises it.
        self::assertArrayHasKey('defaultMode', $square);
        self::assertSame('all', $square['defaultMode']);
        // bbox [west, south, east, north] of the fixture square [4,50]-[5,51].
        self::assertEqualsWithDelta(4.0, $square['bbox'][0], 0.001);
        self::assertEqualsWithDelta(50.0, $square['bbox'][1], 0.001);
        self::assertEqualsWithDelta(5.0, $square['bbox'][2], 0.001);
        self::assertEqualsWithDelta(51.0, $square['bbox'][3], 0.001);
    }

    public function testAllExposesAdjacencyIds(): void
    {
        self::bootKernel();

        $dir = sys_get_temp_dir().'/region-registry-adj-'.getmypid();
        @mkdir($dir, 0777, true);
        copy(__DIR__.'/../fixtures/catalog/region-square.geojson', $dir.'/region-square.geojson');
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:import'));
        $tester->execute(['dir' => $dir]);
        $tester->assertCommandIsSuccessful();

        $square = null;
        foreach (static::getContainer()->get(RegionRegistryProvider::class)->all() as $r) {
            if ('test-square' === $r['slug']) {
                $square = $r;
            }
        }
        self::assertNotNull($square);
        self::assertArrayHasKey('adj', $square);
        self::assertIsArray($square['adj']);           // present + typed
        self::assertSame([], $square['adj']);          // a lone square borders nothing
    }

    /**
     * The simplified ranking outline CCScope.rankByGroundDistance measures to.
     * Shape, not
     * fidelity: rings as FLAT [lng,lat,lng,lat,…], which is what the client
     * walks — a nested [[lng,lat],…] would silently rank everything as
     * "no outline" and fall back to bbox centres with nothing failing.
     */
    public function testAllShipsTheSimplifiedRankingOutline(): void
    {
        self::bootKernel();

        $dir = sys_get_temp_dir().'/region-registry-outline-'.getmypid();
        @mkdir($dir, 0777, true);
        copy(__DIR__.'/../fixtures/catalog/region-square.geojson', $dir.'/region-square.geojson');
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:import'));
        $tester->execute(['dir' => $dir]);
        $tester->assertCommandIsSuccessful();

        $square = null;
        foreach (static::getContainer()->get(RegionRegistryProvider::class)->all() as $r) {
            if ('test-square' === $r['slug']) {
                $square = $r;
            }
        }
        self::assertNotNull($square);
        self::assertArrayHasKey('outline', $square);
        self::assertCount(1, $square['outline'], 'the fixture square is a single part');

        $ring = $square['outline'][0];
        self::assertIsArray($ring);
        self::assertSame(0, \count($ring) % 2, 'flat lng/lat pairs, not [lng,lat] tuples');
        self::assertGreaterThanOrEqual(8, \count($ring), 'a closed square is >= 4 points');
        foreach ($ring as $v) {
            self::assertIsNumeric($v, 'a flat ring holds scalars, never nested arrays');
        }
        // Every coordinate lies on the fixture square [4,50]-[5,51], which also
        // proves the lng/lat interleave is not transposed.
        for ($i = 0; $i < \count($ring); $i += 2) {
            self::assertEqualsWithDelta(4.5, (float) $ring[$i], 0.5001, 'longitude in range');
            self::assertEqualsWithDelta(50.5, (float) $ring[$i + 1], 0.5001, 'latitude in range');
        }
    }
}
