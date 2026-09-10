<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The analytics host is named in `script-src`, not carried by a nonce.
 *
 * It used to be the other way round: script-src was `'self'` plus a nonce with
 * no third-party host, so the loader had to copy its own nonce onto the Umami
 * script it injects, which meant the include tag had to carry one. That worked,
 * and it silently tied every page with analytics on it to a per-request value,
 * which is precisely what stops a page being held in a shared cache
 * (docs/specs/page-caching.md §3.2).
 *
 * Naming the host is both simpler and cacheable. The failure mode either way is
 * the same and worth guarding: get it wrong and analytics is dead in production
 * with nothing ever erroring server-side.
 */
final class AnalyticsNonceTest extends WebTestCase
{
    private const string HOST = 'https://analytics.bikecoders.life';

    public function testScriptSrcNamesTheAnalyticsHost(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertSame(1, preg_match('/script-src ([^;]+)/', $csp, $m), 'CSP must carry a script-src');
        self::assertStringContainsString(
            self::HOST,
            $m[1],
            'script-src must name the analytics host, or the injected Umami script is blocked',
        );
    }

    public function testTheIncludeTagCarriesNoNonce(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        // Not the literal "analytics.js": the asset mapper serves a versioned
        // name (analytics-<hash>.js), which is why the regex matches loosely.
        $html = (string) $client->getResponse()->getContent();
        self::assertSame(1, preg_match('#<script[^>]*src="[^"]*analytics[^"]*"[^>]*>#', $html, $m),
            'the analytics include must be on the page');
        self::assertStringNotContainsString('nonce=', $m[0],
            'a nonce here would tie every page carrying analytics to a per-request value');
    }

    /** The map is not cacheable, but it must not need a different rule either. */
    public function testTheMapPageIsTheSame(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertStringContainsString(self::HOST, $csp);

        $html = (string) $client->getResponse()->getContent();
        self::assertSame(1, preg_match('#<script[^>]*src="[^"]*analytics[^"]*"[^>]*>#', $html, $m));
        self::assertStringNotContainsString('nonce=', $m[0]);
    }
}
