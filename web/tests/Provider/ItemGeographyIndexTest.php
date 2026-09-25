<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Provider;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The provider harvest asks "which item is within 50 m" once per upstream row,
 * on `geom::geography`. The GiST index on `geom` cannot answer that, so a
 * 3 287-row RIVM run read the item table thousands of times (2026-09-25).
 */
final class ItemGeographyIndexTest extends KernelTestCase
{
    public function testMetreRadiusSearchOnItemCanUseAnIndex(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);

        // Seq scan off: the question is whether an index CAN serve it, not
        // whether the planner prefers one on a near-empty test table.
        $db->beginTransaction();
        try {
            $db->executeStatement('SET LOCAL enable_seqscan = off');
            $plan = implode("\n", $db->fetchFirstColumn(
                "EXPLAIN SELECT i.id FROM item i
                  WHERE ST_DWithin(i.geom::geography, ST_SetSRID(ST_MakePoint(5.1084, 52.7702), 4326)::geography, 50)",
            ));
        } finally {
            $db->rollBack();
        }

        self::assertStringContainsString('idx_item_geog', $plan);
    }
}
