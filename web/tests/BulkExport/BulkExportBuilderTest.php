<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\BulkExport;

use App\BulkExport\BulkExportBuilder;
use App\BulkExport\BulkExportBusy;
use App\BulkExport\BulkExportCatalog;
use App\BulkExport\BulkExportStorage;
use App\Catalog\ItemEvidenceResolver;
use App\Entity\User;
use App\Tests\Translation\RecordingLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToWriteFile;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The periodic bulk export (docs/specs/api-strategy.md §3.1): one consistent
 * snapshot of the open catalogue, with nothing personal in it, nothing a
 * curator has not let onto the map, and nothing whose licence does not let us
 * pass it on under the ODbL.
 */
final class BulkExportBuilderTest extends KernelTestCase
{
    /** Exactly what a place carries: the public API's item, plus where it came from. */
    private const array PLACE_KEYS = [
        'id', 'letter', 'name', 'tier', 'grade', 'custody', 'confirmations', 'last_confirmed',
        'last_seen_upstream', 'verified_by', 'region_id', 'country', 'osm_ref', 'source',
    ];

    private const array ROUTE_KEYS = ['id', 'letter', 'name', 'tier', 'distance_m', 'ascent_m', 'region_id', 'source'];

    /** Strings that only exist in the account layer, moderation internals or unapplied edits. */
    private const array SECRETS = [
        'export-secret@example.com',
        'Secret Rider Name',
        'Secret curator note',
        'Secret route note',
        'Pending Secret Name',
        'Submitted Secret Place',
        'Trashed Secret Place',
        'Trashed Secret Route',
        'proposed_by',
        'changed_by',
        'user_id',
        'display_name',
    ];

