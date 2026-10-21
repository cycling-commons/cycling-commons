<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Contribution;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Contribute hub page: public, no auth gate.
 *
 * The hub lists contribution categories and links to the gated wizards.
 * It must be viewable by anyone — the login prompt happens at the wizard level.
 */
final class ContributeHubTest extends WebTestCase
{
    // ── Public access ────────────────────────────────────────────────────────

    public function testAnonCanGetContributePage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/contribute');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);
    }

    // ── Link integrity ───────────────────────────────────────────────────────

    public function testPageContainsAddClimbLink(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/contribute');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('a[href="/improve?type=climbs&mode=add"]')->count(),
            'Expected a link to the climb add form on the contribute hub.',
        );
    }

    public function testPageContainsImproveLink(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/contribute');

        self::assertResponseIsSuccessful();
        // Several improve links exist (different type= params); assert at least one
        self::assertGreaterThan(
            0,
            $crawler->filter('a[href^="/improve"]')->count(),
            'Expected at least one link to /improve on the contribute hub.',
        );
    }

    public function testCardsDeepLinkToTheTypeAwareWizard(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/contribute');

        self::assertResponseIsSuccessful();

        // Each category card carries its canonical ?type= slug so the wizard
        // opens the right per-type form (not the default bike-services one).
        // `quality-rides` (R) is intentionally excluded: it now has its own
        // rider intake at /propose-route (route-domain.md §4), asserted by
        // ProposeRouteFlowTest::testContributeHubCardPointsToProposeRoute.
        foreach (['water-food', 'where-to-sleep', 'road-surface'] as $slug) {
            self::assertGreaterThan(
                0,
                $crawler->filter('a[href*="type='.$slug.'"]')->count(),
                "Expected a contribute card deep-linking to ?type={$slug}.",
            );
        }

        // The stale demo item slugs must be gone.
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('item=water-fountain', $body);
        self::assertStringNotContainsString('item=repair-station', $body);
    }

    public function testTheHubShowsTheHomePagesCards(): void
    {
        $client = static::createClient();
        $home = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $homeTitles = $home->filter('.grid a.card h4')->each(static fn ($h): string => $h->text());

        $hub = $client->request('GET', '/contribute');
        self::assertResponseIsSuccessful();
        $hubTitles = $hub->filter('.grid .card[id^="type-"] h4')->each(static fn ($h): string => $h->text());

        self::assertSame($homeTitles, $hubTitles, 'the hub has the home page\'s cards, in its order');
        self::assertSame(
            $home->filter('.grid a.card .fr')->count(),
            $hub->filter('.grid .card[id^="type-"] .fr')->count(),
            'the same cards wear the freshness dots',
        );
        self::assertCount(1, $hub->filter('.fresh-key'), 'the dots are explained above the cards');
        self::assertCount(0, $hub->filter('.card[id^="type-"] > .ic'), 'categories wear their drawn icon, not an emoji');

        // General facilities holds two forms: water and food, and public toilets.
        $facilities = $hub->filter('#type-water-food');
        self::assertSame('div', $facilities->nodeName());
        self::assertCount(1, $facilities->filter('a[href="/improve?type=water-food&mode=add"]'));
        self::assertCount(1, $facilities->filter('a[href="/improve?type=public-toilets&mode=add"]'));
    }

    public function testPageContainsVoteLink(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/contribute');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('a[href="/vote"]')->count(),
            'Expected a link to /vote on the contribute hub.',
        );
    }

    // ── No raw .html links ───────────────────────────────────────────────────

    public function testNoRawHtmlInternalLinks(): void
    {
        $client = static::createClient();
        $client->request('GET', '/contribute');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        // Internal .html links must not appear in href attributes
        self::assertStringNotContainsString(
            '.html"',
            $body,
            'Found a raw .html href — all internal links must use path().',
        );
    }
}
