<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Town;

use App\Town\TownRoutes;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which recommended routes count as passing through a town.
 *
 * The town card lists the races Wikidata ties to a place, which answers what
 * happened here and not what you can ride from here: the Westfriese
 * Omringdijk at Hoorn and the Great Divide at Banff live in the map's own
 * routes layer and are found by geometry (known issue, 2026-09-06).
 */
final class TownRoutesTest extends KernelTestCase
{
    private Connection $db;
    private TownRoutes $routes;

    protected function setUp(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $routes = self::getContainer()->get(TownRoutes::class);
        self::assertInstanceOf(TownRoutes::class, $routes);
        $this->routes = $routes;
    }

    public function testARouteThroughTheTownIsListedWithItsLength(): void
    {
        // A line straight through 50.4260 N, 6.0270 E.
        $this->seedRoute('Through the town', 'LINESTRING(6.010 50.426, 6.045 50.426)', 42_000);

        $found = $this->routes->near(50.4260, 6.0270);

        self::assertCount(1, $found);
        self::assertSame('Through the town', $found[0]['name']);
        self::assertSame(42_000, $found[0]['distanceM']);
    }

    /**
     * A route the next valley over is not "through here".
     *
     * The radius is measured from the town's OpenStreetMap node, so it has to
     * be loose enough for a bypass along the edge and tight enough to stop at
     * the next village.
     */
    public function testARouteBeyondTheRadiusIsNotListed(): void
    {
        $this->seedRoute('Far away', 'LINESTRING(6.200 50.500, 6.240 50.500)', 10_000);

        self::assertSame([], $this->routes->near(50.4260, 6.0270));
    }

    /** A route nobody has accepted yet is not on the map, so not on the card. */
    public function testAnUnservedRouteIsNotListed(): void
    {
        $this->seedRoute('Still waiting', 'LINESTRING(6.010 50.426, 6.045 50.426)', 5_000, 'submitted');

        self::assertSame([], $this->routes->near(50.4260, 6.0270));
    }

    /** Nearest first: the one you are standing on comes before the one you are not. */
    public function testTheNearestRouteComesFirst(): void
    {
        $this->seedRoute('Eight hundred metres off', 'LINESTRING(6.010 50.4332, 6.045 50.4332)', 1_000);
        $this->seedRoute('Straight through', 'LINESTRING(6.010 50.426, 6.045 50.426)', 2_000);

        $found = $this->routes->near(50.4260, 6.0270);

        self::assertSame(['Straight through', 'Eight hundred metres off'], array_column($found, 'name'));
    }

    private function seedRoute(string $name, string $wkt, int $metres, string $state = 'unverified'): void
    {
        $this->db->executeStatement(
            "INSERT INTO recommended_route (name, geom, distance_m, state, source, source_ref, attributes, created_at, updated_at)
             VALUES (:name, ST_SetSRID(ST_GeomFromText(:wkt), 4326), :m, :state, 'user', :ref, '{}', NOW(), NOW())",
            ['name' => $name, 'wkt' => $wkt, 'm' => $metres, 'state' => $state, 'ref' => 'test-'.bin2hex(random_bytes(4))],
        );
    }
}