    public function testTheSnapshotHoldsOnlyServedRedistributableRowsAndNothingPersonal(): void
    {
        self::bootKernel();
        $ids = $this->seed();

        $manifest = $this->builder()->build(new \DateTimeImmutable('2026-10-09 13:39:00 UTC'));

        $places = $this->collection($manifest['snapshot'], 'places.geojson.gz');
        $byId = [];
        foreach ($places['features'] as $feature) {
            $byId[(int) $feature['properties']['id']] = $feature;
        }

        $included = ['ours', 'scout', 'osmEdited', 'wikidata', 'pivot', 'pendingEdit'];
        $excluded = ['submitted', 'rejected', 'retired', 'trashed', 'gone', 'osmUntouched', 'shareAlike', 'orphanAuthority'];
        foreach ($included as $key) {
            self::assertArrayHasKey($ids[$key], $byId, "{$key} is served and redistributable, so it is in the export");
        }
        foreach ($excluded as $key) {
            self::assertArrayNotHasKey($ids[$key], $byId, "{$key} must stay out of the export");
        }

        foreach ($byId as $feature) {
            self::assertSame(self::PLACE_KEYS, array_keys($feature['properties']), 'the property list is closed: it is the personal-data boundary');
        }

        // The row as approved, never the edit still waiting for a curator.
        self::assertSame('Approved Name', $byId[$ids['pendingEdit']]['properties']['name']);

        self::assertSame('cycling-commons', $byId[$ids['ours']]['properties']['source']);
        self::assertSame('osm', $byId[$ids['osmEdited']]['properties']['source']);
        self::assertSame('node/4207030426', $byId[$ids['osmEdited']]['properties']['osm_ref']);
        self::assertSame('wikidata', $byId[$ids['wikidata']]['properties']['source']);
        self::assertSame('wallonie-pivot', $byId[$ids['pivot']]['properties']['source']);
        self::assertSame('BE', $byId[$ids['ours']]['properties']['country']);
        self::assertSame('Point', $byId[$ids['ours']]['geometry']['type']);

        $routes = $this->collection($manifest['snapshot'], 'routes.geojson.gz');
        $routeIds = array_map(static fn (array $f): int => (int) $f['properties']['id'], $routes['features']);
        self::assertContains($ids['route'], $routeIds);
        self::assertNotContains($ids['routeTrashed'], $routeIds);
        self::assertNotContains($ids['routeSubmitted'], $routeIds);
        foreach ($routes['features'] as $feature) {
            self::assertSame(self::ROUTE_KEYS, array_keys($feature['properties']));
            self::assertSame('LineString', $feature['geometry']['type'], 'a route travels as its whole line');
        }

        $raw = $this->raw($manifest['snapshot'], 'places.geojson.gz')
            .$this->raw($manifest['snapshot'], 'routes.geojson.gz')
            .$this->storage()->read($manifest['snapshot'].'/manifest.json');
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $raw, "the export must never carry \"{$secret}\"");
        }

        foreach ([$places, $routes] as $collection) {
            self::assertSame('FeatureCollection', $collection['type']);
            self::assertSame('ODbL-1.0', $collection['licence']);
            self::assertStringContainsString('OpenStreetMap contributors', $collection['attribution']);
        }
    }

    public function testTheManifestDescribesEveryFileAndEverySourceExactly(): void
    {
        self::bootKernel();
        $this->seed();

        $manifest = $this->builder()->build(new \DateTimeImmutable('2026-10-09 13:39:00 UTC'));

        self::assertSame('20261009T133900Z', $manifest['snapshot']);
        self::assertSame('2026-10-09T13:39:00Z', $manifest['generated_at']);
        self::assertSame('ODbL-1.0', $manifest['licence']['id']);
        self::assertSame('DbCL-1.0', $manifest['licence']['contents']);

        $files = [];
        foreach ($manifest['files'] as $file) {
            $files[$file['name']] = $file;
            $bytes = $this->storage()->read($manifest['snapshot'].'/'.$file['name']);
            self::assertSame(\strlen($bytes), $file['bytes'], "{$file['name']}: size");
            self::assertSame(hash('sha256', $bytes), $file['sha256'], "{$file['name']}: checksum");
            self::assertSame('application/gzip', $file['content_type']);
            $collection = $this->collection($manifest['snapshot'], $file['name']);
            self::assertCount($file['features'], $collection['features'], "{$file['name']}: feature count");
        }
        self::assertSame(['places.geojson.gz', 'routes.geojson.gz'], array_keys($files));

        $sources = [];
        foreach ($manifest['sources'] as $source) {
            $sources[$source['key']] = $source;
        }
        self::assertSame(3, $sources['cycling-commons']['places']);
        self::assertSame(1, $sources['cycling-commons']['routes']);
        self::assertSame('odbl', $sources['cycling-commons']['licence_code']);
        self::assertSame(1, $sources['osm']['places']);
        self::assertSame('odbl', $sources['osm']['licence_code']);
        self::assertStringContainsString('OpenStreetMap contributors', (string) $sources['osm']['attribution']);
        self::assertSame(1, $sources['wikidata']['places']);
        self::assertSame('cc0-1.0', $sources['wikidata']['licence_code']);
        self::assertSame(1, $sources['wallonie-pivot']['places']);
        self::assertSame('cc-by-4.0', $sources['wallonie-pivot']['licence_code']);
        self::assertNotEmpty($sources['wallonie-pivot']['attribution'], 'CC BY travels with its attribution');
        self::assertArrayNotHasKey('wikipedia', $sources);

        $placesTotal = array_sum(array_map(static fn (array $s): int => $s['places'], $manifest['sources']));
        self::assertSame($files['places.geojson.gz']['features'], $placesTotal, 'every place is counted under exactly one source');

        $leftOut = [];
        foreach ($manifest['left_out'] as $row) {
            $leftOut[$row['key']] = $row;
        }
        self::assertSame(1, $leftOut['wikipedia']['places']);
        self::assertSame('cc-by-sa-4.0', $leftOut['wikipedia']['licence_code']);
        self::assertSame(1, $leftOut['unknown']['places'], 'a registry row that is gone leaves its rows with no licence, so they stay out');

        $latest = json_decode($this->storage()->read('latest.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($manifest, $latest, 'the latest pointer is the manifest of the newest snapshot');
        self::assertSame(
            $manifest,
            json_decode($this->storage()->read($manifest['snapshot'].'/manifest.json'), true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    public function testOnlyTheNewestSnapshotsAreKept(): void
    {
        self::bootKernel();
        $builder = $this->builder();
        $stamps = [];
        for ($day = 1; $day <= BulkExportBuilder::KEEP + 2; ++$day) {
            $stamps[] = $builder->build(new \DateTimeImmutable(sprintf('2026-09-%02d 03:00:00 UTC', $day)))['snapshot'];
        }

        self::assertSame(\array_slice($stamps, -BulkExportBuilder::KEEP), $this->storage()->snapshots());
        foreach (\array_slice($stamps, 0, 2) as $gone) {
            self::assertFalse($this->storage()->has($gone.'/places.geojson.gz'), "{$gone} is past the retention and deleted");
        }
        $latest = json_decode($this->storage()->read('latest.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(end($stamps), $latest['snapshot']);
    }

    public function testEveryFileCreditsEverySourceItHolds(): void
    {
        self::bootKernel();
        $this->seed();

        $manifest = $this->builder()->build(new \DateTimeImmutable('2026-10-09 13:39:00 UTC'));

        $places = $this->collection($manifest['snapshot'], 'places.geojson.gz');
        self::assertStringStartsWith(BulkExportBuilder::ATTRIBUTION, $places['attribution']);
        $sources = [];
        foreach ($manifest['sources'] as $source) {
            $sources[$source['key']] = $source;
        }
        foreach (['cycling-commons', 'osm', 'wallonie-pivot'] as $key) {
            self::assertGreaterThan(0, $sources[$key]['places']);
            self::assertStringContainsString((string) $sources[$key]['attribution'], $places['attribution'], "{$key} travels in the places file, so its credit is in the file");
        }
        self::assertStringContainsString('Tourisme Wallonie (CC-BY)', $places['attribution'], 'CC BY travels with its credit, in the file itself');

        // The routes file holds only our own rows, and credits only them.
        $routes = $this->collection($manifest['snapshot'], 'routes.geojson.gz');
        self::assertStringStartsWith(BulkExportBuilder::ATTRIBUTION, $routes['attribution']);
        self::assertStringContainsString('© Cycling Commons contributors', $routes['attribution']);
        self::assertStringNotContainsString('Tourisme Wallonie', $routes['attribution']);
    }

    public function testAFailedBuildLeavesNoHalfSnapshotBehind(): void
    {
        self::bootKernel();
        [$builder, $spy, $storage] = $this->spiedBuilder();
        $good = $builder->build(new \DateTimeImmutable('2026-10-02 05:07:00 UTC'));
        $spy->failWhen = static function (string $operation, string $path): void {
            if ('write' === $operation && str_ends_with($path, '/manifest.json')) {
                throw UnableToWriteFile::atLocation($path, 'storage went away');
            }
        };

        $failure = null;
        try {
            $builder->build(new \DateTimeImmutable('2026-10-09 05:07:00 UTC'));
        } catch (\Throwable $e) {
            $failure = $e;
        }
        self::assertInstanceOf(UnableToWriteFile::class, $failure, 'a build whose manifest cannot be written fails, with the storage error');

        $spy->failWhen = null;
        self::assertSame([$good['snapshot']], $storage->snapshots(), 'the files of the failed build are gone');
        self::assertFalse($storage->has('20261009T050700Z/places.geojson.gz'));
        $latest = json_decode($storage->read('latest.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($good['snapshot'], $latest['snapshot'], 'the latest pointer still names the last good snapshot');
    }

    public function testTheExportTouchesNothingOutsideItsFolder(): void
    {
        self::bootKernel();
        [$builder, $spy] = $this->spiedBuilder();
        // The bucket is shared: the map's tiles, and anything else beside the export.
        $foreign = [
            'coverage/manifest.json' => '{}',
            'coverage/be/20261001-0300/osm.pmtiles' => 'tiles',
            'latest.json' => '{"snapshot":"20200101T000000Z"}',
            'snapshots/20260801T010000Z/places.geojson.gz' => 'not the export',
            'snapshots/20260801T010000Z/manifest.json' => '{}',
            'exportsx/latest.json' => 'a neighbour',
        ];
        foreach ($foreign as $path => $contents) {
            $spy->write($path, $contents, new Config());
        }
        $spy->calls = [];

        // Enough builds to prune, then one that fails and cleans up after itself.
        for ($day = 1; $day <= BulkExportBuilder::KEEP + 2; ++$day) {
            $builder->build(new \DateTimeImmutable(sprintf('2026-09-%02d 03:00:00 UTC', $day)));
        }
        $spy->failWhen = static function (string $operation, string $path): void {
            if ('write' === $operation && str_ends_with($path, '/manifest.json')) {
                throw UnableToWriteFile::atLocation($path, 'storage went away');
            }
        };
        try {
            $builder->build(new \DateTimeImmutable('2026-09-10 03:00:00 UTC'));
            self::fail('the build whose manifest cannot be written fails');
        } catch (UnableToWriteFile) {
        }
        $spy->failWhen = null;
        $calls = $spy->calls;

        foreach ($calls as $call) {
            self::assertStringStartsWith('exports/', explode(' ', $call, 2)[1], $call);
        }
        $operations = array_unique(array_map(static fn (string $call): string => explode(' ', $call, 2)[0], $calls));
        foreach (['listContents', 'fileExists', 'write', 'writeStream', 'deleteDirectory'] as $operation) {
            self::assertContains($operation, $operations, "the builds above {$operation}");
        }
        foreach ($foreign as $path => $contents) {
            self::assertSame($contents, $spy->read($path), "{$path} is not the export's and stays as it was");
        }
        self::assertTrue($spy->fileExists('exports/latest.json'));
        self::assertTrue($spy->fileExists('exports/snapshots/20260906T030000Z/manifest.json'));
        self::assertTrue($spy->fileExists('exports/snapshots/20260906T030000Z/places.geojson.gz'));
        self::assertTrue($spy->fileExists('exports/snapshots/20260906T030000Z/routes.geojson.gz'));
        self::assertFalse($spy->fileExists('exports/snapshots/20260901T030000Z/places.geojson.gz'), 'pruned');
        self::assertFalse($spy->fileExists('exports/snapshots/20260910T030000Z/places.geojson.gz'), 'cleaned up');
    }

    public function testEveryObjectCarriesTheHeadersNginxPassesThrough(): void
    {
        self::bootKernel();
        [$builder, $spy] = $this->spiedBuilder();

        $stamp = $builder->build(new \DateTimeImmutable('2026-10-09 13:39:00 UTC'))['snapshot'];

        // nginx serves the snapshot files straight from the bucket and sends
        // these headers as they are; the gzip file is the payload, never a
        // transfer encoding.
        $snapshot = 'exports/snapshots/'.$stamp.'/';
        $immutable = 'public, max-age=604800, immutable';
        self::assertSame([
            $snapshot.'places.geojson.gz' => ['ContentType' => 'application/gzip', 'CacheControl' => $immutable, 'ContentDisposition' => 'attachment; filename="cycling-commons-places-20261009T133900Z.geojson.gz"'],
            $snapshot.'routes.geojson.gz' => ['ContentType' => 'application/gzip', 'CacheControl' => $immutable, 'ContentDisposition' => 'attachment; filename="cycling-commons-routes-20261009T133900Z.geojson.gz"'],
            $snapshot.'manifest.json' => ['ContentType' => 'application/json', 'CacheControl' => $immutable],
            'exports/latest.json' => ['ContentType' => 'application/json', 'CacheControl' => 'no-cache'],
        ], $spy->metadata);
    }

    public function testPruneKeepsTheNewestSnapshotsWithAManifestAndClearsOlderLeftovers(): void
    {
        self::bootKernel();
        [$builder, , $storage] = $this->spiedBuilder();
        // Leftovers of builds that died before their manifest, between good ones.
        $storage->write('20260901T010000Z/places.geojson.gz', 'partial');
        $good = [];
        for ($day = 2; $day <= 6; ++$day) {
            $storage->write(sprintf('202609%02dT010000Z/places.geojson.gz', $day), 'partial');
            $good[] = $builder->build(new \DateTimeImmutable(sprintf('2026-09-%02d 03:00:00 UTC', $day)))['snapshot'];
        }

        self::assertSame(\array_slice($good, -BulkExportBuilder::KEEP), $storage->snapshots(), 'four good snapshots kept, no leftover counted among them or kept');
    }

    public function testAPruneFailureIsAWarningAndTheSnapshotIsStillPublished(): void
    {
        self::bootKernel();
        [$builder, $spy, $storage, $catalog, $logger] = $this->spiedBuilder();
        for ($day = 1; $day <= BulkExportBuilder::KEEP; ++$day) {
            $builder->build(new \DateTimeImmutable(sprintf('2026-09-%02d 03:00:00 UTC', $day)));
        }
        // The site has read the newest manifest, so a stale cache would hide the new snapshot.
        self::assertSame('20260904T030000Z', $catalog->latest()['snapshot'] ?? null);
        $spy->failWhen = static function (string $operation, string $path): void {
            if ('deleteDirectory' === $operation) {
                throw UnableToDeleteDirectory::atLocation($path, 'storage went away');
            }
        };

        $manifest = $builder->build(new \DateTimeImmutable('2026-09-08 03:00:00 UTC'));

        self::assertSame('20260908T030000Z', $manifest['snapshot']);
        $latest = $catalog->latest();
        self::assertNotNull($latest);
        self::assertSame('20260908T030000Z', $latest['snapshot'], 'the site sees the new snapshot although pruning failed');
        self::assertTrue($logger->hasWarningThatContains('storage went away'));
        self::assertSame([], array_values(array_filter($logger->records, static fn (array $r): bool => \in_array($r['level'], ['error', 'critical'], true))));
        self::assertCount(BulkExportBuilder::KEEP + 1, $storage->snapshots(), 'the next build prunes what this one could not');
    }

    public function testASecondBuildWhileOneRunsIsRefused(): void
    {
        self::bootKernel();
        [$builder, $spy] = $this->spiedBuilder();
        $other = DriverManager::getConnection($this->db()->getParams());
        try {
            self::assertSame(1, (int) $other->fetchOne('SELECT CASE WHEN pg_try_advisory_lock(hashtext(:k)) THEN 1 ELSE 0 END', ['k' => BulkExportBuilder::LOCK]));
            try {
                $builder->build(new \DateTimeImmutable('2026-10-09 05:07:00 UTC'));
                self::fail('a build while another runs is refused');
            } catch (BulkExportBusy) {
            }
            self::assertSame([], array_values(array_filter($spy->calls, static fn (string $c): bool => !str_starts_with($c, 'fileExists') && !str_starts_with($c, 'listContents'))), 'the refused build wrote nothing');
        } finally {
            $other->close();
        }

        // Once the other build is done, the next one runs.
        self::assertSame('20261009T050700Z', $builder->build(new \DateTimeImmutable('2026-10-09 05:07:00 UTC'))['snapshot']);
    }

    public function testAPublishedSnapshotIsNeverOverwritten(): void
    {
        self::bootKernel();
        [$builder, , $storage] = $this->spiedBuilder();
        $first = $builder->build(new \DateTimeImmutable('2026-10-09 05:07:00 UTC'));
        $bytes = $storage->read($first['snapshot'].'/manifest.json');

        try {
            $builder->build(new \DateTimeImmutable('2026-10-09 05:07:00 UTC'));
            self::fail('a second build in the same second would change an immutable snapshot');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($first['snapshot'], $e->getMessage());
        }
        self::assertSame($bytes, $storage->read($first['snapshot'].'/manifest.json'));
        self::assertSame([$first['snapshot']], $storage->snapshots());
    }

    /** @return array{BulkExportBuilder, SpyStorageAdapter, BulkExportStorage, BulkExportCatalog, RecordingLogger} */
    private function spiedBuilder(): array
    {
        $spy = new SpyStorageAdapter();
        $storage = new BulkExportStorage(null, 'test-data-export', new Filesystem($spy));
        $logger = new RecordingLogger();
        $catalog = new BulkExportCatalog($storage, new ArrayAdapter(), $logger);
        $builder = new BulkExportBuilder($this->db(), static::getContainer()->get(ItemEvidenceResolver::class), $storage, $catalog, $logger);

        return [$builder, $spy, $storage, $catalog, $logger];
    }

    /** @return array<string, int> */
    private function seed(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('export-secret@example.com')
            ->setDisplayName('Secret Rider Name');
        $user->setPassword('not-a-hash');
        $user->setRoles([]);
        $em->persist($user);
        $em->flush();
        $uid = (int) $user->getId();

        $db = $this->db();
        $provider = static fn (string $key): int => (int) $db->fetchOne('SELECT id FROM data_provider WHERE provider_key = :k', ['k' => $key]);

        $ids = [
            'ours' => $this->item('D', 'Own Verified Tap', 'verified', 'user', ['note' => 'Secret curator note']),
            'scout' => $this->item('P', 'Scout Parking', 'unverified', 'scout'),
            'submitted' => $this->item('D', 'Submitted Secret Place', 'submitted', 'user'),
            'rejected' => $this->item('B', 'Rejected Place', 'rejected', 'osm'),
            'retired' => $this->item('F', 'Retired Place', 'retired', 'manual'),
            'trashed' => $this->item('D', 'Trashed Secret Place', 'trashed', 'user'),
            'gone' => $this->item('G', 'Gone Place', 'verified', 'manual', ['condition' => 'Not there anymore']),
            'osmUntouched' => $this->item('B', 'Untouched OSM Tap', 'unverified', 'osm'),
            'osmEdited' => $this->item('B', 'Edited OSM Tap', 'verified', 'osm', [], 'node/4207030426'),
            'wikidata' => $this->item('N', 'Wikidata Climb', 'unverified', 'wikidata'),
            'pivot' => $this->item('O', 'Registry Stay', 'unverified', 'authority', [], null, $provider('wallonie-pivot')),
            'shareAlike' => $this->item('O', 'Share-alike Stay', 'unverified', 'authority', [], null, $provider('wikipedia')),
            'orphanAuthority' => $this->item('O', 'Orphan Stay', 'unverified', 'authority'),
            'pendingEdit' => $this->item('D', 'Approved Name', 'verified', 'manual'),
        ];

        // A rider's trail on the rows: history, a confirmation, an edit still waiting.
        $db->executeStatement(
            "INSERT INTO change_history (item_id, field, old_value, new_value, changed_by, changed_at) VALUES (:i, 'name', '\"Old\"', '\"Own Verified Tap\"', :u, NOW())",
            ['i' => $ids['ours'], 'u' => $uid],
        );
        $db->executeStatement(
            "INSERT INTO item_confirmation (item_id, user_id, stance, source, created_at, updated_at) VALUES (:i, :u, 'exists', 'drawer', NOW(), NOW())",
            ['i' => $ids['scout'], 'u' => $uid],
        );
        $db->executeStatement(
            "INSERT INTO submission (type, letter, item_id, user_id, status, title, geom, country_code, changes, payload, created_at)
             VALUES ('edit', 'D', :i, :u, 'pending', 'Pending Secret Name', ST_SetSRID(ST_MakePoint(5.5, 50.5), 4326), 'BE',
                     '{\"name\": {\"was\": \"Approved Name\", \"now\": \"Pending Secret Name\"}}', '{\"name\": \"Pending Secret Name\"}', NOW())",
            ['i' => $ids['pendingEdit'], 'u' => $uid],
        );

        $ids['route'] = $this->route('Rider Loop', 'unverified', $uid, ['note' => 'Secret route note']);
        $ids['routeTrashed'] = $this->route('Trashed Secret Route', 'trashed', $uid);
        $ids['routeSubmitted'] = $this->route('Submitted Route', 'submitted', $uid);

        return $ids;
    }

    /** @param array<string, mixed> $attributes */
    private function item(string $letter, string $name, string $state, string $source, array $attributes = [], ?string $osmRef = null, ?int $providerId = null): int
    {
        static $n = 0;
        ++$n;

        return (int) $this->db()->fetchOne(
            'INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, osm_ref, provider_id, created_at, updated_at)
             VALUES (:letter, :name, ST_SetSRID(ST_MakePoint(:lon, 50.5), 4326), \'BE\', :state, :source, :ref, :attrs, :osm, :provider, NOW(), NOW())
             RETURNING id',
            [
                'letter' => $letter,
                'name' => $name,
                'lon' => 5.0 + $n / 100,
                'state' => $state,
                'source' => $source,
                'ref' => 'test:bulk-export:'.$n,
                'attrs' => json_encode((object) $attributes, \JSON_THROW_ON_ERROR),
                'osm' => $osmRef,
                'provider' => $providerId,
            ],
        );
    }

    /** @param array<string, mixed> $attributes */
    private function route(string $name, string $state, int $proposedBy, array $attributes = []): int
    {
        static $n = 0;
        ++$n;

        return (int) $this->db()->fetchOne(
            "INSERT INTO recommended_route (name, geom, distance_m, ascent_m, state, source, source_ref, attributes, proposed_by, trashed_at, created_at, updated_at)
             VALUES (:name, ST_GeomFromText('LINESTRING(5.1 50.1, 5.2 50.2, 5.3 50.25)', 4326), 21000, 340, :state, 'user', :ref, :attrs, :by,
                     CASE WHEN :state = 'trashed' THEN NOW() END, NOW(), NOW())
             RETURNING id",
            [
                'name' => $name,
                'state' => $state,
                'ref' => 'test:bulk-export-route:'.$n,
                'attrs' => json_encode((object) $attributes, \JSON_THROW_ON_ERROR),
                'by' => $proposedBy,
            ],
        );
    }

    /** @return array{type: string, licence: string, attribution: string, features: list<array{geometry: array<string, mixed>, properties: array<string, mixed>}>} */
    private function collection(string $snapshot, string $file): array
    {
        /* @var array{type: string, licence: string, attribution: string, features: list<array{geometry: array<string, mixed>, properties: array<string, mixed>}>} */
        return json_decode($this->raw($snapshot, $file), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function raw(string $snapshot, string $file): string
    {
        $plain = gzdecode($this->storage()->read($snapshot.'/'.$file));
        self::assertIsString($plain, "{$file} is gzip");

        return $plain;
    }

    private function builder(): BulkExportBuilder
    {
        return static::getContainer()->get(BulkExportBuilder::class);
    }

    private function storage(): BulkExportStorage
    {
        return static::getContainer()->get(BulkExportStorage::class);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
