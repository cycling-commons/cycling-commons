<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Catalog\CatalogScanner;
use App\Catalog\Import\NameKey;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A provider's rank decides which record of one place riders keep
 * (data-provider-hierarchy.md §4).
 *
 * The Providers desk says so, and a curator sets the number there. These run
 * the two places that pick a keeper: `app:catalog:dedupe`, which retires the
 * losers, and the scan behind the Data desk's duplicate findings, whose Yes
 * keeps the ranked row.
 */
final class ProviderRankTest extends KernelTestCase
{
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->executeStatement("DELETE FROM item WHERE source_ref LIKE 'test:pr:%'");
        $this->db->executeStatement("DELETE FROM data_provider WHERE provider_key LIKE 'rank-test-%'");
    }

    public function testTheHigherRankedProviderIsKept(): void
    {
        $low = $this->provider('rank-test-low', 500);
        $high = $this->provider('rank-test-high', 600);
        $fromLow = $this->seed('Fontaine du Rang', ItemSource::Authority, 'a', $low);
        $fromHigh = $this->seed('Fontaine du Rang', ItemSource::Authority, 'b', $high);

        self::assertSame($fromHigh, $this->scannedKeeper(), 'the Data desk keeps the higher-ranked provider');

        $this->dedupe();

        self::assertSame(ItemState::Unverified->value, $this->stateOf($fromHigh));
        self::assertSame(ItemState::Retired->value, $this->stateOf($fromLow));
    }

    public function testChangingTheRankFlipsTheKeeper(): void
    {
        $first = $this->provider('rank-test-first', 600);
        $second = $this->provider('rank-test-second', 500);
        $fromFirst = $this->seed('Fontaine du Rang', ItemSource::Authority, 'a', $first);
        $fromSecond = $this->seed('Fontaine du Rang', ItemSource::Authority, 'b', $second);
        self::assertSame($fromFirst, $this->scannedKeeper());

        $this->db->executeStatement('UPDATE data_provider SET rank = 700 WHERE id = :id', ['id' => $second]);

        self::assertSame($fromSecond, $this->scannedKeeper(), 'the new rank is read, not a cached one');
        $this->dedupe();
        self::assertSame(ItemState::Retired->value, $this->stateOf($fromFirst));
        self::assertSame(ItemState::Unverified->value, $this->stateOf($fromSecond));
    }

    /** A provider ranked below OpenStreetMap loses to it: the registry's number is the order. */
    public function testAProviderRankedBelowOpenStreetMapLosesToIt(): void
    {
        $weak = $this->provider('rank-test-weak', 50);
        $fromWeak = $this->seed('Fontaine du Rang', ItemSource::Authority, 'a', $weak);
        $osm = $this->seed('Fontaine du Rang', ItemSource::Osm, 'b');

        self::assertSame($osm, $this->scannedKeeper());

        $this->dedupe();

        self::assertSame(ItemState::Retired->value, $this->stateOf($fromWeak));
    }

    public function testARidersOwnRowStillWins(): void
    {
        $top = $this->provider('rank-test-top', 9999);
        $fromTop = $this->seed('Fontaine du Rang', ItemSource::Authority, 'a', $top);
        $rider = $this->seed('Fontaine du Rang', ItemSource::User, 'b');

        self::assertSame($rider, $this->scannedKeeper());

        $this->dedupe();

        self::assertSame(ItemState::Unverified->value, $this->stateOf($rider));
        self::assertSame(ItemState::Retired->value, $this->stateOf($fromTop));
    }

    private function scannedKeeper(): int
    {
        $groups = array_values(array_filter(
            static::getContainer()->get(CatalogScanner::class)->duplicateGroups('B'),
            static fn (array $g): bool => 'B|'.NameKey::of('Fontaine du Rang') === $g['key'],
        ));
        self::assertCount(1, $groups);

        return (int) $groups[0]['keeper']['id'];
    }

    private function dedupe(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:dedupe'));
        $tester->execute(['--write' => true, '--letter' => 'B']);
        $tester->assertCommandIsSuccessful();
    }

    private function provider(string $key, int $rank): int
    {
        $this->db->executeStatement(
            "INSERT INTO data_provider (provider_key, name, full_name, homepage, licence, licence_code, rank, letters, enabled, system)
             VALUES (:key, :key, :key, 'https://example.test/', 'CC0 1.0', 'cc0-1.0', :rank, '[\"B\"]', TRUE, FALSE)",
            ['key' => $key, 'rank' => $rank],
        );

        return (int) $this->db->fetchOne('SELECT id FROM data_provider WHERE provider_key = :key', ['key' => $key]);
    }

    private function seed(string $name, ItemSource $source, string $ref, ?int $providerId = null): int
    {
        $this->db->executeStatement(
            'INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, provider_id, attributes, created_at, updated_at)
             VALUES (\'B\', :name, ST_SetSRID(ST_MakePoint(5.1, 51.2), 4326), \'NL\', :state, :source, :ref, :provider, \'{}\', NOW(), NOW())',
            [
                'name' => $name, 'state' => ItemState::Unverified->value, 'source' => $source->value,
                'ref' => 'test:pr:'.$ref, 'provider' => $providerId,
            ],
        );

        return (int) $this->db->fetchOne('SELECT id FROM item WHERE source_ref = :ref', ['ref' => 'test:pr:'.$ref]);
    }

    private function stateOf(int $id): string
    {
        return (string) $this->db->fetchOne('SELECT state FROM item WHERE id = :id', ['id' => $id]);
    }
}
