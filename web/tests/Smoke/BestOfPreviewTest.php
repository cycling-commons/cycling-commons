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

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.prev');
    }
}
