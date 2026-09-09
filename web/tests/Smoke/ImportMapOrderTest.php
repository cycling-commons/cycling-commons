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
 * That is not hypothetical. Loading MapLibre v6 (ES modules only, so the boot
 * has to be a module) put a module script above importmap() on 2026-09-09 and
 * broke /map in Firefox completely, while Chromium happened to carry on. A
 * comment in the template cannot survive the next person moving a script tag;
 * this can.
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
