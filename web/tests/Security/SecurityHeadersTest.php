<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Review 2026-08-16 finding 4: the baseline hardening headers ride on every
 * main response — HTML pages AND the JSON endpoints CspSubscriber skips,
 * because nosniff matters most on the responses that carry no CSP.
 */
final class SecurityHeadersTest extends WebTestCase
{
    public function testHtmlResponseCarriesBaselineHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('Referrer-Policy', 'strict-origin-when-cross-origin');
        self::assertResponseHeaderSame('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function testJsonResponseCarriesNosniffWithoutCsp(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        // The CSP subscriber deliberately skips non-document responses; the
        // hardening headers must NOT share that skip.
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function testHstsIsNotTheAppsJob(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        // TLS terminates in front of the app: HSTS belongs to the nginx
        // frontends (docs/plans/handoffs/2026-08-17-nginx-headers-devops.md).
        // If this assertion ever fails, someone added it app-side — decide
        // which layer owns it before doubling the header.
        self::assertFalse($client->getResponse()->headers->has('Strict-Transport-Security'));
    }
}
