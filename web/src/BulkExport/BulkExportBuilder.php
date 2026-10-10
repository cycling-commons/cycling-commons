<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\BulkExport;

use App\Api\V1\Dto\ItemFeature;
use App\Catalog\CoverageRetirement;
use App\Catalog\GoneRows;
use App\Catalog\ItemEvidenceResolver;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;

/**
 * Builds one snapshot of the open catalogue and publishes it
 * (docs/specs/api-strategy.md §3.1).
 *
 * What goes in is what the public API serves (docs/specs/public-api.md §2.2):
 * the served item rows, minus untouched OSM coverage rows and places reported
 * gone, and the served recommended routes. Each place carries exactly the
 * properties of the API's {@see ItemFeature}, plus `country`, `osm_ref` and
 * `source`; each route its whole line with the properties of the API's route
 * hit, plus `source`. Nothing is read from the account layer and no
 * contributor is joined (docs/specs/public-api-personal-data-boundary.md
 * §1.5): no user id, no name of a person, no attributes, no moderation state.
 *
 * Every row is attributed to one source: `cycling-commons` for our own rows,
 * `osm` and `wikidata` for the mirrored copies, and the registry key of the
 * provider for an authority row. A row whose source's licence does not let
 * it travel under the ODbL ({@see BulkExportLicences}), or whose licence is
 * unknown, is left out and counted in the manifest's `left_out`.
 *
 * Each file's top-level `attribution` is the dataset string followed by the
 * credit of every source that has a row in that file, so a CC BY credit
 * travels inside the file and not only in the manifest.
 *
 * All reads run in one read-only, repeatable-read transaction, so the two
 * files and their counts describe the same moment. The files are written to
 * storage first and `latest.json` last, so a reader never finds a pointer to
 * a half-written snapshot; a build that fails on the way deletes what it
 * wrote. The newest {@see KEEP} snapshots that hold a manifest are kept. One
 * build runs at a time, on a Postgres advisory lock ({@see LOCK}): the
 * database is the one thing every host that could start a build shares.
 *
 * @phpstan-type ExportFile array{name: string, content_type: string, features: int, bytes: int, sha256: string}
 * @phpstan-type ExportSource array{key: string, name: string, licence: string, licence_code: string, attribution: string|null, homepage: string|null, places: int, routes: int}
 * @phpstan-type LeftOut array{key: string, name: string|null, licence_code: string|null, reason: string, places: int, routes: int}
 * @phpstan-type Manifest array{format: int, snapshot: string, generated_at: string, licence: array{id: string, name: string, url: string, contents: string, contents_url: string}, attribution: string, sources: list<ExportSource>, left_out: list<LeftOut>, files: list<ExportFile>}
 *
 * @api
 */
final class BulkExportBuilder
{
    /** How many snapshots stay in storage: four weekly builds, a month to fall back on. */
    public const int KEEP = BulkExportRetention::RECENT;

    public const string PLACES = 'places.geojson.gz';
    public const string ROUTES = 'routes.geojson.gz';
    public const string MANIFEST = 'manifest.json';

    /** Bumped when a field's meaning changes or a field goes; a new field does not bump it. */
    public const int FORMAT = 1;

    /** The required attribution, word for word the string on /developers. */
    public const string ATTRIBUTION = 'Contains data from the Cycling Commons © contributors and © OpenStreetMap contributors, under the Open Database License.';

    /** The source every row of our own is filed under. */
    public const string OURS = 'cycling-commons';

    /** Name of the advisory lock one build holds, hashed to a key by Postgres. */
    public const string LOCK = 'cycling-commons:bulk-export:build';

    /** Sources a person or our own tooling made, all ours under the ODbL. */
    private const array OWN_SOURCES = [ItemSource::User, ItemSource::Scout, ItemSource::Manual, ItemSource::Auto];

    /** Mirrored copies, licensed by the registry row of the same key. */
    private const array MIRRORED_SOURCES = [ItemSource::Osm, ItemSource::Wikidata];

    /** Rows read per query; keyset pages keep memory flat on any catalogue size. */
    private const int BATCH = 2000;

    /** Six decimals is about ten centimetres, finer than any pin is placed. */
    private const int PRECISION = 6;

    /** @var array<string, array{key: string, name: string, licence: string, licence_code: string, attribution: string|null, homepage: string|null}> */
    private array $providersByKey = [];

    /** @var array<int, string> */
    private array $providerKeyById = [];

    /** @var array<string, array{places: int, routes: int}> */
    private array $included = [];

