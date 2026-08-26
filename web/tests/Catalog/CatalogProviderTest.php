<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogProvider;
use App\Entity\User;
use App\Moderation\ModerationScope;
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
     * F · Hazards & conditions (map-and-search.md §4.5 Task A): served as a
     * plain FeatureCollection like the other point letters, so map.js can render
     * it as CATALOG features. The manual hazard is not an untouched-osm row, so
     * the coverage-retirement predicate never drops it; its attributes reach the
     * client and it carries rid for the scope gate.
     */
    public function testHazardsServeAsFeatureCollection(): void
    {
        $f = $this->payload()['F'];
        self::assertSame('FeatureCollection', $f['type']);
        // Locate the seeded hazard by name rather than assuming it is the ONLY F
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

    /**
     * Official-registry provenance counts as verified (map-and-search.md §12,
     * owner decision 2026-07-17): a Tourisme Wallonie PIVOT row serves v:1
     * even in unverified state with zero confirmations — the registry listing
     * is the trust signal, so it never renders as community tier.
     */
    public function testPivotRowsCarryVerifiedFlagFromRegistryProvenance(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "UPDATE item SET state = 'unverified' WHERE source = 'pivot'",
        );
        $conn->executeStatement(
            'DELETE FROM item_confirmation WHERE item_id IN (SELECT id FROM item WHERE source = \'pivot\')',
        );

        foreach ($this->payload()['E']['pivot']['features'] as $f) {
            self::assertSame(1, $f['properties']['v'], 'registry provenance alone must verify a pivot row');
        }
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

        $routes = static::getContainer()->get(CatalogProvider::class)->payload()['K'];
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

        $routes = static::getContainer()->get(CatalogProvider::class)->payload()['K'];
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

        // Demote one to unverified + confirm it: still served (human touch)
        // and still v:1 (the confirmation is the real signal). Demote another
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
        self::assertSame(1, $byId[$ids[0]]['v']);          // confirmed → served + real flag
        self::assertArrayNotHasKey($ids[1], $byId);        // untouched unverified → coverage-only
        self::assertSame(1, $byId[$ids[2]]['v']);          // still verified
    }

    public function testClimbAndSurfaceCarryRealVerifiedFlag(): void
    {
        // Climbs (N) and surface segments (A) serve through their own shapes,
        // not featureCollection() — they must still carry the SAME real
        // verified signal (map-and-search.md §12: v:1 = verified state OR ≥1
        // confirmation), or the map's index mislabels every DB-verified climb
        // as community. Imported seeds are unverified: no 'v' key (byte-stable).
        self::assertArrayNotHasKey('v', $this->payload()['N'][0]);
        self::assertArrayNotHasKey('v', $this->payload()['A'][0]);

        $conn = $this->em->getConnection();
        $conn->executeStatement("UPDATE item SET state = 'verified' WHERE letter = 'N'");
        // A takes the confirmation branch so both derivation legs are pinned.
        $segId = $this->payload()['A'][0]['id'];
        $conn->executeStatement(
            'INSERT INTO item_confirmation (item_id, user_id, stance, created_at, updated_at) VALUES (:item, 1, :stance, NOW(), NOW())',
            ['item' => $segId, 'stance' => 'exists'],
        );

        self::assertSame(1, $this->payload()['B'][0]['v']);
        self::assertSame(1, $this->payload()['A'][0]['v']);
    }

    /** Plan 2 Task 13: payload ships the served OSM refs so the map's tile
     *  layers can hide already-curated objects (osm-data-architecture.md §8
     *  ref dedupe, client half). */
    public function testPayloadCarriesServedOsmRefs(): void
    {
        $refs = $this->payload()['refs'];
        self::assertContains('node/1001', $refs);                              // D shop
        self::assertContains('node/5001', $refs);                              // E stay (osm bucket)
        self::assertContains('way/2001', $refs);                               // A surface — osm-sourced, listed too (harmless to tiles)
        self::assertNotContains('fx:pivot:gite-test|testbourg', $refs);        // pivot is not an OSM ref

        // Served-only: a rejected row's ref must disappear (ItemState::SERVED).
        $this->em->getConnection()->executeStatement("UPDATE item SET state = 'rejected' WHERE source_ref = 'node/1001'");
        self::assertNotContains('node/1001', $this->payload()['refs']);
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

        // Climbs (B) and surface (A) serve through their own shapes — they carry rid too.
        self::assertIsInt($this->payload()['B'][0]['rid']);   // Côte de Test [50.61,4.41] inside
        self::assertIsInt($this->payload()['A'][0]['rid']);   // Test seg inside

        // Routes (K) carry rid via recommended_route.region_id (Test loop inside).
        $route = $this->payload()['K'][0];
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
             VALUES ('C', 'GoneTap', ST_GeomFromText('POINT(4.45 50.65)', 4326), 'BE',
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
                 VALUES ('I', :name, ST_GeomFromText('POINT(4.46 50.66)', 4326), 'BE',
                         'unverified', 'scout', :ref, '{}', now(), now())",
                ['name' => $who.' Added', 'ref' => 'sub:contrib-'.strtolower($who)],
            );
            $itemId = (int) $db->fetchOne('SELECT id FROM item WHERE name = :n', ['n' => $who.' Added']);
            $db->executeStatement(
                "INSERT INTO submission (type, letter, item_id, user_id, status, title, geom, country_code, changes, payload, created_at)
                 VALUES ('new', 'I', :item, :uid, 'approved', :title, ST_GeomFromText('POINT(4.46 50.66)', 4326), 'BE', '{}', '{}', now())",
                ['item' => $itemId, 'uid' => $user->getId(), 'title' => $who.' Added'],
            );
        }

        $props = [];
        foreach ($this->payload()['I']['features'] as $f) {
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

        // K rides the same payload: a route change must mint one too.
        $db->executeStatement("UPDATE recommended_route SET updated_at = updated_at + interval '1 second' WHERE id = (SELECT min(id) FROM recommended_route)");
        self::assertNotSame($v3, $provider->versionTag(), 'a route update must mint a new version');
    }
}
