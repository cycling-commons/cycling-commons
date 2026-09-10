<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Catalog\ItemSource;
use App\Provider\Entity\DataProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The registry that replaced the `pivot` bucket
 * (data-provider-hierarchy.md §3, §10).
 */
final class DataProviderRegistryTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testTheItemProducingProvidersRankInTodaysOrder(): void
    {
        $keys = $this->connection()->fetchFirstColumn(
            'SELECT provider_key FROM data_provider WHERE rank > 0 ORDER BY rank',
        );

        // Rank 0 means the question does not apply: a boundary set or a photo
        // library never produces an item row, so it is here to be cited
        // rather than ranked (data-provider-hierarchy.md §9).
        self::assertSame(['osm', 'wikidata', 'wallonie-pivot', 'rivm-drinkwater'], $keys);
    }

    /**
     * OpenStreetMap credits every raw pin on the map, so a curator who could
     * delete its row could take the credit off the whole map.
     */
    public function testTheTwoHarvestsThatAreTheirOwnCodeAreSystemRows(): void
    {
        $rows = $this->connection()->fetchAllKeyValue(
            'SELECT provider_key, system FROM data_provider',
        );

        self::assertTrue((bool) $rows['osm']);
        self::assertTrue((bool) $rows['wikidata']);
        self::assertFalse((bool) $rows['wallonie-pivot'], 'an ordinary authority is a curator row');
    }

    /**
     * Seeded to today's ladder, so admitting the registry moves nothing a
     * rider can see: the Wallonia rows still outrank Wikidata, which still
     * outranks raw OpenStreetMap.
     */
    public function testTheSeededRanksKeepTodaysOrder(): void
    {
        $ranks = $this->connection()->fetchAllKeyValue(
            'SELECT provider_key, rank FROM data_provider',
        );

        self::assertGreaterThan((int) $ranks['wikidata'], (int) $ranks['wallonie-pivot']);
        self::assertGreaterThan((int) $ranks['osm'], (int) $ranks['wikidata']);
    }

    public function testNoRowIsLeftInTheOldBucket(): void
    {
        self::assertSame(
            0,
            (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM item WHERE source = 'pivot'"),
        );
    }

    /** The enum and the data must never disagree about what the value is. */
    public function testTheEnumHasTheRankAndNotTheBucketName(): void
    {
        self::assertSame('authority', ItemSource::Authority->value);
        self::assertNull(ItemSource::tryFrom('pivot'));
        // Unchanged from the rung `pivot` sat on: a rename is not a
        // reordering (data-provider-hierarchy.md §4).
        self::assertSame(30, ItemSource::Authority->dedupeRank());
    }

    public function testAnAuthorityRowPointsAtItsRegistryRow(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $provider = $em->getRepository(DataProvider::class)->findOneBy(['key' => 'wallonie-pivot']);

        self::assertNotNull($provider);
        self::assertSame('Tourisme Wallonie', $provider->getName());
        self::assertSame('cc-by-4.0', $provider->getLicenceCode());
        self::assertSame('Tourisme Wallonie (CC-BY)', $provider->getAttribution());
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
