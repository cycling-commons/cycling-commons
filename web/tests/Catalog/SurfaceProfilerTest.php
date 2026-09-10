<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SurfaceProfiler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The derived route surface profile (A-layer intersect): the served road-surface
 * segments a route runs alongside, normalized over mapped length (parts) with an
 * honest route-side coverage figure that parallel/duplicate mapping cannot inflate.
 * Real PostGIS via KernelTestCase — the whole point is the ST_* geometry maths.
 */
final class SurfaceProfilerTest extends KernelTestCase
{
    /** GeoJSON LineString from [lng, lat] pairs. */
    private static function line(array $coords): string
    {
        return json_encode(['type' => 'LineString', 'coordinates' => $coords], \JSON_THROW_ON_ERROR);
    }

    /** Persist a served letter-A road-surface segment with a known surface + geometry. */
    private function seedSegment(string $surface, array $coords, string $ref): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item())->setLetter('A')->setName('Segment '.$ref)
            ->setGeom(self::line($coords))->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Osm)
            ->setSourceRef('way/'.$ref)
            ->setAttributes(['surface' => $surface]);
        $em->persist($item);
        $em->flush();
    }

    private function seedRoute(array $coords, array $attributes, string $ref): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = (new RecommendedRoute())->setName('Route '.$ref)
            ->setGeom(self::line($coords))->setDistanceM(1_000)->setAscentM(0)
            ->setState(ItemState::Unverified)->setSource(ItemSource::Auto)
            ->setSourceRef('fx:'.$ref)
            ->setAttributes($attributes);
        $em->persist($route);
        $em->flush();

        return (int) $route->getId();
    }

    private function profiler(): SurfaceProfiler
    {
        return static::getContainer()->get(SurfaceProfiler::class);
    }

    public function testRouteAlongASingleAsphaltSegmentIsAllAsphaltFullyCovered(): void
    {
        self::bootKernel();
        $this->seedSegment('Asphalt', [[5.3, 50.0], [5.3, 50.009]], 'single');

        $result = $this->profiler()->profile(self::line([[5.3, 50.0], [5.3, 50.009]]));

        self::assertNotNull($result);
        self::assertSame([['surface' => 'Asphalt', 'pct' => 100]], $result['parts']);
        self::assertGreaterThanOrEqual(95, $result['covered'], 'a route sitting on a mapped segment is ~fully covered');
        self::assertLessThanOrEqual(100, $result['covered']);
    }

    public function testMixedRouteSplitsPartsByLengthOrderedLongestFirst(): void
    {
        self::bootKernel();
        // Asphalt over the first ~0.6 km, Gravel over the last ~0.4 km of the route.
        $this->seedSegment('Asphalt', [[5.3, 50.0], [5.3, 50.0054]], 'a');
        $this->seedSegment('Gravel', [[5.3, 50.0054], [5.3, 50.009]], 'g');

        $result = $this->profiler()->profile(self::line([[5.3, 50.0], [5.3, 50.009]]));

        self::assertNotNull($result);
        self::assertCount(2, $result['parts']);
        self::assertSame('Asphalt', $result['parts'][0]['surface'], 'longest part first');
        self::assertSame('Gravel', $result['parts'][1]['surface']);
        $sum = $result['parts'][0]['pct'] + $result['parts'][1]['pct'];
        self::assertGreaterThanOrEqual(99, $sum, 'parts normalize to ~100');
        self::assertLessThanOrEqual(100, $sum);
    }

    public function testNearEqualPartsApportionSoPercentagesNeverExceed100(): void
    {
        self::bootKernel();
        // Four surfaces whose mapped lengths run 3 : 3 : 1 : 1, i.e. exact shares
        // ≈37.5 / 37.5 / 12.5 / 12.5 (geodesic lengths land a hair off exact .5,
        // so independent round-half-up rendered 37 + 38 + 13 + 13 = 101 % — the
        // observed RED); largest-remainder apportionment must keep the rendered
        // sum at 100. Deltas 0.006 / 0.006 / 0.002 / 0.002 tile the route.
        $this->seedSegment('Asphalt', [[5.3, 50.000], [5.3, 50.006]], 'q1');
        $this->seedSegment('Gravel', [[5.3, 50.006], [5.3, 50.012]], 'q2');
        $this->seedSegment('Concrete', [[5.3, 50.012], [5.3, 50.014]], 'q3');
        $this->seedSegment('Compacted', [[5.3, 50.014], [5.3, 50.016]], 'q4');

        $result = $this->profiler()->profile(self::line([[5.3, 50.000], [5.3, 50.016]]));

        self::assertNotNull($result);
        self::assertCount(4, $result['parts']);

        $pcts = array_column($result['parts'], 'pct');
        $sum = array_sum($pcts);
        self::assertLessThanOrEqual(100, $sum, 'apportioned surface percentages must never exceed 100');
        self::assertSame(100, $sum, 'all four parts are kept, so they sum to round(100 · kept/total) = 100');
        // Independent round-half-up rendered these near-.5 shares as 37 + 38 + 13 + 13
        // = 101; largest-remainder apportionment floors to 37/37/12/12 (sum 98) and
        // hands the two leftover points to the two largest fractional remainders.
        self::assertSame([37, 37, 13, 13], $pcts, 'floors plus the two apportioned points, ordered longest-first');
    }

    public function testUnverifiedSurfaceSegmentAloneYieldsNull(): void
    {
        self::bootKernel();
        $this->seedSegment('Surface unverified', [[5.3, 50.0], [5.3, 50.009]], 'unv');

        self::assertNull(
            $this->profiler()->profile(self::line([[5.3, 50.0], [5.3, 50.009]])),
            '"Surface unverified" says nothing — excluded from parts and coverage',
        );
    }

    public function testNoSegmentsNearYieldsNull(): void
    {
        self::bootKernel();

        self::assertNull($this->profiler()->profile(self::line([[5.3, 50.0], [5.3, 50.009]])));
    }

    public function testTwoParallelAsphaltSegmentsDoNotInflateCoverageBeyond100(): void
    {
        self::bootKernel();
        // Same stretch, ~11 m apart — both within the 25 m buffer (double mapping).
        $this->seedSegment('Asphalt', [[5.3, 50.0], [5.3, 50.009]], 'p1');
        $this->seedSegment('Asphalt', [[5.30015, 50.0], [5.30015, 50.009]], 'p2');

        $result = $this->profiler()->profile(self::line([[5.3, 50.0], [5.3, 50.009]]));

        self::assertNotNull($result);
        self::assertSame([['surface' => 'Asphalt', 'pct' => 100]], $result['parts']);
        self::assertLessThanOrEqual(100, $result['covered'], 'route-side coverage cannot exceed 100 despite double mapping');
        self::assertGreaterThanOrEqual(95, $result['covered']);
    }

    public function testRecomputeAllWritesAndClearsSurfacesPreservingOtherKeys(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Route 1: sits on a mapped Asphalt segment, has no surfaces yet — gains one.
        $withCoverageId = $this->seedRoute([[5.3, 50.0], [5.3, 50.009]], ['season' => 'Summer'], 'covered');
        $this->seedSegment('Asphalt', [[5.3, 50.0], [5.3, 50.009]], 'seed');

        // Route 2: far from any segment, carries a STALE surfaces key — loses it.
        $staleId = $this->seedRoute(
            [[40.0, 10.0], [40.0, 10.009]],
            ['season' => 'Winter', 'surfaces' => ['covered' => 50, 'parts' => [['surface' => 'Asphalt', 'pct' => 100]]]],
            'stale',
        );

        $updated = $this->profiler()->recomputeAll();
        self::assertSame(2, $updated);

        $em->clear();
        $withCoverage = $em->find(RecommendedRoute::class, $withCoverageId);
        self::assertNotNull($withCoverage);
        $attrs = $withCoverage->getAttributes();
        self::assertArrayHasKey('surfaces', $attrs, 'a route with coverage gains a surfaces profile');
        self::assertSame('Asphalt', $attrs['surfaces']['parts'][0]['surface']);
        self::assertSame('Summer', $attrs['season'], 'other attribute keys are preserved');

        $stale = $em->find(RecommendedRoute::class, $staleId);
        self::assertNotNull($stale);
        $staleAttrs = $stale->getAttributes();
        self::assertArrayNotHasKey('surfaces', $staleAttrs, 'a route that lost coverage loses its stale surfaces key');
        self::assertSame('Winter', $staleAttrs['season'], 'other attribute keys are preserved');
    }
}
