<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Coverage;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Coverage\CoverageRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The stays chips (Bike-friendly, Stay accessibility) narrow the coverage
 * stays, so the rail's count must narrow with them: coverage_count keeps, per
 * bucket, how many shown stays carry a cyclist label, wheelchair=yes, or both
 * (docs/specs/coverage-provider.md §11). The cyclist rule is the tile's `cyl`
 * rule (pipeline/coverage/tiles.py, web/assets/map/osm-tags.js).
 */
final class CoverageStayFacetCountTest extends KernelTestCase
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

    /** @param array<string, string> $tags */
    private function stay(string $ref, array $tags, int $rid = 51, string $cc = 'DE'): void
    {
        self::insertCoveragePoi($this->db, [
            'ref' => $ref, 'letter' => 'O', 'kind' => 'hotel', 'name' => 'Hotel '.$ref,
            'tags' => ['tourism' => 'hotel'] + $tags, 'region_id' => $rid, 'country_code' => $cc,
        ]);
    }

    public function testTheCyclistRuleIsTheTileRule(): void
    {
        $year = (int) date('Y');
        $cases = [
            'node/810001' => [['bettundbike' => 'yes'], true],
            'node/810002' => [['bett_and_bike:ref' => 'NRW-1540'], true],
            'node/810003' => [['bettundbike' => 'no', 'bett_and_bike:ref' => 'NRW-1540'], false],
            'node/810004' => [['award:accueilvelo' => '2024'], true],
            'node/810005' => [['award:accueilvelo' => $year.'-'.($year + 3)], true],
            'node/810006' => [['award:accueilvelo' => ($year - 10).'-'.($year - 2)], false],
            'node/810007' => [['award:accueilvelo' => 'maybe'], false],
            'node/810008' => [['bike_friend' => 'yes'], true],
            'node/810009' => [['bike_friend' => 'no'], false],
            'node/810010' => [[], false],
        ];
        foreach ($cases as $ref => [$tags]) {
            $this->stay($ref, $tags);
        }
        foreach ($cases as $ref => [, $labelled]) {
            $got = $this->db->fetchOne('SELECT coverage_poi_cyclist(tags) FROM coverage_poi WHERE ref = :ref', ['ref' => $ref]);
            self::assertSame($labelled, (bool) $got, $ref);
        }
        self::assertSame(['cyl' => 5, 'acc' => 0, 'cyl_acc' => 0], $this->coverage->stayFacetCounts());
    }

    public function testTheStayFacetsAreCountedPerScopeAndFollowTheShownRule(): void
    {
        self::assertSame(['cyl' => 0, 'acc' => 0, 'cyl_acc' => 0], $this->coverage->stayFacetCounts(), 'nothing harvested, nothing counted');

        $this->stay('node/820001', ['bettundbike' => 'yes']);
        $this->stay('node/820002', ['bettundbike' => 'yes', 'wheelchair' => 'yes']);
        $this->stay('node/820003', ['wheelchair' => 'yes']);
        $this->stay('node/820004', []);
        $this->stay('node/820005', ['award:accueilvelo' => 'yes'], 52, 'FR');
        // A water point with the same tags is no stay: only letter O counts.
        self::insertCoveragePoi($this->db, ['ref' => 'node/820006', 'tags' => ['amenity' => 'drinking_water', 'bike_friend' => 'yes', 'wheelchair' => 'yes'], 'region_id' => 51, 'country_code' => 'DE']);

        self::assertSame(['cyl' => 3, 'acc' => 2, 'cyl_acc' => 1], $this->coverage->stayFacetCounts());
        self::assertSame(['cyl' => 2, 'acc' => 2, 'cyl_acc' => 1], $this->coverage->stayFacetCounts([51]), 'a region scope sums its own bucket');
        self::assertSame(['cyl' => 1, 'acc' => 0, 'cyl_acc' => 0], $this->coverage->stayFacetCounts([], 'FR'), 'a country scope sums the country');

        // A stay one of our items claims is not shown, so it leaves every count.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new Item())->setLetter('O')->setName('Our hotel')
            ->setGeom(json_encode(['type' => 'Point', 'coordinates' => [5.8, 50.4]], \JSON_THROW_ON_ERROR))
            ->setCountryCode('DE')->setState(ItemState::Verified)->setSource(ItemSource::Manual)
            ->setSourceRef('manual:our-hotel')->setOsmRef('node/820002')->setAttributes([]));
        $em->flush();
        self::assertSame(['cyl' => 2, 'acc' => 1, 'cyl_acc' => 0], $this->coverage->stayFacetCounts());

        // Retagging a row moves its counts at once (the statement trigger recounts the bucket).
        $this->db->executeStatement("UPDATE coverage_poi SET tags = tags - 'bettundbike' WHERE ref = 'node/820001'");
        self::assertSame(['cyl' => 1, 'acc' => 1, 'cyl_acc' => 0], $this->coverage->stayFacetCounts());
    }
}
