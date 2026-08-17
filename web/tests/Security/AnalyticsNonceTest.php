<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Review 2026-08-16, deferred frontend item 1 (pulled forward): script-src is
 * 'self' + nonce with no third-party host, so the Umami script the analytics
 * loader injects is only allowed if the loader can hand it the page nonce —
 * which requires the include tag itself to carry it. Without this, analytics
 * is silently dead in production and nothing ever errors server-side.
 */
final class AnalyticsNonceTest extends WebTestCase
{
    public function testTheAnalyticsIncludeCarriesThePageNonce(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertSame(1, preg_match("/'nonce-([A-Za-z0-9+\\/=]+)'/", $csp, $m), 'CSP must carry a nonce');
        $nonce = $m[1];

        $html = (string) $client->getResponse()->getContent();
        self::assertSame(
            1,
            preg_match('#<script[^>]*src="[^"]*analytics[^"]*"[^>]*nonce="'.preg_quote($nonce, '#').'"#', $html)
                + preg_match('#<script[^>]*nonce="'.preg_quote($nonce, '#').'"[^>]*src="[^"]*analytics[^"]*"#', $html),
            'the analytics include tag must carry the SAME nonce the CSP header names, or the loader has nothing to pass on'
        );
    }

    public function testTheMapPageIncludeCarriesItToo(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertSame(1, preg_match("/'nonce-([A-Za-z0-9+\\/=]+)'/", $csp, $m));
        $nonce = $m[1];

        $html = (string) $client->getResponse()->getContent();
        // Not the literal "analytics.js": the asset mapper serves a versioned
        // name (analytics-<hash>.js), which is why the regexes match loosely.
        self::assertSame(
            1,
            preg_match('#<script[^>]*src="[^"]*analytics[^"]*"[^>]*nonce="'.preg_quote($nonce, '#').'"#', $html)
                + preg_match('#<script[^>]*nonce="'.preg_quote($nonce, '#').'"[^>]*src="[^"]*analytics[^"]*"#', $html)
        );
    }
}
