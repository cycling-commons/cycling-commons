<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use App\Service\BuildVersion;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The version of every catalog document the map downloads.
 *
 * A region's stamp is a hash of what its rows serialize from: the region's
 * change count and the every-region count (both kept by the database itself,
 * `catalog_change`, Version20260928150000), the day the document is built for,
 * the build and the staleness window. The count moves on every committed
 * change, even two in the same second; the day moves the pins that turned
 * orange or red overnight; the build moves a deploy that serializes the same
 * rows differently.
 *
 * @see docs/specs/catalog-data-model.md §9.1
 *
 * @api
 */
final class CatalogStamps
{
    /** The rows that belong to no region: region slice 0 serves them (regionSql()). */
    public const int NO_REGION = 0;

    /** A change every region's rows serialize (a public name, a citation). */
    public const int EVERY_REGION = -1;

    private const string COMPACT_KEY = 'catalog.change.last_compact';
    private const int COMPACT_EVERY_SECONDS = 3600;

    public function __construct(
        private readonly Connection $db,
        private readonly BuildVersion $buildVersion,
        private readonly ConfirmationFreshness $freshness,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * The SQL condition for one region's rows. Region {@see NO_REGION} is the
     * rows no region holds, so the places outside every region have a slice
     * of their own like any other. `$regionId` is an int, so it is written in
     * place.
     */
    public static function regionSql(string $alias, int $regionId): string
    {
        $column = ('' === $alias ? '' : $alias.'.').'region_id';

        return self::NO_REGION === $regionId ? $column.' IS NULL' : $column.' = '.$regionId;
    }

    /**
     * The day a document is built for, at its first second. Freshness and
     * evidence are judged against it, so two web hosts building the same stamp
     * write the same bytes.
     */
    public static function day(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today');
    }

    /**
     * Change count per region, keyed by region id. Read it once and pass it
     * on, so every stamp of one answer comes from the same read.
     *
     * @return array<int, int>
     */
    public function counts(): array
    {
        /** @var list<array{region_id: int|string, n: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative('SELECT region_id, sum(n) AS n FROM catalog_change GROUP BY region_id');
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['region_id']] = (int) $row['n'];
        }
        ksort($counts);

        return $counts;
    }

    /**
     * One stamp per region the change count knows, with `0` for the rows no
     * region holds. A region that never held a row has no stamp, and the map
     * asks for nothing there.
     *
     * @param array<int, int>|null $counts
     *
     * @return array<int, string>
     */
    public function regionStamps(?array $counts = null): array
    {
        $counts ??= $this->counts();
        $stamps = [];
        foreach (array_keys($counts) as $rid) {
            if (self::EVERY_REGION !== $rid) {
                $stamps[$rid] = $this->stamp($rid, $counts);
            }
        }

        return $stamps;
    }

    /** @param array<int, int>|null $counts */
    public function stamp(int $regionId, ?array $counts = null): string
    {
        $counts ??= $this->counts();

        return $this->hash([$regionId, $counts[$regionId] ?? 0, $counts[self::EVERY_REGION] ?? 0]);
    }

    /**
     * Fold the change rows into one per region. The sums stay the same, so no
     * stamp moves. Rows a transaction still holds uncommitted are not visible
     * to the DELETE and stay for the next fold.
     *
     * @return int the rows folded away
     */
    public function compact(): int
    {
        $before = (int) $this->db->fetchOne('SELECT count(*) FROM catalog_change');
        $this->db->executeStatement(
            'WITH folded AS (DELETE FROM catalog_change RETURNING region_id, n)
             INSERT INTO catalog_change (region_id, n) SELECT region_id, sum(n) FROM folded GROUP BY region_id',
        );

        return max(0, $before - (int) $this->db->fetchOne('SELECT count(*) FROM catalog_change'));
    }

    /** At most once an hour, off ordinary traffic. A failed fold must never break the map. */
    public function compactOpportunistically(): void
    {
        try {
            $this->cache->get(self::COMPACT_KEY, function (ItemInterface $item): true {
                $item->expiresAfter(self::COMPACT_EVERY_SECONDS);
                $this->compact();

                return true;
            });
        } catch (\Throwable) {
            // The next hour tries again; the stamps are right either way.
        }
    }

    /** @param list<int|string> $parts */
    private function hash(array $parts): string
    {
        $parts[] = self::day()->format('Y-m-d');
        $parts[] = $this->buildVersion->stamp()['number'];
        $parts[] = $this->freshness->staleMonths();

        return substr(hash('xxh128', implode('|', array_map(strval(...), $parts))), 0, 16);
    }
}