    /** @var array<string, array{name: string|null, licence_code: string|null, reason: string, places: int, routes: int}> */
    private array $leftOut = [];

    public function __construct(
        private readonly Connection $db,
        private readonly ItemEvidenceResolver $evidence,
        private readonly BulkExportStorage $storage,
        private readonly BulkExportCatalog $catalog,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Builds, publishes and prunes. Returns the manifest it published.
     *
     * @return Manifest
     *
     * @throws BulkExportUnavailable when no bucket is configured
     * @throws BulkExportBusy        when another build holds the lock
     */
    public function build(\DateTimeImmutable $now): array
    {
        if (!$this->storage->isConfigured()) {
            throw new BulkExportUnavailable();
        }
        if (1 !== (int) $this->db->fetchOne('SELECT CASE WHEN pg_try_advisory_lock(hashtext(:k)) THEN 1 ELSE 0 END', ['k' => self::LOCK])) {
            throw new BulkExportBusy();
        }
        try {
            $manifest = $this->publish($now);
        } finally {
            $this->db->executeStatement('SELECT pg_advisory_unlock(hashtext(:k))', ['k' => self::LOCK]);
        }

        // The site sees the new snapshot first, whatever pruning does next.
        $this->catalog->forget();
        try {
            $this->prune($manifest['snapshot']);
        } catch (FilesystemException|\RuntimeException $e) {
            // Published is published: the next build prunes what this one could not.
            $this->logger->warning('Bulk export {snapshot} published, but deleting old snapshots failed: {message}', ['snapshot' => $manifest['snapshot'], 'message' => $e->getMessage()]);
        }

        return $manifest;
    }

    /**
     * Writes one snapshot and points `latest.json` at it.
     *
     * @return Manifest
     */
    private function publish(\DateTimeImmutable $now): array
    {
        $at = $now->setTimezone(new \DateTimeZone('UTC'));
        $stamp = $at->format('Ymd\THis\Z');
        $generatedAt = $at->format('Y-m-d\TH:i:s\Z');
        if ($this->storage->has($stamp.'/'.self::MANIFEST)) {
            // A snapshot never changes once published (its files are served
            // as immutable): a second build in the same second waits a second.
            throw new \RuntimeException(sprintf('Snapshot %s is already published.', $stamp));
        }
        $this->included = [];
        $this->leftOut = [];

        $dir = sys_get_temp_dir().'/cc-bulk-export-'.bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create %s.', $dir));
        }
        $placesPath = $dir.'/'.self::PLACES;
        $routesPath = $dir.'/'.self::ROUTES;

        try {
            $outer = $this->insideTransaction();
            $this->db->beginTransaction();
            try {
                if (!$outer) {
                    // Only possible as the first statement of a top-level
                    // transaction. Inside an enclosing one (the test suite
                    // wraps every test in one) the reads share its snapshot.
                    $this->db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                }
                $this->loadProviders();
                $places = $this->writeCollection($placesPath, $generatedAt, 'places', fn (\Closure $emit): int => $this->places($emit, $at));
                $routes = $this->writeCollection($routesPath, $generatedAt, 'routes', fn (\Closure $emit): int => $this->routes($emit));
            } finally {
                // Read-only: nothing to commit.
                $this->db->rollBack();
            }

            try {
                $files = [
                    $this->publishFile($stamp, $placesPath, self::PLACES, $places),
                    $this->publishFile($stamp, $routesPath, self::ROUTES, $routes),
                ];
                $manifest = $this->manifest($stamp, $generatedAt, $files);
                $json = json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
                $this->storage->write($stamp.'/'.self::MANIFEST, $json);
                $this->storage->write(BulkExportStorage::LATEST, $json);
            } catch (\Throwable $e) {
                // `latest.json` still names the previous snapshot: take back
                // what this build wrote, so no directory without a pointer to
                // it, or without a manifest, is left in the bucket.
                try {
                    $this->storage->deleteSnapshot($stamp);
                } catch (FilesystemException|\RuntimeException $cleanup) {
                    $this->logger->warning('Bulk export {snapshot} failed and its files could not be deleted; the next build deletes them: {message}', ['snapshot' => $stamp, 'message' => $cleanup->getMessage()]);
                }
                throw $e;
            }
        } finally {
            @unlink($placesPath);
            @unlink($routesPath);
            @rmdir($dir);
        }

        return $manifest;
    }

