<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
            $crawler->filter('a[href="/add-climb"]')->count(),
            'Expected a link to /add-climb on the contribute hub.',
        );
    }

    public function testPageContainsImproveLink(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/contribute');

        self::assertResponseIsSuccessful();
        // Several improve links exist (different item= params); assert at least one
        self::assertGreaterThan(
            0,
            $crawler->filter('a[href^="/improve"]')->count(),
            'Expected at least one link to /improve on the contribute hub.',
        );
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
