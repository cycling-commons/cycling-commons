<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Region;
use App\Catalog\RegionDerivations;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A region page's drawing leaves the big lakes out (map-and-search.md §4.6):
 * a lake inside a region is a hole in its shape, one on its edge cuts the
 * edge back, and the pipeline's refresh function draws with the same SQL
 * as the app. DAMA wraps each test in a rolled-back transaction, the lake
 * table the pipeline owns included.
 */
final class RegionSilhouetteLakesTest extends KernelTestCase
{
    public function testALakeInsideARegionIsAHoleAndOneOnItsEdgeCutsItBack(): void
    {
        self::bootKernel();
        $this->region('XL', 2, [[0.0, 50.0], [4.0, 50.0], [4.0, 53.0], [0.0, 53.0], [0.0, 50.0]]);
        $inside = $this->region('XL', 4, [[0.0, 50.0], [2.0, 50.0], [2.0, 53.0], [0.0, 53.0], [0.0, 50.0]]);
        $this->lake([[0.5, 51.0], [1.5, 51.0], [1.5, 52.0], [0.5, 52.0], [0.5, 51.0]]);
        // Half over the east edge of the second region, half outside the country.
        $edge = $this->region('XL', 4, [[2.0, 50.0], [4.0, 50.0], [4.0, 53.0], [2.0, 53.0], [2.0, 50.0]]);
        $this->lake([[3.5, 51.0], [4.5, 51.0], [4.5, 52.0], [3.5, 52.0], [3.5, 51.0]]);

        $this->db()->executeStatement(RegionDerivations::silhouetteSql());

        $rings = $this->silhouette($inside);
        self::assertCount(2, $rings, 'the outer edge and the lake');
        self::assertEqualsWithDelta(0.5, min(self::xs($rings[1])), 0.01, 'the hole is the lake');

        $rings = $this->silhouette($edge);
        self::assertCount(1, $rings, 'a lake on the edge is no hole');
        self::assertContains(3.5, self::xs($rings[0]), 'it cuts the edge back to the shore');
    }

    public function testThePipelinesRefreshDrawsWithTheAppsSql(): void
    {
        self::bootKernel();
        $source = (string) $this->db()->fetchOne("SELECT prosrc FROM pg_proc WHERE proname = 'region_silhouettes_refresh'");

        self::assertStringContainsString(RegionDerivations::silhouetteSql(), $source,
            'RegionDerivations::silhouetteSql() changed: ship a migration that runs Version20261025010000::refreshFunction() again');
    }

    /** @param list<array{0: float, 1: float}> $ring */
    private function region(string $cc, int $level, array $ring): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $region = (new Region())->setSlug('lakes-'.bin2hex(random_bytes(4)))->setName('Lakes '.$cc.' '.$level)
            ->setCountryCode($cc)->setAdminLevel($level)
            ->setGeom((string) json_encode(['type' => 'MultiPolygon', 'coordinates' => [[$ring]]]));
        $em->persist($region);
        $em->flush();

        return (int) $region->getId();
    }

    /** @param list<array{0: float, 1: float}> $ring */
    private function lake(array $ring): void
    {
        $this->db()->executeStatement('CREATE TABLE IF NOT EXISTS coverage_water (
            id bigserial PRIMARY KEY, extract text NOT NULL, osm_ref text NOT NULL, name text NULL,
            area_km2 double precision NOT NULL, geom geometry(MultiPolygon, 4326) NOT NULL)');
        $this->db()->executeStatement(
            "INSERT INTO coverage_water (extract, osm_ref, area_km2, geom) VALUES ('test/lakes', 'way/1', 100, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(:g), 4326)))",
            ['g' => json_encode(['type' => 'Polygon', 'coordinates' => [$ring]], \JSON_THROW_ON_ERROR)],
        );
    }

    /** @return list<list<float>> */
    private function silhouette(int $id): array
    {
        /** @var list<list<float>> $rings */
        $rings = json_decode((string) $this->db()->fetchOne('SELECT silhouette FROM region WHERE id = :id', ['id' => $id]), true, 512, \JSON_THROW_ON_ERROR);

        return $rings;
    }

    /**
     * @param list<float> $ring flat [x, y, x, y, ...]
     *
     * @return list<float>
     */
    private static function xs(array $ring): array
    {
        return array_values(array_filter($ring, static fn (int $i): bool => 0 === $i % 2, \ARRAY_FILTER_USE_KEY));
    }

    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