    /**
     * The predicate the public API serves items by: a served state, not an
     * untouched OSM coverage row, not reported gone. Composed from the same
     * shared helpers `PublicItemsProvider` uses, never a fork of their SQL.
     */
    public static function servedItemSql(string $alias): string
    {
        return $alias.'.state IN '.ItemState::servedSqlTuple()
            .' AND NOT ('.$alias.'.letter IN '.CoverageRetirement::lettersSqlTuple().' AND ('.CoverageRetirement::untouchedOsmSql($alias).'))'
            .' AND '.GoneRows::notGoneSql($alias);
    }

    /**
     * Whether a transaction is already open on the connection, at the DBAL
     * level or underneath it on the driver connection.
     */
    private function insideTransaction(): bool
    {
        if ($this->db->isTransactionActive()) {
            return true;
        }
        $native = $this->db->getNativeConnection();

        return $native instanceof \PDO && $native->inTransaction();
    }

    /**
     * Writes one file in two passes: the features to a scratch file first,
     * then the head, whose `attribution` names every source those features
     * came from, and the features copied behind it.
     *
     * @param 'places'|'routes'                     $kind
     * @param \Closure(\Closure(string): void): int $rows writes each feature through the emitter it is given, returns the count
     */
    private function writeCollection(string $path, string $generatedAt, string $kind, \Closure $rows): int
    {
        $bodyPath = $path.'.features';
        $body = gzopen($bodyPath, 'wb1');
        if (false === $body) {
            throw new \RuntimeException(sprintf('Cannot write %s.', $bodyPath));
        }
        try {
            try {
                $first = true;
                $count = $rows(static function (string $feature) use ($body, &$first): void {
                    gzwrite($body, ($first ? "\n" : ",\n").$feature);
                    $first = false;
                });
            } finally {
                gzclose($body);
            }

            $gz = gzopen($path, 'wb6');
            $features = gzopen($bodyPath, 'rb');
            if (false === $gz || false === $features) {
                throw new \RuntimeException(sprintf('Cannot write %s.', $path));
            }
            try {
                $head = [
                    'type' => 'FeatureCollection',
                    'licence' => 'ODbL-1.0',
                    'attribution' => $this->attribution($kind),
                    'generated_at' => $generatedAt,
                ];
                gzwrite($gz, substr(self::json($head), 0, -1).',"features":[');
                while (!gzeof($features)) {
                    $chunk = gzread($features, 1 << 16);
                    if (false === $chunk) {
                        throw new \RuntimeException(sprintf('Cannot read %s.', $bodyPath));
                    }
                    gzwrite($gz, $chunk);
                }
                gzwrite($gz, "\n]}\n");
            } finally {
                gzclose($features);
                gzclose($gz);
            }
        } finally {
            @unlink($bodyPath);
        }

        return $count;
    }

    /**
     * The dataset attribution, then the credit of every source with a row in
     * the file, ours first, each credit once.
     *
     * @param 'places'|'routes' $kind
     */
    private function attribution(string $kind): string
    {
        $credits = [];
        foreach ($this->includedKeys() as $key) {
            if (0 === $this->included[$key][$kind]) {
                continue;
            }
            $credit = (self::OURS === $key ? self::ours() : $this->providersByKey[$key])['attribution'];
            if (null !== $credit && !\in_array($credit, $credits, true)) {
                $credits[] = $credit;
            }
        }

        return [] === $credits ? self::ATTRIBUTION : self::ATTRIBUTION.' Source credits: '.implode('; ', $credits).'.';
    }

    /** @return list<string> the sources with a row in this build, ours first, then by key */
    private function includedKeys(): array
    {
        $keys = array_keys($this->included);
        usort($keys, static fn (string $a, string $b): int => [self::OURS !== $a, $a] <=> [self::OURS !== $b, $b]);

        return $keys;
    }

