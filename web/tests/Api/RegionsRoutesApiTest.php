<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Api;

use App\Settings\SettingsRegistry;
use App\Settings\SystemSettingsWriter;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regions, their best-of lists, and routes with their whole line
 * (public-api.md §2.2). The scope tests come first: a country filter and a
 * box keep everything outside them out.
 */
final class RegionsRoutesApiTest extends WebTestCase
{
    use ApiSeeding;

    public function testACountryFilterLeavesOtherCountriesOut(): void
    {
        $client = static::createClient();
        $this->seedCatalog($client);
        $this->region('elsewhere-nl', 'NL', 5.0, 52.0);

        $be = $this->getJson($client, '/v1/regions?country=BE');
        $slugs = array_column($be['features'], 'id');
        self::assertContains('test-square', $slugs);
        self::assertNotContains('elsewhere-nl', $slugs);
        foreach ($be['features'] as $f) {
            self::assertSame('BE', $f['properties']['country']);
        }

        self::assertContains('elsewhere-nl', array_column($this->getJson($client, '/v1/regions?country=nl')['features'], 'id'));
        self::assertSame('invalid_country', $this->getJson($client, '/v1/regions?country=BEL', 400)['error']);
    }

    public function testTheRegionListPagesBySlug(): void
    {
        $client = static::createClient();
        $this->seedCatalog($client);
        $this->region('elsewhere-nl', 'NL', 5.0, 52.0);

        $all = array_column($this->getJson($client, '/v1/regions?limit=500')['features'], 'id');
        $paged = [];
        $url = '/v1/regions?limit=1';
        for ($guard = 0; $guard < 1000; ++$guard) {
            $page = $this->getJson($client, $url);
            self::assertLessThanOrEqual(1, \count($page['features']));
            $paged = [...$paged, ...array_column($page['features'], 'id')];
            if (!isset($page['next_cursor'])) {
                break;
            }
            $url = '/v1/regions?limit=1&cursor='.$page['next_cursor'];
        }
        self::assertSame($all, $paged, 'every region once, in slug order');
    }

    public function testOneRegionHasItsBoundaryAndItsCountsPerLetter(): void
    {
        $client = static::createClient();
        $this->seedCatalog($client);

        $region = $this->getJson($client, '/v1/regions/test-square');
        self::assertSame('test-square', $region['id']);
        self::assertSame('MultiPolygon', $region['geometry']['type']);
        self::assertSame([4.0, 50.0, 5.0, 51.0], $region['bbox']);
        $stats = $region['properties']['stats'];
        self::assertSame(['items', 'verified', 'routes', 'items_by_letter', 'coverage_by_letter'], array_keys($stats));
        self::assertSame(2, $stats['items_by_letter']['D'], 'the two D fixtures inside the square');
        self::assertLessThanOrEqual($stats['items'], $stats['verified'], 'verified is a part of the served items');

        // A verified place reported gone is no longer served, so not counted as verified either.
        $this->item('B', 'Gone tap', 4.5, 50.5, 'verified', ['condition' => 'Not there anymore']);
        self::assertSame($stats['verified'], $this->getJson($client, '/v1/regions/test-square')['properties']['stats']['verified']);
        self::assertSame('Test Square', $region['properties']['name']);

        self::assertSame('not_found', $this->getJson($client, '/v1/regions/no-such-place', 404)['error']);
    }

    public function testBestOfShowsNothingWhileVotingIsClosed(): void
    {
        $client = static::createClient();
        $this->seedCatalog($client);
        $settings = static::getContainer()->get(SystemSettingsWriter::class);

        $settings->set(SettingsRegistry::COMMUNITY_VOTING_LIVE, 0, null);
        $closed = $this->getJson($client, '/v1/regions/test-square/best');
        self::assertFalse($closed['voting']);
        self::assertSame([], $closed['lists'], 'never the preview with made-up tallies');

        $settings->set(SettingsRegistry::COMMUNITY_VOTING_LIVE, 1, null);
        $open = $this->getJson($client, '/v1/regions/test-square/best');
        self::assertTrue($open['voting']);
        self::assertSame(['N', 'O', 'P', 'Q', 'R'], array_column($open['lists'], 'letter'));
        foreach ($open['lists'] as $list) {
            self::assertFalse($list['ranked'], 'nobody voted here');
            self::assertSame([], $list['entries'], 'an unranked list is not dressed up as a ranking');
        }

        $this->getJson($client, '/v1/regions/no-such-place/best', 404);
    }

    public function testRoutesOutsideTheBoxStayOut(): void
    {
        $client = static::createClient();
        $inside = $this->route('Inside', [[4.2, 50.2], [4.4, 50.4]]);
        $this->route('Across the border', [[6.2, 52.2], [6.4, 52.4]]);
        $this->route('Retired', [[4.2, 50.2], [4.3, 50.3]], 'retired');

        $routes = $this->getJson($client, '/v1/routes?bbox=4,50,5,51');
        self::assertSame([$inside], array_map(static fn (array $f): int => $f['properties']['id'], $routes['features']));
        self::assertSame('LineString', $routes['features'][0]['geometry']['type'], 'the whole line, not a point');
    }

