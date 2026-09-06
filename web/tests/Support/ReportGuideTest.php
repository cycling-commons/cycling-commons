<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\ReportLinkResolver;
use App\Support\ReportTarget;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /report, the page that says where the Report link is and turns a pasted
 * Commons link into the right report form (content-reports.md §12).
 */
final class ReportGuideTest extends WebTestCase
{
    public function testTheGuideRendersForEveryoneAndNamesEveryTarget(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/report');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'should not be there');
        self::assertCount(6, $crawler->filter('.rg-where li'), 'one card per reportable kind, photos included');
        self::assertSame(1, $crawler->filter('form.rg-find input[name="url"]')->count());
        self::assertSame(0, $crawler->filter('.rg-miss')->count(), 'no verdict before a link is pasted');
    }

    public function testTheGuideIsLinkedFromTheFooterInEveryLocale(): void
    {
        $client = static::createClient();
        foreach (['/about' => '/report', '/fr/a-propos' => '/fr/signaler'] as $page => $expected) {
            $crawler = $client->request('GET', $page);
            self::assertResponseIsSuccessful();
            self::assertContains($expected, $crawler->filter('footer a')->extract(['href']), $page.' links the report guide');
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function linksThatResolve(): iterable
    {
        $uuid = '0192f5c1-7a3b-7c4d-8e9f-0a1b2c3d4e5f';
        yield 'rider profile, full address' => ['https://cyclingcommons.org/riders/'.$uuid, '/report/rider/'.$uuid];
        yield 'rider profile, upper-case uuid' => ['/riders/'.strtoupper($uuid), '/report/rider/'.$uuid];
        yield 'photo page, localised' => ['/de/photo/'.$uuid, '/report/photo/'.$uuid];
        yield 'map item with a slug tail' => ['/map?item=123/col-du-rosier', '/report/item/123'];
        yield 'map route' => ['https://cyclingcommons.org/map?route=77&x=1', '/report/route/77'];
    }

    #[DataProvider('linksThatResolve')]
    public function testAPastedLinkOpensTheRightReportForm(string $url, string $expected): void
    {
        $client = static::createClient();
        $client->request('GET', '/report?url='.rawurlencode($url));
        self::assertResponseRedirects($expected);
    }

    public function testARegionPageResolvesBySlug(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(Connection::class);
        $row = $db->fetchAssociative('SELECT id, slug FROM region ORDER BY id LIMIT 1');
        if (false === $row) {
            self::markTestSkipped('no region rows in this database');
        }

        $client->request('GET', '/report?url='.rawurlencode('/nl/regios/'.$row['slug']));
        self::assertResponseRedirects('/report/region/'.$row['id']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function linksThatDoNot(): iterable
    {
        yield 'a coverage point straight from OSM' => ['/map?ref=way/1234', 'OpenStreetMap'];
        yield 'not a Commons address at all' => ['https://example.com/anything', 'does not point'];
        yield 'a map link with no target' => ['/map', 'does not point'];
        yield 'an item id that is not one of ours' => ['/map?item=abc', 'does not point'];
        yield 'an unknown region slug' => ['/regions/no-such-region-xyz', 'does not point'];
    }

    #[DataProvider('linksThatDoNot')]
    public function testALinkThatLeadsNowhereSaysSoAndKeepsTheInput(string $url, string $expected): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/report?url='.rawurlencode($url));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($expected, $crawler->filter('.rg-miss')->text());
        self::assertSame($url, $crawler->filter('input[name="url"]')->attr('value'));
    }

    public function testTheResolverNeverTreatsAnIdAsALookup(): void
    {
        static::createClient();
        $resolver = static::getContainer()->get(ReportLinkResolver::class);
        // An id nobody has: still resolved, because the form itself is the
        // only place that may learn whether a thing exists.
        $hit = $resolver->resolve('/map?item=9999999');
        self::assertSame(ReportLinkResolver::OUTCOME_FOUND, $hit['outcome']);
        self::assertSame(ReportTarget::Item, $hit['target']);
        self::assertSame('9999999', $hit['id']);
    }
}
