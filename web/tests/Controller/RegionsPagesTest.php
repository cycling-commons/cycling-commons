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
             VALUES ('N', 'T', ST_SetSRID(ST_GeomFromText('POINT(4.5 50.5)'), 4326), 'BE', ?, 'verified', 'seed', 't1', '{}', NOW(), NOW())",
            [$id],
        );

        $client->request('GET', '/regions/wallonia-t');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('scope=region:wallonia-t', $html, 'map door');
        self::assertStringContainsString('/join/BE', $html, 'curator door');
    }

    /**
     * The build-time Wikipedia lead: shown verbatim in the reader's locale
     * with an English fallback, always with the attribution line rendered
     * from the SAME stored entry (the text is CC BY-SA 4.0), and simply
     * absent when the region has none.
     */
    public function testDetailPageRendersWikipediaContextWithAttribution(): void
    {
        $client = static::createClient();
        $id = $this->seedRegion('ctx-page-region', 'BE', 4);
        static::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE region SET context = :ctx WHERE id = :id',
            ['id' => $id, 'ctx' => json_encode([
                'en' => ['title' => 'Ctxland', 'extract' => 'Ctxland is a rolling test province.', 'url' => 'https://en.wikipedia.org/wiki/Ctxland'],
                'fr' => ['title' => 'Ctxlande', 'extract' => 'La Ctxlande est une province de test.', 'url' => 'https://fr.wikipedia.org/wiki/Ctxlande'],
            ], \JSON_THROW_ON_ERROR)],
        );

        $client->request('GET', '/regions/ctx-page-region');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Ctxland is a rolling test province.', $html);
        self::assertStringContainsString('https://en.wikipedia.org/wiki/Ctxland', $html, 'the attribution links the exact article');
        self::assertStringContainsString('CC BY-SA 4.0', $html, 'the licence is named beside the text');

        // French readers get the French article, not the English one.
        $client->request('GET', '/fr/regions/ctx-page-region');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('La Ctxlande est une province de test.', $html);
        self::assertStringContainsString('https://fr.wikipedia.org/wiki/Ctxlande', $html);

        // A locale without an article falls back to English rather than to nothing.
        $client->request('GET', '/de/regions/ctx-page-region');
        self::assertStringContainsString('Ctxland is a rolling test province.', (string) $client->getResponse()->getContent());
    }

    public function testDetailPageWithoutContextOmitsTheSection(): void
    {
        $client = static::createClient();
        $this->seedRegion('ctx-less-region', 'BE', 4);

        $client->request('GET', '/regions/ctx-less-region');
        self::assertResponseIsSuccessful();
        // The heading string, not the CSS class: the style block always ships.
        self::assertStringNotContainsString('About this region', (string) $client->getResponse()->getContent());
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

    /**
     * The "your country" picker's country list is sorted with \Collator, not
     * strcoll — strcoll sorts by raw byte order and misplaces accented names
     * (e.g. "Éthiopie" would sort after every plain-ASCII name instead of
     * taking its alphabetic place before "Zambie").
     */
    public function testYourCountryBlockOrdersLocalizedNamesByCollationNotByteOrder(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/fr/regions');
        self::assertResponseIsSuccessful();

        $json = $crawler->filter('#cc-countries')->text();
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $names = array_column($data, 'name');

        $ethIndex = array_search('Éthiopie', $names, true);
        $zmIndex = array_search('Zambie', $names, true);
        self::assertNotFalse($ethIndex);
        self::assertNotFalse($zmIndex);
        self::assertLessThan($zmIndex, $ethIndex, 'proper collation must place "Éthiopie" before "Zambie", not after it as byte-order strcoll would');
    }
}
