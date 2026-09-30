<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteClimbService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Climbs on this route" in the route drawer (docs/specs/route-domain.md §6.4).
 * A climb counts when the route follows at least 30% of the climb's own line,
 * foot to summit, in the climbing direction, and reaches its top part (within
 * TOP_M of the summit). Real PostGIS: the point is the ST_* share and
 * direction maths.
 *
 * Geometry: a straight ~2.1 km west to east route at lat 50.4000
 * (lng 5.8000 to 5.8300). At this latitude 0.001° lat ≈ 111 m, 0.001° lng ≈ 71 m.
 */
final class RouteClimbServiceTest extends KernelTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<array{0: float, 1: float}> $lngLat */
    private static function line(array $lngLat): string
    {
        return json_encode(['type' => 'LineString', 'coordinates' => $lngLat], \JSON_THROW_ON_ERROR);
    }

    /** @param list<array{0: float, 1: float}> $lngLat */
    private function seedRoute(ItemState $state = ItemState::Verified, array $lngLat = [[5.8000, 50.4000], [5.8150, 50.4000], [5.8300, 50.4000]]): int
    {
        $route = (new RecommendedRoute())->setName('Climb test route')
            ->setGeom(self::line($lngLat))
            ->setDistanceM(2_130)->setAscentM(0)
            ->setState($state)->setSource(ItemSource::Auto)
            ->setSourceRef('fx:route-climbs-'.bin2hex(random_bytes(4)))
            ->setAttributes([]);
        $this->em()->persist($route);
        $this->em()->flush();

        return (int) $route->getId();
    }

    /**
     * A climb: the pin at the foot, the line foot to summit in `route` as [lat, lng].
     *
     * @param list<array{0: float, 1: float}> $latLng
     */
    private function seedClimb(string $name, array $latLng, ItemState $state = ItemState::Verified, string $avg = '6.1%'): int
    {
        $item = (new Item())->setLetter('N')->setName($name)
            ->setGeom(json_encode(['type' => 'Point', 'coordinates' => [$latLng[0][1], $latLng[0][0]]], \JSON_THROW_ON_ERROR))
            ->setCountryCode('BE')
            ->setState($state)->setSource(ItemSource::Manual)
            ->setSourceRef('climb-'.bin2hex(random_bytes(4)))
            ->setAttributes(['route' => $latLng, 'avgGradient' => $avg, 'maxGradient' => '11%']);
        $this->em()->persist($item);
        $this->em()->flush();

        return (int) $item->getId();
    }

    /**
     * The climbs the route rides: "Climbs on this route".
     *
     * @return list<array{id: int, name: string, alongKm: float, avgGradient: string|null, ll: array{0: float, 1: float}}>
     */
    private function climbs(int $routeId, bool $allowSubmitted = false): array
    {
        $result = static::getContainer()->get(RouteClimbService::class)->climbsOn($routeId, $allowSubmitted);
        self::assertNotNull($result);

        return $result;
    }

    /**
     * climbsAlong() on a line given as [lng, lat] pairs, scaled to its own geodesic length.
     *
     * @param list<array{0: float, 1: float}> $lngLat
     *
     * @return array{ridden: list<array{id: int, name: string, alongKm: float, avgGradient: string|null, ll: array{0: float, 1: float}, distM: int}>, near: list<array{id: int, name: string, alongKm: float, avgGradient: string|null, ll: array{0: float, 1: float}, distM: int}>}
     */
    private function along(array $lngLat = [[5.8000, 50.4000], [5.8150, 50.4000], [5.8300, 50.4000]], int $radiusM = 250): array
    {
        $geoJson = self::line($lngLat);
        $lengthM = (float) $this->em()->getConnection()->fetchOne('SELECT ST_Length(ST_GeomFromGeoJSON(:g)::geography)', ['g' => $geoJson]);

        return static::getContainer()->get(RouteClimbService::class)->climbsAlong($geoJson, $lengthM, $radiusM);
    }

    public function testAClimbTheRouteRidesWholeCountsWithItsKmAndHeadlineGradient(): void
    {
        $routeId = $this->seedRoute();
        $id = $this->seedClimb('Whole climb', [[50.4000, 5.8050], [50.4000, 5.8075], [50.4000, 5.8100]], avg: '8.4%');

        $climbs = $this->climbs($routeId);

        self::assertSame([$id], array_column($climbs, 'id'));
        self::assertSame('Whole climb', $climbs[0]['name']);
        self::assertSame('8.4%', $climbs[0]['avgGradient']);
        // The foot sits 0.005° lng (≈ 355 m) from the route start; km carry one decimal.
        self::assertSame(0.4, $climbs[0]['alongKm']);
        self::assertEqualsWithDelta([50.4, 5.805], $climbs[0]['ll'], 0.0001);
    }

    public function testKmAlongIsTrueDistanceOnARouteThatTurns(): void
    {
        // An L: ~3.34 km north, then ~4.26 km east. The foot sits 0.03° lng
        // into the east leg: 5.47 km along in metres, where the route's
        // fraction in degrees (0.06 of 0.09) times its length says 5.07 km.
        $routeId = $this->seedRoute(lngLat: [[5.8000, 50.4000], [5.8000, 50.4300], [5.8600, 50.4300]]);
        $id = $this->seedClimb('Climb past the corner', [[50.4300, 5.8300], [50.4300, 5.8325], [50.4300, 5.8350]]);

        $climbs = $this->climbs($routeId);

        self::assertSame([$id], array_column($climbs, 'id'));
        self::assertSame(5.5, $climbs[0]['alongKm']);
    }

    public function testAClimbOffsetByGpsDriftStillCounts(): void
    {
        $routeId = $this->seedRoute();
        // 25 m north of the route line, running alongside it.
        $id = $this->seedClimb('Drifted climb', [[50.40022, 5.8050], [50.40022, 5.8100]]);

        self::assertSame([$id], array_column($this->climbs($routeId), 'id'));
    }

    public function testAClimbTheRouteOnlyCrossesDoesNotCount(): void
    {
        $routeId = $this->seedRoute();
        // ≈ 1.1 km north to south, crossing the route at right angles.
        $this->seedClimb('Crossing climb', [[50.3950, 5.8150], [50.4050, 5.8150]]);

        self::assertSame([], $this->climbs($routeId));
    }

    public function testAClimbTheRouteSharesOnlyAFifthOfDoesNotCount(): void
    {
        $routeId = $this->seedRoute();
        // ≈ 200 m along the route, then ≈ 800 m north away from it.
        $this->seedClimb('Fifth shared', [[50.4000, 5.8200], [50.4000, 5.8228], [50.4072, 5.8228]]);

        self::assertSame([], $this->climbs($routeId));
    }

    /** A race route rides only part of a climb (Liège-Bastogne-Liège, owner 2026-09-15): 30% is enough. */
    public function testAClimbTheRouteSharesFortyPercentOfCounts(): void
    {
        $routeId = $this->seedRoute();
        // ≈ 400 m along the route, then ≈ 600 m north away from it.
        $id = $this->seedClimb('Forty shared', [[50.4000, 5.8150], [50.4000, 5.8206], [50.4054, 5.8206]]);

        self::assertSame([$id], array_column($this->climbs($routeId), 'id'));
    }

    public function testAClimbTheRouteSharesMoreThanHalfOfCounts(): void
    {
        $routeId = $this->seedRoute();
        // ≈ 700 m along the route, then ≈ 300 m north away from it.
        $id = $this->seedClimb('Mostly shared', [[50.4000, 5.8100], [50.4000, 5.8198], [50.4027, 5.8198]]);

        self::assertSame([$id], array_column($this->climbs($routeId), 'id'));
    }

    public function testARouteRidingTheClimbDownhillDoesNotCount(): void
    {
        $routeId = $this->seedRoute();
        // Foot in the east, summit in the west: the route rides it summit to foot.
        $this->seedClimb('Descent', [[50.4000, 5.8280], [50.4000, 5.8230]]);

        self::assertSame([], $this->climbs($routeId));
    }

    /**
     * A route that joins a climb partway up and rides it to the summit climbs
     * it, and the ascent starts where it joins (owner 2026-09-30).
     */
    public function testARouteThatJoinsPartwayUpAndRidesToTheSummitCounts(): void
    {
        $routeId = $this->seedRoute();
        // Foot ≈ 670 m north of the route, down to it at 5.8150, then
        // ≈ 710 m east along it to the summit: the route rides the top 51%.
        $id = $this->seedClimb('Joined partway up', [[50.4060, 5.8150], [50.4000, 5.8150], [50.4000, 5.8250]]);

        $climbs = $this->climbs($routeId);

        self::assertSame([$id], array_column($climbs, 'id'));
        // The join sits 0.015° lng (≈ 1.06 km) from the route start.
        self::assertSame(1.1, $climbs[0]['alongKm']);
    }

    /**
     * The same shape ridden the other way: the route reaches the climb at its
     * summit and rides it down to where the climb leaves the road. That is a
     * descent, however much of the line it shares (Côte de Mont-le-Soie on
     * Liège-Bastogne-Liège, dev route 111: 86% of the line, all downhill).
     */
    public function testARouteThatRidesFromTheSummitDownToWhereItLeavesDoesNotCount(): void
    {
        $routeId = $this->seedRoute();
        // Foot ≈ 670 m north of the route at 5.8250, down to it, then west
        // along it to the summit at 5.8150; the route runs west to east.
        $this->seedClimb('Descended from the top', [[50.4060, 5.8250], [50.4000, 5.8250], [50.4000, 5.8150]]);

        self::assertSame([], $this->climbs($routeId));
    }

    /**
     * Partway up counts from 30% of the line (MIN_SHARE): a route that rides
     * only the top quarter of a climb to its summit passes over the top and is
     * not listed.
     */
    public function testARouteRidingOnlyTheTopQuarterToTheSummitDoesNotCount(): void
    {
        $routeId = $this->seedRoute();
        // Foot 1 km north of the route, down to it at 5.8150, then ≈ 330 m
        // east along it to the summit: 25% of the line.
        $this->seedClimb('Top quarter', [[50.4090, 5.8150], [50.4000, 5.8150], [50.4000, 5.8196]]);

        self::assertSame([], $this->climbs($routeId));
    }

    public function testClimbsAreListedInRouteOrderNotIdOrder(): void
    {
        $routeId = $this->seedRoute();
        $late = $this->seedClimb('Late climb', [[50.4000, 5.8200], [50.4000, 5.8260]]);
        $early = $this->seedClimb('Early climb', [[50.4000, 5.8020], [50.4000, 5.8080]]);

        $climbs = $this->climbs($routeId);

        self::assertSame([$early, $late], array_column($climbs, 'id'));
        self::assertLessThan($climbs[1]['alongKm'], $climbs[0]['alongKm']);
    }

    public function testOnlyServedClimbsAreListed(): void
    {
        $routeId = $this->seedRoute();
        $this->seedClimb('Waiting climb', [[50.4000, 5.8050], [50.4000, 5.8100]], ItemState::Submitted);
        $gone = $this->seedClimb('Gone climb', [[50.4000, 5.8150], [50.4000, 5.8200]]);
        $this->em()->getConnection()->executeStatement(
            "UPDATE item SET attributes = attributes || '{\"condition\": \"Not there anymore\"}' WHERE id = :id",
            ['id' => $gone],
        );

        self::assertSame([], $this->climbs($routeId));
    }

    public function testAClimbWithNoUsableLineIsSkippedNotAnError(): void
    {
        $routeId = $this->seedRoute();
        $id = $this->seedClimb('Whole climb', [[50.4000, 5.8050], [50.4000, 5.8075], [50.4000, 5.8100]]);
        $bare = $this->seedClimb('Bare climb', [[50.4000, 5.8150], [50.4000, 5.8200]]);
        $this->em()->getConnection()->executeStatement(
            "UPDATE item SET attributes = attributes || '{\"route\": \"not a line\"}' WHERE id = :id",
            ['id' => $bare],
        );

        self::assertSame([$id], array_column($this->climbs($routeId), 'id'));
    }

    public function testASubmittedRouteAnswersOnlyWhenAllowed(): void
    {
        $routeId = $this->seedRoute(ItemState::Submitted);
        $id = $this->seedClimb('Preview climb', [[50.4000, 5.8050], [50.4000, 5.8100]]);
        $service = static::getContainer()->get(RouteClimbService::class);

        self::assertNull($service->climbsOn($routeId, false));
        self::assertSame([$id], array_column($service->climbsOn($routeId, true) ?? [], 'id'));
        self::assertNull($service->climbsOn(999_999_999, true), 'no such route');
    }

    public function testClimbsAlongSplitsTheRiddenClimbsFromTheNearOnesEachInKmOrder(): void
    {
        $routeId = $this->seedRoute();
        // Crossed at right angles near the end of the route: near, at the crossing.
        $crossing = $this->seedClimb('Crossing climb', [[50.3950, 5.8250], [50.4050, 5.8250]]);
        $ridden = $this->seedClimb('Ridden climb', [[50.4000, 5.8150], [50.4000, 5.8200]]);
        // Parallel ≈ 150 m north of the route: near, where the route comes closest.
        $beside = $this->seedClimb('Climb over the hedge', [[50.40135, 5.8050], [50.40135, 5.8100]]);
        // ≈ 560 m north: beyond the drawer's 250 m corridor.
        $this->seedClimb('Far climb', [[50.4050, 5.8050], [50.4050, 5.8100]]);

        $along = $this->along();

        self::assertSame([$ridden], array_column($along['ridden'], 'id'));
        self::assertSame([$beside, $crossing], array_column($along['near'], 'id'), 'the near ones in km order, the far one absent, the ridden one not again');
        self::assertEqualsWithDelta(1.1, $along['ridden'][0]['alongKm'], 0.05, 'a ridden row is where the ascent starts');
        self::assertEqualsWithDelta(0.4, $along['near'][0]['alongKm'], 0.05, 'a near row is where the route comes closest to the climb line');
        self::assertEqualsWithDelta(1.8, $along['near'][1]['alongKm'], 0.05);
        self::assertEqualsWithDelta(150, $along['near'][0]['distM'], 5);
        self::assertSame('6.1%', $along['near'][0]['avgGradient']);
        self::assertEqualsWithDelta([50.40135, 5.8050], $along['near'][0]['ll'], 0.00001, 'a near climb stands at its foot');
        // "Climbs on this route" is the ridden list, at the same km.
        self::assertSame([$ridden], array_column($this->climbs($routeId), 'id'));
        self::assertSame($along['ridden'][0]['alongKm'], $this->climbs($routeId)[0]['alongKm']);
    }

    public function testAClimbTheRouteRidesDownhillIsNear(): void
    {
        $down = $this->seedClimb('Ridden down', [[50.4000, 5.8200], [50.4000, 5.8150]]);

        $along = $this->along();

        self::assertSame([], $along['ridden']);
        self::assertSame([$down], array_column($along['near'], 'id'));
    }

    public function testTheNearCorridorIsTheRadiusGiven(): void
    {
        // ≈ 560 m north of the route.
        $far = $this->seedClimb('Far climb', [[50.4050, 5.8050], [50.4050, 5.8100]]);

        self::assertSame([], $this->along(radiusM: 250)['near']);
        self::assertSame([$far], array_column($this->along(radiusM: 1000)['near'], 'id'));
    }

    /** A ~10.6 km route west to east at lat 50.4000, for climbs of about 10 km. */
    private const array LONG_ROUTE = [[5.8000, 50.4000], [5.9500, 50.4000]];

    /**
     * A route that rides the middle 55% of a long climb and leaves it 2.5 km
     * below the summit passes it: it does not reach the top part (TOP_M).
     */
    public function testARouteRidingTheMiddleOfALongClimbIsNear(): void
    {
        $routeId = $this->seedRoute(lngLat: self::LONG_ROUTE);
        // ≈ 2.0 km north up to the route at 5.8200, ≈ 5.5 km east along it,
        // then ≈ 2.5 km north away from it to the summit: ≈ 10 km in all.
        $id = $this->seedClimb('Middle ridden', [[50.3820, 5.8200], [50.4000, 5.8200], [50.4000, 5.8970], [50.4225, 5.8970]]);

        self::assertSame([], $this->climbs($routeId));
        $along = $this->along(self::LONG_ROUTE);
        self::assertSame([], $along['ridden']);
        self::assertSame([$id], array_column($along['near'], 'id'), 'it is near the route instead');
    }

    /** A route that joins a long climb at 40% and rides it to the summit climbs it. */
    public function testARouteJoiningALongClimbAtFortyPercentAndRidingToTheSummitCounts(): void
    {
        $routeId = $this->seedRoute(lngLat: self::LONG_ROUTE);
        // Foot ≈ 4.0 km north of the route at 5.8200, down to it, then
        // ≈ 6.0 km east along it to the summit.
        $id = $this->seedClimb('Joined at forty', [[50.4360, 5.8200], [50.4000, 5.8200], [50.4000, 5.9046]]);

        $climbs = $this->climbs($routeId);

        self::assertSame([$id], array_column($climbs, 'id'));
        // The join sits 0.02° lng (≈ 1.42 km) from the route start.
        self::assertEqualsWithDelta(1.4, $climbs[0]['alongKm'], 0.05);
    }

    /**
     * Out and back: the route rides the straight line east, then back west
     * about 10 m north of it, as a recorded ride does. Both passes lie inside
     * the climb's band, so each is read on its own.
     */
    private function seedOutAndBackRoute(): int
    {
        return $this->seedRoute(lngLat: [
            [5.8000, 50.4000], [5.8150, 50.4000], [5.8300, 50.4000],
            [5.8300, 50.4001], [5.8150, 50.4001], [5.8000, 50.4001],
        ]);
    }

    public function testAnOutAndBackRouteCountsAClimbItRidesUpOnTheWayBack(): void
    {
        $routeId = $this->seedOutAndBackRoute();
        // Foot in the east, summit in the west: down on the way out, up on the way back.
        $id = $this->seedClimb('Back up', [[50.4000, 5.8280], [50.4000, 5.8230]]);

        $climbs = $this->climbs($routeId);

        self::assertSame([$id], array_column($climbs, 'id'));
        // ≈ 2.13 km out, ≈ 11 m north to the return pass, then ≈ 142 m back west to the foot.
        self::assertSame(2.3, $climbs[0]['alongKm']);
    }

    public function testAnOutAndBackRouteCountsAClimbItRidesUpOnTheWayOut(): void
    {
        $routeId = $this->seedOutAndBackRoute();
        // Foot in the west, summit in the east: up on the way out, down on the way back.
        $id = $this->seedClimb('Out up', [[50.4000, 5.8230], [50.4000, 5.8280]]);

        $climbs = $this->climbs($routeId);

        self::assertSame([$id], array_column($climbs, 'id'));
        self::assertSame(1.6, $climbs[0]['alongKm']);
    }

    public function testARouteThatRidesTheClimbDownTwiceDoesNotCount(): void
    {
        // Two laps of a loop that rides the climb only downhill.
        $routeId = $this->seedRoute(lngLat: [
            [5.8000, 50.4000], [5.8300, 50.4000], [5.8300, 50.4050], [5.8000, 50.4050],
            [5.8000, 50.4001], [5.8300, 50.4001], [5.8300, 50.4049], [5.8000, 50.4049], [5.8000, 50.4002],
        ]);
        $this->seedClimb('Descent twice', [[50.4000, 5.8280], [50.4000, 5.8230]]);

        self::assertSame([], $this->climbs($routeId));
    }

    /** Pieces are [fromM, toM, fromCf, toCf, footM] along a 10 km route over a 2 km climb. */
    public function testEveryPassIsReadOnItsOwn(): void
    {
        // Up then back down the same road: the way up counts from the foot.
        self::assertSame(1_000.0, RouteClimbService::ascent([
            [1_000.0, 3_000.0, 0.0, 1.0, 1_000.0],
            [3_000.0, 5_000.0, 1.0, 0.0, 5_000.0],
        ], 10_000.0, 2_000.0));
        // Down then back up: the way up starts where the route turns at the foot.
        self::assertSame(5_000.0, RouteClimbService::ascent([
            [3_000.0, 5_000.0, 1.0, 0.0, 5_000.0],
            [5_000.0, 7_000.0, 0.0, 1.0, 5_000.0],
        ], 10_000.0, 2_000.0));
        // Only down, however often.
        self::assertNull(RouteClimbService::ascent([
            [1_000.0, 3_000.0, 1.0, 0.0, 3_000.0],
            [6_000.0, 8_000.0, 1.0, 0.0, 8_000.0],
        ], 10_000.0, 2_000.0));
    }

    public function testAStretchRiddenUpTwiceCountsOnce(): void
    {
        // The same fifth of the climb, twice: 20% of the line, not 40%.
        self::assertNull(RouteClimbService::ascent([
            [1_000.0, 1_400.0, 0.1, 0.3, 1_000.0],
            [5_000.0, 5_400.0, 0.1, 0.3, 5_000.0],
        ], 10_000.0, 2_000.0));
    }

    public function testAnAscentRunsOnWhereTheRouteLeavesTheBandBriefly(): void
    {
        // 25% up, 100 m outside the band, 25% more: one ascent from the first piece.
        self::assertSame(2_000.0, RouteClimbService::ascent([
            [2_000.0, 2_500.0, 0.0, 0.25, 2_000.0],
            [2_600.0, 3_100.0, 0.3, 0.55, 2_600.0],
        ], 10_000.0, 2_000.0));
        // A brief rise where the route crosses the climb early on is its own pass,
        // not the start of the ascent 5 km later.
        self::assertSame(6_000.0, RouteClimbService::ascent([
            [1_000.0, 1_080.0, 0.0, 0.01, 1_000.0],
            [6_000.0, 7_000.0, 0.02, 0.6, 6_000.0],
        ], 10_000.0, 2_000.0));
    }

    /** Pieces along a 10 km climb: the ascent must end within TOP_M (2 km) of the summit. */
    public function testAnAscentMustReachTheTopPartOfTheClimb(): void
    {
        self::assertSame(1_000.0, RouteClimbService::ascent([[1_000.0, 9_000.0, 0.0, 0.8, 1_000.0]], 20_000.0, 10_000.0), '2 km below the top');
        self::assertNull(RouteClimbService::ascent([[1_000.0, 8_900.0, 0.0, 0.79, 1_000.0]], 20_000.0, 10_000.0), '2.1 km below the top');
        // A first pass rides the lower 60%; a later one rides the top half:
        // the route's ascent is the one that reaches the top.
        self::assertSame(12_000.0, RouteClimbService::ascent([
            [1_000.0, 7_000.0, 0.0, 0.6, 1_000.0],
            [12_000.0, 17_000.0, 0.5, 1.0, 12_000.0],
        ], 20_000.0, 10_000.0));
    }

    public function testClimbingDirectionReadsThroughTheSeamOfALoop(): void
    {
        // A loop that starts partway up the climb: the ascent starts late in the
        // route at the foot and runs on through the seam to the summit.
        self::assertSame(9_600.0, RouteClimbService::ascent([
            [0.0, 1_200.0, 0.4, 1.0, 0.0],
            [9_600.0, 10_000.0, 0.0, 0.4, 9_600.0],
        ], 10_000.0, 2_000.0));
        self::assertNull(RouteClimbService::ascent([
            [0.0, 1_200.0, 1.0, 0.4, 1_200.0],
            [9_600.0, 10_000.0, 0.4, 0.0, 10_000.0],
        ], 10_000.0, 2_000.0));
    }
}
