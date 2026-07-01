<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Path-prefix i18n routing: English is served clean, the other locales under a
 * `/xx` prefix. Covers the localized routes, the hreflang alternates, and the
 * language switcher's post-switch redirect (including its open-redirect guard).
 */
final class LocalizedRoutingTest extends WebTestCase
{
    public function testEnglishPathIsUnprefixed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/regions');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('lang="en"', (string) $client->getResponse()->getContent());
        // English nav tagline confirms the English catalog is in play.
        self::assertSelectorTextContains('.brandsub', 'OPEN ATLAS');
    }

    public function testFrenchPrefixResolvesAndRendersFrench(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/regions');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('lang="fr"', (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('.brandsub', 'ATLAS OUVERT');
    }

    public function testLocalizedHomeAndAuthPagesResolve(): void
    {
        $client = static::createClient();
        foreach (['/fr/', '/nl/about', '/de/login', '/fr/reset-password'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful(sprintf('Expected 200 for %s', $path));
        }
    }

    public function testSystemRoutesStayUnprefixed(): void
    {
        $client = static::createClient();
        // No localized variant exists for the map or the email-verify link.
        $client->request('GET', '/fr/map');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/fr/verify/email');
        self::assertResponseStatusCodeSame(404);
    }

    public function testHreflangAlternatesEmittedOnLocalizedPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/regions');
        self::assertResponseIsSuccessful();

        $en = $crawler->filter('link[hreflang="en"]');
        $fr = $crawler->filter('link[hreflang="fr"]');
        self::assertCount(1, $en);
        self::assertCount(1, $fr);
        self::assertStringEndsWith('/regions', $en->attr('href') ?? '');
        self::assertStringEndsWith('/fr/regions', $fr->attr('href') ?? '');
        self::assertCount(1, $crawler->filter('link[hreflang="x-default"]'));
    }

    public function testHreflangAbsentOnEnglishOnlyPage(): void
    {
        $client = static::createClient();
        // The map has no localized variants, so no alternates should be advertised.
        $crawler = $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('link[hreflang]'));
    }

    public function testSwitcherLinksToCurrentPageInTargetLocale(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/regions');
        self::assertResponseIsSuccessful();

        // The FR switcher entry routes through locale_switch carrying the FR path as `to`.
        $href = $crawler->filter('.lang-dropdown a')->reduce(
            static fn ($node) => str_contains($node->attr('href') ?? '', '/i18n/fr')
        )->attr('href');
        self::assertStringContainsString('/i18n/fr', $href);
        self::assertStringContainsString('to=/fr/regions', $href);
    }

    public function testLocaleSwitchRedirectsToTargetPath(): void
    {
        $client = static::createClient();
        $client->request('GET', '/i18n/fr', ['to' => '/fr/regions']);
        self::assertResponseRedirects('/fr/regions');
    }

    public function testLocaleSwitchRejectsOpenRedirect(): void
    {
        $client = static::createClient();
        $client->request('GET', '/i18n/fr', ['to' => '//evil.example/phish']);
        self::assertResponseStatusCodeSame(302);
        $location = $client->getResponse()->headers->get('Location');
        self::assertIsString($location);
        self::assertStringStartsNotWith('//', $location);
        self::assertStringNotContainsString('evil.example', $location);
    }
}
