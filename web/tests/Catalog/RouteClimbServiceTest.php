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
 * foot to summit, in the climbing direction. Real PostGIS: the point is the
 * ST_* share and direction maths.
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

    private function seedRoute(ItemState $state = ItemState::Verified): int
    {
        $route = (new RecommendedRoute())->setName('Climb test route')
            ->setGeom(self::line([[5.8000, 50.4000], [5.8150, 50.4000], [5.8300, 50.4000]]))
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

    /** @return list<array{id: int, name: string, alongKm: float, avgGradient: string|null, ll: array{0: float, 1: float}}> */
    private function climbs(int $routeId, bool $allowSubmitted = false): array
    {
        $result = static::getContainer()->get(RouteClimbService::class)->climbsOn($routeId, $allowSubmitted);
        self::assertNotNull($result);

        return $result;
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

    public function testASubmittedRouteAnswersOnlyWhenAllowed(): void
    {
        $routeId = $this->seedRoute(ItemState::Submitted);
        $id = $this->seedClimb('Preview climb', [[50.4000, 5.8050], [50.4000, 5.8100]]);
        $service = static::getContainer()->get(RouteClimbService::class);

        self::assertNull($service->climbsOn($routeId, false));
        self::assertSame([$id], array_column($service->climbsOn($routeId, true) ?? [], 'id'));
        self::assertNull($service->climbsOn(999_999_999, true), 'no such route');
    }

    public function testClimbingDirectionReadsThroughTheSeamOfALoop(): void
    {
        // Shared points in climb order, located on a loop that starts mid-climb.
        self::assertTrue(RouteClimbService::ridesFootToSummit([0.97, 0.99, 0.002, 0.01]));
        self::assertFalse(RouteClimbService::ridesFootToSummit([0.01, 0.002, 0.99, 0.97]));
        self::assertTrue(RouteClimbService::ridesFootToSummit([0.2, 0.25, 0.3]));
        self::assertFalse(RouteClimbService::ridesFootToSummit([0.3, 0.25, 0.2]));
        self::assertFalse(RouteClimbService::ridesFootToSummit([0.3]));
    }
}
