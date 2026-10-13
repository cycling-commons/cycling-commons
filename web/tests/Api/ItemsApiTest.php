<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The item half of the read API (public-api.md §2.2): the catalogue, one
 * item, the counts in a box, and a browse paged by cursor. Every test that
 * limits rows to an area or a set starts by proving nothing outside it
 * shows up.
 */
final class ItemsApiTest extends WebTestCase
{
    use ApiSeeding;

    public function testTheCountsLeaveOutEverythingOutsideTheBox(): void
    {
        $client = static::createClient();
        $this->item('B', 'In the box', 4.5, 50.5);
        $this->item('B', 'Just outside', 5.2, 50.5);
        $this->item('B', 'Another country', 6.5, 52.5);
        $this->item('B', 'Waiting for review', 4.6, 50.6, 'submitted');
        $this->item('B', 'Reported gone', 4.7, 50.7, 'verified', ['condition' => 'Not there anymore']);
        $this->route('A loop in the box', [[4.4, 50.4], [4.6, 50.6]]);
        $this->route('A loop elsewhere', [[6.4, 52.4], [6.6, 52.6]]);

        $body = $this->getJson($client, '/v1/coverage/counts?bbox=4.0,50.0,5.0,51.0');

        self::assertSame(['B' => 1, 'R' => 1], $body['counts'], 'only the served item and the route inside the box');
        self::assertSame([4.0, 50.0, 5.0, 51.0], $body['bbox']);
        self::assertIsArray($body['coverage']);
    }

