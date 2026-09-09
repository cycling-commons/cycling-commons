<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A browser honours an import map only while it has not yet seen a module
 * script. Once one appears, a later <script type="importmap"> is rejected, and
 * on /map that map is the only thing that resolves the rewritten relative
 * imports AssetMapper emits: `/assets/map/i18n.js` and its twenty-odd siblings
 * are UNDIGESTED paths that exist nowhere on disk. Without the map they 404
 * into index.php and come back as HTML, so the browser refuses them for their
 * MIME type and the map never boots.
 *
 * That is not hypothetical, and it happened twice in one day on 2026-09-09.
 * First a module script went above importmap() (MapLibre v6 is ES modules only,
 * so its boot has to be one). Then, with that fixed, two
 * `<link rel="modulepreload">` in the preamble did it again: a modulepreload
 * TRIGGERS a module load just as a module script does, which is why every other
 * preload on that page sits below importmap(). Firefox broke both times;
 * Chromium carried on both times, so a green browser check proved nothing and
 * the first version of this test, which only looked for `<script
 * type="module">`, passed over the second bug.
 *
 * A comment in the template cannot survive the next person moving a tag; this
 * can.
 *
 * @see docs/specs/map-and-search.md §2
 */
final class ImportMapOrderTest extends WebTestCase
{
    public function testTheImportMapComesBeforeEveryModuleScript(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $map = strpos($html, '<script type="importmap"');
        self::assertNotFalse($map, '/map must carry an import map: the map modules resolve through it');

        $firstModule = $this->firstModuleScriptOffset($html);
        self::assertNotFalse(
            $firstModule,
            '/map must load at least one module script, or this guard is watching nothing',
        );

        self::assertLessThan(
            $firstModule,
            $map,
            'the import map must precede every <script type="module">: a module script first '
            .'makes the browser discard the map, and every rewritten relative import then 404s',
        );
    }

    public function testTheImportMapComesBeforeEveryModulePreload(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $map = strpos($html, '<script type="importmap"');
        self::assertNotFalse($map);

        $firstPreload = $this->firstModulePreloadOffset($html);
        self::assertNotFalse(
            $firstPreload,
            '/map must carry at least one modulepreload, or this guard is watching nothing',
        );

        self::assertLessThan(
            $firstPreload,
            $map,
            'the import map must precede every <link rel="modulepreload"> too: a preload starts a '
            .'module load, and a map added after one has started is discarded exactly as if a '
            .'<script type="module"> had run. Firefox enforces this; Chromium does not, so a '
            .'working browser is not evidence',
        );
    }

    /** Offset of the first `<link rel="modulepreload">`, or false when there is none. */
    private function firstModulePreloadOffset(string $html): int|false
    {
        if (preg_match('/<link\b[^>]*\brel=("|\')modulepreload\1/', $html, $m, \PREG_OFFSET_CAPTURE)) {
            return $m[0][1];
        }

        return false;
    }

    /**
     * Offset of the first `<script type="module">`, in either attribute order,
     * or false when the page carries none.
     */
    private function firstModuleScriptOffset(string $html): int|false
    {
        $best = false;
        foreach (['<script type="module"', '<script defer type="module"'] as $needle) {
            $at = strpos($html, $needle);
            if (false !== $at && (false === $best || $at < $best)) {
                $best = $at;
            }
        }

        // `type` need not come first on the tag, so fall back to a scan that
        // does not care about attribute order.
        if (preg_match('/<script\b[^>]*\btype=("|\')module\1/', $html, $m, \PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1];
            if (false === $best || $at < $best) {
                $best = $at;
            }
        }

        return $best;
    }
}
