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
        // `/nl/about` is now `/nl/over-ons`: LocalizedPath translates the slug,
        // not only the prefix. Generated, so a future slug change does not need
        // this list edited.
        $router = static::getContainer()->get('router');
        $paths = [
            '/fr/',
            $router->generate('about', ['_locale' => 'nl']),
            '/de/login',
            '/fr/reset-password',
        ];
        self::assertSame('/nl/over-ons', $paths[1]);

        foreach ($paths as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful(sprintf('Expected 200 for %s', $path));
        }
    }

    public function testSystemRoutesStayUnprefixed(): void
    {
        $client = static::createClient();
        // No localized variant exists for the map.
        $client->request('GET', '/fr/map');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * `/verify/email` used to be deliberately unprefixed, and this test asserted
     * it 404'd under a locale. That was reversed on 2026-08-27 (owner): it is
     * the one page a rider reaches from an email, with no referring page to
     * inherit a language from, so the language it should answer in has to be
     * written into the link at signup time. English keeps the bare path, so
     * links already sitting in inboxes stay valid.
     */
    public function testTheEmailVerifyLinkIsLocalized(): void
    {
        $client = static::createClient();

        // A missing `id` short-circuits to `register` before any signature
        // check, which is enough to prove the route matched and which locale
        // it resolved to.
        foreach (['/nl/verify/email' => '/nl/register', '/fr/verify/email' => '/fr/register', '/verify/email' => '/register'] as $path => $expected) {
            $client->request('GET', $path);
            self::assertResponseRedirects($expected, null, sprintf('%s should route and redirect in its own locale', $path));
        }
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

    /**
     * Regression, reported 2026-07-27 as "the map does not change locale".
     * The labels were translating fine — but the switcher regenerated the path
     * from `_route`/`_route_params`, which do NOT carry the query string, so
     * switching language on `/map?scope=region:niedersachsen` landed the rider
     * on a bare `/map`, i.e. the default scope, looking at a different region.
     * `?feature=`, `?route=` and `?pending=` deep links were lost the same way.
     */
    public function testSwitcherPreservesTheQueryString(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/map', ['scope' => 'region:niedersachsen', 'feature' => 'Sample']);
        self::assertResponseIsSuccessful();

        $href = $crawler->filter('.lang-dropdown a')->reduce(
            static fn ($node) => str_contains($node->attr('href') ?? '', '/i18n/de')
        )->attr('href');
        self::assertIsString($href);
        $to = [];
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $to);
        self::assertArrayHasKey('to', $to);
        self::assertSame('/map?scope=region%3Aniedersachsen&feature=Sample', $to['to']);

        // ...and the switch actually lands there.
        $client->request('GET', '/i18n/de', ['to' => $to['to']]);
        self::assertResponseRedirects($to['to']);
    }

    /**
     * The query string must not make an English-only page start advertising
     * hreflang: `localized` compares PATHS, not paths-plus-query.
     */
    public function testQueryStringDoesNotMakeAnEnglishOnlyPageLookLocalized(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/map', ['scope' => 'region:niedersachsen']);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('link[hreflang]'));
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

    /**
     * Review 2026-08-16 finding 8: browsers strip tab/CR/LF inside URLs before
     * resolving, so "/\t//evil.example" would leave the browser as
     * protocol-relative "//evil.example" — the exact redirect the `//` prefix
     * check exists to block. Control characters must fail the allowlist
     * wherever they sit in the string; backslashes too (browsers normalise
     * them to slashes).
     */
    public function testLocaleSwitchRejectsControlCharacterSmuggling(): void
    {
        $client = static::createClient();
        foreach (["/\t//evil.example", "/\r\n//evil.example", '/\\evil.example', "/regions\t.evil.example"] as $payload) {
            $client->request('GET', '/i18n/fr', ['to' => $payload]);
            self::assertResponseStatusCodeSame(302);
            $location = $client->getResponse()->headers->get('Location');
            self::assertIsString($location);
            self::assertStringNotContainsString('evil.example', $location, var_export($payload, true).' must not survive into the redirect');
        }
    }
}
