<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Traffic\TrafficProgress;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Progress per country and region (docs/specs/traffic-measurements.md §4.6):
 * how many roads with data are still building and how many are usable. Counts
 * only; a road's own numbers show nowhere until it is usable. A road's region
 * comes with its lines, from the road-piece tiles.
 */
final class TrafficProgressTest extends KernelTestCase
{
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
    }

    private function region(string $name, string $country): int
    {
        return (int) $this->db->fetchOne(
            "INSERT INTO region (slug, name, country_code, geom, area_km2, created_at, updated_at)
             VALUES (:slug, :name, :cc, ST_GeomFromText('POLYGON((0 0,1 0,1 1,0 1,0 0))', 4326), 1, now(), now())
             RETURNING id",
            ['slug' => 'test-'.bin2hex(random_bytes(4)), 'name' => $name, 'cc' => $country],
        );
    }

    public function testRoadsAreCountedPerCountryAndRegion(): void
    {
        $alpha = $this->region('Alpha', 'ZZ');
        $beta = $this->region('Beta', 'ZZ');
        $this->region('Gamma', 'ZZ');
        $this->region('Delta', 'ZY');

        $progress = new TrafficProgress($this->db)->count(
            [9000001 => $alpha, 9000002 => $alpha, 9000003 => $beta, 9000004 => null],
            [9000002],
            ['zy'],
        );

        $byCountry = array_column($progress['countries'], null, 'country');
        self::assertSame(['ZY', 'ZZ'], array_keys($byCountry), 'onboarded, and every country with data');
        $zz = $byCountry['ZZ'];
        self::assertSame(2, $zz['building']);
        self::assertSame(1, $zz['usable']);
        $byName = array_column($zz['regions'], null, 'name');
        self::assertSame(['name' => 'Alpha', 'building' => 1, 'usable' => 1], $byName['Alpha']);
        self::assertSame(['name' => 'Beta', 'building' => 1, 'usable' => 0], $byName['Beta']);
        self::assertSame(['name' => 'Gamma', 'building' => 0, 'usable' => 0], $byName['Gamma'], 'a region without data still shows');
        self::assertSame(0, $byCountry['ZY']['building'], 'an onboarded country without data still shows');
        self::assertSame(1, $progress['unplaced'], 'a road whose tiles named no region');
    }
}
