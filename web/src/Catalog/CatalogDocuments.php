<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The catalog documents the map downloads, built once per version and shared
 * by both web hosts through the app cache (Redis).
 *
 * One entry per region (`catalog.doc.region.<rid>`) holding the version it
 * was built for. A request whose current version
 * matches is served from the entry; any other version rebuilds it and
 * overwrites it, so a busy region never leaves a trail of dead copies behind.
 * The version is a {@see CatalogStamps} stamp, so a document is rebuilt the
 * first time it is asked for after an edit in its region, and at most once a
 * day otherwise.
 *
 * A build reads its rows and its change count in one REPEATABLE READ
 * snapshot, so the version stored with the bytes is the one they were built
 * from, even while a curator is deciding something.
 *
 * @see docs/specs/catalog-data-model.md §9.1
 *
 * @api
 */
final class CatalogDocuments
{
    /** A little over a day: the day in every version retires an entry by then anyway. */
    private const int TTL_SECONDS = 90000;

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogProvider $provider,
        private readonly CatalogStamps $stamps,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * One region's slice: `GET /map/catalog/region/{rid}.json`.
     *
     * @return array{json: string, etag: string}
     */
    public function region(int $regionId): array
    {
        return $this->document(
            'catalog.doc.region.'.$regionId,
            fn (array $counts): string => $this->stamps->stamp($regionId, $counts),
            $regionId,
        );
    }

    /**
     * Built and not kept: a region the change count does not know never held
     * a row, so its document is empty and cheap, and keeping one per id asked
     * for would let anybody fill the cache by counting upwards.
     *
     * @param array<int, int> $counts
     */
    private static function isKnown(int $regionId, array $counts): bool
    {
        return \array_key_exists($regionId, $counts);
    }

    /**
     * @param \Closure(array<int, int>): string $versionOf
     *
     * @return array{json: string, etag: string}
     */
    private function document(string $key, \Closure $versionOf, int $regionId): array
    {
        $counts = $this->stamps->counts();
        if (!self::isKnown($regionId, $counts)) {
            $json = $this->build($regionId, $versionOf)[0];

            return ['json' => $json, 'etag' => md5($json)];
        }
        $version = $versionOf($counts);
        $entry = $this->cache->getItem($key);
        $held = $entry->get();
        if ($entry->isHit() && \is_array($held) && ($held['version'] ?? null) === $version
            && \is_string($held['json'] ?? null) && \is_string($held['etag'] ?? null)) {
            return ['json' => $held['json'], 'etag' => $held['etag']];
        }

        [$json, $builtFor] = $this->build($regionId, $versionOf);
        $etag = md5($json);
        $this->cache->save($entry->set(['version' => $builtFor, 'json' => $json, 'etag' => $etag])->expiresAfter(self::TTL_SECONDS));

        return ['json' => $json, 'etag' => $etag];
    }

    /**
     * @param \Closure(array<int, int>): string $versionOf
     *
     * @return array{0: string, 1: string} the document and the version it was built for
     */
    private function build(int $regionId, \Closure $versionOf): array
    {
        // Inside a transaction already open, the snapshot is that
        // transaction's. Asked of the driver, not of DBAL: the test suite's
        // rollback wrapper opens its transaction below DBAL, which then
        // reports none, and SET TRANSACTION would come too late.
        $native = $this->db->getNativeConnection();
        if ($this->db->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            $counts = $this->stamps->counts();

            return [$this->provider->json($regionId, $counts), $versionOf($counts)];
        }

        $this->db->beginTransaction();
        try {
            $this->db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $counts = $this->stamps->counts();
            $json = $this->provider->json($regionId, $counts);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [$json, $versionOf($counts)];
    }
}
