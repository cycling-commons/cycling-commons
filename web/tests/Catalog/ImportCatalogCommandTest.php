<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Region;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\World\Entity\Country;
use App\World\Entity\Subdivision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportCatalogCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function runImport(string $dir): CommandTester
    {
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:catalog:import'));
        $tester->execute(['dir' => $dir]);

        return $tester;
    }

    private function fixturesDir(string $subset = 'ok'): string
    {
        // Copy only the wanted files into a temp dir so error-fixtures don't pollute the happy path.
        $src = __DIR__.'/../fixtures/catalog';
        $dir = sys_get_temp_dir().'/catalog-import-'.$subset.'-'.getmypid();
        @mkdir($dir, 0777, true);
        $files = match ($subset) {
            'ok' => ['region-square.geojson', 'services.json', 'surface.json'],
            'bad-key' => ['bad-key.json'],
            'bad-source' => ['bad-source.json'],
            default => ['bad-geom.json'],
        };
        foreach ($files as $f) {
            copy($src.'/'.$f, $dir.'/'.$f);
        }

        return $dir;
    }

    public function testImportCreatesRegionItemsAndMembership(): void
    {
        $tester = $this->runImport($this->fixturesDir());
        $tester->assertCommandIsSuccessful();

        $region = $this->em->getRepository(Region::class)->findOneBy(['slug' => 'test-square']);
        self::assertNotNull($region);
        // Phase 1 gate: provenance stamped from artifact properties
        // (map-and-search.md §4.5). country_code is the load-bearing one —
        // country-scoped curators match on it, so an unstamped region is a
        // silent moderation hole.
        self::assertSame('BE', $region->getCountryCode());
        self::assertSame('BE-TST', $region->getIsoCode());
        self::assertSame(4, $region->getAdminLevel());
        self::assertSame('osm', $region->getSource());

        $items = $this->em->getRepository(Item::class)->findAll();
        self::assertCount(4, $items); // 3 services + 1 surface

        $shop = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'node/1001']);
        self::assertNotNull($shop);
        self::assertSame('D', $shop->getLetter());
        self::assertSame('Ecocyclo', $shop->getName());
        self::assertSame(ItemState::Unverified, $shop->getState());
        self::assertSame(ItemSource::Osm, $shop->getSource());
        self::assertSame('Bike shop', $shop->getAttributes()['t']);
        self::assertArrayNotHasKey('prov', $shop->getAttributes());
        self::assertArrayNotHasKey('ref', $shop->getAttributes());
        self::assertSame($region->getId(), $shop->getRegionId());     // inside the square
        self::assertSame('BE', $shop->getCountryCode());
        // CRITICAL fix: the fixture's services.json (like the real stale
        // tools/wallonia/out/services.json before the harvester emits
        // serviceKind directly) has no serviceKind on the feature — the
        // importer must derive it from the legacy `t` label so the D-kind
        // split works even against artifacts produced before that fix.
        self::assertSame('shop', $shop->getAttributes()['serviceKind']);

        $station = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'node/1002']);
        self::assertNotNull($station);
        self::assertSame('station', $station->getAttributes()['serviceKind']);

        $pump = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'node/1003']);
        self::assertNotNull($pump);
        self::assertSame('pump', $pump->getAttributes()['serviceKind']);

        $outside = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'node/1003']);
        self::assertNotNull($outside);
        self::assertNull($outside->getRegionId());                    // outside the square

        $seg = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'way/2001']);
        self::assertNotNull($seg);
        self::assertSame('A', $seg->getLetter());
        self::assertSame($region->getId(), $seg->getRegionId());      // PointOnSurface of the line is inside
    }

    public function testImportTwiceIsIdempotent(): void
    {
        $dir = $this->fixturesDir();
        $this->runImport($dir)->assertCommandIsSuccessful();
        $countAfterFirst = \count($this->em->getRepository(Item::class)->findAll());
        $this->runImport($dir)->assertCommandIsSuccessful();
        self::assertSame($countAfterFirst, \count($this->em->getRepository(Item::class)->findAll()));

        // Backdate, then re-import: updated_at must NOT bump (content unchanged),
        // imported_at MUST (last harvest touch). NOW() is transaction-constant
        // under DAMA, so wall-clock comparisons cannot work here.
        $epoch = new \DateTimeImmutable('2000-01-01 00:00:00');
        $this->em->getConnection()->executeStatement(
            "UPDATE item SET updated_at = '2000-01-01 00:00:00', imported_at = '2000-01-01 00:00:00' WHERE source_ref = 'node/1001'",
        );
        $this->em->clear();
        $this->runImport($dir)->assertCommandIsSuccessful();
        $shop = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'node/1001']);
        self::assertNotNull($shop);
        self::assertEquals($epoch, $shop->getUpdatedAt());              // content unchanged -> no bump
        self::assertGreaterThan($epoch, $shop->getImportedAt());        // harvest touch ALWAYS bumps
    }

    /**
     * #26: a re-harvest must not clobber a curator-approved rider edit. An
     * approved edit leaves change_history rows for the item; the upsert keeps
     * the DB content for such items and only refreshes imported_at.
     */
    public function testReimportPreservesCuratorApprovedEdits(): void
    {
        $dir = $this->fixturesDir();
        $this->runImport($dir)->assertCommandIsSuccessful();

        $shop = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'node/1001']);
        self::assertNotNull($shop);
        self::assertSame('Ecocyclo', $shop->getName());
        self::assertSame('Bike shop', $shop->getAttributes()['t']);
        $itemId = $shop->getId();

        // Simulate an approved rider edit: change content AND leave the
        // change_history trail moderation would (ModerationService::applyEdit).
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "UPDATE item SET name = 'Curated Bike Hub', attributes = jsonb_set(attributes, '{t}', '\"Curated shop\"') WHERE id = :id",
            ['id' => $itemId],
        );
        $conn->executeStatement(
            "INSERT INTO change_history (item_id, submission_id, field, old_value, new_value, changed_by, changed_at)
             VALUES (:id, 1, 'name', '\"Ecocyclo\"', '\"Curated Bike Hub\"', 1, NOW())",
            ['id' => $itemId],
        );
        $this->em->clear();

        // Re-import the SAME harvest (which still says 'Ecocyclo' / 'Bike shop').
        $this->runImport($dir)->assertCommandIsSuccessful();

        $shop = $this->em->getRepository(Item::class)->findOneBy(['id' => $itemId]);
        self::assertNotNull($shop);
        self::assertSame('Curated Bike Hub', $shop->getName(), 'curated name must survive re-import');
        self::assertSame('Curated shop', $shop->getAttributes()['t'], 'curated attribute must survive re-import');
    }

    public function testMalformedJsonFailsCleanly(): void
    {
        $dir = sys_get_temp_dir().'/catalog-import-broken-'.getmypid();
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/broken.json', '{"layer": "services", "letter": "D", "features": [');
        $tester = $this->runImport($dir);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Syntax error', $tester->getDisplay());
    }

    public function testFailureRollsBackAllWork(): void
    {
        // services.json imports fine; zz-bad-key.json (sorts last in the glob)
        // then fails validation — the transaction must leave NOTHING behind.
        $src = __DIR__.'/../fixtures/catalog';
        $dir = sys_get_temp_dir().'/catalog-import-rollback-'.getmypid();
        @mkdir($dir, 0777, true);
        copy($src.'/region-square.geojson', $dir.'/region-square.geojson');
        copy($src.'/services.json', $dir.'/services.json');
        copy($src.'/bad-key.json', $dir.'/zz-bad-key.json');
        $tester = $this->runImport($dir);
        self::assertSame(1, $tester->getStatusCode());
        self::assertCount(0, $this->em->getRepository(Item::class)->findAll());
        self::assertNull($this->em->getRepository(Region::class)->findOneBy(['slug' => 'test-square']));
    }

    public function testRegionArtifactMissingCountryCodeFails(): void
    {
        // Phase 1 gate: a region artifact with no country_code must fail loudly
        // at import, never insert an unstamped row — country-scoped curators
        // match on region.country_code, so an unstamped region is a silent
        // moderation-jurisdiction hole (map-and-search.md §4.5 risk 1).
        $dir = sys_get_temp_dir().'/catalog-import-region-nocc-'.getmypid();
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/region-nocc.geojson', json_encode([
            'type' => 'Feature',
            'properties' => ['slug' => 'no-country', 'name' => 'No Country', 'area_km2' => 50],
            'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [[[[4.0, 50.0], [5.0, 50.0], [5.0, 51.0], [4.0, 51.0], [4.0, 50.0]]]]],
        ], \JSON_THROW_ON_ERROR));

        $tester = $this->runImport($dir);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('country_code', $tester->getDisplay());
        self::assertNull($this->em->getRepository(Region::class)->findOneBy(['slug' => 'no-country']));
    }

    public function testOverlappingRegionsInSameCountryFail(): void
    {
        // Operating-level regions must tessellate, not overlap: an ST_Overlaps
        // pair within one country is a bad import and must roll the whole
        // transaction back (map-and-search.md §4.5).
        $dir = sys_get_temp_dir().'/catalog-import-region-overlap-'.getmypid();
        @mkdir($dir, 0777, true);
        $square = static fn (string $slug, array $ring): string => json_encode([
            'type' => 'Feature',
            'properties' => ['slug' => $slug, 'name' => $slug, 'area_km2' => 100, 'country_code' => 'BE'],
            'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [[$ring]]],
        ], \JSON_THROW_ON_ERROR);
        file_put_contents($dir.'/region-a.geojson', $square('overlap-a',
            [[4.0, 50.0], [6.0, 50.0], [6.0, 52.0], [4.0, 52.0], [4.0, 50.0]]));
        file_put_contents($dir.'/region-b.geojson', $square('overlap-b',
            [[5.0, 51.0], [7.0, 51.0], [7.0, 53.0], [5.0, 53.0], [5.0, 51.0]]));

        $tester = $this->runImport($dir);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsStringIgnoringCase('overlap', $tester->getDisplay());
        self::assertNull($this->em->getRepository(Region::class)->findOneBy(['slug' => 'overlap-a']));
        self::assertNull($this->em->getRepository(Region::class)->findOneBy(['slug' => 'overlap-b']));
    }

    public function testAdjacentRegionsWithSubPermilleSliverImport(): void
    {
        // The other half of the tessellation guard: it TOLERATES the sub-permille
        // slivers real adjacent admin boundaries carry (REGION_OVERLAP_TOLERANCE
        // = 0.001). Two same-country regions sharing an edge whose interiors
        // overlap by << 0.1% of the smaller area must IMPORT — only a MEANINGFUL
        // overlap trips (testOverlappingRegionsInSameCountryFail). This pins the
        // real-data gate for importing adjacent Flanders/Wallonia/Brussels
        // boundaries (map-and-search.md §4.5 Phase 2).
        $dir = sys_get_temp_dir().'/catalog-import-region-sliver-'.getmypid();
        @mkdir($dir, 0777, true);
        $square = static fn (string $slug, array $ring): string => json_encode([
            'type' => 'Feature',
            'properties' => ['slug' => $slug, 'name' => $slug, 'area_km2' => 100, 'country_code' => 'BE'],
            'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [[$ring]]],
        ], \JSON_THROW_ON_ERROR);
        // A = [4,50]-[5,51] (1 deg²). B is its eastern neighbour nudged 0.0002°
        // west, so their interiors overlap in a 0.0002 × 1 strip: intersection
        // ≈ 2e-4 deg² vs ≈ 1 deg² → 0.02% << the 0.1% tolerance. ST_Overlaps is
        // TRUE (real 2-D overlap), so this exercises the area-ratio filter, not
        // a no-overlap short-circuit.
        file_put_contents($dir.'/region-sliver-a.geojson', $square('sliver-a',
            [[4.0, 50.0], [5.0, 50.0], [5.0, 51.0], [4.0, 51.0], [4.0, 50.0]]));
        file_put_contents($dir.'/region-sliver-b.geojson', $square('sliver-b',
            [[4.9998, 50.0], [6.0, 50.0], [6.0, 51.0], [4.9998, 51.0], [4.9998, 50.0]]));

        $this->runImport($dir)->assertCommandIsSuccessful();
        self::assertNotNull($this->em->getRepository(Region::class)->findOneBy(['slug' => 'sliver-a']));
        self::assertNotNull($this->em->getRepository(Region::class)->findOneBy(['slug' => 'sliver-b']));
    }

    public function testRealBelgiumRegionsTessellate(): void
    {
        // Real-data gate (map-and-search.md §4.5 Phase 2): the ACTUAL Overture
        // Belgium boundaries (Wallonia/Flanders/Brussels) must pass the
        // tessellation guard through real PostGIS — adjacent admin polygons carry
        // digitisation slivers the tolerance is designed to absorb. Consumes the
        // artifacts from `make divisions-data` (generated, gitignored); skipped
        // when they are absent (e.g. CI). DAMA rolls the import back.
        $out = \dirname(__DIR__, 3).'/tools/divisions/out';
        $files = ['region-wallonia.geojson', 'region-flanders.geojson', 'region-brussels.geojson'];
        foreach ($files as $f) {
            if (!is_file($out.'/'.$f)) {
                self::markTestSkipped("run `make divisions-data` first — {$f} not generated");
            }
        }
        $dir = sys_get_temp_dir().'/catalog-import-be-real-'.getmypid();
        @mkdir($dir, 0777, true);
        foreach ($files as $f) {
            copy($out.'/'.$f, $dir.'/'.$f);
        }

        $this->runImport($dir)->assertCommandIsSuccessful();
        $repo = $this->em->getRepository(Region::class);
        foreach (['wallonia', 'flanders', 'brussels'] as $slug) {
            self::assertNotNull($repo->findOneBy(['slug' => $slug]), "{$slug} imported (tessellation guard passed)");
        }
    }

    public function testMembershipPrefersSmallestAreaRegionOnContainment(): void
    {
        // Nested regions (outer contains inner) are NOT an ST_Overlaps overlap,
        // so the import is accepted — and recomputeMembership must give an item
        // inside both the SMALLER (inner) region, deterministically by area not
        // row order (map-and-search.md §4.5). Files are named so the OUTER
        // (bigger) region imports first and takes the LOWER id: an id-ordered
        // bug would pick it, an area-ordered rule picks the inner.
        $dir = sys_get_temp_dir().'/catalog-import-nested-'.getmypid();
        @mkdir($dir, 0777, true);
        $region = static fn (string $slug, float $area, array $ring): string => json_encode([
            'type' => 'Feature',
            'properties' => ['slug' => $slug, 'name' => $slug, 'area_km2' => $area, 'country_code' => 'BE'],
            'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [[$ring]]],
        ], \JSON_THROW_ON_ERROR);
        file_put_contents($dir.'/region-1-outer.geojson', $region('nest-outer', 400.0,
            [[3.0, 49.0], [7.0, 49.0], [7.0, 53.0], [3.0, 53.0], [3.0, 49.0]]));
        file_put_contents($dir.'/region-2-inner.geojson', $region('nest-inner', 4.0,
            [[4.5, 50.5], [5.5, 50.5], [5.5, 51.5], [4.5, 51.5], [4.5, 50.5]]));
        file_put_contents($dir.'/services.json', json_encode([
            'layer' => 'services', 'letter' => 'D', 'features' => [[
                'type' => 'Feature',
                'properties' => ['t' => 'Bike shop', 'serviceKind' => 'shop', 'n' => 'Nested Shop', 'source' => 'osm', 'ref' => 'node/7777'],
                'geometry' => ['type' => 'Point', 'coordinates' => [5.0, 51.0]],
            ]],
        ], \JSON_THROW_ON_ERROR));

        $this->runImport($dir)->assertCommandIsSuccessful();
        $inner = $this->em->getRepository(Region::class)->findOneBy(['slug' => 'nest-inner']);
        $outer = $this->em->getRepository(Region::class)->findOneBy(['slug' => 'nest-outer']);
        self::assertNotNull($inner);
        self::assertNotNull($outer);
        self::assertLessThan($inner->getId(), $outer->getId()); // outer took the lower id
        $shop = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'node/7777']);
        self::assertNotNull($shop);
        self::assertSame($inner->getId(), $shop->getRegionId()); // smallest-area region wins
    }

    public function testUpdatePreservesState(): void
    {
        $dir = $this->fixturesDir();
        $this->runImport($dir)->assertCommandIsSuccessful();
        $shop = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'node/1001']);
        self::assertNotNull($shop);
        $shop->setState(ItemState::Verified);
        $this->em->flush();
        $this->em->clear();

        $this->runImport($dir)->assertCommandIsSuccessful();
        $shop = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'node/1001']);
        self::assertNotNull($shop);
        self::assertSame(ItemState::Verified, $shop->getState()); // upsert never touches state
    }

    public function testSameSourceRefWithDifferentLettersPersistsBothRows(): void
    {
        // One OSM entity may legitimately carry two classifications (e.g. a
        // heritage site that is also scenic): item identity is
        // (source, source_ref, letter), so both rows persist independently.
        $dir = sys_get_temp_dir().'/catalog-import-dual-'.getmypid();
        @mkdir($dir, 0777, true);
        $feature = static fn (string $t): array => [
            'type' => 'Feature',
            'properties' => ['t' => $t, 'n' => 'Villers Abbey', 'source' => 'osm', 'ref' => 'node/9001'],
            'geometry' => ['type' => 'Point', 'coordinates' => [4.5, 50.6]],
        ];
        file_put_contents($dir.'/history.json', json_encode(
            ['layer' => 'history', 'letter' => 'Q', 'features' => [$feature('Abbey')]],
            \JSON_THROW_ON_ERROR,
        ));
        file_put_contents($dir.'/scenic.json', json_encode(
            ['layer' => 'scenic', 'letter' => 'P', 'features' => [$feature('Viewpoint')]],
            \JSON_THROW_ON_ERROR,
        ));

        $this->runImport($dir)->assertCommandIsSuccessful();
        $rows = $this->em->getRepository(Item::class)->findBy(['sourceRef' => 'node/9001']);
        $letters = array_map(static fn (Item $i): string => $i->getLetter(), $rows);
        sort($letters);
        self::assertSame(['P', 'Q'], $letters);

        // Still two rows (updated, not multiplied) after a second run.
        $this->runImport($dir)->assertCommandIsSuccessful();
        self::assertCount(2, $this->em->getRepository(Item::class)->findBy(['sourceRef' => 'node/9001']));
    }

    /**
     * An imported photo goes through PhotoValidator before it is written: a
     * non-commercial licence, a platform name for a credit, or a scenic photo
     * with no camera point is left off, the row is still imported, and the
     * report names what was dropped.
     */
    public function testImportedPhotosPhotoValidatorRefusesAreNotWritten(): void
    {
        $dir = sys_get_temp_dir().'/catalog-import-photos-'.getmypid();
        @mkdir($dir, 0777, true);
        $photo = static fn (string $licence, string $credit): array => [
            'sm' => 'https://commons.wikimedia.org/wiki/Special:FilePath/X.jpg?width=520',
            'lg' => 'https://commons.wikimedia.org/wiki/Special:FilePath/X.jpg?width=1400',
            'credit' => $credit, 'license' => $licence,
            'source' => 'https://commons.wikimedia.org/wiki/File:X.jpg',
        ];
        $feature = static fn (string $ref, string $name, array $photo): array => [
            'type' => 'Feature',
            'properties' => ['t' => 'Abbey', 'n' => $name, 'source' => 'osm', 'ref' => $ref, 'photo' => $photo],
            'geometry' => ['type' => 'Point', 'coordinates' => [4.5, 50.6 + (int) substr($ref, -1) / 100]],
        ];
        file_put_contents($dir.'/history.json', json_encode(['layer' => 'history', 'letter' => 'Q', 'features' => [
            $feature('node/9101', 'Kept Abbey', $photo('CC BY-SA 4.0', 'Jane Rider')),
            $feature('node/9102', 'NC Abbey', $photo('CC BY-NC-SA 4.0', 'Jane Rider')),
            $feature('node/9103', 'Anonymous Abbey', $photo('CC BY-SA 4.0', 'Wikimedia Commons')),
        ]], \JSON_THROW_ON_ERROR));
        file_put_contents($dir.'/scenic.json', json_encode(['layer' => 'scenic', 'letter' => 'P', 'features' => [
            $feature('node/9104', 'Viewpoint With No Camera', $photo('CC BY-SA 4.0', 'Jane Rider')),
        ]], \JSON_THROW_ON_ERROR));

        $tester = $this->runImport($dir);
        $tester->assertCommandIsSuccessful();

        $attrs = static fn (Item $i): array => $i->getAttributes();
        $repo = $this->em->getRepository(Item::class);
        self::assertArrayHasKey('photo', $attrs($repo->findOneBy(['sourceRef' => 'node/9101']) ?? self::fail('kept row')));
        foreach (['node/9102', 'node/9103', 'node/9104'] as $ref) {
            $item = $repo->findOneBy(['sourceRef' => $ref]);
            self::assertNotNull($item, 'the row is imported');
            self::assertArrayNotHasKey('photo', $item->getAttributes(), $ref);
        }
        $display = preg_replace('~\s+~', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('NC Abbey: licence', $display);
        self::assertStringContainsString('Anonymous Abbey: no_author', $display);
        self::assertStringContainsString('Viewpoint With No Camera: camera_unknown', $display);
    }

    /**
     * A road surface names its Commons photo in four flat keys (`photoFile`,
     * `photoCredit`, `photoUser`, `photoLicense`). The import stores them as
     * the one `photo` entry every other place carries, so the photo passes
     * PhotoValidator here and `app:media:localise-commons` later, and no flat
     * key reaches the drawer to be turned into a hotlink.
     */
    public function testASurfacePhotoFileIsStoredAsAPhotoEntry(): void
    {
        $dir = sys_get_temp_dir().'/catalog-import-surface-photo-'.getmypid();
        @mkdir($dir, 0777, true);
        $segment = static fn (string $ref, string $name, string $licence): array => [
            'type' => 'Feature',
            'properties' => [
                'surface' => 'Asphalt', 'cls' => 'cycleway', 'name' => $name, 'source' => 'osm', 'ref' => $ref,
                'photoFile' => 'Charleroi-ravel.jpg', 'photoCredit' => 'Bronstein', 'photoUser' => 'Bronstein', 'photoLicense' => $licence,
            ],
            'geometry' => ['type' => 'LineString', 'coordinates' => [[4.2, 50.1], [4.3, 50.2]]],
        ];
        file_put_contents($dir.'/surface.json', json_encode(['layer' => 'surface', 'letter' => 'A', 'features' => [
            $segment('way/9201', 'Photo RAVeL', 'CC BY-SA 4.0'),
            $segment('way/9202', 'NC RAVeL', 'CC BY-NC-SA 4.0'),
        ]], \JSON_THROW_ON_ERROR));

        $tester = $this->runImport($dir);
        $tester->assertCommandIsSuccessful();

        $repo = $this->em->getRepository(Item::class);
        $kept = ($repo->findOneBy(['sourceRef' => 'way/9201']) ?? self::fail('kept row'))->getAttributes();
        // jsonb keeps its own key order, so the entry is compared as a map.
        self::assertEquals([
            'sm' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Charleroi-ravel.jpg?width=520',
            'lg' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Charleroi-ravel.jpg?width=1400',
            'credit' => 'Bronstein',
            'creditUrl' => 'https://commons.wikimedia.org/wiki/User:Bronstein',
            'license' => 'CC BY-SA 4.0',
            'source' => 'https://commons.wikimedia.org/wiki/File:Charleroi-ravel.jpg',
        ], $kept['photo'] ?? null);
        foreach (['photoFile', 'photoCredit', 'photoUser', 'photoLicense'] as $flat) {
            self::assertArrayNotHasKey($flat, $kept);
        }

        $refused = ($repo->findOneBy(['sourceRef' => 'way/9202']) ?? self::fail('refused row'))->getAttributes();
        self::assertArrayNotHasKey('photo', $refused);
        self::assertArrayNotHasKey('photoFile', $refused);
        self::assertStringContainsString('NC RAVeL: licence', preg_replace('~\s+~', ' ', $tester->getDisplay()) ?? '');
    }

    public function testUnknownAttributeKeyFails(): void
    {
        $tester = $this->runImport($this->fixturesDir('bad-key'));
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('bogus', $tester->getDisplay());
    }

    public function testWrongGeometryKindFails(): void
    {
        $tester = $this->runImport($this->fixturesDir('bad-geom'));
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('LineString', $tester->getDisplay());
    }

    public function testUnknownSourceFails(): void
    {
        // A drifted/hand-made export with an invalid source (e.g. wrong case
        // "OSM") must not reach the DB: it would insert fine into the varchar
        // column and only blow up later, poisoning every ORM read that
        // hydrates the ItemSource enum.
        $tester = $this->runImport($this->fixturesDir('bad-source'));
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('bad-source.json', $tester->getDisplay());
        self::assertStringContainsString('OSM', $tester->getDisplay());
    }

    public function testMissingRefFails(): void
    {
        $dir = sys_get_temp_dir().'/catalog-import-missing-ref-'.getmypid();
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/services.json', json_encode(
            ['layer' => 'services', 'letter' => 'D', 'features' => [[
                'type' => 'Feature',
                'properties' => ['t' => 'Bike shop', 'source' => 'osm'], // no 'ref'
                'geometry' => ['type' => 'Point', 'coordinates' => [4.4, 50.7]],
            ]]],
            \JSON_THROW_ON_ERROR,
        ));

        $tester = $this->runImport($dir);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('services.json', $tester->getDisplay());
    }

    /**
     * BaseLocationService::rederiveAll() runs inside the import transaction,
     * right after recomputeMembership() (map-and-search.md §4.5): a
     * rider whose base point sits inside a freshly-imported region must come
     * out of the very same `app:catalog:import` run with that region in their
     * derived set, proving the hook actually fires in the import flow.
     */
    public function testImportRederivesRiderBaseAreas(): void
    {
        $user = (new User())->setEmail('base-import-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('x');
        // region-square.geojson covers lng [4.0, 5.0] x lat [50.0, 51.0].
        $user->setBaseLocation(50.5, 4.5, null);
        $this->em->persist($user);
        $this->em->flush();

        $tester = $this->runImport($this->fixturesDir());
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Re-derived base areas for 1 rider', $tester->getDisplay());

        $region = $this->em->getRepository(Region::class)->findOneBy(['slug' => 'test-square']);
        self::assertNotNull($region);
        $this->em->refresh($user);
        self::assertSame([$region->getId()], $user->getBaseRegionIds());
        self::assertSame(['BE'], $user->getBaseCountryCodes());
    }

    public function testSubdivisionResolvedWhenWorldDataPresent(): void
    {
        // The World bundle (app:world:import) seeds real reference data outside
        // any test transaction, so BE / BE-WBR may already exist in this DB —
        // reuse-or-create (same pattern as ImportWorldDataCommand) keeps this
        // test correct whether run against a fresh DB or an already-seeded one.
        $country = $this->em->getRepository(Country::class)->findOneBy(['iso2' => 'BE'])
            ?? (new Country())->setIso2('BE')->setIso3('BEL')->setName('Belgium');
        $this->em->persist($country);
        $sub = $this->em->getRepository(Subdivision::class)->findOneBy(['code' => 'BE-WBR'])
            ?? (new Subdivision())->setCode('BE-WBR')->setName('Brabant wallon')->setCountry($country);
        $this->em->persist($sub);
        $this->em->flush();

        $this->runImport($this->fixturesDir())->assertCommandIsSuccessful();
        $shop = $this->em->getRepository(Item::class)->findOneBy(['sourceRef' => 'node/1001']);
        self::assertNotNull($shop);
        self::assertSame($sub->getId(), $shop->getSubdivisionId()); // prov "Brabant wallon" -> BE-WBR
    }

    public function testImportComputesSymmetricAdjacency(): void
    {
        // Two edge-sharing squares (adjacent) + one distant square (not). After
        // import each region's adj must list exactly its border-neighbours, both
        // directions.
        $dir = sys_get_temp_dir().'/catalog-import-region-adj-'.getmypid();
        @mkdir($dir, 0777, true);
        $square = static fn (string $slug, array $ring): string => json_encode([
            'type' => 'Feature',
            'properties' => ['slug' => $slug, 'name' => $slug, 'area_km2' => 100, 'country_code' => 'BE'],
            'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [[$ring]]],
        ], \JSON_THROW_ON_ERROR);
        // west = [4,50]-[5,51]; east shares the x=5 edge = [5,50]-[6,51];
        // far is disjoint = [10,50]-[11,51].
        file_put_contents($dir.'/region-adj-west.geojson', $square('adj-west',
            [[4.0, 50.0], [5.0, 50.0], [5.0, 51.0], [4.0, 51.0], [4.0, 50.0]]));
        file_put_contents($dir.'/region-adj-east.geojson', $square('adj-east',
            [[5.0, 50.0], [6.0, 50.0], [6.0, 51.0], [5.0, 51.0], [5.0, 50.0]]));
        file_put_contents($dir.'/region-adj-far.geojson', $square('adj-far',
            [[10.0, 50.0], [11.0, 50.0], [11.0, 51.0], [10.0, 51.0], [10.0, 50.0]]));

        $this->runImport($dir)->assertCommandIsSuccessful();

        $db = $this->em->getConnection();
        $adj = static function (string $slug) use ($db): array {
            $raw = $db->fetchOne('SELECT adj FROM region WHERE slug = :s', ['s' => $slug]);
            if (null === $raw) {
                return [];
            }

            return array_map('intval', $db->fetchFirstColumn(
                'SELECT unnest(adj) FROM region WHERE slug = :s', ['s' => $slug]
            ));
        };
        $id = static fn (string $slug): int => (int) $db->fetchOne(
            'SELECT id FROM region WHERE slug = :s', ['s' => $slug]);

        self::assertSame([$id('adj-east')], $adj('adj-west'), 'west borders only east');
        self::assertSame([$id('adj-west')], $adj('adj-east'), 'east borders only west (symmetric)');
        self::assertSame([], $adj('adj-far'), 'the distant square borders nothing — empty array, not NULL');
    }

    public function testL2CountryOutlineOverlappingItsL4ChildImports(): void
    {
        // The 2+4 playbook imports a country's level-2 outline ALONGSIDE its
        // level-4 subdivisions. Overture's L2 and L4 polygons carry independent
        // digitisation, so a coastal L4 child can stick a sliver outside its L2
        // parent — ST_Overlaps TRUE with a near-total overlap area. Tessellation
        // is a SAME-LEVEL invariant: cross-level pairs are expected containment,
        // resolved by smallest-area-wins membership.
        $dir = sys_get_temp_dir().'/catalog-import-region-l2l4-'.getmypid();
        @mkdir($dir, 0777, true);
        $feature = static fn (string $slug, int $level, array $ring): string => json_encode([
            'type' => 'Feature',
            'properties' => ['slug' => $slug, 'name' => $slug, 'area_km2' => 100, 'country_code' => 'BE', 'admin_level' => $level],
            'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [[$ring]]],
        ], \JSON_THROW_ON_ERROR);
        // L2 parent [4,50]-[6,52]; L4 child mostly inside but poking 0.5° east
        // past the parent edge — a MEANINGFUL cross-level overlap (~75% of the
        // child), which the old whole-country guard would reject.
        file_put_contents($dir.'/region-l2-parent.geojson', $feature('l2-parent', 2,
            [[4.0, 50.0], [6.0, 50.0], [6.0, 52.0], [4.0, 52.0], [4.0, 50.0]]));
        file_put_contents($dir.'/region-l4-child.geojson', $feature('l4-child', 4,
            [[4.5, 50.5], [6.5, 50.5], [6.5, 51.5], [4.5, 51.5], [4.5, 50.5]]));

        $this->runImport($dir)->assertCommandIsSuccessful();
        self::assertNotNull($this->em->getRepository(Region::class)->findOneBy(['slug' => 'l2-parent']));
        self::assertNotNull($this->em->getRepository(Region::class)->findOneBy(['slug' => 'l4-child']));
    }
}
