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
}