    /** @param \Closure(string): void $emit */
    private function places(\Closure $emit, \DateTimeImmutable $now): int
    {
        $sql = 'SELECT i.id, i.name, i.letter, ST_AsGeoJSON(i.geom, '.self::PRECISION.') AS geom,
                       i.state, i.source, i.imported_at, i.country_code, i.osm_ref, i.provider_id,
                       r.slug AS region, '.ItemEvidenceResolver::selectSql('i').'
                FROM item i
                LEFT JOIN region r ON r.id = i.region_id
                WHERE i.id > :after AND '.self::servedItemSql('i').'
                ORDER BY i.id
                LIMIT :lim';
        $count = 0;
        $after = 0;
        do {
            /** @var list<array{id: int|string, name: string, letter: string, geom: string|null, state: string, source: string, imported_at: string|null, country_code: string, osm_ref: string|null, provider_id: int|string|null, region: string|null, ev_provider: bool, ev_scope: bool|null, ev_conf: int|string, ev_curator?: bool|null, ev_last: string|null, ev_witness: string|null, ev_reclaimed: string|null, ev_edited?: bool|null}> $rows */
            $rows = $this->db->fetchAllAssociative($sql, ['after' => $after, 'lim' => self::BATCH], ['after' => ParameterType::INTEGER, 'lim' => ParameterType::INTEGER]);
            foreach ($rows as $row) {
                $after = (int) $row['id'];
                $source = $this->sourceFor($row['source'], null === $row['provider_id'] ? null : (int) $row['provider_id'], 'places');
                if (null === $source || null === $row['geom']) {
                    continue;
                }
                $feature = new ItemFeature(
                    (int) $row['id'],
                    $row['letter'],
                    $row['name'],
                    ItemState::Verified->value === $row['state'] ? 'curated' : 'community',
                    [],
                    $this->evidence->fromRow($row, $now),
                    $row['region'],
                );
                $properties = $feature->toGeoJson()['properties'] + [
                    'country' => $row['country_code'],
                    'osm_ref' => $row['osm_ref'],
                    'source' => $source,
                ];
                $emit(self::feature($row['geom'], $properties));
                ++$count;
            }
        } while (self::BATCH === \count($rows));

        return $count;
    }

    /** @param \Closure(string): void $emit */
    private function routes(\Closure $emit): int
    {
        $sql = 'SELECT rr.id, rr.name, ST_AsGeoJSON(rr.geom, '.self::PRECISION.') AS geom, rr.state, rr.source,
                       rr.distance_m, rr.ascent_m, r.slug AS region
                FROM recommended_route rr
                LEFT JOIN region r ON r.id = rr.region_id
                WHERE rr.id > :after AND rr.state IN '.ItemState::servedSqlTuple().' AND rr.trashed_at IS NULL
                ORDER BY rr.id
                LIMIT :lim';
        $count = 0;
        $after = 0;
        do {
            /** @var list<array{id: int|string, name: string, geom: string|null, state: string, source: string, distance_m: int|string|null, ascent_m: int|string|null, region: string|null}> $rows */
            $rows = $this->db->fetchAllAssociative($sql, ['after' => $after, 'lim' => self::BATCH], ['after' => ParameterType::INTEGER, 'lim' => ParameterType::INTEGER]);
            foreach ($rows as $row) {
                $after = (int) $row['id'];
                $source = $this->sourceFor($row['source'], null, 'routes');
                if (null === $source || null === $row['geom']) {
                    continue;
                }
                $emit(self::feature($row['geom'], [
                    'id' => (int) $row['id'],
                    'letter' => 'R',
                    'name' => $row['name'],
                    'tier' => ItemState::Verified->value === $row['state'] ? 'curated' : 'community',
                    'distance_m' => null === $row['distance_m'] ? null : (int) $row['distance_m'],
                    'ascent_m' => null === $row['ascent_m'] ? null : (int) $row['ascent_m'],
                    'region_id' => $row['region'],
                    'source' => $source,
                ]));
                ++$count;
            }
        } while (self::BATCH === \count($rows));

        return $count;
    }

    /**
     * The source a row is filed under, or null when its licence keeps it out
     * (the row is then counted in `left_out`).
     *
     * @param 'places'|'routes' $kind
     */
    private function sourceFor(string $value, ?int $providerId, string $kind): ?string
    {
        $source = ItemSource::tryFrom($value);
        if (\in_array($source, self::OWN_SOURCES, true)) {
            $key = self::OURS;
        } elseif (\in_array($source, self::MIRRORED_SOURCES, true)) {
            $key = $source->value;
        } elseif (ItemSource::Authority === $source && null !== $providerId && isset($this->providerKeyById[$providerId])) {
            $key = $this->providerKeyById[$providerId];
        } else {
            $this->leave('unknown', null, null, 'no_licence', $kind);

            return null;
        }

        $provider = self::OURS === $key ? self::ours() : ($this->providersByKey[$key] ?? null);
        if (null === $provider) {
            $this->leave($key, null, null, 'no_licence', $kind);

            return null;
        }
        if (!BulkExportLicences::allows($provider['licence_code'])) {
            $this->leave($key, $provider['name'], $provider['licence_code'], 'licence', $kind);

            return null;
        }
        $this->included[$key] ??= ['places' => 0, 'routes' => 0];
        ++$this->included[$key][$kind];

        return $key;
    }

