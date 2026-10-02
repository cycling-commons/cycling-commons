<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Vote\BallotRegions;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BallotRegionsTest extends KernelTestCase
{
    private Connection $db;
    private BallotRegions $regions;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->regions = static::getContainer()->get(BallotRegions::class);
    }

    private function region(string $slug, string $cc, float $lat, int $level = 4): int
    {
        $polygon = sprintf('POLYGON((5 %1$F,5 %2$F,6 %2$F,6 %1$F,5 %1$F))', $lat, $lat + 1);
        $this->db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES (?, ?, ST_GeomFromText(?, 4326), 1000, ?, ?, ?, 'test', NOW(), NOW())",
            [$slug, ucfirst($slug), $polygon, $cc, strtoupper(substr($slug, 0, 8)), $level],
        );

        return (int) $this->db->fetchOne('SELECT id FROM region WHERE slug = ?', [$slug]);
    }

    public function testACountryListsItsOperationalRegionsByName(): void
    {
        $this->region('xa-outline', 'XA', 50.0, 2);
        $b = $this->region('xa-bravo', 'XA', 50.0);
        $a = $this->region('xa-alpha', 'XA', -34.0);
        $this->region('xb-other', 'XB', 50.0);

        $rows = $this->regions->inCountry('XA');

        self::assertSame([$a, $b], array_column($rows, 'id'));
        self::assertSame(['slug' => 'xa-alpha', 'name' => 'Xa-alpha', 'countryCode' => 'XA', 'mid' => -33.5], array_diff_key($rows[0], ['id' => true]));
    }

    public function testIdsNarrowToThoseRegionsAndNoIdsToNone(): void
    {
        $a = $this->region('xa-alpha', 'XA', 50.0);
        $this->region('xa-bravo', 'XA', 50.0);
        $outline = $this->region('xa-outline', 'XA', 50.0, 2);

        self::assertSame([$a], array_column($this->regions->withIds([$a, $outline]), 'id'));
        self::assertSame([], $this->regions->withIds([]));
    }

    public function testRegionsAreGroupedUnderTheCountryNameInTheReadersLanguage(): void
    {
        $a = $this->region('xa-alpha', 'XA', 50.0);
        $b = $this->region('xb-bravo', 'XB', 50.0);

        $grouped = $this->regions->byCountry('fr');

        $xa = (string) \Locale::getDisplayRegion('-XA', 'fr');
        $xb = (string) \Locale::getDisplayRegion('-XB', 'fr');
        self::assertSame([$a], array_column($grouped[$xa], 'id'));
        self::assertSame([$b], array_column($grouped[$xb], 'id'));
        self::assertSame(array_values(array_filter(array_keys($grouped), static fn (string $k): bool => \in_array($k, [$xa, $xb], true))), [$xa, $xb]);
    }
}
