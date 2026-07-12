<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Frontend review 2026-07-12 W3: every HTML response must carry a
 * Content-Security-Policy whose script-src is limited to self + a request
 * nonce + SRI-pinned unpkg, and every inline <script> in the rendered page
 * must carry that same nonce — otherwise the policy would silently break the
 * page instead of protecting it.
 */
final class CspTest extends WebTestCase
{
    public function testHtmlResponseCarriesCspWithNonce(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $csp = $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertNotNull($csp, 'HTML responses must carry a CSP header');
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\\/=]+' https:\\/\\/unpkg\\.com/", $csp);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp, 'script-src must not allow unsafe-inline');
    }

    /**
     * Every inline script block on a page must carry the SAME nonce the
     * header advertises — a missed block is a page break under enforcement.
     */
    #[DataProvider('inlineScriptPages')]
    public function testEveryInlineScriptCarriesTheHeaderNonce(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);
        self::assertResponseIsSuccessful();

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertSame(1, preg_match("/'nonce-([^']+)'/", $csp, $m), 'CSP must advertise a nonce');
        $nonce = $m[1];

        $html = (string) $client->getResponse()->getContent();
        preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/i', $html, $tags);
        self::assertNotEmpty($tags[1], "expected inline scripts on {$path}");
        foreach ($tags[1] as $attrs) {
            self::assertStringContainsString('nonce="'.$nonce.'"', $attrs, "un-nonced inline <script{$attrs}> on {$path}");
        }
    }

    /** @return iterable<string, array{string}> */
    public static function inlineScriptPages(): iterable
    {
        yield 'home' => ['/'];
        yield 'map' => ['/map'];
        yield 'regions' => ['/regions'];
        yield 'contributors' => ['/contributors'];
    }

    public function testNonHtmlResponsesSkipCsp(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map/catalog.json');

        self::assertResponseIsSuccessful();
        self::assertNull($client->getResponse()->headers->get('Content-Security-Policy'));
    }
}
