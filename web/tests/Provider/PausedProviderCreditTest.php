<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Provider\ProviderCitations;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A paused provider keeps its credit while its places are on the map
 * (data-provider-hierarchy.md §3, §7, §9).
 *
 * Paused means "keep the rows, stop refreshing". A row still served still owes
 * its publisher the credit its licence asks for, on the place itself and on
 * `/credits`, so the credit follows the served rows and not the switch.
 */
final class PausedProviderCreditTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->db = static::getContainer()->get(Connection::class);
        static::getContainer()->get(ProviderCitations::class)->invalidate();
    }

    public function testAPausedProviderWithServedRowsKeepsItsCredit(): void
    {
        $id = $this->provider('credit-test-paused', enabled: false);
        $this->row($id, 'a', ItemState::Unverified);

        self::assertArrayHasKey('credit-test-paused', $this->citations(), 'its places still cite it on the map');
        self::assertStringContainsString('manual:provider-credit-test-paused"', $this->creditsPage(), '/credits still names it');
    }

    public function testAPausedProviderWithNothingServedIsNotCredited(): void
    {
        $id = $this->provider('credit-test-gone', enabled: false);
        $this->row($id, 'b', ItemState::Retired);

        self::assertArrayNotHasKey('credit-test-gone', $this->citations());
        self::assertStringNotContainsString('manual:provider-credit-test-gone"', $this->creditsPage());
    }

    /** @return array<string, mixed> */
    private function citations(): array
    {
        $citations = static::getContainer()->get(ProviderCitations::class);
        $citations->invalidate();

        return $citations->all();
    }

    private function creditsPage(): string
    {
        $this->client->request('GET', '/credits');
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function provider(string $key, bool $enabled): int
    {
        $this->db->executeStatement(
            "INSERT INTO data_provider (provider_key, name, full_name, homepage, licence, licence_code, attribution, rank, letters, enabled, system)
             VALUES (:key, :key, :key, 'https://example.test/', 'Creative Commons BY 4.0', 'cc-by-4.0', :key, 500, '[\"B\"]', :enabled, FALSE)",
            ['key' => $key, 'enabled' => $enabled ? 'true' : 'false'],
        );

        return (int) $this->db->fetchOne('SELECT id FROM data_provider WHERE provider_key = :key', ['key' => $key]);
    }

    private function row(int $providerId, string $ref, ItemState $state): void
    {
        $this->db->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, provider_id, attributes, created_at, updated_at)
             VALUES ('B', 'Tap', ST_SetSRID(ST_MakePoint(5.1, 52.1), 4326), 'NL', :state, :source, :ref, :provider, '{}', NOW(), NOW())",
            ['state' => $state->value, 'source' => ItemSource::Authority->value, 'ref' => 'test:credit:'.$ref, 'provider' => $providerId],
        );
    }
}
