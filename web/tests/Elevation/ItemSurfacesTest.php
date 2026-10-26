<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Elevation;

use App\Elevation\ItemSurfaces;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The curated road surfaces along a planned leg (docs/specs/route-planner.md section 4.4a): the road surface rows a
 * curator or a rider set (layer A), served ones only, close to the leg. Nothing from further away, from another layer
 * or from a row the map does not show comes back.
 */
final class ItemSurfacesTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->db = $db;
    }

    private function item(string $ref, string $letter, string $state, string $wkt, string $surface, ?string $smoothness): void
    {
        $this->db->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES (:letter, :ref, ST_SetSRID(ST_GeomFromText(:wkt), 4326), 'NL', :state, 'osm', :ref, :attrs, NOW(), NOW())",
            ['letter' => $letter, 'ref' => $ref, 'wkt' => $wkt, 'state' => $state,
                'attrs' => json_encode(array_filter(['surface' => $surface, 'smoothness' => $smoothness]), \JSON_THROW_ON_ERROR)],
        );
    }

    public function testOnlyServedRoadSurfacesCloseToTheLegComeBack(): void
    {
        // The leg runs north along 5.1 E; the dike beside it is curated Good.
        $this->item('test/dike', 'A', 'verified', 'LINESTRING(5.1 52.60, 5.1 52.62)', 'Asphalt', 'Good');
        $this->item('test/unverified', 'A', 'unverified', 'LINESTRING(5.1001 52.60, 5.1001 52.62)', 'Gravel', null);
        // None of these may reach the leg's figures.
        $this->item('test/rejected', 'A', 'rejected', 'LINESTRING(5.1 52.60, 5.1 52.62)', 'Sand', 'Bad');
        $this->item('test/submitted', 'A', 'submitted', 'LINESTRING(5.1 52.60, 5.1 52.62)', 'Sand', 'Bad');
        $this->item('test/far', 'A', 'verified', 'LINESTRING(5.12 52.60, 5.12 52.62)', 'Sand', 'Bad');
        $this->item('test/water', 'W', 'verified', 'LINESTRING(5.1 52.60, 5.1 52.62)', 'Sand', 'Bad');

        $got = (new ItemSurfaces($this->db))->near([[52.600, 5.1], [52.610, 5.1], [52.620, 5.1]]);

        usort($got, static fn (array $a, array $b): int => strcmp((string) $a['surface'], (string) $b['surface']));
        self::assertCount(2, $got, 'the verified and the unverified rows the map shows, nothing else');
        self::assertSame('Asphalt', $got[0]['surface']);
        self::assertSame('Good', $got[0]['smoothness']);
        self::assertSame([[52.6, 5.1], [52.62, 5.1]], $got[0]['line'], '[lat, lng] pairs');
        self::assertSame('Gravel', $got[1]['surface']);
        self::assertNull($got[1]['smoothness']);
    }

    public function testAShortLegAsksNothing(): void
    {
        self::assertSame([], (new ItemSurfaces($this->db))->near([[52.6, 5.1]]));
    }
}
