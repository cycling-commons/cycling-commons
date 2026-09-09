<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Frontend review 2026-07-12 W3: every HTML response must carry a
 * Content-Security-Policy whose script-src is limited to self + a request
 * nonce + vendored same-origin libraries, and every inline <script> in the rendered page
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
        // The libraries are vendored same-origin, so the allowlist is 'self',
        // the nonce, and exactly one named host: the analytics origin, which is
        // named rather than nonce-carried so that a page can be cached without
        // freezing a nonce (page-caching.md §3.2). Anything else appearing here
        // is a third-party script host and has to be argued for.
        self::assertMatchesRegularExpression(
            "/script-src 'self' 'nonce-[A-Za-z0-9+\\/=]+' https:\\/\\/analytics\\.bikecoders\\.life;/",
            $csp,
        );
        self::assertStringNotContainsString('unpkg.com', $csp);
        self::assertNotContains("'unsafe-inline'", self::scriptSrc($csp), 'script-src must not allow unsafe-inline');
    }

    /**
     * Every page, not only the home page, and anywhere in the directive.
     *
     * The old check looked for the literal `script-src 'self' 'unsafe-inline'`,
     * which only matches when the two sit side by side. The shape that actually
     * turns up has the nonce in between, and the source of it is real: Symfony's
     * web debug toolbar appends `'unsafe-inline'` plus a nonce of its own to
     * whatever CSP it finds. That is fine in dev, where it is scoped to the
     * toolbar's own markup and the browser ignores it anyway once a nonce is
     * present, and it is why the literal check kept passing while the header on
     * a running dev page carried the token twice over.
     *
     * If this ever fails, the question is which of two things happened: our own
     * policy grew an `'unsafe-inline'`, which is the bug this guards, or the
     * profiler started rewriting responses in the test environment too, which
     * would mean the test environment stopped resembling production.
     */
    #[DataProvider('cspPages')]
    public function testNoPageAllowsInlineScript(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');

        self::assertNotContains(
            "'unsafe-inline'",
            self::scriptSrc($csp),
            $path.": script-src must not allow inline script anywhere in the directive.\n".$csp,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function cspPages(): iterable
    {
        yield 'home' => ['/'];
        yield 'map' => ['/map'];
        yield 'regions' => ['/regions'];
        yield 'contributors' => ['/contributors'];
    }

    /**
     * The script-src directive's sources, split out of the whole policy.
     *
     * Asserting on the tokens rather than on a substring of the header: a
     * substring check silently stops matching the moment anything is inserted
     * between the two words it was looking for.
     *
     * @return list<string>
     */
    private static function scriptSrc(string $csp): array
    {
        foreach (explode(';', $csp) as $directive) {
            $parts = preg_split('/\s+/', trim($directive), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            if ('script-src' === ($parts[0] ?? '')) {
                return array_values(\array_slice($parts, 1));
            }
        }

        self::fail('the policy carries no script-src at all: '.$csp);
    }

    /**
     * MapLibre v6 starts its tile worker from the same-origin module URL under
     * public/lib/, where the v5 UMD bundle built a blob: worker. Without 'self'
     * the worker is blocked and the map never loads a tile, and CSP failures
     * are console-only: nothing on the page says why. blob: stays for
     * mapillary-js and for v6's own cross-origin fallback path.
     */
    public function testWorkerSrcAllowsSameOriginAndBlob(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            "worker-src 'self' blob:",
            (string) $client->getResponse()->headers->get('Content-Security-Policy'),
            "MapLibre v6's worker is same-origin; dropping 'self' silently kills every tile",
        );
    }

    /**
     * The Mapillary street-level viewer (mapillary-js) compiles filter
     * expressions with new Function(), so /map — and ONLY /map — relaxes
     * script-src with 'unsafe-eval'; every other page keeps the strict policy.
     */
    public function testUnsafeEvalIsScopedToTheMapPage(): void
    {
        $client = static::createClient();

        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            "'unsafe-eval'",
            (string) $client->getResponse()->headers->get('Content-Security-Policy'),
            "the /map street-level viewer (mapillary-js new Function()) needs 'unsafe-eval'",
        );

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(
            "'unsafe-eval'",
            (string) $client->getResponse()->headers->get('Content-Security-Policy'),
            "'unsafe-eval' must stay scoped to /map, not leak to other pages",
        );
    }

    /**
     * Any inline script block that remains must carry the SAME nonce the
     * header advertises — a missed block is a page break under enforcement.
     *
     * Having none is fine, and on most pages is now the case. A data block
     * (`type="application/json"`) is not executed, so script-src never gates
     * it and it needs no nonce.
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

        foreach (self::executableInlineScripts((string) $client->getResponse()->getContent()) as $attrs) {
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

    /**
     * The pages meant to be cacheable must carry no nonce at all.
     *
     * This is the invariant the whole page-caching design rests on
     * (page-caching.md §3.2). A nonce is worth something only while it is
     * unpredictable; a stored copy freezes it, and everyone served that copy
     * gets the same one, which an attacker can simply read. So these pages
     * carry no executable inline script, and nothing on them may reference the
     * nonce either.
     *
     * If this fails, somebody added an inline block to a shared template or to
     * one of these pages. Move it into a file, as `assets/home/hero.js` and
     * `assets/pages/regions-typeahead.js` were, rather than relaxing the test.
     */
    #[DataProvider('cacheablePages')]
    public function testCacheablePagesCarryNoNonceAtAll(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertSame([], self::executableInlineScripts($html),
            "inline script on {$path}: it needs a nonce, and a nonce cannot be cached");
        self::assertStringNotContainsString('nonce=', $html,
            "something on {$path} references the CSP nonce, which a shared cache would freeze");
    }

    /** @return iterable<string, array{string}> */
    public static function cacheablePages(): iterable
    {
        // page-caching.md §6. /coverage and /map are deliberately absent.
        foreach ([
            'home' => '/',
            'about' => '/about',
            'developers' => '/developers',
            'licenses' => '/licenses',
            'privacy' => '/privacy',
            'terms' => '/terms',
            'accessibility' => '/accessibility',
            'roadmap' => '/roadmap',
            'changelog' => '/changelog',
            'credits' => '/credits',
            'regions' => '/regions',
            'blog' => '/blog',
            'known-issues' => '/known-issues',
            'coverage' => '/coverage',
            'coverage by total' => '/coverage?sort=total',
            // The three guarded forms. They were the last public pages that
            // could not be cached, and an inline block creeping back onto one
            // is exactly how that would silently return.
            'contact' => '/contact',
            'report a bug' => '/report-bug',
            'report content' => '/report/item/1',
        ] as $name => $path) {
            yield $name => [$path];
        }
    }

    /**
     * Inline `<script>` blocks the browser will execute: no `src`, and not a
     * data block.
     *
     * @return list<string> the attribute string of each, for the failure message
     */
    private static function executableInlineScripts(string $html): array
    {
        preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/i', $html, $tags);

        return array_values(array_filter(
            $tags[1],
            static fn (string $attrs): bool => !str_contains($attrs, 'type="application/json"'),
        ));
    }

    public function testNonHtmlResponsesSkipCsp(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map/catalog.json');

        self::assertResponseIsSuccessful();
        self::assertNull($client->getResponse()->headers->get('Content-Security-Policy'));
    }
}
