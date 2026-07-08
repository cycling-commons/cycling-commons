<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogProvider;
use App\World\Entity\Country;
use App\World\Entity\Subdivision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CatalogProviderTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->seedWorld();
        $this->import();
    }

    /** BE + BE-WBR + BE-WNA, reuse-or-create (the dev/test DB may hold real world data). */
    private function seedWorld(): void
    {
        $countryRepo = $this->em->getRepository(Country::class);
        $country = $countryRepo->findOneBy(['iso2' => 'BE'])
            ?? (new Country())->setIso2('BE')->setIso3('BEL')->setName('Belgium');
        $this->em->persist($country);
        $subRepo = $this->em->getRepository(Subdivision::class);
        foreach (['BE-WBR' => 'Brabant wallon', 'BE-WNA' => 'Namur'] as $code => $name) {
            $sub = $subRepo->findOneBy(['code' => $code])
                ?? (new Subdivision())->setCode($code)->setName($name)->setCountry($country);
            $this->em->persist($sub);
        }
        $this->em->flush();
    }

    private function import(): void
    {
        $src = __DIR__.'/../fixtures/catalog';
        $dir = sys_get_temp_dir().'/catalog-provider-'.getmypid();
        @mkdir($dir, 0777, true);
        foreach (['region-square.geojson', 'services.json', 'surface.json', 'climbs.json', 'stays.json', 'routes.json', 'heat.json'] as $f) {
            copy($src.'/'.$f, $dir.'/'.$f);
        }
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:catalog:import'));
        $tester->execute(['dir' => $dir]);
        $tester->assertCommandIsSuccessful();
    }

    private function payload(): array
    {
        return static::getContainer()->get(CatalogProvider::class)->payload();
    }

    public function testPoiFeatureCollectionRebuildsFixtureShape(): void
    {
        $d = $this->payload()['D'];
        self::assertSame('FeatureCollection', $d['type']);
        self::assertCount(3, $d['features']);

        $byRef = [];
        foreach ($d['features'] as $f) {
            $byRef[$f['properties']['t']] = $f;
        }
        $shop = $byRef['Bike shop'];
        self::assertSame('Ecocyclo', $shop['properties']['n']);
        self::assertSame('Brabant wallon', $shop['properties']['prov']);      // via world_subdivision join
        self::assertSame([4.4, 50.7], $shop['geometry']['coordinates']);      // GeoJSON [lng, lat]
        // W6: the raw ItemSource value IS meant to reach the client (map.js maps
        // it to a display label) — but under 'srcType', never the free-text
        // 'source'/'ref' import columns that would leak internal detail.
        self::assertSame('osm', $shop['properties']['srcType']);
        self::assertArrayNotHasKey('source', $shop['properties']);
        self::assertArrayNotHasKey('ref', $shop['properties']);
        // The map edit-bridge's `?item=` target — the real DB id, an integer.
        self::assertIsInt($shop['properties']['id']);
        self::assertGreaterThan(0, $shop['properties']['id']);

        $station = $byRef['Repair station'];                                  // fixture had no n; prov Namur seeded
        self::assertArrayNotHasKey('n', $station['properties']);              // '' name -> key omitted
        self::assertSame('Namur', $station['properties']['prov']);
        self::assertNotSame($shop['properties']['id'], $station['properties']['id']); // distinct items, distinct ids

        // Deterministic NULL-subdivision case: the shared test DB may hold real
        // world data (BE-WLG would resolve 'Liège'), so force the NULL instead
        // of depending on DB state.
        $this->em->getConnection()->executeStatement(
            "UPDATE item SET subdivision_id = NULL WHERE source_ref = 'node/1003'",
        );
        $pump = $this->payload()['D']['features'];
        $pump = array_values(array_filter($pump, static fn (array $f): bool => 'Pump' === $f['properties']['t']))[0];
        self::assertArrayNotHasKey('prov', $pump['properties']);
    }

    public function testStaysSplitBySource(): void
    {
        $e = $this->payload()['E'];
        self::assertCount(1, $e['osm']['features']);
        self::assertCount(1, $e['pivot']['features']);
        self::assertSame('Camping Test', $e['osm']['features'][0]['properties']['n']);
        self::assertSame('http://example.test', $e['pivot']['features'][0]['properties']['web']);
        self::assertIsInt($e['osm']['features'][0]['properties']['id']);
        self::assertIsInt($e['pivot']['features'][0]['properties']['id']);
        // W6: each bucket's srcType matches the split it was fetched by.
        self::assertSame('osm', $e['osm']['features'][0]['properties']['srcType']);
        self::assertSame('pivot', $e['pivot']['features'][0]['properties']['srcType']);
    }

    public function testClimbShapeRestoresCitationAndLatLng(): void
    {
        $climb = $this->payload()['B'][0];
        self::assertSame('Côte de Test', $climb['name']);
        self::assertSame([50.61, 4.41], $climb['geom']['ll']);                // [lat, lng]
        self::assertSame('Wikidata (P625) · OpenStreetMap', $climb['source']); // attribution -> source
        self::assertArrayNotHasKey('attribution', $climb);
        self::assertSame('wikidata', $climb['srcType']);                      // W6: raw enum, separate from the citation text above
        self::assertSame([[50.61, 4.41], [50.62, 4.42]], $climb['route']);    // raw [lat,lng] pass-through
        self::assertSame(1, $climb['descTr']);
        // The map edit-bridge's `?item=` target — the real DB id, an integer.
        self::assertIsInt($climb['id']);
        self::assertGreaterThan(0, $climb['id']);
    }

    public function testSurfaceSegmentDecodesWayIdAndFlipsPath(): void
    {
        $seg = $this->payload()['A'][0];
        self::assertSame('Test seg', $seg['name']);
        self::assertSame(2001, $seg['wayId']);                                // from source_ref 'way/2001'
        self::assertSame([[50.1, 4.2], [50.2, 4.3]], $seg['path']);           // flipped to [lat,lng]
        self::assertSame('Asphalt', $seg['surface']);
        self::assertSame('osm', $seg['srcType']);                             // W6
        self::assertArrayNotHasKey('edit', $seg);                             // accepted loss (map.js hardcodes it)
        // The map edit-bridge's `?item=` target — the real DB id, an integer.
        self::assertIsInt($seg['id']);
        self::assertGreaterThan(0, $seg['id']);
    }

    public function testRouteShapeAndHeat(): void
    {
        $p = $this->payload();
        $route = $p['K'][0];
        self::assertSame('Test loop', $route['name']);
        self::assertSame(12.3, $route['km']);                                 // 12300 / 1000
        self::assertSame(210, $route['gain']);
        self::assertSame([[50.6, 4.4], [50.7, 4.5], [50.6, 4.4]], $route['loop']);
        self::assertSame(['name' => 'Test U.', 'public' => true], $route['uploader']);
        self::assertSame('Tester', $route['photo']['credit']);
        self::assertSame('auto', $route['srcType']);                          // W6
        // C2-T7 (spec §W2): QualityRides registry attributes (dominantSurface,
        // quietness, etc.) forward the same way difficulty/uploader/photo
        // always have — an approved improve-form edit must reach the client.
        self::assertSame('Mixed', $route['dominantSurface']);
        self::assertSame('4', $route['quietness']);
        self::assertSame([[50.5, 4.5, 'summer'], [50.6, 4.6, 'winter']], $p['L']);
        // The map edit-bridge's `?item=` target — the real DB id, an integer.
        self::assertIsInt($route['id']);
        self::assertGreaterThan(0, $route['id']);
    }

    public function testExcludedStatesAreNotServed(): void
    {
        // Spec §8 excludes all three: retired, submitted, rejected.
        $conn = $this->em->getConnection();
        $expected = 3;
        foreach (['retired' => 'node/1003', 'submitted' => 'node/1002', 'rejected' => 'node/1001'] as $state => $ref) {
            $conn->executeStatement('UPDATE item SET state = :state WHERE source_ref = :ref', ['state' => $state, 'ref' => $ref]);
            self::assertCount(--$expected, $this->payload()['D']['features'], $state.' rows must not be served');
        }
    }

    public function testJsonEncodesUnescaped(): void
    {
        $json = static::getContainer()->get(CatalogProvider::class)->json();
        self::assertStringContainsString('Côte de Test', $json);              // JSON_UNESCAPED_UNICODE
        self::assertStringContainsString('http://example.test', $json);      // JSON_UNESCAPED_SLASHES
        // JSON_PRESERVE_ZERO_FRACTION: whole-number floats (services "r" rating
        // here) must keep their ".0" so the served bytes match the legacy
        // fixture — PHP's json_encode renders a whole-number float as a bare
        // integer unless this flag is set. This "r":4.0 assertion covers the
        // shared json() flag site (route km transitively; testRouteShapeAndHeat
        // asserts payload(), not json(), so 12.3 can't distinguish the flag).
        self::assertStringContainsString('"r":4.0', $json);
    }

    public function testServedRoutesCarryStateForBadging(): void
    {
        // A verified and an unverified route both serve; each carries its state
        // so the map can badge "proposed" (unverified) vs a normal (verified) pin.
        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        foreach ([['verified', \App\Catalog\ItemState::Verified], ['proposed', \App\Catalog\ItemState::Unverified]] as [$tag, $state]) {
            $em->persist((new \App\Catalog\Entity\RecommendedRoute())
                ->setName('State route '.$tag)
                ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
                ->setDistanceM(9000)->setState($state)
                ->setSource(\App\Catalog\ItemSource::User)->setSourceRef('user:state-'.$tag));
        }
        $em->flush();

        $routes = static::getContainer()->get(\App\Catalog\CatalogProvider::class)->payload()['K'];
        $byName = [];
        foreach ($routes as $r) {
            $byName[$r['name']] = $r;
        }
        self::assertSame('verified', $byName['State route verified']['state'] ?? null);
        self::assertSame('unverified', $byName['State route proposed']['state'] ?? null);
    }
}
