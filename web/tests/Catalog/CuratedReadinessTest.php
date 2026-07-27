<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CuratedReadiness;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The gate behind Curated-by-default
 * (2026-07-27-map-view-mode-default-design.md §4). What it counts is the point:
 * only content Curated mode would actually SHOW, so a region full of utility
 * POIs can never unlock a mode that hides them.
 */
final class CuratedReadinessTest extends KernelTestCase
{
    private Connection $db;
    private int $regionId;
    private int $seq = 0;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->beginTransaction();   // rolled back in tearDown — nothing persists

        $this->regionId = (int) $this->db->fetchOne(
            "INSERT INTO region (slug, name, country_code, area_km2, created_at, updated_at)
             VALUES ('readiness-test', 'Readiness Test', 'BE', 100, NOW(), NOW())
             RETURNING id"
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function readiness(int $threshold): CuratedReadiness
    {
        return new CuratedReadiness($this->db, $threshold);
    }

    private function addItem(string $letter, ?string $cur): void
    {
        $attrs = null === $cur ? '{}' : json_encode(['cur' => $cur], \JSON_THROW_ON_ERROR);
        $this->db->executeStatement(
            "INSERT INTO item (letter, name, source, source_ref, state, country_code, attributes, region_id, geom, created_at, updated_at)
             VALUES (:l, 'x', 'manual', :ref, 'verified', 'BE', CAST(:a AS jsonb), :r,
                     ST_SetSRID(ST_MakePoint(4.5, 50.5), 4326), NOW(), NOW())",
            ['l' => $letter, 'ref' => 'readiness:'.$letter.':'.++$this->seq, 'a' => $attrs, 'r' => $this->regionId],
        );
    }

    public function testCountsOnlyCuratedItemsOnExperientialLetters(): void
    {
        $this->addItem('B', 'true');    // curated climb — counts
        $this->addItem('E', '1');       // curated stay — counts
        $this->addItem('I', 'false');   // present but false — must NOT count
        $this->addItem('J', null);      // no cur key at all — must NOT count
        // Utility letters render in BOTH modes, so they are not evidence that
        // Curated has anything to show — the exact trap this gate exists for.
        $this->addItem('C', 'true');
        $this->addItem('D', 'true');
        $this->addItem('G', 'true');

        self::assertSame(2, $this->readiness(25)->countFor($this->regionId));
    }

    public function testUnknownRegionCountsZeroRatherThanThrowing(): void
    {
        self::assertSame(0, $this->readiness(25)->countFor(-1));
    }

    public function testIsReadyComparesAgainstTheConfiguredThreshold(): void
    {
        $this->addItem('B', 'true');
        $this->addItem('B', 'true');

        self::assertFalse($this->readiness(3)->isReady($this->regionId), 'two picks, threshold three');
        self::assertTrue($this->readiness(2)->isReady($this->regionId), 'exactly at the threshold is ready');
        self::assertTrue($this->readiness(1)->isReady($this->regionId));
    }

    public function testBatchFormReportsZeroForRegionsWithNothingCurated(): void
    {
        $this->addItem('B', 'true');
        $other = (int) $this->db->fetchOne(
            "INSERT INTO region (slug, name, country_code, area_km2, created_at, updated_at)
             VALUES ('readiness-test-2', 'Readiness Test 2', 'BE', 50, NOW(), NOW())
             RETURNING id"
        );

        $counts = $this->readiness(25)->countForRegions([$this->regionId, $other]);
        // Present with 0, not absent: the desk renders "0 / 25", never a blank.
        self::assertSame([$this->regionId => 1, $other => 0], $counts);
        self::assertSame([], $this->readiness(25)->countForRegions([]));
    }
}
