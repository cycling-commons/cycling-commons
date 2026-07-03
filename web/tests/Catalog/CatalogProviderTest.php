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
        self::assertArrayNotHasKey('source', $shop['properties']);            // provenance never leaks
        self::assertArrayNotHasKey('ref', $shop['properties']);

        $station = $byRef['Repair station'];                                  // fixture had no n; prov Namur seeded
        self::assertArrayNotHasKey('n', $station['properties']);              // '' name -> key omitted
        self::assertSame('Namur', $station['properties']['prov']);

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
    }

    public function testClimbShapeRestoresCitationAndLatLng(): void
    {
        $climb = $this->payload()['B'][0];
        self::assertSame('Côte de Test', $climb['name']);
        self::assertSame([50.61, 4.41], $climb['geom']['ll']);                // [lat, lng]
        self::assertSame('Wikidata (P625) · OpenStreetMap', $climb['source']); // attribution -> source
        self::assertArrayNotHasKey('attribution', $climb);
        self::assertSame([[50.61, 4.41], [50.62, 4.42]], $climb['route']);    // raw [lat,lng] pass-through
        self::assertSame(1, $climb['descTr']);
    }

    public function testSurfaceSegmentDecodesWayIdAndFlipsPath(): void
    {
        $seg = $this->payload()['A'][0];
        self::assertSame('Test seg', $seg['name']);
        self::assertSame(2001, $seg['wayId']);                                // from source_ref 'way/2001'
        self::assertSame([[50.1, 4.2], [50.2, 4.3]], $seg['path']);           // flipped to [lat,lng]
        self::assertSame('Asphalt', $seg['surface']);
        self::assertArrayNotHasKey('edit', $seg);                             // accepted loss (map.js hardcodes it)
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
        self::assertSame([[50.5, 4.5, 'summer'], [50.6, 4.6, 'winter']], $p['L']);
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
        // here; route "km" is covered by testRouteShapeAndHeat) must keep their
        // ".0" so the served bytes match the legacy fixture — PHP's json_encode
        // renders a whole-number float as a bare integer unless this flag is set.
        self::assertStringContainsString('"r":4.0', $json);
    }
}
