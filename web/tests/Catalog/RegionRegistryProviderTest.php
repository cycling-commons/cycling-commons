<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\RegionRegistryProvider;
use Doctrine\DBAL\Connection;
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

    /**
     * A region crossing the date line must not claim the whole planet.
     *
     * `ST_XMin`/`ST_XMax` on a geometry that straddles ±180° return -180 and
     * +180, because they are minimum and maximum over a set of numbers and know
     * nothing about the seam. The box then reads as 359° wide. Two real
     * countries hit it, the United States through the Aleutians and New Zealand
     * through the Chathams, and the failure is silent: nothing errors, both
     * simply behave as "everywhere". A rider scoped to New Zealand gets Belgian
     * towns in search, and `regionOfPoint()` hands anyone anywhere the United
     * States, because a box that contains every point wins on centre distance.
     *
     * The fix is the GeoJSON convention (RFC 7946 §5.2): a crossing box is
     * written with its **west value greater than its east value**. That is a
     * shape readers have to understand rather than a number they can compare
     * naively, which is the point: the naive comparison is the bug.
     */
    public function testABoxCrossingTheDateLineWrapsInsteadOfSpanningTheWorld(): void
    {
        self::bootKernel();
        $slug = 'test-antimeridian';
        $this->seedRegion(
            $slug,
            // Two lobes either side of the seam, the shape of an island tail:
            // 170E to 180, and 180 to 172W. True extent is 18 degrees.
            'MULTIPOLYGON(((170 -10, 180 -10, 180 -20, 170 -20, 170 -10)),'
            .'((-180 -10, -172 -10, -172 -20, -180 -20, -180 -10)))',
        );

        $region = $this->find($slug);
        [$w, $s, $e, $n] = $region['bbox'];

        self::assertGreaterThan($e, $w, 'a crossing box is written west > east (RFC 7946 §5.2)');
        self::assertEqualsWithDelta(170.0, $w, 0.01);
        self::assertEqualsWithDelta(-172.0, $e, 0.01);
        self::assertEqualsWithDelta(-20.0, $s, 0.01);
        self::assertEqualsWithDelta(-10.0, $n, 0.01);
    }

    /** Everything that does not cross keeps the ordinary west < east box. */
    public function testAnOrdinaryBoxIsUntouched(): void
    {
        self::bootKernel();
        $slug = 'test-ordinary-box';
        $this->seedRegion($slug, 'MULTIPOLYGON(((2 49, 6 49, 6 51, 2 51, 2 49)))');

        [$w, $s, $e, $n] = $this->find($slug)['bbox'];

        self::assertEqualsWithDelta(2.0, $w, 0.01);
        self::assertEqualsWithDelta(6.0, $e, 0.01);
        self::assertLessThan($e, $w, 'a box that does not cross must stay west < east');
        self::assertEqualsWithDelta(49.0, $s, 0.01);
        self::assertEqualsWithDelta(51.0, $n, 0.01);
    }

    /**
     * The far side of the world is not a crossing.
     *
     * A region sitting wholly in the western hemisphere has a large negative
     * longitude span, and a test that only asked "is this box wide?" would call
     * it a crossing and mangle it.
     */
    public function testAWideButUncrossingBoxIsUntouched(): void
    {
        self::bootKernel();
        $slug = 'test-wide-box';
        $this->seedRegion($slug, 'MULTIPOLYGON(((-125 30, -66 30, -66 49, -125 49, -125 30)))');

        [$w, , $e] = $this->find($slug)['bbox'];

        self::assertEqualsWithDelta(-125.0, $w, 0.01);
        self::assertEqualsWithDelta(-66.0, $e, 0.01);
        self::assertLessThan($e, $w);
    }

    /**
     * Shifting longitudes loses bits, and that is not a crossing.
     *
     * ST_ShiftLongitude adds 360 to a negative longitude, and the result cannot
     * hold the original mantissa exactly, so a region entirely in the western
     * hemisphere comes back a few times 1e-14 NARROWER than it went in. A test
     * of "is the shifted span smaller?" therefore says yes for Madrid, Asturias
     * and Québec, and the first version of this fix duly rewrote 32 perfectly
     * ordinary regions into crossings. Integer fixture coordinates hid it,
     * because there the arithmetic is exact.
     *
     * The gate is a raw span wider than 180 degrees, which no ordinary region
     * has and every crossing does, since a crossing box reaches from one edge
     * of the seam to the other.
     */
    public function testFloatingPointNoiseIsNotMistakenForACrossing(): void
    {
        self::bootKernel();
        $slug = 'test-western-hemisphere';
        // Asturias' real extent, to the same precision the importer stores.
        $this->seedRegion($slug, 'MULTIPOLYGON(((-7.1834561 42.9014, -4.5108817 42.9014, '
            .'-4.5108817 43.6634, -7.1834561 43.6634, -7.1834561 42.9014)))');

        [$w, , $e] = $this->find($slug)['bbox'];

        self::assertLessThan($e, $w, 'a western-hemisphere region must not be read as crossing');
        self::assertEqualsWithDelta(-7.1834561, $w, 0.0001);
        self::assertEqualsWithDelta(-4.5108817, $e, 0.0001);
    }

    /** A region of its own country, so OperationalRegions keeps it. */
    private function seedRegion(string $slug, string $wkt): void
    {
        /** @var Connection $db */
        $db = static::getContainer()->get('doctrine.dbal.default_connection');
        $db->executeStatement('DELETE FROM region WHERE slug = :s', ['s' => $slug]);
        $db->executeStatement(
            <<<'SQL'
                INSERT INTO region (slug, name, geom, area_km2, country_code, admin_level,
                                    default_map_mode, created_at, updated_at)
                VALUES (:s, :s, ST_SetSRID(ST_GeomFromText(:wkt), 4326), 1000, :cc, 4,
                        'everything', NOW(), NOW())
                SQL,
            ['s' => $slug, 'wkt' => $wkt, 'cc' => strtoupper(substr(md5($slug), 0, 2))],
        );
    }

    /** @return array<string, mixed> */
    private function find(string $slug): array
    {
        foreach (static::getContainer()->get(RegionRegistryProvider::class)->all() as $r) {
            if ($slug === $r['slug']) {
                return $r;
            }
        }

        self::fail('the seeded region '.$slug.' is missing from the registry');
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
