<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Command\ImportCatalogCommand;
use App\Catalog\RegionRegistryProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `region.adj` is what the map's spotlight unions into its clear hole, so a
 * neighbour list that names something that is not a scope has a visible cost.
 *
 * Every province intersects its own country outline, so the first recompute
 * made the level-2 row a neighbour of all twelve Dutch provinces: selecting
 * North Holland cleared the entire Netherlands, and the neighbour tier the
 * three-tone mask exists for was invisible (owner 2026-08-24).
 *
 * Two adjoining level-4 boxes, plus a level-2 outline covering both.
 */
final class RegionAdjacencyTest extends KernelTestCase
{
    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function seed(string $slug, string $wkt, int $adminLevel): int
    {
        $db = $this->db();
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES (?, ?, ST_GeomFromText(?, 4326), 1000, 'ZZ', ?, ?, 'test', NOW(), NOW())",
            [$slug, ucfirst($slug), $wkt, strtoupper(substr($slug, 0, 6)), $adminLevel],
        );

        return (int) $db->lastInsertId('region_id_seq');
    }

    /** @return list<int> */
    private function adjOf(int $id): array
    {
        /** @var string $raw */
        $raw = $this->db()->fetchOne('SELECT to_json(adj)::text FROM region WHERE id = ?', [$id]);

        return array_map('intval', json_decode($raw, true, 512, \JSON_THROW_ON_ERROR) ?? []);
    }

    public function testACountryOutlineIsNobodysNeighbour(): void
    {
        self::bootKernel();
        $west = $this->seed('adj-west', 'POLYGON((0 0,0 1,1 1,1 0,0 0))', 4);
        $east = $this->seed('adj-east', 'POLYGON((1 0,1 1,2 1,2 0,1 0))', 4);
        $country = $this->seed('adj-country', 'POLYGON((0 0,0 1,2 1,2 0,0 0))', 2);

        $this->db()->executeStatement(ImportCatalogCommand::adjacencySql());

        self::assertSame([$east], $this->adjOf($west), 'the touching province, and only it');
        self::assertSame([$west], $this->adjOf($east));
        self::assertSame([], $this->adjOf($country), 'a row that is not a scope has no neighbours');
    }

    public function testTheRegistryDropsANeighbourItDoesNotCarry(): void
    {
        self::bootKernel();
        $west = $this->seed('adj-reg-west', 'POLYGON((10 0,10 1,11 1,11 0,10 0))', 4);
        $east = $this->seed('adj-reg-east', 'POLYGON((11 0,11 1,12 1,12 0,11 0))', 4);
        $country = $this->seed('adj-reg-country', 'POLYGON((10 0,10 1,12 1,12 0,10 0))', 2);

        // A database that has not run the corrected recompute yet: the stale
        // list still names the outline. The registry must not pass it on.
        $this->db()->executeStatement('UPDATE region SET adj = ? WHERE id = ?',
            ['{'.$east.','.$country.'}', $west]);

        $row = null;
        foreach (static::getContainer()->get(RegionRegistryProvider::class)->all() as $r) {
            if ('adj-reg-west' === $r['slug']) {
                $row = $r;
            }
        }

        self::assertNotNull($row, 'the level-4 region is in the registry');
        self::assertSame([$east], $row['adj'], 'the level-2 id is filtered out, the real neighbour stays');
    }
}
