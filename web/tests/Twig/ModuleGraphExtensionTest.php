<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\ModuleGraphExtension;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The map preloads its whole static module graph, read from AssetMapper,
 * not a hand-kept list that drifts (owner 2026-10-11).
 *
 * @see docs/specs/map-and-search.md (the map's script loading)
 */
final class ModuleGraphExtensionTest extends WebTestCase
{
    /** The modules the hand-kept list held, all of which the graph must still carry. */
    private const array ONCE_BY_HAND = [
        'catalog', 'climb-profile', 'community', 'corrections', 'coverage-notice', 'coverage', 'drawer', 'filters',
        'i18n', 'icons', 'item-index', 'lightbox', 'map-init', 'map', 'mapillary', 'osm-pools', 'panels',
        'pending-shape', 'picking', 'places', 'render', 'ride-check', 'scope-ui', 'search-ui', 'shell', 'sheet',
        'spotlight', 'tile-sources', 'units', 'util',
    ];

    public function testTheGraphStartsAtTheEntryAndHoldsEveryStaticImport(): void
    {
        self::bootKernel();
        $graph = static::getContainer()->get(ModuleGraphExtension::class)->graph('map/map.js');

        self::assertStringStartsWith('/assets/map/map-', $graph[0], 'the entry first');
        self::assertSame($graph, array_values(array_unique($graph)), 'each module once');
        $names = array_map(static fn (string $p): string => (string) preg_replace('~^/assets/map/(.+)-[A-Za-z0-9_-]{7}\.js$~', '$1', $p), $graph);
        foreach (self::ONCE_BY_HAND as $module) {
            self::assertContains($module, $names, $module.' is still preloaded');
        }
        self::assertGreaterThan(\count(self::ONCE_BY_HAND), \count($graph), 'and the modules the hand list missed');
    }

    public function testTheMapPageCarriesOnePreloadPerModule(): void
    {
        $client = static::createClient();
        $page = $client->request('GET', '/map');
        self::assertResponseIsSuccessful();

        $preloads = $page->filter('link[rel="modulepreload"]')->each(static fn ($l): string => (string) $l->attr('href'));
        $graph = static::getContainer()->get(ModuleGraphExtension::class)->graph('map/map.js');
        foreach ($graph as $path) {
            self::assertContains($path, $preloads);
        }
        self::assertSame($preloads, array_values(array_unique($preloads)), 'no module preloaded twice');
    }
}
