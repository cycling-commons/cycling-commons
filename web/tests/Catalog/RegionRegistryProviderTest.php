<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\RegionRegistryProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The client-side region registry (region-scoping-design.md §4 / §7 Phase 2):
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
        // bbox [west, south, east, north] of the fixture square [4,50]-[5,51].
        self::assertEqualsWithDelta(4.0, $square['bbox'][0], 0.001);
        self::assertEqualsWithDelta(50.0, $square['bbox'][1], 0.001);
        self::assertEqualsWithDelta(5.0, $square['bbox'][2], 0.001);
        self::assertEqualsWithDelta(51.0, $square['bbox'][3], 0.001);
    }
}
