<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Settings\SettingsRegistry;
use App\Settings\SystemSettingsWriter;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The cycling history timeline (docs/specs/cycling-history.md §4).
 *
 * The page must show exactly the places the map shows: a gone place, a place
 * waiting for a curator or a place with no story is not on it. A dated place
 * sits under its year in year order; an undated one under the line.
 */
final class CyclingHistoryPageTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->db = static::getContainer()->get(Connection::class);
        $this->live(true);
        $region = (int) $this->db->fetchOne(
            "INSERT INTO region (slug, name, country_code, area_km2, geom, created_at, updated_at)
             VALUES ('ch-page-test', 'Test Valley', 'FR', 100,
                     ST_Multi(ST_GeomFromText('POLYGON((5.0 44.0, 5.4 44.0, 5.4 44.3, 5.0 44.3, 5.0 44.0))', 4326)),
                     NOW(), NOW())
             RETURNING id",
        );
        $wiki = [['label' => 'Wikipedia', 'urls' => [
            ['locale' => 'en', 'url' => 'https://en.wikipedia.org/wiki/Test_Memorial'],
            ['locale' => 'nl', 'url' => 'https://nl.wikipedia.org/wiki/Test_Gedenkteken'],
        ]]];
        $this->item($region, 'Late Velodrome', 'unverified', ['cyclingStory' => 'A record fell here.', 'year' => '1972', 'type' => 'heritage']);
        $this->item($region, 'Early Memorial', 'verified', ['cyclingStory' => 'A rider died here.', 'year' => '1967', 'type' => 'memorial', 'links' => $wiki]);
        $this->item($region, 'Old Museum', 'unverified', ['cyclingStory' => 'Bicycles of every age.', 'type' => 'museum']);
        $this->item($region, 'Gone Plaque', 'unverified', ['cyclingStory' => 'Taken down.', 'year' => '1950', 'condition' => 'Not there anymore']);
        $this->item($region, 'Waiting Chapel', 'submitted', ['cyclingStory' => 'Not approved yet.', 'year' => '1959']);
        $this->item($region, 'Plain Castle', 'unverified', ['type' => 'castle']);
    }

    public function testTheLineOrdersPlacesByYearAndListsTheUndatedUnderIt(): void
    {
        $crawler = $this->client->request('GET', '/cycling-history');

        self::assertResponseIsSuccessful();
        self::assertSame(['1967', '1972'], $crawler->filter('.ch-y span')->each(static fn (Crawler $n): string => $n->text()));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertLessThan(strpos($html, 'Late Velodrome'), strpos($html, 'Early Memorial'));
        self::assertGreaterThan(strpos($html, 'Late Velodrome'), strpos($html, 'Old Museum'), 'an undated place comes after the line');
        self::assertStringContainsString('Memorial · Test Valley, France', $crawler->filter('.ch-card')->first()->filter('.ch-meta')->text());
    }

    public function testOnlyWhatTheMapShowsIsOnThePage(): void
    {
        $html = $this->client->request('GET', '/cycling-history')->html();

        foreach (['Gone Plaque', 'Waiting Chapel', 'Plain Castle'] as $hidden) {
            self::assertStringNotContainsString($hidden, $html, $hidden);
        }
    }

    public function testACardLinksTheMapAndTheArticleInTheReadersLanguage(): void
    {
        $crawler = $this->client->request('GET', $this->url('nl'));

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('.ch-card')->first();
        self::assertMatchesRegularExpression('~^/map\?item=\d+$~', (string) $card->filter('a.ch-map')->attr('href'));
        $wiki = $card->filter('a[target="_blank"]');
        self::assertSame('https://nl.wikipedia.org/wiki/Test_Gedenkteken', $wiki->attr('href'));
        self::assertSame('noopener noreferrer nofollow', $wiki->attr('rel'), 'riders can edit these links, as in the drawer');
    }

    public function testTheSlugIsLocalisedAndEveryLanguageRenders(): void
    {
        self::assertSame('/fr/histoire-du-cyclisme', $this->url('fr'));
        foreach (['en', 'fr', 'nl', 'de', 'es'] as $locale) {
            $this->client->request('GET', $this->url($locale));
            self::assertResponseIsSuccessful($locale);
        }
    }

    /**
     * Each environment seeds its places first and opens the page after
     * (cycling-history.md §5). Until then nothing links a page that answers
     * not found: not the footer, not /pages, not What's new or its feed, not
     * the sitemap.
     */
    public function testWhileSwitchedOffThePageIsNotFoundAndNothingLinksIt(): void
    {
        $this->live(false);

        $this->client->request('GET', '/cycling-history');
        self::assertResponseStatusCodeSame(404);
        foreach (['/pages', '/whats-new', '/changelog.atom', '/sitemap.xml'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            $html = (string) $this->client->getResponse()->getContent();
            self::assertStringNotContainsString('cycling-history', $html, $url);
            self::assertStringNotContainsString('Cycling history on a timeline', $html, $url);
        }

        $this->live(true);
        foreach (['/pages', '/whats-new'] as $url) {
            $this->client->request('GET', $url);
            self::assertStringContainsString('/cycling-history', (string) $this->client->getResponse()->getContent(), $url);
        }
    }

    private function live(bool $on): void
    {
        static::getContainer()->get(SystemSettingsWriter::class)->set(SettingsRegistry::CYCLING_HISTORY_LIVE, $on ? 1 : 0, null);
    }

    private function url(string $locale): string
    {
        return static::getContainer()->get('router')->generate('cycling_history', ['_locale' => $locale]);
    }

    /** @param array<string, mixed> $attributes */
    private function item(int $region, string $name, string $state, array $attributes): void
    {
        $this->db->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, region_id, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('Q', :name, ST_SetSRID(ST_MakePoint(5.2, 44.1), 4326), 'FR', :region, :state, 'manual', :ref, :attrs, NOW(), NOW())",
            ['name' => $name, 'region' => $region, 'state' => $state, 'ref' => 'manual:test-'.md5($name), 'attrs' => json_encode($attributes, \JSON_THROW_ON_ERROR)],
        );
    }
}
