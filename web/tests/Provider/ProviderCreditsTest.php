<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Provider;

use App\Provider\LicenceObligation;
use App\Twig\ProviderCreditsExtension;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The data credits, generated from the registry
 * (data-provider-hierarchy.md §9, §9.2, §9.3).
 */
final class ProviderCreditsTest extends WebTestCase
{
    public function testALicenceThatOwesANoticeGetsItsOwnRow(): void
    {
        self::bootKernel();
        $credits = $this->credits()->creditedProviders();

        $required = array_column($credits['required'], 'key');
        foreach (['osm', 'wallonie-pivot', 'wikipedia', 'copernicus-worlddem30', 'overture'] as $key) {
            self::assertContains($key, $required, $key.' owes an attribution notice');
        }
    }

    /**
     * Naming them is decency, not obligation, and a linked name names them.
     * This is what lets the page survive a registry of hundreds.
     */
    public function testALicenceThatOwesNothingGoesInTheCommaRun(): void
    {
        self::bootKernel();
        $credits = $this->credits()->creditedProviders();

        self::assertContains('wikidata', array_column($credits['courtesy'], 'key'));
        self::assertNotContains('wikidata', array_column($credits['required'], 'key'));
    }

    /** An unrecognised code owes a notice: a missed credit is a licence breach. */
    public function testAnUnknownLicenceCodeIsTreatedAsOwingANotice(): void
    {
        self::assertTrue(LicenceObligation::requiresAttribution('something-nobody-mapped'));
        self::assertFalse(LicenceObligation::requiresAttribution('cc0-1.0'));
        self::assertFalse(LicenceObligation::requiresAttribution('  PUBLIC-DOMAIN  '));
    }

    /**
     * The one bounded exception: the licence sets the floor, not the ceiling.
     */
    public function testPromotingACourtesyRowLiftsItIntoAFullRow(): void
    {
        self::bootKernel();
        $this->connection()->executeStatement(
            "UPDATE data_provider SET promoted = TRUE WHERE provider_key = 'wikidata'",
        );

        $credits = $this->credits()->creditedProviders();

        self::assertContains('wikidata', array_column($credits['required'], 'key'));
    }

    /**
     * Facts come out of the registry; only the sentence is translated, and it
     * arrives already resolved so the template renders and does not choose.
     */
    public function testTheFactsAreTheRegistrysAndTheSentenceIsTranslated(): void
    {
        self::bootKernel();
        $rows = $this->credits()->creditedProviders()['required'];
        $osm = current(array_filter($rows, static fn (array $r): bool => 'osm' === $r['key']));

        self::assertIsArray($osm);
        self::assertSame('OpenStreetMap', $osm['name']);
        self::assertSame('Open Database License (ODbL) 1.0', $osm['licence']);
        self::assertSame('© OpenStreetMap contributors', $osm['attribution']);
        self::assertStringNotContainsString('credits.', $osm['blurb'], 'an unresolved key must never reach a reader');
        self::assertNotSame('', $osm['blurb']);
    }

    /** The page shows the generated rows, and shows each one once. */
    public function testTheCreditsPageRendersEachProviderExactlyOnce(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/credits');
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        foreach (['osm', 'wallonie-pivot', 'wikipedia'] as $key) {
            self::assertSame(
                1,
                substr_count($html, 'manual:provider-'.$key.'"'),
                $key.' must be credited exactly once: a hand-written row beside a generated one is a duplicate',
            );
        }
        // The hand-written markers those rows used to carry are gone with them.
        self::assertStringNotContainsString('manual:openstreetmap-data', $html);
        self::assertStringNotContainsString('manual:wallonia-pivot', $html);
        self::assertStringNotContainsString('manual:copernicus-dem', $html);
        // A row outside the generated group keeps its hand-placed marker:
        // a live tile service is fetched per request and is not a provider
        // (credits-page.md §8.8).
        self::assertStringContainsString('manual:openfreemap-tiles', $html);

        self::assertGreaterThan(0, $crawler->filter('.pkglist')->count());
    }

    private function credits(): ProviderCreditsExtension
    {
        return static::getContainer()->get(ProviderCreditsExtension::class);
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
