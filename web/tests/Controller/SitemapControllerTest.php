<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\RegionRegistryProvider;
use App\Routing\LocalePrefix;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /robots.txt and /sitemap.xml (test-suite review 2026-08-24).
 *
 * Both routes had no test of any kind, in any file. They are the two documents
 * that tell a crawler what this site is, so a silent break shows up as an
 * indexing problem weeks later, with nothing in the suite to point at.
 *
 * The load-bearing assertion here is testNoSitemapUrlIsDisallowed: the sitemap
 * says "please index this" and robots.txt says "please do not", and a page that
 * appears in both is a contradiction the crawler resolves against us. Adding a
 * page to SitemapController::PAGES whose path sits under a Disallow prefix is
 * the exact way that happens, and it is invisible by reading either list alone.
 */
final class SitemapControllerTest extends WebTestCase
{
    public function testRobotsIsServedAsPlainText(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        self::assertStringStartsWith('User-agent: *', (string) $client->getResponse()->getContent());
    }

    public function testRobotsDisallowsEveryPrivateSurface(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');
        $body = (string) $client->getResponse()->getContent();

        // The firewall is the real access control; this list is the crawl hint
        // that keeps login walls and moderation desks out of search results.
        // Each entry is named so a deletion fails loudly instead of quietly
        // publishing a surface.
        foreach ([
            '/admin', '/moderate', '/profile', '/settings', '/messages',
            '/login', '/register', '/reset-password', '/2fa', '/i18n/',
            '/contribute', '/add-climb', '/improve', '/propose-route',
            '/map/', '/api/', '/photo/',
        ] as $path) {
            self::assertStringContainsString("Disallow: {$path}\n", $body, "robots.txt no longer disallows {$path}");
        }
    }

    public function testRobotsPointsAtTheAbsoluteSitemapUrl(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');
        $body = (string) $client->getResponse()->getContent();

        // Relative Sitemap: lines are ignored by crawlers, so the absolute form
        // is the whole point of generating this from the router.
        self::assertMatchesRegularExpression('~^Sitemap: https?://[^/]+/sitemap\.xml$~m', $body);
    }

    public function testSitemapIsWellFormedXml(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string((string) $client->getResponse()->getContent());
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertNotFalse($doc, 'sitemap.xml is not parseable XML: '.implode(', ', array_map(
            static fn (\LibXMLError $e): string => trim($e->message),
            $errors,
        )));
        self::assertSame('urlset', $doc->getName());
    }

    public function testSitemapListsEveryPublicPageWithAlternatesForEveryLocale(): void
    {
        $client = static::createClient();
        $body = self::sitemapBody($client);
        self::assertNotEmpty(self::locs($body));

        foreach (array_keys(LocalePrefix::PATHS) as $locale) {
            // hreflang alternates are how the five locale variants of one page
            // are declared as one page rather than four duplicates.
            self::assertStringContainsString(sprintf('hreflang="%s"', $locale), $body);
        }

        // The English home page is the canonical entry and carries priority 1.0.
        self::assertStringContainsString('<priority>1.0</priority>', $body);
    }

    public function testEveryListedUrlIsAbsolute(): void
    {
        $client = static::createClient();
        foreach (self::locs(self::sitemapBody($client)) as $loc) {
            self::assertMatchesRegularExpression('~^https?://~', $loc, "relative <loc> in sitemap: {$loc}");
        }
    }

    public function testNoSitemapUrlIsDisallowed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');
        $robots = (string) $client->getResponse()->getContent();
        $locs = self::locs(self::sitemapBody($client));

        $disallowed = [];
        foreach (explode("\n", $robots) as $line) {
            if (str_starts_with($line, 'Disallow: ')) {
                $disallowed[] = substr($line, \strlen('Disallow: '));
            }
        }
        self::assertNotEmpty($disallowed);

        foreach ($locs as $loc) {
            $path = (string) parse_url($loc, \PHP_URL_PATH);
            // Strip the locale prefix before comparing: /fr/about and /about
            // are the same page, and robots.txt lists the unprefixed form.
            foreach (LocalePrefix::PATHS as $prefix) {
                if ('' !== $prefix && str_starts_with($path, $prefix.'/')) {
                    $path = substr($path, \strlen($prefix));
                    break;
                }
            }
            foreach ($disallowed as $rule) {
                self::assertFalse(
                    str_starts_with($path, $rule),
                    "sitemap advertises {$loc} while robots.txt disallows {$rule}",
                );
            }
        }
    }

    public function testEveryOperationalRegionHasASitemapEntry(): void
    {
        $client = static::createClient();

        // Seed rather than skip. A bare test database has world reference data
        // and no imported catalog, so a skip here would mean the region arm of
        // the sitemap is untested on every CI run — which is the state this
        // whole file exists to end. One row with a real polygon is enough:
        // RegionRegistryProvider::all() needs geom NOT NULL and a country code.
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement(
            "INSERT INTO region (slug, name, country_code, area_km2, geom, created_at, updated_at)
             VALUES ('sitemap-test-region', 'Sitemap Test Region', 'BE', 100,
                     ST_Multi(ST_GeomFromText('POLYGON((5.0 50.0, 5.1 50.0, 5.1 50.1, 5.0 50.1, 5.0 50.0))', 4326)),
                     NOW(), NOW())",
        );

        $regions = static::getContainer()->get(RegionRegistryProvider::class)->all();
        self::assertNotEmpty($regions, 'the seeded region is not operational; check OperationalRegions::predicate');

        $body = self::sitemapBody($client);
        foreach ($regions as $region) {
            self::assertStringContainsString('/'.$region['slug'], $body, "region {$region['slug']} is missing from sitemap.xml");
        }
    }

    private static function sitemapBody(KernelBrowser $client): string
    {
        $client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();

        return (string) $client->getResponse()->getContent();
    }

    /** @return list<string> */
    private static function locs(string $body): array
    {
        preg_match_all('~<loc>([^<]+)</loc>~', $body, $m);

        return array_map(html_entity_decode(...), $m[1]);
    }
}
