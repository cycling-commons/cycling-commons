<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * AGPL section 13: anyone who interacts with this software over a network must
 * be offered its Corresponding Source. That duty is ours, not only a fork's,
 * and it is discharged by exactly one thing on the page: the footer build
 * stamp, which is a link.
 *
 * Both halves are load-bearing and neither works alone. A link that names no
 * build points at whatever HEAD happens to be, which is not the code serving
 * the page once a box is hotfixed. A build name that is not a link offers
 * nothing to fetch. This test fails if either half is dropped again, and it
 * deliberately does not accept the GitHub glyph in the social row as a
 * substitute: that row comes from CC_SOCIAL_GITHUB and renders nothing when
 * the variable is unset, so a licence duty cannot rest on it.
 */
final class SourceOfferTest extends WebTestCase
{
    public function testTheFooterOffersTheSourceOfThisExactBuild(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $stamp = $crawler->filter('a.footver');
        self::assertCount(1, $stamp, 'the build stamp must be exactly one link');

        $href = $stamp->attr('href') ?? '';
        self::assertStringStartsWith('https://', $href, 'the source offer must be fetchable');

        $build = static::getContainer()->get('App\Service\BuildVersion')->stamp();
        if ('' !== $build['commit']) {
            self::assertStringEndsWith('/commit/'.$build['commit'], $href, 'the offer must name the running commit');
        }

        // The visible text names the build server-side, so the offer survives
        // with JavaScript off; version.js only reformats the date.
        self::assertStringContainsString($build['number'], $stamp->text(), 'the stamp must name the build');
    }

    /**
     * `/humans.txt` dates itself from the build, so the line cannot go stale.
     *
     * The static file this replaced said `Last update: 2026/06/17` for months.
     * A hand-kept date does not say when the site last changed, it says when
     * somebody last thought about the file, and a reader cannot tell which. The
     * date and the source link both come from the same stamp the footer prints.
     */
    public function testHumansTxtDatesItselfFromTheBuild(): void
    {
        $client = static::createClient();
        $client->request('GET', '/humans.txt');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=utf-8');

        $body = (string) $client->getResponse()->getContent();
        $build = static::getContainer()->get('App\Service\BuildVersion')->stamp();

        self::assertStringContainsString('Build: '.$build['number'], $body);
        self::assertStringContainsString('Source: '.$build['url'], $body);
        if ('' !== $build['date']) {
            self::assertStringContainsString('Last update: '.$build['date'], $body);
        }

        // The stale hand-written date must never come back in any form.
        self::assertDoesNotMatchRegularExpression('#Last update: 20\d\d/#', $body, 'the date is derived, never written by hand');
        // It states the dataset claim correctly, not a platform-wide absolute.
        self::assertStringContainsString('Commons dataset', $body);
    }

    /** One offer, not two: the old colophon "Source code" link is gone. */
    public function testTheColophonNoLongerCarriesASecondSourceLink(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $links = $crawler->filter('.colophon a');
        self::assertCount(0, $links, 'the colophon states licences; the offer lives on the stamp');

        self::assertSame(1, $crawler->filter('footer a[href*="/commit/"], footer a.footver')->reduce(
            static fn (Crawler $n): bool => str_contains($n->attr('class') ?? '', 'footver'),
        )->count());
    }
}
