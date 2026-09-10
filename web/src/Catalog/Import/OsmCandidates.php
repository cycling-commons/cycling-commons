<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Import;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * The OSM-candidate list of a row with an open OSM question, computed ONCE
 * and stored on the row (`item.osm_candidates`, `item.osm_candidates_at`).
 *
 * Until 2026-08-25 the desk asked {@see OsmLinker::nearby()} for every card
 * on every list view: the same question, the same answer, 2.8 s per card
 * before the geography index and still one spatial query per card after it
 * (owner: "that doesn't sound well engineered"). The answer changes only when
 * the place moves or the coverage data of its country is re-harvested, so:
 *
 *  - intake computes it when the row is created ({@see refresh()});
 *  - a curator-approved location change recomputes it ({@see refreshAt()});
 *  - the pipeline's per-region swap clears it for every open row of that
 *    country (`pipeline/coverage/load.py`), and the next read fills it again
 *    ({@see forItems()}, which computes only where the column is NULL).
 *
 * A row whose question is answered (`osm_checked_at` set) keeps whatever it
 * holds; nothing reads it, and the harvest leaves it alone.
 *
 * @see docs/specs/catalog-data-model.md §5b
 *
 * @api
 */
final readonly class OsmCandidates
{
    public function __construct(
        private Connection $db,
        private OsmLinker $linker,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The stored list for every id whose question is still open, computing and
     * storing it where the harvest (or a fresh row) left NULL.
     *
     * @param list<int> $itemIds
     *
     * @return array<int, list<array{ref: string, name: ?string, distanceM: float}>>
     */
    public function forItems(array $itemIds): array
    {
        if ([] === $itemIds) {
            return [];
        }
        /** @var list<array{id: int|string, letter: string, lat: string|float, lng: string|float, osm_candidates: ?string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, letter, osm_candidates,
                    ST_Y(ST_Centroid(geom)) AS lat, ST_X(ST_Centroid(geom)) AS lng
               FROM item
              WHERE id IN (:ids) AND osm_checked_at IS NULL',
            ['ids' => $itemIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (\is_string($row['osm_candidates'])) {
                /** @var list<array{ref: string, name: ?string, distanceM: float}> $stored */
                $stored = json_decode($row['osm_candidates'], true, 8, \JSON_THROW_ON_ERROR);
                $out[$id] = $stored;
                continue;
            }
            $out[$id] = $this->refreshAt($id, (string) $row['letter'], (float) $row['lat'], (float) $row['lng']);
        }

        return $out;
    }

    /** Recompute for one row from its stored geometry (intake, backfill). */
    public function refresh(int $itemId): void
    {
        /** @var array{letter: string, lat: string|float, lng: string|float}|false $row */
        $row = $this->db->fetchAssociative(
            'SELECT letter, ST_Y(ST_Centroid(geom)) AS lat, ST_X(ST_Centroid(geom)) AS lng FROM item WHERE id = :id',
            ['id' => $itemId],
        );
        if (false === $row) {
            return;
        }
        $this->refreshAt($itemId, (string) $row['letter'], (float) $row['lat'], (float) $row['lng']);
    }

    /**
     * Recompute for one row at a point the caller already holds (a location
     * change not yet flushed), and store it.
     *
     * @return list<array{ref: string, name: ?string, distanceM: float}>
     */
    public function refreshAt(int $itemId, string $letter, float $lat, float $lng): array
    {
        // The coverage table is pipeline-owned: a fresh install that has not
        // harvested yet has no coverage_poi, and intake must still work. The
        // empty list is stored like any other; the first harvest of the
        // country clears it and the next read computes it for real.
        $candidates = null === $this->db->fetchOne("SELECT to_regclass('coverage_poi')")
            ? []
            : $this->linker->nearby($letter, $lat, $lng);
        $this->db->executeStatement(
            'UPDATE item SET osm_candidates = :c, osm_candidates_at = :at WHERE id = :id',
            [
                'c' => json_encode($candidates, \JSON_THROW_ON_ERROR),
                'at' => $this->clock->now()->format('Y-m-d H:i:s'),
                'id' => $itemId,
            ],
        );

        return $candidates;
    }
}