    public function testARouteCarriesWhatWasPickedAndMeasuredAndNoPerson(): void
    {
        $client = static::createClient();
        $id = $this->route('Spa loop', [[4.2, 50.2], [4.4, 50.4]], 'verified', [
            'difficulty' => 'Moderate',
            'season' => 'Summer',
            'dominantSurface' => 'Asphalt',
            'bikeTypes' => ['Road', 'Nonsense'],
            'quietness' => '4',
            'note' => 'Call Jan on 0470 for the gate',
            'uploader' => ['name' => 'Jan Peeters', 'public' => true],
            'start' => 'Jan\'s farm',
            'elev' => [100, 120, 110],
            'surfaces' => ['parts' => [['surface' => 'Asphalt', 'pct' => 80], ['surface' => 'Gravel', 'pct' => 20]], 'covered' => 0.9],
        ], proposedBy: 1);

        $list = $this->getJson($client, '/v1/routes?bbox=4,50,5,51');
        self::assertSame(['id', 'letter', 'name', 'tier', 'distance_m', 'ascent_m', 'region_id', 'difficulty', 'dominant_surface', 'season', 'bike_types'], array_keys($list['features'][0]['properties']));

        $route = $this->getJson($client, '/v1/routes/'.$id);
        $p = $route['properties'];
        self::assertSame(['id', 'letter', 'name', 'tier', 'distance_m', 'ascent_m', 'region_id', 'difficulty', 'dominant_surface', 'season', 'bike_types', 'ratings', 'surfaces', 'gradient_limited', 'best_direction', 'elevation', 'photos', 'gpx'], array_keys($p));
        self::assertSame(['score' => 2, 'label' => 'Moderate'], $p['difficulty']);
        self::assertSame(['Summer'], $p['season'], 'a single stored season is a list of one');
        self::assertSame(['quietness' => 4, 'scenic' => null, 'friendliness' => null], $p['ratings']);
        self::assertSame([100.0, 120.0, 110.0], $p['elevation']);
        self::assertSame('/v1/routes/'.$id.'.gpx', $p['gpx']);
        self::assertSame('curated', $p['tier']);

        $raw = (string) $client->getResponse()->getContent();
        foreach (['Jan', '0470', 'proposed', 'uploader'] as $leak) {
            self::assertStringNotContainsString($leak, $raw);
        }
    }

    public function testTheGpxIsTheSiteGpx(): void
    {
        $client = static::createClient();
        $id = $this->route('Spa loop', [[4.2, 50.2], [4.4, 50.4]]);
        $retired = $this->route('Old loop', [[4.2, 50.2], [4.4, 50.4]], 'retired');

        $client->request('GET', '/v1/routes/'.$id.'.gpx');
        self::assertResponseIsSuccessful();
        self::assertSame('application/gpx+xml', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename="spa-loop.gpx"', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('public', (string) $client->getResponse()->headers->get('Cache-Control'));
        $api = (string) $client->getResponse()->getContent();

        $client->request('GET', '/routes/'.$id.'.gpx');
        self::assertSame($api, (string) $client->getResponse()->getContent(), 'one track, two addresses');

        foreach (['/v1/routes/'.$retired.'.gpx', '/v1/routes/'.$retired] as $url) {
            self::assertSame('not_found', $this->getJson($client, $url, 404)['error'], $url);
        }
    }

    public function testTheRouteListPagesById(): void
    {
        $client = static::createClient();
        $ids = [];
        foreach (range(1, 3) as $n) {
            $ids[] = $this->route('Loop '.$n, [[4.2, 50.2], [4.4, 50.4]]);
        }
        $first = $this->getJson($client, '/v1/routes?bbox=4,50,5,51&limit=2');
        self::assertCount(2, $first['features']);
        $second = $this->getJson($client, '/v1/routes?bbox=4,50,5,51&limit=2&cursor='.$first['next_cursor']);
        self::assertArrayNotHasKey('next_cursor', $second);
        self::assertSame($ids, array_map(static fn (array $f): int => $f['properties']['id'], [...$first['features'], ...$second['features']]));
        self::assertSame('invalid_cursor', $this->getJson($client, '/v1/routes?bbox=4,50,5,51&cursor=eyJzIjoieCJ9', 400)['error']);
    }

    /** An operational region of its own country, one degree square. */
    private function region(string $slug, string $country, float $lon, float $lat): void
    {
        $this->db()->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES (:slug, :slug, ST_Multi(ST_MakeEnvelope(:w, :s, :e, :n, 4326)), 100, :cc, :iso, 4, 'osm', now(), now())",
            ['slug' => $slug, 'cc' => $country, 'iso' => $country.'-X', 'w' => $lon, 's' => $lat, 'e' => $lon + 1, 'n' => $lat + 1],
        );
    }
}
