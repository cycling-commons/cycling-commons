<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MapPageTest extends WebTestCase
{
    public function testMapRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.rail-nav');   // map's own chrome
        self::assertSelectorExists('div#map');         // MapLibre mount point
    }

    public function testMapLoadsExtractedAppScript(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        // map.js no longer ships as a direct script tag — it arrives via the catalog loader
        self::assertGreaterThan(0, $crawler->filter('script[src*="map/catalog-load"]')->count());
    }

    public function testEnglishMapRailIsEnglish(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('lang="en"', $html);
        self::assertStringContainsString('View mode', $html);
        self::assertStringContainsString('Data layers', $html);
    }

    public function testFrenchMapRailIsTranslated(): void
    {
        $client = static::createClient();
        // /map is not path-prefixed: locale resolves from session/Accept-Language
        $client->request('GET', '/map', [], [], ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('lang="fr"', $html);
        // rail chrome must come from the FR catalog, not baked-in English
        self::assertStringContainsString('Rechercher en Wallonie', $html);
        self::assertStringContainsString('Couches de données', $html);
        self::assertStringNotContainsString('View mode', $html);
        self::assertStringNotContainsString('Data layers', $html);

        // map.js renders the layer list and subtitle — it gets its strings from
        // the injected CC_I18N bundle, layer labels reusing item_type.*.label.
        self::assertSame(1, preg_match('/window\.CC_I18N = (\{.*?\});/s', $html, $m), 'CC_I18N bundle must be injected');
        $i18n = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Revêtement', $i18n['layers']['surface']);
        self::assertSame('Où dormir', $i18n['layers']['stays']);
        self::assertArrayHasKey('pending', $i18n['layers']);
        self::assertArrayHasKey('deselectAll', $i18n);
        self::assertArrayHasKey('seasons', $i18n);
        self::assertArrayHasKey('bikes', $i18n);
        // drawer namespace — map.js's own strings (record labels, CTAs, toasts)
        self::assertSame('Modifier cet élément', $i18n['d']['editItem']);
        self::assertSame('Historique', $i18n['d']['history']);

        // CC_FIELD_SCHEMA select fields carry canonical => localized choice
        // maps, so stored values render in the rider's language (drawer) while
        // the improve form keeps submitting canonical English.
        self::assertSame(1, preg_match('/window\.CC_FIELD_SCHEMA = (\{.*?\});/s', $html, $ms), 'CC_FIELD_SCHEMA must be injected');
        $schema = json_decode($ms[1], true, 512, JSON_THROW_ON_ERROR);
        $difficulty = array_values(array_filter($schema['R'], static fn (array $f): bool => 'difficulty' === $f['key']))[0];
        self::assertSame('Très difficile', $difficulty['choices']['Very hard']);
        $surface = array_values(array_filter($schema['A'], static fn (array $f): bool => 'surface' === $f['key']))[0];
        self::assertSame('Asphalte', $surface['choices']['Asphalt'] ?? null);
    }

    public function testMapBootsFromCatalogEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('window.CC_CATALOG_URL', $html);
        // Versioned, not bare: catalog.json is browser-cached for an hour, and
        // the ?v= tag (CatalogProvider::versionTag) is what makes an approved
        // submission appear on the next page load instead of "disappearing"
        // into the stale cache until it expires (owner-reported 2026-08-13).
        self::assertMatchesRegularExpression(
            '~window\.CC_CATALOG_URL = "/map/catalog\.json\?v=[0-9a-f]{8,}"~',
            $html,
        );
        self::assertStringContainsString('window.CC_MAP_SRC', $html);
        self::assertStringContainsString('map/catalog-load', $html);
        self::assertStringNotContainsString('src="/assets/data/', $html);
        self::assertStringNotContainsString('stays-merge', $html);
        self::assertSame(0, preg_match('#<script src="[^"]*\bmap/map\b[^"]*"#', $html), 'map.js must arrive via the loader, not a direct script tag');

        $tokenPos = strpos($html, 'window.MAPILLARY_TOKEN =');
        self::assertNotFalse($tokenPos, 'Mapillary token global must be injected into the map shell');
        $loaderPos = strpos($html, 'map/catalog-load');
        self::assertNotFalse($loaderPos, 'catalog loader script must be present');
        self::assertLessThan($loaderPos, $tokenPos, 'window.MAPILLARY_TOKEN must be defined before the catalog loader');

        $chipsPos = strpos($html, 'map/scope-chips');
        self::assertNotFalse($chipsPos, 'scope-chips.js must be in the map shell');
        self::assertLessThan($loaderPos, $chipsPos, 'scope-chips.js must load before catalog-load.js injects map.js');
    }

    /**
     * Every `window.CC_*` global the map shell emits must sit INSIDE a
     * <script> element.
     *
     * Not a re-test of one typo: on 2026-08-25 a security scan found
     * window.CC_RIDECHECK stranded between two conditional script blocks after
     * an edit moved the closing tag. The ride-check feature was dead for
     * everyone, and its CSRF token rendered as visible page text. The template
     * has ~15 of these blocks and they are edited constantly, so the guard is
     * on the SHAPE (a global outside a script tag), not on one variable name.
     *
     * Anonymous client on purpose: the always-emitted globals are the ones a
     * logged-out visitor sees, and CC_RIDECHECK is deliberately one of them.
     */
    public function testEveryInjectedGlobalIsInsideAScriptTag(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // Strip every <script>...</script> body. Anything named window.CC_*
        // that survives was never inside a script element.
        $stripped = preg_replace('#<script\b[^>]*>.*?</script>#si', '', $html);
        self::assertIsString($stripped);

        self::assertSame(
            0,
            preg_match_all('/window\.CC_[A-Z0-9_]+/', $stripped, $loose),
            sprintf('These globals render as page text, not script: %s', implode(', ', $loose[0] ?? [])),
        );

        // And the specific one that broke, so the regression has a named guard.
        self::assertStringContainsString('window.CC_RIDECHECK', $html);
    }
}
