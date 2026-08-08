<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Smoke;

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
    }

    public function testLicensesRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/licenses');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('footer.foot');
        self::assertSelectorTextContains('h1', 'The short version');
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
    }

    public function testPagesRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pages');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('footer.foot');
        self::assertSelectorTextContains('h1', 'The whole Commons');
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
