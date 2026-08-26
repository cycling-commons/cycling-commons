<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CuratedReadiness;
use App\Settings\SettingsRegistry;
use App\Tests\Settings\FakeSettings;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The gate behind Curated-by-default.
 * What it counts is the point:
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

    private function readiness(int $threshold, int $minBlocks = 1, int $minPerBlock = 1): CuratedReadiness
    {
        // The three numbers are settings now, not constructor scalars — see
        // system-configuration.md §3. Seeding them explicitly keeps each case
        // stating the gate it is testing, exactly as the old signature did.
        return new CuratedReadiness($this->db, new FakeSettings([
            SettingsRegistry::MAP_CURATED_THRESHOLD => $threshold,
            SettingsRegistry::MAP_CURATED_MIN_BLOCKS => $minBlocks,
            SettingsRegistry::MAP_CURATED_MIN_PER_BLOCK => $minPerBlock,
        ]));
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

        self::assertSame(2, $this->readiness(25)->reportFor($this->regionId)['total']);
    }

    public function testUnknownRegionCountsZeroRatherThanThrowing(): void
    {
        self::assertSame(0, $this->readiness(25)->reportFor(-1)['total']);
    }

    public function testIsReadyComparesAgainstTheConfiguredThreshold(): void
    {
        $this->addItem('B', 'true');
        $this->addItem('B', 'true');

        // minBlocks 1 here so this stays a test of the TOTAL alone; breadth has
        // its own tests below.
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

        $reports = $this->readiness(25)->reportForRegions([$this->regionId, $other]);
        self::assertSame(1, $reports[$this->regionId]['total']);
        // Present with a zero for EVERY block, not absent: the desk renders a
        // chip per block with a number in it, never a blank.
        self::assertSame(0, $reports[$other]['total']);
        self::assertSame(array_keys(CuratedReadiness::BLOCKS), array_keys($reports[$other]['blocks']));
        self::assertSame([0, 0, 0, 0, 0, 0], array_values($reports[$other]['blocks']));
        self::assertSame([], $this->readiness(25)->reportForRegions([]));
    }

    /**
     * Breadth, the owner's question of 2026-07-27: a flat total can be met by
     * 25 scenic views and nothing else, and Curated then shows a region with
     * nowhere to sleep and no routes. The gate requires the total to be spread.
     */
    public function testATotalCarriedByOneBlockIsNotReady(): void
    {
        for ($i = 0; $i < 25; ++$i) {
            $this->addItem('I', 'true');   // all scenic views
        }
        $r = $this->readiness(25, minBlocks: 3, minPerBlock: 5);
        $rep = $r->reportFor($this->regionId);

        self::assertSame(25, $rep['total'], 'the total is met');
        self::assertSame(1, $rep['blocksMet'], 'by exactly one block');
        self::assertSame(0, $rep['shortTotal']);
        self::assertSame(2, $rep['shortBlocks'], 'two more blocks must carry their share');
        self::assertFalse($rep['ready']);
        self::assertFalse($r->isReady($this->regionId));
    }

    public function testATotalSpreadOverEnoughBlocksIsReady(): void
    {
        foreach (['N' => 9, 'O' => 8, 'P' => 8] as $letter => $n) {
            for ($i = 0; $i < $n; ++$i) {
                $this->addItem($letter, 'true');
            }
        }
        $rep = $this->readiness(25, minBlocks: 3, minPerBlock: 5)->reportFor($this->regionId);

        self::assertSame(25, $rep['total']);
        self::assertSame(3, $rep['blocksMet']);
        self::assertTrue($rep['ready']);
    }

    /**
     * A region can be short on breadth OR on total, and the desk states each
     * separately — a moderator should not have to subtract to find the gap.
     */
    public function testBreadthMetButTotalShortReportsOnlyTheTotalGap(): void
    {
        foreach (['N', 'O', 'P'] as $letter) {
            for ($i = 0; $i < 5; ++$i) {
                $this->addItem($letter, 'true');
            }
        }
        $rep = $this->readiness(25, minBlocks: 3, minPerBlock: 5)->reportFor($this->regionId);

        self::assertSame(15, $rep['total']);
        self::assertSame(3, $rep['blocksMet']);
        self::assertSame(10, $rep['shortTotal']);
        self::assertSame(0, $rep['shortBlocks']);
        self::assertFalse($rep['ready']);
    }

    /** minBlocks: 1 is the documented escape hatch back to a pure total. */
    public function testMinBlocksOfOneRevertsToAPureTotal(): void
    {
        for ($i = 0; $i < 25; ++$i) {
            $this->addItem('I', 'true');
        }
        self::assertTrue($this->readiness(25, minBlocks: 1, minPerBlock: 1)->isReady($this->regionId));
    }
}