    /** @param 'places'|'routes' $kind */
    private function leave(string $key, ?string $name, ?string $licenceCode, string $reason, string $kind): void
    {
        $this->leftOut[$key] ??= ['name' => $name, 'licence_code' => $licenceCode, 'reason' => $reason, 'places' => 0, 'routes' => 0];
        ++$this->leftOut[$key][$kind];
    }

    private function loadProviders(): void
    {
        $this->providersByKey = [];
        $this->providerKeyById = [];
        /** @var list<array{id: int|string, provider_key: string, name: string, licence: string, licence_code: string, attribution: string|null, homepage: string}> $rows */
        $rows = $this->db->fetchAllAssociative('SELECT id, provider_key, name, licence, licence_code, attribution, homepage FROM data_provider ORDER BY id');
        foreach ($rows as $row) {
            $this->providerKeyById[(int) $row['id']] = $row['provider_key'];
            $this->providersByKey[$row['provider_key']] = [
                'key' => $row['provider_key'],
                'name' => $row['name'],
                'licence' => $row['licence'],
                'licence_code' => $row['licence_code'],
                'attribution' => (null === $row['attribution'] || '' === trim($row['attribution'])) ? null : $row['attribution'],
                'homepage' => '' === $row['homepage'] ? null : $row['homepage'],
            ];
        }
    }

    /** @return array{key: string, name: string, licence: string, licence_code: string, attribution: string|null, homepage: string|null} */
    private static function ours(): array
    {
        return [
            'key' => self::OURS,
            'name' => 'Cycling Commons',
            'licence' => 'Open Database License (ODbL) 1.0',
            'licence_code' => 'odbl',
            'attribution' => '© Cycling Commons contributors',
            'homepage' => null,
        ];
    }

    /** @return ExportFile */
    private function publishFile(string $stamp, string $path, string $name, int $features): array
    {
        $bytes = filesize($path);
        $sha = hash_file('sha256', $path);
        $stream = fopen($path, 'rb');
        if (false === $bytes || false === $sha || false === $stream) {
            throw new \RuntimeException(sprintf('Cannot read %s.', $path));
        }
        try {
            $this->storage->writeStream($stamp.'/'.$name, $stream);
        } finally {
            fclose($stream);
        }

        return ['name' => $name, 'content_type' => 'application/gzip', 'features' => $features, 'bytes' => $bytes, 'sha256' => $sha];
    }

    /**
     * @param list<ExportFile> $files
     *
     * @return Manifest
     */
    private function manifest(string $stamp, string $generatedAt, array $files): array
    {
        $sources = [];
        foreach ($this->includedKeys() as $key) {
            $provider = self::OURS === $key ? self::ours() : $this->providersByKey[$key];
            $sources[] = $provider + $this->included[$key];
        }
        $leftOut = [];
        ksort($this->leftOut);
        foreach ($this->leftOut as $key => $row) {
            $leftOut[] = ['key' => $key, 'name' => $row['name'], 'licence_code' => $row['licence_code'], 'reason' => $row['reason'], 'places' => $row['places'], 'routes' => $row['routes']];
        }

        return [
            'format' => self::FORMAT,
            'snapshot' => $stamp,
            'generated_at' => $generatedAt,
            'licence' => [
                'id' => 'ODbL-1.0',
                'name' => 'Open Database License (ODbL) 1.0',
                'url' => 'https://opendatacommons.org/licenses/odbl/1-0/',
                'contents' => 'DbCL-1.0',
                'contents_url' => 'https://opendatacommons.org/licenses/dbcl/1-0/',
            ],
            'attribution' => self::ATTRIBUTION,
            'sources' => $sources,
            'left_out' => $leftOut,
            'files' => $files,
        ];
    }

    /**
     * Keeps what {@see BulkExportRetention::kept()} keeps: the newest KEEP
     * snapshots that hold a manifest, and the first of every month. Deletes
     * the other older ones, and every directory without a manifest that is
     * older than the snapshot just published: what a build that died halfway
     * left behind.
     */
    private function prune(string $newest): void
    {
        $keep = BulkExportRetention::kept($this->storage->published());
        foreach ($this->storage->snapshots() as $stamp) {
            if (!\in_array($stamp, $keep, true) && $stamp < $newest) {
                $this->storage->deleteSnapshot($stamp);
            }
        }
    }

    /** @param array<string, mixed> $properties */
    private static function feature(string $geometry, array $properties): string
    {
        return '{"type":"Feature","geometry":'.$geometry.',"properties":'.self::json($properties).'}';
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
