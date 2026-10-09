<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog\Import;

use App\Catalog\Import\OsmLinker;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "On top of" is the nearest OSM object within OsmLinker::ON_TOP_M
 * (catalog-data-model.md §5b): when another served row claims it, the pin is
 * that row's duplicate, and the next object a few metres on is not taken.
 */
final class OsmLinkerOnTopTest extends KernelTestCase
{
    use CoverageSchema;

    public function testAClaimedNearestObjectIsNeverSwappedForTheNextOne(): void
    {
        self::bootKernel();
        $db = static::getContainer()->get(Connection::class);
        self::ensureCoverageSchema($db);
        self::insertCoveragePoi($db, ['ref' => 'node/8870001', 'letter' => 'B', 'lat' => 50.3, 'lng' => 4.3]);
        self::insertCoveragePoi($db, ['ref' => 'node/8870002', 'letter' => 'B', 'lat' => 50.30007, 'lng' => 4.3]);
        $holder = (int) $db->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, osm_ref, attributes, created_at, updated_at)
             VALUES ('B', 'Holder', ST_SetSRID(ST_MakePoint(4.3, 50.3), 4326), 'BE', 'unverified', 'user', 'test:ontop:holder', 'node/8870001', '{}', NOW(), NOW())
             RETURNING id",
        );
        $linker = static::getContainer()->get(OsmLinker::class);

        self::assertSame('node/8870001', $linker->nearestOnTop('B', 50.30001, 4.3));
        self::assertNull($linker->onTopOf('B', 50.30001, 4.3, $holder + 1), 'node/8870002 is a neighbour, not this pin');
        self::assertSame('node/8870001', $linker->onTopOf('B', 50.30001, 4.3, $holder), 'the holder itself sits on its own object');
    }
}
