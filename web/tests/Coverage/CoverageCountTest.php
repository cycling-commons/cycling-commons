<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Coverage;

use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\ItemConfirmation;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Coverage\CoverageRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * coverage_count is kept by the database (Version20260906180000) and read by
 * /map/coverage/counts. These prove it follows every change that can move a
 * count, and that it never disagrees with the slow walk it replaced.
 */
final class CoverageCountTest extends KernelTestCase
{
    use CoverageSchema;

    private Connection $db;
    private CoverageRepository $coverage;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->coverage = static::getContainer()->get(CoverageRepository::class);
        self::ensureCoverageSchema($this->db);
    }

    private function item(string $sourceRef, ItemState $state, ItemSource $source = ItemSource::Osm, ?string $osmRef = null): Item
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item())->setLetter('B')->setName('Counted tap')
            ->setGeom(json_encode(['type' => 'Point', 'coordinates' => [5.8, 50.4]], \JSON_THROW_ON_ERROR))
            ->setCountryCode('BE')->setState($state)->setSource($source)
            ->setSourceRef($sourceRef)->setAttributes([]);
        if (null !== $osmRef) {
            $item->setOsmRef($osmRef);
        }
        $em->persist($item);
        $em->flush();

        return $item;
    }

    /** @param list<int> $rids */
    private function b(array $rids = [], ?string $cc = null): int
    {
        return $this->coverage->counts($rids, $cc)['B'] ?? 0;
    }

    public function testTheKeptCountFollowsCoverageRowsAndTheItemsThatClaimThem(): void
    {
        self::assertSame(0, $this->b());

        self::insertCoveragePoi($this->db, ['ref' => 'node/700001', 'region_id' => 41, 'country_code' => 'BE']);
        self::insertCoveragePoi($this->db, ['ref' => 'node/700002', 'region_id' => 41, 'country_code' => 'BE']);
        self::insertCoveragePoi($this->db, ['ref' => 'node/700003', 'region_id' => 42, 'country_code' => 'NL']);
        self::assertSame(3, $this->b(), 'every row counts, everywhere');
        self::assertSame(2, $this->b([41]), 'a region scope sums its own bucket');
        self::assertSame(1, $this->b([], 'NL'), 'a country scope sums the country');

        // An untouched OSM import does not hide its twin (the twin IS the row).
        $legacy = $this->item('node/700001', ItemState::Unverified);
        self::assertSame(3, $this->b(), 'an untouched OSM import hides nothing');

        // The first human touch turns it into a curated row: the twin goes.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new ItemConfirmation((int) $legacy->getId(), 1, ConfirmationStance::Exists));
        $em->flush();
        self::assertSame(2, $this->b(), 'a confirmation on the import hides the coverage twin');

        // A served row from another source claims a coverage ref through osm_ref.
        $this->item('fx:pivot:x', ItemState::Verified, ItemSource::Authority, 'node/700003');
        self::assertSame(1, $this->b(), 'an authority row linked by osm_ref hides its twin');
        self::assertSame(0, $this->b([], 'NL'));

        // Rejecting a claim gives the twin back.
        $this->db->executeStatement("UPDATE item SET state = 'rejected' WHERE source_ref = 'fx:pivot:x'");
        self::assertSame(2, $this->b(), 'a rejected claim frees the twin');

        // A coverage row leaving takes its count with it.
        $this->db->executeStatement("DELETE FROM coverage_poi WHERE ref = 'node/700002'");
        self::assertSame(1, $this->b());

        // A row moving to another region moves its count.
        $this->db->executeStatement("UPDATE coverage_poi SET region_id = 43 WHERE ref = 'node/700003'");
        self::assertSame(0, $this->b([42]));
        self::assertSame(1, $this->b([43]));
    }

    /** The kept table and the slow walk answer the same question; if they ever differ, the predicate drifted. */
    public function testTheCountTableAgreesWithALiveCount(): void
    {
        self::insertCoveragePoi($this->db, ['ref' => 'node/710001', 'region_id' => 41, 'country_code' => 'BE']);
        self::insertCoveragePoi($this->db, ['ref' => 'node/710002', 'region_id' => 41, 'country_code' => 'BE', 'letter' => 'O']);
        self::insertCoveragePoi($this->db, ['ref' => 'way/710003', 'region_id' => 42, 'country_code' => 'NL', 'letter' => 'P']);
        $this->item('node/710001', ItemState::Verified);
        $this->item('fx:pivot:y', ItemState::Unverified, ItemSource::Authority, 'way/710003');

        foreach ([[[], null], [[41], null], [[], 'NL'], [[41, 42], 'BE']] as [$rids, $cc]) {
            self::assertSame($this->coverage->liveCounts($rids, $cc), $this->coverage->counts($rids, $cc), sprintf('scope rids=%s cc=%s', implode(',', $rids), (string) $cc));
        }
    }

    public function testRecountRebuildsFromScratch(): void
    {
        self::insertCoveragePoi($this->db, ['ref' => 'node/720001', 'region_id' => 41, 'country_code' => 'BE']);
        $this->db->executeStatement('DELETE FROM coverage_count');
        self::assertSame(0, $this->b(), 'emptied by hand: the sum is empty');
        $this->db->executeStatement('SELECT coverage_count_rebuild()');
        self::assertSame(1, $this->b());
    }
}
