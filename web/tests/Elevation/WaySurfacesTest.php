<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Elevation;

use App\Elevation\WaySurfaces;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The recorded surfaces under a planned leg (docs/specs/route-planner.md section 4.4a), read from the table the
 * weekly surface pipeline fills per country (docs/specs/coverage-provider.md section 4). A country is read only once
 * its ways are loaded: before that the engine's own words stand, never a leg of "not recorded".
 */
final class WaySurfacesTest extends KernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->db = $db;
        // The pipeline's own DDL (pipeline/coverage/way_surface.py); the test transaction takes it away again.
        $db->executeStatement('CREATE TABLE IF NOT EXISTS coverage_way_surface (way_id bigint PRIMARY KEY, country_code text NOT NULL, surface text, smoothness text, tracktype text)');
        $db->executeStatement('CREATE TABLE IF NOT EXISTS coverage_way_surface_country (country_code text PRIMARY KEY, inputs text NOT NULL, ways integer NOT NULL, loaded_at timestamptz NOT NULL DEFAULT now())');
        $db->executeStatement("INSERT INTO region (slug, name, geom, area_km2, country_code, admin_level, created_at, updated_at)
             VALUES ('way-surfaces-be', 'Way surfaces BE', ST_SetSRID(ST_MakeEnvelope(5.0, 50.0, 6.0, 51.0), 4326), 100, 'BE', 4, NOW(), NOW()),
                    ('way-surfaces-nl', 'Way surfaces NL', ST_SetSRID(ST_MakeEnvelope(4.5, 52.0, 5.5, 53.0), 4326), 100, 'NL', 4, NOW(), NOW())");
        $db->executeStatement("INSERT INTO coverage_way_surface (way_id, country_code, surface, smoothness, tracktype) VALUES
             (107819295, 'BE', 'paved', 'good', NULL), (15, 'BE', NULL, NULL, 'grade3')");
    }

    public function testALoadedCountryAnswersWhatIsRecordedAndLeavesTheRestOut(): void
    {
        $this->db->executeStatement("INSERT INTO coverage_way_surface_country (country_code, inputs, ways) VALUES ('BE', 'x', 2)");

        $got = (new WaySurfaces($this->db))->forLeg(50.49, 5.86, [107819295, 15, 99]);

        self::assertSame([
            107819295 => ['surface' => 'paved', 'smoothness' => 'good', 'tracktype' => null],
            15 => ['surface' => null, 'smoothness' => null, 'tracktype' => 'grade3'],
        ], $got);
    }

    public function testALegOutsideEveryLoadedCountryAsksNothing(): void
    {
        $this->db->executeStatement("INSERT INTO coverage_way_surface_country (country_code, inputs, ways) VALUES ('BE', 'x', 2)");

        self::assertNull((new WaySurfaces($this->db))->forLeg(52.64, 5.05, [107819295]), 'North Holland is not loaded');
        self::assertNull((new WaySurfaces($this->db))->forLeg(45.0, 2.0, [107819295]), 'outside every region');
    }

    public function testNoCountryLoadedYetAsksNothing(): void
    {
        self::assertNull((new WaySurfaces($this->db))->forLeg(50.49, 5.86, [107819295]));
    }
}
