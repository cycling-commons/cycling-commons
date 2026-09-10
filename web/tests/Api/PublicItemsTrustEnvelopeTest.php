<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Api;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The trust envelope on /v1/search (public-api.md §1, data-provider-hierarchy.md
 * §6.7.7): a coarse grade first, and behind it the receipt that produced it,
 * so a consumer that filters reads one word and a consumer that has to
 * defend a decision reads the count and the dates. A grade with no receipt is
 * the trust-me this project faults others for.
 */
final class PublicItemsTrustEnvelopeTest extends WebTestCase
{
    private const array ENVELOPE = ['grade', 'custody', 'confirmations', 'last_confirmed', 'last_seen_upstream', 'verified_by'];

    public function testEveryItemCarriesItsGradeAndTheReceiptThatProducedIt(): void
    {
        $client = static::createClient();
        $this->seedCatalog($client);

        $client->request('GET', '/v1/search?bbox=4.0,50.0,5.0,51.0&letter=D');

        self::assertResponseIsSuccessful();
        $features = $this->features($client);
        self::assertNotEmpty($features);
        foreach ($features as $props) {
            foreach (self::ENVELOPE as $field) {
                self::assertArrayHasKey($field, $props, "the envelope is incomplete without {$field}");
            }
            self::assertContains($props['grade'], ['claimed', 'attested', 'minimum', 'high']);
            self::assertContains($props['custody'], ['gross', 'specialty', 'ours']);
            // The seed promotes the fixtures to verified with nobody standing
            // there: that is a curator's word, and the receipt says so.
            self::assertSame('curated', $props['tier']);
            self::assertSame('minimum', $props['grade']);
            self::assertSame('ours', $props['custody']);
            self::assertSame(0, $props['confirmations']);
            self::assertNull($props['last_confirmed']);
            self::assertNull($props['last_seen_upstream'], 'a row with no upstream has no upstream sighting');
            self::assertSame('curator', $props['verified_by']);
        }
    }

    public function testTheReceiptMovesWithTheEvidenceAndNeverNamesARider(): void
    {
        $client = static::createClient();
        $this->seedCatalog($client);
        $db = $this->db();
        $ids = $db->fetchFirstColumn("SELECT id FROM item WHERE letter = 'D' AND ST_X(geom) BETWEEN 4.0 AND 5.0 ORDER BY id");
        self::assertCount(2, $ids);
        [$unverified, $verified] = array_map(intval(...), $ids);

        // One rider stood at the first row, last month: unverified, one
        // confirmation, a dated receipt, no verified_by.
        $db->executeStatement("UPDATE item SET state = 'unverified' WHERE id = :id", ['id' => $unverified]);
        $db->executeStatement(
            "INSERT INTO item_confirmation (item_id, user_id, stance, source, created_at, updated_at) VALUES (:item, 1, 'exists', 'drawer', '2026-08-20 10:00:00', '2026-08-20 10:00:00')",
            ['item' => $unverified],
        );
        // Two riders stood at the second: verified by riders, count two.
        foreach ([1, 2] as $user) {
            $db->executeStatement(
                "INSERT INTO item_confirmation (item_id, user_id, stance, source, created_at, updated_at) VALUES (:item, :user, 'exists', 'drawer', '2026-08-2{$user} 10:00:00', '2026-08-2{$user} 10:00:00')",
                ['item' => $verified, 'user' => $user],
            );
        }

        $client->request('GET', '/v1/search?bbox=4.0,50.0,5.0,51.0&letter=D');
        self::assertResponseIsSuccessful();
        $byId = [];
        foreach ($this->features($client) as $props) {
            $byId[(int) $props['id']] = $props;
        }

        self::assertSame('community', $byId[$unverified]['tier']);
        self::assertSame('attested', $byId[$unverified]['grade']);
        self::assertSame(1, $byId[$unverified]['confirmations']);
        self::assertSame('2026-08-20', $byId[$unverified]['last_confirmed']);
        self::assertNull($byId[$unverified]['verified_by']);

        self::assertSame('curated', $byId[$verified]['tier']);
        self::assertSame('minimum', $byId[$verified]['grade']);
        self::assertSame(2, $byId[$verified]['confirmations']);
        self::assertSame('2026-08-22', $byId[$verified]['last_confirmed']);
        self::assertSame('riders', $byId[$verified]['verified_by']);

        // The receipt is a count and a date, never a person: the closed
        // property list is the personal-data boundary.
        foreach ($byId as $props) {
            self::assertSame(['id', 'letter', 'name', 'tier', ...self::ENVELOPE], array_keys($props));
        }
    }

    /** @return list<array<string, mixed>> */
    private function features(KernelBrowser $client): array
    {
        /** @var array{features: list<array{properties: array<string, mixed>}>} $collection */
        $collection = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return array_map(static fn (array $f): array => $f['properties'], $collection['features']);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function seedCatalog(KernelBrowser $client): void
    {
        $src = __DIR__.'/../fixtures/catalog';
        $dir = sys_get_temp_dir().'/public-api-envelope-'.getmypid();
        @mkdir($dir, 0777, true);
        foreach (['region-square.geojson', 'services.json', 'routes.json', 'heat.json'] as $f) {
            copy($src.'/'.$f, $dir.'/'.$f);
        }
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:import'));
        $tester->execute(['dir' => $dir]);
        $tester->assertCommandIsSuccessful();
        // Untouched osm/unverified rows are coverage-retired from serving
        // (coverage-provider.md §9); promote the fixtures like PublicApiV1Test does.
        $this->db()->executeStatement("UPDATE item SET state = 'verified' WHERE letter IN ('B','D','F','G','O','P','Q')");
        $this->db()->executeStatement("DELETE FROM item_confirmation WHERE item_id IN (SELECT id FROM item WHERE letter = 'D')");
    }
}
