<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogProvider;
use App\Entity\User;
use App\Moderation\ModerationScope;
use App\Tests\Coverage\CoverageSchema;
use App\World\Entity\Country;
use App\World\Entity\Subdivision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CatalogProviderTest extends KernelTestCase
{
    use CoverageSchema;

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
        foreach (['region-square.geojson', 'services.json', 'surface.json', 'climbs.json', 'stays.json', 'hazards.json', 'routes.json', 'heat.json'] as $f) {
            copy($src.'/'.$f, $dir.'/'.$f);
        }
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:catalog:import'));
        $tester->execute(['dir' => $dir]);
        $tester->assertCommandIsSuccessful();

        // Coverage retirement (coverage-provider.md §9):
        // untouched osm/unverified POIs no longer serve from catalog.json.
        // These fixtures assert payload SHAPE, so promote them to verified
        // (still a served state) instead of re-plumbing every assertion.
        $this->em->getConnection()->executeStatement(
            "UPDATE item SET state = 'verified' WHERE letter IN ('B','D','F','G','O','P','Q')",
        );
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

    /**
     * E · Hazards & conditions (map-and-search.md §4.5 Task A): served as a
     * plain FeatureCollection like the other point letters, so map.js can render
     * it as CATALOG features. The manual hazard is not an untouched-osm row, so
     * the coverage-retirement predicate never drops it; its attributes reach the
     * client and it carries rid for the scope gate.
     */
    public function testHazardsServeAsFeatureCollection(): void
    {
        $f = $this->payload()['E'];
        self::assertSame('FeatureCollection', $f['type']);
        // Locate the seeded hazard by name rather than assuming it is the ONLY E
        // feature — the DB is shared across tests, so a global count(1) is fragile
        // (finding 21 / CodeRabbit).
        $matches = array_values(array_filter(
            $f['features'],
            static fn (array $ft): bool => 'Test crosswind' === ($ft['properties']['n'] ?? null),
        ));
        self::assertCount(1, $matches, 'exactly one "Test crosswind" hazard is served');
        $hazard = $matches[0];
        self::assertSame('Crosswind / fog', $hazard['properties']['hazardType']);
        self::assertSame('Moderate', $hazard['properties']['severity']);
        self::assertSame('manual', $hazard['properties']['srcType']);
        self::assertSame([4.5, 50.5], $hazard['geometry']['coordinates']);   // GeoJSON [lng, lat]
        self::assertIsInt($hazard['properties']['id']);
        // Inside the test-square region ([4,50]-[5,51]) → carries rid for scope.
        self::assertIsInt($hazard['properties']['rid']);
        self::assertGreaterThan(0, $hazard['properties']['rid']);
        // Consumed keys never leak as attributes.
        self::assertArrayNotHasKey('source', $hazard['properties']);
        self::assertArrayNotHasKey('ref', $hazard['properties']);
    }

    public function testStaysSplitBySource(): void
    {
        $e = $this->payload()['O'];
        self::assertCount(1, $e['osm']['features']);
        self::assertCount(1, $e['authority']['features']);
        self::assertSame('Camping Test', $e['osm']['features'][0]['properties']['n']);
        self::assertSame('http://example.test', $e['authority']['features'][0]['properties']['web']);
        self::assertIsInt($e['osm']['features'][0]['properties']['id']);
        self::assertIsInt($e['authority']['features'][0]['properties']['id']);
        // W6: each bucket's srcType matches the split it was fetched by.
        self::assertSame('osm', $e['osm']['features'][0]['properties']['srcType']);
        self::assertSame('authority', $e['authority']['features'][0]['properties']['srcType']);
    }

    /**
     * Registry provenance is NOT verification (owner 2026-09-06, reversing the
     * 2026-07-17 decision that a Tourisme Wallonie PIVOT row served v:1 on its
     * listing alone): a row nobody here has stood at wears the dashed "?" pin
     * until a rider confirms it, whoever published it. The register's
     * authority is its rank (data-provider-hierarchy.md §4), not a dot. Found
     * when 3283 RIVM taps drew the plain pin the night they were harvested.
     */
    public function testAuthorityRowsAreNotVerifiedByProvenanceAlone(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "UPDATE item SET state = 'unverified' WHERE source = 'authority'",
        );
        $conn->executeStatement(
            'DELETE FROM item_confirmation WHERE item_id IN (SELECT id FROM item WHERE source = \'authority\')',
        );

        $features = $this->payload()['O']['authority']['features'];
        self::assertNotEmpty($features);
        foreach ($features as $f) {
            self::assertArrayNotHasKey('v', $f['properties'], 'a listing alone does not verify a row');
        }

        // Nor does one confirmation, for an authority row or any other. The
        // tally that earns the state lives in ItemConfirmationService; this
        // query reads the state and nothing else (owner 2026-09-09).
        $id = (int) $features[0]['properties']['id'];
        $conn->executeStatement(
            "INSERT INTO item_confirmation (item_id, user_id, stance, source, created_at, updated_at) VALUES (:item, 1, 'exists', 'drawer', NOW(), NOW())",
            ['item' => $id],
        );
        self::assertArrayNotHasKey('v', $this->byId($this->payload()['O']['authority']['features'])[$id]);

        $conn->executeStatement("UPDATE item SET state = 'verified' WHERE id = :id", ['id' => $id]);
        self::assertSame(1, $this->byId($this->payload()['O']['authority']['features'])[$id]['v'] ?? null);
    }

    /**
     * Every served point says who keeps it and how far up the ladder it is
     * (data-provider-hierarchy.md §6.7.7), computed once in PHP so the pin,
     * the API and the curator page can never disagree.
     */
    public function testServedFeaturesCarryTheirRungAndCustody(): void
    {
        $conn = $this->em->getConnection();
        // Import promotes the fixture rows to verified with nobody standing
        // there: that is a curator's word, rung 10, and the record is ours.
        foreach ($this->payload()['D']['features'] as $f) {
            self::assertSame('ours', $f['properties']['custody']);
            self::assertSame(11, $f['properties']['rung']);
        }

        // A register row nobody has confirmed: the registry scope for its
        // letter makes it specialty, the fresh import makes it a live claim.
        $conn->executeStatement("UPDATE item SET state = 'unverified', provider_id = (SELECT id FROM data_provider WHERE provider_key = 'wallonie-pivot') WHERE source = 'authority'");
        $conn->executeStatement('DELETE FROM item_confirmation WHERE item_id IN (SELECT id FROM item WHERE source = \'authority\')');
        $features = $this->payload()['O']['authority']['features'];
        self::assertNotEmpty($features);
        foreach ($features as $f) {
            self::assertSame('specialty', $f['properties']['custody']);
            self::assertSame(5, $f['properties']['rung']);
        }

        // One rider: a witness, but custody stays with the register.
        $id = (int) $features[0]['properties']['id'];
        foreach ([1, 2] as $user) {
            $conn->executeStatement(
                "INSERT INTO item_confirmation (item_id, user_id, stance, source, created_at, updated_at) VALUES (:item, :user, 'exists', 'drawer', NOW(), NOW())",
                ['item' => $id, 'user' => $user],
            );
            if (1 === $user) {
                $props = $this->byId($this->payload()['O']['authority']['features'])[$id];
                self::assertSame(9, $props['rung']);
                self::assertSame('specialty', $props['custody']);
            }
        }

        // Threshold riders and the state flip: the record is ours, rung 10.
        $conn->executeStatement("UPDATE item SET state = 'verified' WHERE id = :id", ['id' => $id]);
        $props = $this->byId($this->payload()['O']['authority']['features'])[$id];
        self::assertSame(10, $props['rung']);
        self::assertSame('ours', $props['custody']);
        self::assertArrayNotHasKey('reclaimed', $props, 'absent until it happened');

        // The register's newer survey takes the record back (data-provider-hierarchy.md
        // §6.7.2): the border is the provider's again, the rung and state stay,
        // and the survey date rides the public body for the drawer.
        $conn->executeStatement("UPDATE item SET custody_reclaimed_at = '2027-01-15 00:00:00' WHERE id = :id", ['id' => $id]);
        $props = $this->byId($this->payload()['O']['authority']['features'])[$id];
        self::assertSame('specialty', $props['custody']);
        self::assertSame(10, $props['rung']);
        self::assertSame('2027-01-15', $props['reclaimed']);
    }

    /**
     * Feature properties keyed by item id.
     *
     * @param list<array{properties: array<string, mixed>}> $features
     *
     * @return array<int, array<string, mixed>>
     */
    private function byId(array $features): array
    {
        $out = [];
        foreach ($features as $f) {
            $out[(int) $f['properties']['id']] = $f['properties'];
        }

        return $out;
    }

    /**
     * Coverage retirement (coverage-provider.md §9): the
     * retirement predicate no longer sits behind COVERAGE_TILES — an untouched
     * source=osm state=unverified POI never serves from catalog.json (it lives
     * in coverage_poi now); the same row serves again as soon as any human
     * signal exists (here: an item_confirmation).
     */
    public function testUntouchedOsmUnverifiedRowsAreExcludedUnconditionally(): void
    {
        $conn = $this->em->getConnection();
        // Revert one fixture POI to the untouched-coverage shape.
        $conn->executeStatement(
            "UPDATE item SET state = 'unverified', source = 'osm' WHERE source_ref = 'node/1003'",
        );
        $types = array_map(
            static fn (array $f): string => $f['properties']['t'],
            $this->payload()['D']['features'],
        );
        self::assertNotContains('Pump', $types, 'untouched osm/unverified must be coverage-only');

        // A community confirmation is a human touch — the row stays canonical.
        $id = (int) $conn->fetchOne("SELECT id FROM item WHERE source_ref = 'node/1003'");
        $conn->executeStatement(
            "INSERT INTO item_confirmation (item_id, user_id, stance, created_at, updated_at)
             VALUES (:item, 999999, 'exists', NOW(), NOW())",
            ['item' => $id],
        );
        $types = array_map(
            static fn (array $f): string => $f['properties']['t'],
            $this->payload()['D']['features'],
        );
        self::assertContains('Pump', $types, 'a confirmed row stays canonical and served');
    }

    public function testClimbShapeRestoresCitationAndLatLng(): void
    {
        $climb = $this->payload()['N'][0];
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

    /**
     * featureForItem() serves N · climbs too.
     *
     * Approving an EDIT has to refresh the item the curator is looking at, and
     * the drawer can only do that from the item's served shape. Climbs used to
     * be excluded with A and R, so approving a climb edit left the map and the
     * drawer showing the pre-edit values until a full page reload
     * (owner-reported 2026-08-03). Rebuilt through the SAME mapper the bulk
     * payload uses, so the live-updated climb cannot drift from the served one.
     */
    public function testFeatureForItemRebuildsAClimbInItsOwnShape(): void
    {
        $bulk = $this->payload()['N'][0];

        $one = static::getContainer()->get(CatalogProvider::class)->featureForItem($bulk['id']);

        self::assertNotNull($one);
        self::assertSame('N', $one['letter']);
        self::assertArrayHasKey('climb', $one, 'a climb comes back as a climb, not a GeoJSON feature');
        self::assertArrayNotHasKey('feature', $one);
        // Byte-identical to the bulk payload's entry: one mapper, two callers.
        self::assertSame($bulk, $one['climb']);
    }

    /**
     * A submitted (not yet served) item must stay out of every payload, but a
     * curator reviewing it on the map needs its final form: `anyState` opens
     * the one-item mapper for that preview only (owner 2026-08-25).
     */
    public function testFeatureForItemPreviewsASubmittedItemOnlyWhenAsked(): void
    {
        $bulk = $this->payload()['N'][0];
        $db = static::getContainer()->get(\Doctrine\DBAL\Connection::class);
        $provider = static::getContainer()->get(CatalogProvider::class);

        $db->executeStatement("UPDATE item SET state = 'submitted' WHERE id = :id", ['id' => $bulk['id']]);
        try {
            self::assertNull($provider->featureForItem($bulk['id']), 'served payloads keep the state gate');
            $one = $provider->featureForItem($bulk['id'], anyState: true);
            self::assertNotNull($one);
            self::assertSame('N', $one['letter']);
            self::assertSame($bulk['name'], $one['climb']['name']);
            self::assertArrayHasKey('route', $one['climb']);
        } finally {
            $db->executeStatement("UPDATE item SET state = 'verified' WHERE id = :id", ['id' => $bulk['id']]);
        }
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

    /**
     * A road surface carries who filed it, on the same terms as every other
     * layer.
     *
     * This shape carried no contributor at all, so the drawer fell back to
     * citing OSM for a surface, a smoothness and a traffic level a rider had
     * typed (owner-reported 2026-08-31). OSM is still the source of the LINE,
     * which `srcType` and the provenance line under the name say; it is not the
     * source of the values.
     *
     * The consent rule is the part that has to match `mapRow()` exactly: named
     * only with a public profile, fail-closed to anonymous, never a leaked
     * name. `by` absent entirely means a harvested row nobody filed.
     */
    public function testASurfaceSegmentSaysWhoFiledIt(): void
    {
        $seg = $this->payload()['A'][0];

        // The fixture's row is harvested, so it must name nobody at all: an
        // absent `by` is the honest answer, not `by:0`, which claims a rider.
        self::assertArrayNotHasKey('byName', $seg, 'a harvested row has no contributor to name');

        // What the shape must be capable of carrying, so the drawer can read it.
        $reflection = new \ReflectionMethod(CatalogProvider::class, 'surfaceSegments');
        self::assertTrue($reflection->isPrivate(), 'surfaceSegments stays internal');
        $src = file_get_contents((string) $reflection->getFileName());
        self::assertIsString($src);
        self::assertStringContainsString("\$seg['by'] = \$public ? 1 : 0;", $src,
            'the segment must carry `by`, or the drawer has nobody to name');
        self::assertStringContainsString("\$seg['byName'] = (string) \$row['by_name'];", $src);
        self::assertStringContainsString('if ($public) {', $src,
            'a name may only be shown with a public profile');
    }

    /**
     * A route's photo is served only when PhotoValidator shows it on the route
     * (letter R), the same display filter every item's photo passes: a photo
     * with no usable licence never reaches the drawer, on the served payload
     * or on a curator's preview of a route waiting for review.
     */
    public function testARoutePhotoPhotoValidatorRefusesIsNotServed(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement(
            "UPDATE recommended_route SET attributes = jsonb_set(attributes, '{photo,license}', '\"Fair use\"'), updated_at = NOW() + interval '1 second' WHERE name = 'Test loop'",
        );

        $route = $this->payload()['R'][0];
        self::assertSame('Test loop', $route['name']);
        self::assertArrayNotHasKey('photo', $route, 'a refused photo must not be served');

        $db->executeStatement("UPDATE recommended_route SET state = 'submitted' WHERE name = 'Test loop'");
        $id = (int) $db->fetchOne("SELECT id FROM recommended_route WHERE name = 'Test loop'");
        $preview = static::getContainer()->get(CatalogProvider::class)->submittedRoute($id);
        self::assertNotNull($preview);
        self::assertArrayNotHasKey('photo', $preview['route']);
    }

    /**
     * A route's `photos` gallery, where an approved rider photo lands
     * (photo-uploads.md §5i), is served like its single `photo`.
     */
    public function testARoutesPhotoGalleryIsServed(): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE recommended_route SET attributes = attributes || '{\"photos\": [{\"id\": \"0b7d2c1e-1111-4a2b-9c3d-000000000001\", \"sm\": \"https://example.test/r-s.webp\", \"lg\": \"https://example.test/r-l.webp\", \"credit\": \"Rider\", \"license\": \"CC BY-SA 4.0\"}]}'::jsonb, updated_at = NOW() + interval '1 second' WHERE name = 'Test loop'",
        );

        $route = $this->payload()['R'][0];
        self::assertSame('Test loop', $route['name']);
        self::assertCount(1, $route['photos'] ?? []);
        self::assertSame('https://example.test/r-s.webp', $route['photos'][0]['sm']);
    }

    /** A road surface's photo passes the same filter. */
    public function testASurfacePhotoPhotoValidatorRefusesIsNotServed(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('A', 'Photo dijk', ST_GeomFromText('LINESTRING(4.4 50.6, 4.5 50.7)', 4326), 'BE',
                     'unverified', 'osm', 'way/999002',
                     '{\"surface\": \"Asphalt\", \"photo\": {\"sm\": \"https://example.test/s.jpg\", \"lg\": \"https://example.test/l.jpg\", \"credit\": \"Tester\", \"license\": \"Fair use\"}, \"photos\": [{\"sm\": \"https://example.test/s2.jpg\", \"lg\": \"https://example.test/l2.jpg\", \"credit\": \"Tester\", \"license\": \"CC0\"}]}',
                     now(), now())",
        );

        $segs = array_column($this->payload()['A'], null, 'name');
        self::assertArrayNotHasKey('photo', $segs['Photo dijk']);
        self::assertCount(1, $segs['Photo dijk']['photos']);
    }

    public function testRouteShapeAndHeat(): void
    {
        $p = $this->payload();
        $route = $p['R'][0];
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
        // Heat points carry rid as element 3 (07-20 review finding 5): both
        // fixture points sit inside the region-square fixture, so this pins
        // the whole chain — import → recomputeMembership stamping → payload.
        $regionId = (int) $this->em->getConnection()->fetchOne(
            "SELECT id FROM region WHERE slug = 'test-square'",
        );
        self::assertGreaterThan(0, $regionId);
        // heat() is its own endpoint now, a derived layer with no catalog letter (2026-08-09).
        self::assertArrayNotHasKey('heat', $p);
        self::assertSame(
            [[50.5, 4.5, 'summer', $regionId], [50.6, 4.6, 'winter', $regionId]],
            static::getContainer()->get(CatalogProvider::class)->heat(),
        );
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
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ([['verified', \App\Catalog\ItemState::Verified], ['proposed', \App\Catalog\ItemState::Unverified]] as [$tag, $state]) {
            $em->persist((new \App\Catalog\Entity\RecommendedRoute())
                ->setName('State route '.$tag)
                ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
                ->setDistanceM(9000)->setState($state)
                ->setSource(\App\Catalog\ItemSource::User)->setSourceRef('user:state-'.$tag));
        }
        $em->flush();

        $routes = static::getContainer()->get(CatalogProvider::class)->payload()['R'];
        $byName = [];
        foreach ($routes as $r) {
            $byName[$r['name']] = $r;
        }
        self::assertSame('verified', $byName['State route verified']['state'] ?? null);
        self::assertSame('unverified', $byName['State route proposed']['state'] ?? null);
    }

    /** P2-D1: a proposal's rider-vocabulary difficulty string is canonicalized on
     *  intake (RouteProposalService) and served as {score,label} (CatalogProvider),
     *  the same shape imports use — never the raw legacy string. */
    public function testProposedRouteServesCanonicalDifficulty(): void
    {
        $user = (new User())->setEmail('difficulty-proposer@test.test');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        $pts = '';
        for ($i = 0; $i <= 100; ++$i) {
            $pts .= sprintf('<trkpt lat="%.4f" lon="5.3000"><ele>%d</ele></trkpt>', 50.0 + $i * 0.001, 100 + $i);
        }
        $gpx = '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
            .'<trk><trkseg>'.$pts.'</trkseg></trk></gpx>';

        $service = static::getContainer()->get(\App\Contribution\RouteProposalService::class);
        $route = $service->propose($gpx, ['rName' => 'Difficulty canon test', 'difficulty' => 'Hard'], $user);
        self::assertSame(['score' => 4, 'label' => 'Hard'], $route->getAttributes()['difficulty']);

        // Approve it (a real proposal never serves while `submitted`) and confirm
        // CatalogProvider hands the map the same canonical shape.
        $route->setState(\App\Catalog\ItemState::Unverified);
        $this->em->flush();

        $routes = static::getContainer()->get(CatalogProvider::class)->payload()['R'];
        $byName = [];
        foreach ($routes as $r) {
            $byName[$r['name']] = $r;
        }
        self::assertSame(['score' => 4, 'label' => 'Hard'], $byName['Difficulty canon test']['difficulty'] ?? null);
    }

    public function testServedPoiCarriesRealVerifiedFlag(): void
    {
        // import() promotes the shape fixtures to verified (coverage
        // retirement, Task 14) — every served D feature carries the real flag.
        foreach ($this->payload()['D']['features'] as $f) {
            self::assertSame(1, $f['properties']['v']);
        }

        // Demote one to unverified + confirm it: still SERVED, because a human
        // touched it, and yet NOT flagged verified, because one confirmation is
        // not the threshold. Serving and verifying are two questions, and this
        // is the row that proves they are asked separately. Demote another
        // without any touch: it leaves the payload entirely (coverage-only).
        $ids = array_map(static fn (array $f): int => $f['properties']['id'], $this->payload()['D']['features']);
        sort($ids);
        $conn = $this->em->getConnection();
        $conn->executeStatement("UPDATE item SET state = 'unverified' WHERE id = :id", ['id' => $ids[0]]);
        $conn->executeStatement(
            'INSERT INTO item_confirmation (item_id, user_id, stance, created_at, updated_at) VALUES (:item, 1, :stance, NOW(), NOW())',
            ['item' => $ids[0], 'stance' => 'exists'],
        );
        $conn->executeStatement("UPDATE item SET state = 'unverified' WHERE id = :id", ['id' => $ids[1]]);

        $byId = [];
        foreach ($this->payload()['D']['features'] as $f) {
            $byId[$f['properties']['id']] = $f['properties'];
        }
        self::assertArrayHasKey($ids[0], $byId);               // confirmed → served
        self::assertArrayNotHasKey('v', $byId[$ids[0]]);       // one confirmation → not verified
        self::assertArrayNotHasKey($ids[1], $byId);            // untouched unverified → coverage-only
        self::assertSame(1, $byId[$ids[2]]['v']);              // verified state → the flag
    }

    public function testClimbAndSurfaceCarryRealVerifiedFlag(): void
    {
        // Climbs (N) and surface segments (A) serve through their own shapes,
        // not featureCollection() — they must still carry the SAME verified
        // signal (map-and-search.md §12: v:1 = verified state, one definition),
        // or the map's index mislabels every DB-verified climb as community.
        // Imported seeds are unverified: no 'v' key (byte-stable).
        self::assertArrayNotHasKey('v', $this->payload()['N'][0]);
        self::assertArrayNotHasKey('v', $this->payload()['A'][0]);

        $conn = $this->em->getConnection();
        $conn->executeStatement("UPDATE item SET state = 'verified' WHERE letter = 'N'");
        // A gets a lone confirmation, which is short of the threshold, so this
        // shape must not invent a flag the featureCollection() leg would deny.
        $segId = $this->payload()['A'][0]['id'];
        $conn->executeStatement(
            'INSERT INTO item_confirmation (item_id, user_id, stance, created_at, updated_at) VALUES (:item, 1, :stance, NOW(), NOW())',
            ['item' => $segId, 'stance' => 'exists'],
        );

        self::assertSame(1, $this->payload()['N'][0]['v']);
        self::assertArrayNotHasKey('v', $this->payload()['A'][0]);

        $conn->executeStatement("UPDATE item SET state = 'verified' WHERE id = :id", ['id' => $segId]);
        self::assertSame(1, $this->payload()['A'][0]['v']);
    }

    /** Plan 2 Task 13: payload ships the served OSM refs so the map's tile
     *  layers can hide already-curated objects (osm-data-architecture.md §8
     *  ref dedupe, client half). */
    public function testPayloadCarriesServedOsmRefs(): void
    {
        $refs = $this->payload()['refs'];
        self::assertContains('node/1001', $refs);                              // D shop
        self::assertContains('node/5001', $refs);                              // O stay (osm bucket)
        self::assertContains('way/2001', $refs);                               // A surface — osm-sourced, listed too (harmless to tiles)
        self::assertNotContains('fx:pivot:gite-test|testbourg', $refs);        // pivot is not an OSM ref

        // Served-only: a rejected row's ref must disappear (ItemState::SERVED).
        $this->em->getConnection()->executeStatement("UPDATE item SET state = 'rejected' WHERE source_ref = 'node/1001'");
        self::assertNotContains('node/1001', $this->payload()['refs']);
    }

    /**
     * An authority row attached to an OSM tap (data-provider-hierarchy.md
     * §4.1) is served as a pin, so the tap's own tile drop must be hidden:
     * its `osm_ref` ships in `refs` like a curated OSM ref does. Found on
     * 2026-09-05, the first RIVM harvest: 2429 taps drew twice.
     */
    public function testPayloadCarriesTheOsmTwinOfAnAttachedAuthorityRow(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, osm_ref, attributes, created_at, updated_at)
             VALUES ('B', '', ST_GeomFromText('POINT(4.75 52.57)', 4326), 'NL',
                     'unverified', 'authority', 'rivm-drinkwater:52.57,4.75', 'node/7001', '{}', now(), now())",
        );
        self::assertContains('node/7001', $this->payload()['refs']);

        $conn->executeStatement("UPDATE item SET state = 'rejected' WHERE osm_ref = 'node/7001'");
        self::assertNotContains('node/7001', $this->payload()['refs'], 'a row no longer served frees its twin');
    }

    /**
     * Phase 2 (map-and-search.md §4.5): every served feature carries
     * its region_id as `rid` so map.js `featureVisible` can filter by scope.
     * Rows outside every region carry no rid (byte-stable for null-region rows).
     */
    public function testServedFeaturesCarryRegionIdAsRid(): void
    {
        $byType = [];
        foreach ($this->payload()['D']['features'] as $f) {
            $byType[$f['properties']['t']] = $f['properties'];
        }
        // Bike shop [4.4,50.7] + Repair station [4.9,50.3] fall inside the
        // test-square region ([4,50]-[5,51]); both get the same rid.
        self::assertIsInt($byType['Bike shop']['rid']);
        self::assertGreaterThan(0, $byType['Bike shop']['rid']);
        self::assertSame($byType['Bike shop']['rid'], $byType['Repair station']['rid'], 'same region → same rid');
        // Pump [6.5,49.0] is outside every region → no rid key.
        self::assertArrayNotHasKey('rid', $byType['Pump']);

        // Climbs (N) and surface (A) serve through their own shapes — they carry rid too.
        self::assertIsInt($this->payload()['N'][0]['rid']);   // Côte de Test [50.61,4.41] inside
        self::assertIsInt($this->payload()['A'][0]['rid']);   // Test seg inside

        // Routes (R) carry rid via recommended_route.region_id (Test loop inside).
        $route = $this->payload()['R'][0];
        self::assertIsInt($route['rid']);
        self::assertGreaterThan(0, $route['rid']);
    }

    /**
     * A materialized A item (the confirm/correct flow) stores the FORM
     * vocabulary — surface: 'Asphalt' — and no `cls`, because `cls` was a
     * harvester attribute. The client keys the drawn class layer on `cls`, so
     * without a serve-time derivation every rider-contributed segment fell
     * into the grey 'other' fallback instead of its class colour
     * (owner-reported 2026-08-13: the approved Zuiderdijk drew as no-class).
     */
    public function testAMaterializedSegmentServesADerivedClass(): void
    {
        $db = $this->em->getConnection();
        // The exact attribute shape ContributeController materializes: form
        // vocabulary, segment geometry, no cls.
        $db->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('A', 'Testdijk', ST_GeomFromText('LINESTRING(4.4 50.6, 4.5 50.7)', 4326), 'BE',
                     'unverified', 'osm', 'way/999001',
                     '{\"surface\": \"Asphalt\", \"smoothness\": \"Excellent\", \"segment\": {\"a\": [4.4, 50.6], \"b\": [4.5, 50.7], \"line\": [[4.4, 50.6], [4.5, 50.7]]}}',
                     now(), now())",
        );
        $segs = array_column($this->payload()['A'], null, 'name');
        self::assertArrayHasKey('Testdijk', $segs);
        self::assertSame('paved', $segs['Testdijk']['cls'] ?? null,
            'a rider-materialized Asphalt segment must draw in the paved class, not the other fallback');
        // A stored cls (the harvested rows) is never second-guessed.
        $fixture = $this->payload()['A'][0];
        self::assertArrayHasKey('cls', $fixture);
    }

    /**
     * A gone place is hidden from riders for good but must stay FINDABLE by
     * curators, or a rebuilt tap could never be reactivated (owner
     * 2026-08-13): goneForMap serves the curator ghost layer, scoped like the
     * pending queue; the public payload keeps excluding the item.
     */
    public function testGonePlacesServeTheCuratorGhostLayerOnly(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('B', 'GoneTap', ST_GeomFromText('POINT(4.45 50.65)', 4326), 'BE',
                     'unverified', 'osm', 'node/999002',
                     '{\"t\": \"Drinking water\", \"condition\": \"Not there anymore\"}', now(), now())",
        );
        $provider = static::getContainer()->get(CatalogProvider::class);

        $gone = $provider->goneForMap(ModerationScope::global());
        $mine = array_values(array_filter($gone, static fn (array $g): bool => 'GoneTap' === $g['name']));
        self::assertCount(1, $mine);
        self::assertSame('B', $mine[0]['letter']);
        self::assertEqualsWithDelta(50.65, $mine[0]['lat'], 0.001);

        // Still invisible to riders: the served payload keeps excluding it.
        $served = array_column($this->payload()['B']['features'], 'properties');
        self::assertNotContains('GoneTap', array_column($served, 'n'));
    }

    /**
     * The rider who added a place is named on it, with their consent.
     *
     * A point item has no creator column - the person is on the submission
     * that minted it - so an addition tagged while riding read as if it had
     * arrived from nowhere (owner-reported 2026-08-14: "tagged via Scout but
     * added by a rider, so show the rider name"). `public_profile` is the
     * gate, and it fails closed: a rider who has not made their profile public
     * still gets `by`, so the drawer can say the contribution was a rider's
     * without naming them.
     */
    public function testAnAddedPlaceNamesItsContributorWhenTheyAllowIt(): void
    {
        $db = $this->em->getConnection();
        foreach ([['Publicly', true], ['Privately', false]] as [$who, $public]) {
            $user = (new User())->setEmail(strtolower($who).'.contributor@example.test')
                ->setDisplayName($who.' Named');
            $user->setEmailVerified(true)->setRoles([])->setPassword('x')->setPublicProfile($public);
            $this->em->persist($user);
            $this->em->flush();

            $db->executeStatement(
                "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
                 VALUES ('P', :name, ST_GeomFromText('POINT(4.46 50.66)', 4326), 'BE',
                         'unverified', 'scout', :ref, '{}', now(), now())",
                ['name' => $who.' Added', 'ref' => 'sub:contrib-'.strtolower($who)],
            );
            $itemId = (int) $db->fetchOne('SELECT id FROM item WHERE name = :n', ['n' => $who.' Added']);
            $db->executeStatement(
                "INSERT INTO submission (type, letter, item_id, user_id, status, title, geom, country_code, changes, payload, created_at)
                 VALUES ('new', 'P', :item, :uid, 'approved', :title, ST_GeomFromText('POINT(4.46 50.66)', 4326), 'BE', '{}', '{}', now())",
                ['item' => $itemId, 'uid' => $user->getId(), 'title' => $who.' Added'],
            );
        }

        $props = [];
        foreach ($this->payload()['P']['features'] as $f) {
            $props[$f['properties']['n'] ?? ''] = $f['properties'];
        }

        self::assertSame(1, $props['Publicly Added']['by']);
        self::assertSame('Publicly Named', $props['Publicly Added']['byName']);
        self::assertNotEmpty($props['Publicly Added']['byUuid'], 'the uuid is the profile link target');

        self::assertSame(0, $props['Privately Added']['by'], 'still a rider\'s, still said');
        self::assertArrayNotHasKey('byName', $props['Privately Added'], 'never named without consent');
        self::assertArrayNotHasKey('byUuid', $props['Privately Added']);
    }

    /**
     * The version tag is what busts the browser's hour-long catalog.json cache
     * the moment the catalog actually changes (owner-reported 2026-08-13: an
     * approved submission "disappeared" — the item was in the DB and in the
     * payload, but the rider's browser replayed the pre-approval JSON for up
     * to an hour). /map embeds the tag as ?v= on CC_CATALOG_URL, so a change
     * mints a new URL and the stale cache entry is simply never asked for.
     */
    public function testVersionTagFollowsEveryCatalogMutationPath(): void
    {
        $provider = static::getContainer()->get(CatalogProvider::class);
        $db = $this->em->getConnection();

        $v0 = $provider->versionTag();
        self::assertMatchesRegularExpression('/^[0-9a-f]{8,}$/', $v0, 'a compact hex tag, URL-safe');
        self::assertSame($v0, $provider->versionTag(), 'stable while nothing changes');

        // An UPDATE (moderation decision, materialize-on-edit, closure expiry
        // sweep — they all touch updated_at).
        $db->executeStatement("UPDATE item SET updated_at = updated_at + interval '1 second' WHERE id = (SELECT min(id) FROM item)");
        $v1 = $provider->versionTag();
        self::assertNotSame($v0, $v1, 'an item update must mint a new version');

        // A DELETE without any other change (takedown, trash purge): max
        // timestamps do not move, so the tag must also see the row count — a
        // removed item kept alive by a cached payload is the takedown-critical
        // case.
        $db->executeStatement('DELETE FROM item WHERE id = (SELECT max(id) FROM item)');
        $v2 = $provider->versionTag();
        self::assertNotSame($v1, $v2, 'an item delete must mint a new version');

        // A confirmation flips the served verified flag without touching item.
        // user_id 1 exists in the test DB, same shape ManualSourceTest uses.
        $db->executeStatement(
            "INSERT INTO item_confirmation (item_id, user_id, stance, created_at, updated_at)
             SELECT min(id), 1, 'exists', now(), now() FROM item",
        );
        $v3 = $provider->versionTag();
        self::assertNotSame($v2, $v3, 'a confirmation must mint a new version');

        // R rides the same payload: a route change must mint one too.
        $db->executeStatement("UPDATE recommended_route SET updated_at = updated_at + interval '1 second' WHERE id = (SELECT min(id) FROM recommended_route)");
        self::assertNotSame($v3, $provider->versionTag(), 'a route update must mint a new version');
    }

    /**
     * A scenic view serves only the photos whose camera stood near its pin
     * (PhotoValidator). The same far photo on a water tap is untouched: the
     * rule is about promising a view, and only a scenic pin promises one. A
     * photo with no licence we accept is served on no letter.
     */
    public function testAScenicViewDropsPhotosTakenAwayFromItsPin(): void
    {
        $db = $this->em->getConnection();
        // 0.0009 degrees of latitude is about 100 m, 0.0036 about 400 m.
        $credited = ['credit' => 'Jane Rider', 'license' => 'CC BY-SA 4.0'];
        $near = ['sm' => 'https://img.test/near-sm.webp', 'lg' => 'https://img.test/near-lg.webp', 'cameraAt' => [50.4009, 4.5]] + $credited;
        $far = ['sm' => 'https://img.test/far-sm.webp', 'lg' => 'https://img.test/far-lg.webp', 'cameraAt' => [50.4036, 4.5]] + $credited;
        $rider = ['id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'sm' => 'https://img.test/rider-sm.webp', 'lg' => 'https://img.test/rider-lg.webp', 'distanceM' => 40, 'credit' => '', 'license' => 'CC BY-SA 4.0'];
        $noGps = ['id' => 'aaaaaaaa-0000-4000-8000-000000000002', 'sm' => 'https://img.test/nogps-sm.webp', 'lg' => 'https://img.test/nogps-lg.webp', 'distanceM' => null, 'credit' => '', 'license' => 'CC BY-SA 4.0'];
        $unlicensed = ['sm' => 'https://img.test/nc-sm.webp', 'lg' => 'https://img.test/nc-lg.webp', 'credit' => 'Jane Rider', 'license' => 'CC BY-NC-SA 4.0'];

        $insert = "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
                   VALUES (:letter, :name, ST_GeomFromText('POINT(4.5 50.4)', 4326), 'BE', 'verified', 'manual', :ref, CAST(:attrs AS jsonb), now(), now())";
        $db->executeStatement($insert, ['letter' => 'P', 'name' => 'Scenic far test', 'ref' => 'manual:scenic-far',
            'attrs' => json_encode(['type' => 'Viewpoint', 'photo' => $far], \JSON_THROW_ON_ERROR)]);
        $db->executeStatement($insert, ['letter' => 'P', 'name' => 'Scenic gallery test', 'ref' => 'manual:scenic-gallery',
            'attrs' => json_encode(['type' => 'Viewpoint', 'photos' => [$far, $near, $rider, $noGps]], \JSON_THROW_ON_ERROR)]);
        $db->executeStatement($insert, ['letter' => 'B', 'name' => 'Tap far photo test', 'ref' => 'manual:tap-far',
            'attrs' => json_encode(['t' => 'Drinking water', 'photo' => $far, 'photos' => [$unlicensed]], \JSON_THROW_ON_ERROR)]);

        $payload = $this->payload();
        $byName = static function (array $collection, string $name): array {
            foreach ($collection['features'] as $f) {
                if ($name === ($f['properties']['n'] ?? null)) {
                    return $f['properties'];
                }
            }
            self::fail($name.' is not served');
        };

        $lonely = $byName($payload['P'], 'Scenic far test');
        self::assertArrayNotHasKey('photo', $lonely, 'a photo from 400 m away is not the view from the pin');
        self::assertArrayNotHasKey('photos', $lonely);

        $gallery = $byName($payload['P'], 'Scenic gallery test');
        self::assertSame([$near['sm'], $rider['sm']], array_column($gallery['photos'], 'sm'), 'near cameras and near rider photos stay, in order');

        $tap = $byName($payload['B'], 'Tap far photo test');
        self::assertSame($far['sm'], $tap['photo']['sm'] ?? null, 'other letters are unaffected by the camera');
        self::assertArrayNotHasKey('photos', $tap, 'a non-commercial licence is served on no letter');

        $id = (int) $db->fetchOne("SELECT id FROM item WHERE source_ref = 'manual:scenic-far'");
        $live = static::getContainer()->get(CatalogProvider::class)->featureForItem($id);
        self::assertArrayNotHasKey('photo', $live['feature']['properties'] ?? [], 'a live insert follows the same rule');
    }

    /**
     * A place that stands for an OSM point and has no photo of its own names
     * that point as `photoRef` when its tags could resolve a Commons photo,
     * the same test the coverage detail answers `photo` with
     * (coverage-provider.md §7). The drawer then asks /map/coverage/photo for
     * it, exactly as it does for the coverage point. Found 2026-09-15: a
     * monument materialized from an OSM point lost the point's photo.
     */
    public function testAPlaceStandingForAnOsmPointNamesThePointsPhoto(): void
    {
        $db = $this->em->getConnection();
        self::ensureCoverageSchema($db);
        $poi = static function (string $ref, string $letter, array $tags) use ($db): void {
            $db->executeStatement(
                "INSERT INTO coverage_poi (ref, letter, name, geom, tags, country_code)
                 VALUES (:r, :l, 'x', ST_SetSRID(ST_MakePoint(4.5, 50.4), 4326), CAST(:t AS jsonb), 'BE')",
                ['r' => $ref, 'l' => $letter, 't' => json_encode($tags, \JSON_THROW_ON_ERROR)],
            );
        };
        $item = static function (string $name, string $letter, string $source, string $sourceRef, ?string $osmRef, array $attrs) use ($db): int {
            $db->executeStatement(
                "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, osm_ref, attributes, created_at, updated_at)
                 VALUES (:l, :n, ST_GeomFromText('POINT(4.5 50.4)', 4326), 'BE', 'verified', :s, :sr, :or, CAST(:a AS jsonb), now(), now())",
                ['l' => $letter, 'n' => $name, 's' => $source, 'sr' => $sourceRef, 'or' => $osmRef, 'a' => json_encode((object) $attrs, \JSON_THROW_ON_ERROR)],
            );

            return (int) $db->fetchOne('SELECT id FROM item WHERE name = :n', ['n' => $name]);
        };

        $poi('way/9101', 'Q', ['historic' => 'memorial', 'wikidata' => 'Q140185900', 'wikimedia_commons' => 'Category:Somewhere']);
        $poi('node/9102', 'Q', ['historic' => 'castle', 'wikimedia_commons' => 'File:Own photo test.jpg']);
        $poi('node/9103', 'Q', ['historic' => 'ruins', 'wikimedia_commons' => 'Category:Only a category']);
        $poi('node/9104', 'B', ['amenity' => 'drinking_water', 'image' => 'File:Twin tap.jpg']);

        $monument = $item('Photo ref monument', 'Q', 'osm', 'way/9101', 'way/9101', []);
        $castle = $item('Photo ref castle', 'Q', 'osm', 'node/9102', 'node/9102', ['photo' => [
            'sm' => 'https://img.test/own-sm.webp', 'lg' => 'https://img.test/own-lg.webp', 'credit' => 'Jane Rider', 'license' => 'CC BY-SA 4.0',
        ]]);
        $ruins = $item('Photo ref ruins', 'Q', 'osm', 'node/9103', 'node/9103', []);
        $tap = $item('Photo ref tap', 'B', 'authority', 'rivm-drinkwater:50.4,4.5', 'node/9104', []);
        $manual = $item('Photo ref manual', 'Q', 'manual', 'manual:photo-ref', null, []);

        $payload = $this->payload();
        $q = $this->byId($payload['Q']['features']);

        self::assertSame('way/9101', $q[$monument]['photoRef'] ?? null, 'a Wikidata id is enough, as it is for the coverage point');
        self::assertArrayNotHasKey('photoRef', $q[$castle], 'its own photo always wins');
        self::assertSame('https://img.test/own-sm.webp', $q[$castle]['photo']['sm'] ?? null);
        self::assertArrayNotHasKey('photoRef', $q[$ruins], 'a Commons category is not a photo');
        self::assertArrayNotHasKey('photoRef', $q[$manual], 'no OSM point, nothing to borrow');
        self::assertSame('node/9104', $this->byId($payload['B']['features'])[$tap]['photoRef'] ?? null, 'the OSM twin of an authority row counts');

        $live = static::getContainer()->get(CatalogProvider::class)->featureForItem($monument);
        self::assertSame('way/9101', $live['feature']['properties']['photoRef'] ?? null, 'a live insert carries it too');
    }
}
