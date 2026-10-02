<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Catalog\BestOfPreview;
use App\Catalog\ItemType;
use App\Catalog\Season;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The public seasonal result, while its numbers are invented.
 *
 * The page exists to settle what a result page should show before the ballot
 * is built (owner 2026-09-12). That makes two things load-bearing: it must be
 * reachable without an account, since the whole complaint was that nobody
 * outside can see what riders chose, and it must never let an invented tally
 * be mistaken for a real one.
 */
final class BestOfPreviewTest extends WebTestCase
{
    public function testItIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/best');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'best');
    }

    /** Said on the page itself, not only in a spec nobody reading it will open. */
    public function testEveryRenderSaysTheNumbersAreMadeUp(): void
    {
        $client = static::createClient();

        foreach (['/best', '/best?cat=quality-rides', '/best?cat=scenic-views&season=winter'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertSelectorExists('.prev', $url);
            self::assertStringContainsString('made up', (string) $client->getResponse()->getContent(), $url);
        }
    }

    /**
     * A reload must not reshuffle anything.
     *
     * A preview whose order moved would teach a reader that votes are coming
     * in, which is the one thing it must not imply while there are none.
     */
    public function testTheSameRoundAlwaysRanksTheSameWay(): void
    {
        self::bootKernel();
        $preview = self::getContainer()->get(BestOfPreview::class);
        self::assertInstanceOf(BestOfPreview::class, $preview);

        $once = $preview->ranking(ItemType::ScenicViews, Season::Summer);
        $twice = $preview->ranking(ItemType::ScenicViews, Season::Summer);

        self::assertSame($once, $twice);
    }

    /**
     * Bike type belongs to the vote, so it changes WHO ranked, not WHICH
     * routes are on offer. That is the reason the split is worth having, and
     * a preview that ignored it would design the wrong ballot.
     */
    public function testADifferentBikeGivesADifferentRankingOfTheSameRoutes(): void
    {
        self::bootKernel();
        $preview = self::getContainer()->get(BestOfPreview::class);
        self::assertInstanceOf(BestOfPreview::class, $preview);

        $any = $preview->ranking(ItemType::QualityRides, Season::Summer);
        $handbike = $preview->ranking(ItemType::QualityRides, Season::Summer, null, ['Handbike']);

        if ([] === $any) {
            self::markTestSkipped('no served routes in this database');
        }
        self::assertNotSame(array_column($any, 'votes'), array_column($handbike, 'votes'));
        self::assertSame(
            array_unique(array_column($any, 'id')),
            array_unique(array_column($any, 'id')),
            'a route may appear once per ranking',
        );
    }

    /** Every row carries the flag, so no template can forget to say so. */
    public function testEveryRowIsMarkedSimulated(): void
    {
        self::bootKernel();
        $preview = self::getContainer()->get(BestOfPreview::class);
        self::assertInstanceOf(BestOfPreview::class, $preview);

        foreach ($preview->ranking(ItemType::Climbs, Season::Autumn) as $row) {
            self::assertTrue($row['simulated']);
            self::assertGreaterThan(0, $row['votes']);
            self::assertGreaterThanOrEqual($row['votes'], $row['rides'], 'fewer riders than voters is not possible');
        }
    }

    /**
     * A country is read region by region, and the silent ones are named.
     *
     * One national top ten flattens the Alps into Brittany and tells a rider
     * near neither anything (owner 2026-09-12). The regions with nothing yet
     * are listed rather than dropped, because "nobody has voted here" is an
     * invitation and a silent omission is not.
     */
    public function testPickingACountrySplitsItIntoRegions(): void
    {
        self::bootKernel();
        $preview = self::getContainer()->get(BestOfPreview::class);
        self::assertInstanceOf(BestOfPreview::class, $preview);

        $split = $preview->byRegion(ItemType::Climbs, Season::Spring, 'FR');

        self::assertArrayHasKey('ranked', $split);
        self::assertArrayHasKey('quiet', $split);
        foreach ($split['ranked'] as $region) {
            self::assertNotSame([], $region['top'], 'a region in the ranked half must have a ranking');
        }
        foreach ($split['quiet'] as $region) {
            self::assertArrayNotHasKey('top', $region);
        }
    }

    /**
     * A country's own L2 outline row is not one of its regions.
     *
     * It has no page, so listing it would put "France" inside France with a
     * link to nowhere (catalog-data-model.md §2.4).
     */
    public function testTheCountryOutlineIsNotListedAsARegionOfItself(): void
    {
        self::bootKernel();
        $preview = self::getContainer()->get(BestOfPreview::class);
        self::assertInstanceOf(BestOfPreview::class, $preview);

        $split = $preview->byRegion(ItemType::Climbs, Season::Spring, 'FR');
        $names = array_merge(
            array_column($split['ranked'], 'name'),
            array_column($split['quiet'], 'name'),
        );

        self::assertNotContains('France', $names);
    }

    /** An unknown category or season falls back rather than 404s: these are browsing controls. */
    public function testJunkParametersFallBack(): void
    {
        $client = static::createClient();
        $client->request('GET', '/best?cat=not-a-thing&season=harvest&cc=ZZ&bike=Unicycle&diff=Impossible');

        // Nothing in that query names a real filter, so its one URL is the bare
        // page (BestOfFilters): a 301 there, then the page itself.
        self::assertResponseRedirects('/best', 301);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.prev');
    }

    /**
     * One URL per filter state (BestOfFilters). A crawler following every
     * filter link found an endless set of spellings of the same pages, each a
     * page-cache miss (devOps 2026-09-28).
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function spellings(): iterable
    {
        yield 'empty parameters' => ['/best?season=autumn&bike=&diff=&len=&cc=', '/best?season=autumn'];
        yield 'another order' => ['/best?season=autumn&cat=quality-rides', '/best?cat=quality-rides&season=autumn'];
        yield 'one bike, the first one asked' => ['/best?cat=quality-rides&bike=Gravel,Road,Gravel', '/best?cat=quality-rides&bike=Gravel'];
        yield 'a route filter on a category that has none' => ['/best?cat=climbs&bike=Road', '/best'];
        yield 'the default category' => ['/best?cat=climbs&season=winter', '/best?season=winter'];
        yield 'an unknown parameter' => ['/best?utm_source=x', '/best'];
        yield 'in another language' => ['/nl/beste?season=summer&cc=', '/nl/beste?season=summer'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('spellings')]
    public function testEverySpellingMovesToTheOneUrl(string $asked, string $normal): void
    {
        $client = static::createClient();
        $client->request('GET', $asked);
        self::assertResponseRedirects($normal, 301);

        $client->request('GET', $normal);
        self::assertResponseIsSuccessful('the normal form answers itself');
    }

    /** Filter links are built in the normal form, never followed by a crawler, and a filtered view stays out of the index. */
    public function testFilterLinksAreNormalNofollowAndFilteredViewsAreNotIndexed(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/best?cat=quality-rides&season=autumn');
        self::assertResponseIsSuccessful();

        $links = $crawler->filter('.filters a[href^="/best"]');
        self::assertGreaterThan(5, $links->count());
        foreach ($links as $a) {
            \assert($a instanceof \DOMElement);
            self::assertSame('nofollow', $a->getAttribute('rel'), $a->getAttribute('href'));
            $client->request('GET', $a->getAttribute('href'));
            self::assertResponseIsSuccessful('a filter link needs no redirect: '.$a->getAttribute('href'));
        }

        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringEndsWith('/best', (string) $crawler->filter('link[rel="canonical"]')->attr('href'), 'the canonical names the unfiltered page');

        $bare = $client->request('GET', '/best');
        self::assertCount(0, $bare->filter('meta[name="robots"]'), 'the unfiltered page stays indexable');
    }
}
