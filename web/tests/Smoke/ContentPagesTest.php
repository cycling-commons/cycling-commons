<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Smoke;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContentPagesTest extends WebTestCase
{
    public function testAboutRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/about');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.topnav .links');
        self::assertSelectorExists('footer.foot');
        self::assertSelectorTextContains('h1.disp', 'The map belongs to everyone');
    }

    public function testPrivacyRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/privacy');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('footer.foot');
        self::assertSelectorTextContains('h1', 'What we collect');
    }

    public function testTermsRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/terms');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('footer.foot');
        self::assertSelectorTextContains('h1', 'The deal, in plain language');
        self::assertStringContainsString(
            'UI translations',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testLicensesRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/licenses');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('footer.foot');
        self::assertSelectorTextContains('h1', 'The short version');
        // Fourth licence card: UI translations, AGPL-3.0-only like the code
        // they render, separate from the ODbL data (translations.md §6).
        self::assertStringContainsString(
            'The UI translations',
            (string) $client->getResponse()->getContent(),
        );
    }

    /**
     * The slug is localised too, not only the prefix (`LocalizedPath`), so the
     * URL is generated rather than typed. A hardcoded `/fr/licenses` broke the
     * day the French slug became `/fr/licences`, and the next slug change would
     * break it again.
     */
    public function testLicensesRendersInFrench(): void
    {
        $client = static::createClient();
        $url = static::getContainer()->get('router')->generate('licenses', ['_locale' => 'fr']);

        self::assertSame('/fr/licences', $url, 'the French slug is translated, not just prefixed');

        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
    }

    public function testCreditsRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/credits');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('footer.foot');
        self::assertSelectorTextContains('h1', 'Credits');
        self::assertSelectorTextContains('body', 'OpenStreetMap');
    }

    public function testCoverageRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/coverage');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('footer.foot');
        self::assertSelectorTextContains('h1', 'How complete is the map');
        // One response carries both views. The table is the one the markup
        // shows on its own, because it is the view that needs no JavaScript;
        // the globe rides along hidden by CSS until a class says otherwise.
        self::assertSelectorExists('#cov-table table');
        self::assertSelectorExists('#cov-globe #coverage-globe[data-ramp][data-outlines]');
        self::assertSelectorExists('#cov-globe .clegend');
        // The chips are buttons, not links: pressing one swaps the view in
        // place. They start hidden, so a reader with no JavaScript is never
        // offered a switch that cannot move.
        self::assertSelectorExists('.viewrow[hidden] button.chip[data-view="globe"]');
        self::assertSelectorExists('.viewrow[hidden] button.chip[data-view="table"]');
        self::assertSelectorNotExists('.viewrow a.chip');
        // The head script sets the opening view as a class.
        self::assertSelectorExists('script[src*="pages/coverage-view"]');
        self::assertSelectorExists('script[src*="pages/country-globe"]');
        self::assertSelectorExists('script[src*="pages/coverage-globe"]');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('nonce=', $html, 'the page must stay nonce-free so a shared cache can hold it');
        // One card per country waits hidden with its density class.
        if (preg_match_all('/class="ccard"[^>]*data-cls="(\d)"/', $html, $m) > 0) {
            foreach ($m[1] as $cls) {
                self::assertLessThanOrEqual(5, (int) $cls);
            }
        }
    }

    /**
     * The view is not a server concern, so every spelling of `?view=` serves
     * the one page, whole. Which view opens is decided in the browser by
     * assets/pages/coverage-view.js, which is why a shared `?view=globe`
     * link still lands on the globe without the server knowing about it.
     */
    public function testEveryViewParameterServesTheOnePage(): void
    {
        $client = static::createClient();

        foreach (['/coverage', '/coverage?view=globe', '/coverage?view=table', '/coverage?view=pie'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            // The hreflang and language-switcher links carry the query
            // string through, so the bodies are not byte-identical; what must
            // not vary is which views the page holds.
            self::assertSelectorExists('#cov-table table', $path);
            self::assertSelectorExists('#cov-globe #coverage-globe', $path);
            self::assertSelectorExists('.viewrow[hidden] button.chip[data-view="globe"]', $path);
        }
    }

    public function testPagesRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pages');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('footer.foot');
        self::assertSelectorTextContains('h1', 'The whole Commons');
    }

    /**
     * A site directory whose links go nowhere is worse than no directory: it
     * teaches a reader the section is unbuilt. Five of the cards shipped with
     * `href="#"` while every one of those pages existed and was routable
     * (found 2026-08-15), so the shape of that bug is pinned here rather than
     * left to the next reader to notice.
     */
    public function testEveryCardOnThePagesDirectoryLeadsSomewhere(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/pages');
        self::assertResponseIsSuccessful();

        $hrefs = $crawler->filter('.pg a')->extract(['href']);
        self::assertNotEmpty($hrefs, 'the directory grid rendered no cards at all');
        foreach ($hrefs as $href) {
            self::assertNotSame('#', $href, 'a directory card still points at nothing');
            // The wiki is the one door that leaves the site; everything else is ours.
            self::assertMatchesRegularExpression('#^(/|https://wiki\.cyclingcommons\.org/)#', (string) $href);
        }

        // The surfaces that were missing entirely until the same audit. Named
        // one by one, because "some links exist" is what the bug looked like.
        foreach (['/join', '/scout', '/propose-route', '/messages', '/settings', '/privacy', '/terms',
            '/contribute', '/improve'] as $path) {
            self::assertContains($path, $hrefs, $path.' is a live surface and belongs in the directory');
        }
    }

    /**
     * The directory is the public face of the site. The moderation desk is a
     * curator's workroom, reached from the account chip, and is not listed
     * here for anyone, curator included.
     */
    public function testTheDirectoryNeverListsTheModerationDesk(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/pages');
        self::assertResponseIsSuccessful();
        self::assertNotContains('/moderate', $crawler->filter('.pg a')->extract(['href']));

        $curator = (new User())->setEmail('pages-directory-curator@example.com');
        $curator->setPassword('x');
        $curator->setDisplayName('Directory Curator');
        $curator->setRoles(['ROLE_CURATOR']);
        // A curator without a second factor is sent to /2fa/setup before any
        // page renders, so the account arrives with one already enrolled.
        $curator->setTotpSecret('JBSWY3DPEHPK3PXP');
        $curator->setTwoFaEnabled(true);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($curator);
        $em->flush();

        $client->loginUser($curator);
        $crawler = $client->request('GET', '/pages');
        self::assertResponseIsSuccessful();
        self::assertNotContains('/moderate', $crawler->filter('.pg a')->extract(['href']));
    }

    /**
     * The map and the list are two drawings of one directory. They read the
     * same data in the template, and this pins that they cannot drift: every
     * page on one is on the other, and nothing else.
     */
    public function testTheDirectoryMapAndListLinkTheSamePages(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/pages');
        self::assertResponseIsSuccessful();

        $map = array_values(array_unique($crawler->filter('#dir-map a.hub')->extract(['href'])));
        $list = array_values(array_unique($crawler->filter('#dir-list .pg a')->extract(['href'])));
        sort($map);
        sort($list);
        self::assertNotEmpty($map);
        self::assertSame($list, $map);
        self::assertGreaterThan(0, $crawler->filter('#dir-map svg.pmap .road')->count(), 'the map draws its roads');
        self::assertSame(6, $crawler->filter('#dir-map svg.pmap .land')->count(), 'six countries');
    }

    /** The toggle is two links, so it works with no script and each view has its own URL. */
    public function testTheDirectoryToggleWorksWithoutAScript(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/pages');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('#dir-map[hidden]')->count(), 'the map is the default drawing');
        self::assertSame(1, $crawler->filter('#dir-list[hidden]')->count());
        self::assertSame(1, $crawler->filter('.dirtoggle a[data-view="map"][aria-pressed="true"]')->count());

        $crawler = $client->request('GET', '/pages?view=list');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('#dir-map[hidden]')->count());
        self::assertSame(0, $crawler->filter('#dir-list[hidden]')->count());
        self::assertSame(1, $crawler->filter('.dirtoggle a[data-view="list"][aria-pressed="true"]')->count());
        self::assertSame('/pages?view=map', $crawler->filter('.dirtoggle a[data-view="map"]')->attr('href'));
    }

    /**
     * A CSS rule that lands one line past its </style> prints as text at the
     * top of the page (found on the content-report form, 2026-09-06, just
     * before a deploy). Every public page is read with its style and script
     * blocks removed; a selector followed by a brace is the leak.
     */
    public function testNoPublicPagePrintsAStylesheetAsText(): void
    {
        $client = static::createClient();
        foreach (['/', '/about', '/contact', '/report-bug', '/report/item/1', '/report', '/pages', '/contributors', '/roadmap', '/known-issues', '/privacy', '/terms', '/accessibility', '/credits', '/licenses', '/developers', '/regions', '/coverage', '/join', '/scout', '/map-key'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            // The whole document, not the body: a rule that escaped its
            // <style> in the head block is still head markup, and a browser
            // moves stray head text into the body, which is what was seen.
            $html = (string) $client->getResponse()->getContent();
            $visible = preg_replace(['#<style\b.*?</style>#s', '#<script\b.*?</script>#s'], '', $html) ?? '';
            self::assertDoesNotMatchRegularExpression('/^\s*[.#][A-Za-z][\w-]*[^{}\n]*\{[^{}]*\}\s*$/m', $visible, $path.' prints a CSS rule as page text');
        }
    }

    public function testUnknownPathReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/no-such-page-xyz');
        self::assertResponseStatusCodeSame(404);
    }

    public function testHomeRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.topnav');
        self::assertSelectorExists('footer.foot');
        // The closing line, not the opening one: "One open atlas" is the part
        // of the hero that has held across rewrites, where the lines above it
        // are the pitch and get re-cut (2026-08-08).
        self::assertSelectorTextContains('h1', 'open atlas');
    }

    public function testHomeUsesFinalOrderAndCompactCopy(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        // The chosen homepage (was /home-pair2) is the real one: indexable.
        // Only the template-level check works here: in debug/test the framework
        // stamps X-Robots-Tag: noindex on every response by itself.
        self::assertSelectorNotExists('meta[name="robots"]');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('community-built map of the world', $html);
        self::assertStringContainsString('Each answer lives in its own app', $html);
        self::assertStringNotContainsString('Today every layer lives in its own silo', $html);

        $frag = strpos($html, 'Cycling knowledge is scattered');
        $cur = strpos($html, 'The best of a region');
        $what = strpos($html, 'Open data about the world');
        $how = strpos($html, 'One tap at a time');
        $scout = strpos($html, 'Scout — tag it while you ride');
        self::assertNotFalse($frag);
        self::assertNotFalse($cur);
        self::assertNotFalse($what);
        self::assertNotFalse($how);
        self::assertNotFalse($scout);
        self::assertLessThan($cur, $frag);
        self::assertLessThan($what, $cur);
        self::assertLessThan($scout, $how);
    }

    public function testRetiredHomeVariantsAreGone(): void
    {
        $client = static::createClient();
        $client->request('GET', '/home-pair');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/home-pair2');
        self::assertResponseStatusCodeSame(404);
    }

    public function testJoinRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/join');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('footer.foot');
        self::assertSelectorTextContains('h1', 'Build the Commons with us');
    }

    public function testContributorsRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/contributors');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('footer.foot');
        self::assertSelectorTextContains('h1', 'Built by riders');
    }

    public function testDevelopersRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/developers');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('footer.foot');
        self::assertSelectorTextContains('h1', 'An open API');
    }

    public function testRegionRedirectsToDetailPage(): void
    {
        // /region is the pre-DB showcase URL; it now permanently redirects
        // into the DB-driven /regions/{slug} detail page (RegionsPagesTest
        // covers that page's own rendering against seeded data).
        $client = static::createClient();
        $client->request('GET', '/region');
        self::assertResponseRedirects('/regions/wallonia', 301);
    }

    public function testRegionsRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/regions');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('footer.foot');
        self::assertSelectorExists('h1');
        self::assertStringNotContainsString(
            'being built in a later migration phase',
            (string) $client->getResponse()->getContent()
        );
    }
}