    public function testTheCountsNeedASmallBox(): void
    {
        $client = static::createClient();
        self::assertSame('invalid_bbox', $this->getJson($client, '/v1/coverage/counts', 400)['error']);
        self::assertSame('bbox_too_large', $this->getJson($client, '/v1/coverage/counts?bbox=4,50,6.5,51', 400)['error']);
        self::assertSame('*', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function testTheCatalogueDescribesEveryLetterFromTheSiteForm(): void
    {
        $client = static::createClient();
        $catalog = $this->getJson($client, '/v1/catalog');

        $letters = array_column($catalog['letters'], null, 'letter');
        $keys = array_keys($letters);
        sort($keys);
        self::assertSame(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'N', 'O', 'P', 'Q', 'R'], $keys);
        self::assertCount(12, $catalog['letters'], 'every letter, once');
        self::assertSame(['community', 'curated'], $catalog['tiers']);

        $d = $letters['D'];
        self::assertSame('bike-services', $d['slug']);
        self::assertSame('serviceKind', $d['kind_field']);
        self::assertContains(['key' => 'station', 'label' => 'Repair stand'], $d['kinds']);
        self::assertTrue($d['contributions']);

        $fields = array_column($letters['B']['fields'], null, 'key');
        self::assertTrue($fields['potable']['served'], 'a pick list is served');
        self::assertContains('Yes (public supply)', $fields['potable']['values']);
        self::assertFalse($fields['note']['served'], 'free text a rider wrote is never served');
        self::assertTrue($fields['note']['accepted'], 'but a partner app may send it for review');
        self::assertArrayNotHasKey('name', $fields, 'the name travels as the feature name');

        $climb = array_column($letters['N']['fields'], null, 'key');
        self::assertSame('measured', $climb['gain']['type']);
        self::assertTrue($climb['gain']['served']);
        self::assertFalse($climb['gain']['accepted'], 'the server measures, nobody types it');
        self::assertFalse($letters['N']['contributions']);
        self::assertFalse($letters['R']['contributions']);

        // An empty labels map is an object, never a JSON list.
        self::assertStringContainsString('"labels":{}', (string) $client->getResponse()->getContent());
    }

    public function testOneItemCarriesItsDetailAndNoFreeText(): void
    {
        $client = static::createClient();
        $rider = '0c85817b-b39d-4420-bd73-2e5056bfcb36';
        $id = $this->item('B', 'Fountain on the square', 4.5, 50.5, 'verified', [
            'type' => 'Public fountain',
            'potable' => 'Yes (public supply)',
            'note' => 'Ask Marie at number 12 for the key',
            'web' => 'https://example.test/fountain',
            // Typed text under a pick-list key, as an old import left some rows.
            'seasonal' => 'Ask Marie, she knows',
            'photos' => [[
                'id' => $rider, 'sm' => 'https://img.example.test/s.webp', 'lg' => 'https://img.example.test/l.webp',
                'credit' => 'Marie Dupont', 'license' => 'CC BY-SA 4.0', 'alt' => 'Marie at the fountain',
                'distanceM' => 12, 'distancePin' => [50.5, 4.5],
            ]],
        ], 'manual', 'node/4207030426');

        $feature = $this->getJson($client, '/v1/items/'.$id);
        self::assertSame('application/geo+json', $client->getResponse()->headers->get('Content-Type'));
        $p = $feature['properties'];
        self::assertSame(['id', 'letter', 'name', 'tier', 'grade', 'custody', 'confirmations', 'last_confirmed', 'last_seen_upstream', 'verified_by', 'region_id', 'country', 'osm_ref', 'source', 'kind', 'attributes', 'photos'], array_keys($p));
        self::assertSame('node/4207030426', $p['osm_ref']);
        self::assertSame('cycling-commons', $p['source']);
        self::assertSame('BE', $p['country']);
        self::assertSame(['type' => 'Public fountain', 'potable' => 'Yes (public supply)', 'web' => 'https://example.test/fountain'], $p['attributes']);
        self::assertSame([[
            'url' => 'https://img.example.test/l.webp',
            'thumb' => 'https://img.example.test/s.webp',
            'licence' => 'CC BY-SA 4.0',
            'credit' => null,
            'credit_url' => 'http://localhost/photo/'.$rider,
            'source' => null,
        ]], $p['photos'], 'a rider is credited by their photo page, never by a stored name, an alt text or where they stood');

        $raw = (string) $client->getResponse()->getContent();
        foreach (['Marie', 'number 12', 'distance'] as $leak) {
            self::assertStringNotContainsString($leak, $raw);
        }

        $hydrated = $this->getJson($client, '/v1/items/'.$id.'?hydrate=osm');
        self::assertArrayHasKey('osm', $hydrated['properties']);
        self::assertSame('invalid_hydrate', $this->getJson($client, '/v1/items/'.$id.'?hydrate=wiki', 400)['error']);
    }

    public function testAnItemTheMapDoesNotServeIsNotFound(): void
    {
        $client = static::createClient();
        $ids = [
            'submitted' => $this->item('B', 'Waiting', 4.5, 50.5, 'submitted'),
            'rejected' => $this->item('B', 'Turned down', 4.5, 50.5, 'rejected'),
            'gone' => $this->item('B', 'Gone', 4.5, 50.5, 'verified', ['condition' => 'Not there anymore']),
            'untouched osm copy' => $this->item('B', 'Raw OSM', 4.5, 50.5, 'unverified', [], 'osm'),
        ];
        foreach ($ids as $why => $id) {
            self::assertSame('not_found', $this->getJson($client, '/v1/items/'.$id, 404)['error'], $why);
        }
        $this->getJson($client, '/v1/items/999999999', 404);
    }

    public function testABrowsePagesWithoutRepeatsAndThenTheRoutes(): void
    {
        $client = static::createClient();
        $made = [];
        foreach (range(1, 5) as $n) {
            $made[] = $this->item('D', 'Shop '.$n, 4.1 + $n / 10, 50.5);
        }
        $this->item('D', 'Outside the box', 7.0, 52.0);
        $routeId = $this->route('Loop', [[4.2, 50.2], [4.3, 50.3]]);

        $seen = [];
        $routes = [];
        $url = '/v1/search?bbox=4.0,50.0,5.0,51.0&routes=include&limit=2';
        for ($guard = 0; $guard < 10; ++$guard) {
            $page = $this->getJson($client, $url);
            self::assertLessThanOrEqual(2, \count($page['features']));
            foreach ($page['features'] as $f) {
                if ('R' === $f['properties']['letter']) {
                    $routes[] = $f['properties']['id'];
                } else {
                    $seen[] = $f['properties']['id'];
                }
            }
            if (!isset($page['next_cursor'])) {
                break;
            }
            $url = '/v1/search?bbox=4.0,50.0,5.0,51.0&routes=include&limit=2&cursor='.$page['next_cursor'];
        }

        sort($made);
        self::assertSame($made, $seen, 'every item in the box once, none from outside');
        self::assertSame([$routeId], $routes, 'the routes after the items');
    }

    public function testACursorIsForABrowseOnly(): void
    {
        $client = static::createClient();
        self::assertSame('invalid_cursor', $this->getJson($client, '/v1/search?q=shop&cursor=eyJpIjoxfQ', 400)['error']);
        self::assertSame('invalid_cursor', $this->getJson($client, '/v1/search?bbox=4,50,5,51&cursor=not-ours', 400)['error']);
        // Well-formed, but not a search cursor: a word where an id goes, a key search never writes.
        self::assertSame('invalid_cursor', $this->getJson($client, '/v1/search?bbox=4,50,5,51&cursor=eyJpIjoiYWJjIn0', 400)['error']);
        self::assertSame('invalid_cursor', $this->getJson($client, '/v1/search?bbox=4,50,5,51&cursor=eyJzIjoiYSJ9', 400)['error']);
    }

    public function testHydrateAddsTheOsmSubsetToItemsOnly(): void
    {
        $client = static::createClient();
        $this->item('B', 'Tap', 4.5, 50.5, 'verified', [], 'manual', 'node/77');
        $this->route('Loop', [[4.2, 50.2], [4.3, 50.3]]);

        $plain = $this->getJson($client, '/v1/search?bbox=4,50,5,51');
        self::assertArrayNotHasKey('osm', $plain['features'][0]['properties'], 'nothing extra unless asked for');

        $page = $this->getJson($client, '/v1/search?bbox=4,50,5,51&hydrate=osm&routes=include');
        foreach ($page['features'] as $f) {
            if ('R' === $f['properties']['letter']) {
                self::assertArrayNotHasKey('osm', $f['properties']);
            } else {
                self::assertArrayHasKey('osm', $f['properties']);
            }
        }
    }
}
