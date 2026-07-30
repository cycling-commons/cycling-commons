<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\Entity\Region;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegionsPagesTest extends WebTestCase
{
    private const GEOM = '{"type":"MultiPolygon","coordinates":[[[[4,50],[5,50],[5,51],[4,51],[4,50]]]]}';

    private function seedRegion(string $slug, string $cc, ?int $level): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $r = (new Region())->setSlug($slug)->setName(ucfirst($slug))
            ->setCountryCode($cc)->setAdminLevel($level)->setGeom(self::GEOM)->setAreaKm2(1000.0);
        $em->persist($r);
        $em->flush();

        return (int) $r->getId();
    }

    public function testListingShowsOnboardedCountriesAndHidesL2(): void
    {
        $client = static::createClient();
        $this->seedRegion('wallonia-t', 'BE', 4);
        $this->seedRegion('belgium-t', 'BE', 2);

        $crawler = $client->request('GET', '/regions');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.country h3', 'Belgium');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('belgium-t', $html, 'L2 infrastructure rows never render');
        self::assertStringContainsString('/join/BE', $html, 'every country header carries the curator door');
        // The baked snapshot is gone from this page.
        self::assertStringNotContainsString('regions-data.js', $html);
    }

    public function testListingLocalizedPath(): void
    {
        $client = static::createClient();
        $this->seedRegion('wallonia-t', 'BE', 4);

        $client->request('GET', '/fr/regions');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.country h3', 'Belgique');
    }

    public function testDetailPageRendersStatsAndDoors(): void
    {
        $client = static::createClient();
        $id = $this->seedRegion('wallonia-t', 'BE', 4);
        static::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, region_id, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('B', 'T', ST_SetSRID(ST_GeomFromText('POINT(4.5 50.5)'), 4326), 'BE', ?, 'verified', 'seed', 't1', '{}', NOW(), NOW())",
            [$id],
        );

        $client->request('GET', '/regions/wallonia-t');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('scope=region:wallonia-t', $html, 'map door');
        self::assertStringContainsString('/join/BE', $html, 'curator door');
    }

    public function testDetailPage404sForInfrastructureAndUnknownSlugs(): void
    {
        $client = static::createClient();
        $this->seedRegion('wallonia-t', 'BE', 4);
        $this->seedRegion('belgium-t', 'BE', 2);

        $client->request('GET', '/regions/belgium-t');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/regions/never-a-region');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOldRegionPathRedirectsPermanently(): void
    {
        $client = static::createClient();
        $client->request('GET', '/region');
        self::assertResponseRedirects('/regions/wallonia', 301);
        $client->request('GET', '/fr/region');
        self::assertResponseRedirects('/fr/regions/wallonia', 301);
    }

    public function testYourCountryBlockCarriesCountryDataAndNoscriptFallback(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/regions');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('#your-country')->count());
        $json = $crawler->filter('#cc-countries')->text();
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertNotEmpty($data);
        $fr = array_values(array_filter($data, static fn (array $c): bool => 'FR' === $c['code']));
        self::assertSame('France', $fr[0]['name'] ?? null);
        // String check, not a crawler filter: HTML parsers may expose <noscript>
        // children as raw text, which would false-fail a node assertion.
        self::assertMatchesRegularExpression(
            '#<noscript>.*href="[^"]*/join".*</noscript>#s',
            (string) $client->getResponse()->getContent(),
        );
    }
}
