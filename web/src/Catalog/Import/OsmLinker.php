<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Import;

use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;

/**
 * Finds the OSM object a non-OSM row is a record of.
 *
 * OSM is the identity spine ([osm-data-architecture.md §1] — "the join key
 * between our data and OSM is always `osm_ref`"). A rider editing an OSM object
 * already inherits that ref as the new row's identity
 * (`CatalogContributionService`). PIVOT and Wikidata never did, so their rows
 * carry refs like `fx:pivot:hotel-koru|ramillies`, and the dedupe between the
 * catalog and the coverage cache — which is by ref — could not see that a PIVOT
 * hotel and an OSM hotel were one hotel. The result was two entries in one
 * drawer for one building.
 *
 * **Where the refs come from: `coverage_poi`**, the OSM mirror the pipeline
 * already builds (1.1M named rows). No network call, no Overpass, nothing
 * fetched. The refs are already in the database.
 *
 * Two bands, because confidence is not uniform (owner decision 2026-08-24):
 *
 * - **≤ {@see TIGHT_M}** with an identical name key: written automatically.
 *   Measured against the real catalog, matches in this band are things like
 *   Dufourspitze at 0 m and Cascade de Coo at 1 m.
 * - **{@see TIGHT_M}–{@see LOOSE_M}**: never written. It becomes a
 *   `FindingKind::OsmLink` for a curator, because this is where the name is
 *   doing all the work and the name can be wrong. "Dom" at 179 m is a Swiss
 *   4000-metre peak or a cathedral, and nothing but a human knows which.
 *
 * An identity link is harder to unpick than a duplicate pin, which is why the
 * automatic band is tighter here than the duplicate guard's 250 m.
 *
 * @see docs/specs/catalog-data-model.md §5b
 *
 * @api
 */
final readonly class OsmLinker
{
    /** Written without asking. */
    public const int TIGHT_M = 50;

    /** Beyond this, not even worth showing a curator. */
    public const int LOOSE_M = 250;

    public function __construct(private Connection $db)
    {
    }

    /**
     * The best OSM candidate for this row, or null when there is none.
     *
     * `confident` is what the caller acts on: true means "write it", false
     * means "raise it for a human". Never decide that by reading `distanceM`
     * at the call site — the threshold belongs here, in one place.
     *
     * @return array{ref: string, name: string, distanceM: float, confident: bool}|null
     */
    public function candidateFor(string $letter, string $name, float $lat, float $lng): ?array
    {
        $key = NameKey::of($name);
        if ('' === $key) {
            return null;
        }

        // Spatial first (GIST-indexed), then the name key in PHP: the key is a
        // PHP rule pinned to a cross-language contract, and mirroring it into
        // SQL would be a third copy to keep in step.
        /** @var list<array{ref: string, name: string, distance_m: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT cp.ref, cp.name,
                    ST_Distance(cp.geom::geography, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography) AS distance_m
               FROM coverage_poi cp
              WHERE cp.letter = :letter
                AND cp.name IS NOT NULL AND cp.name <> \'\'
                AND ST_DWithin(cp.geom::geography, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography, :radius)
              ORDER BY distance_m',
            ['letter' => $letter, 'lat' => $lat, 'lng' => $lng, 'radius' => self::LOOSE_M],
        );

        foreach ($rows as $row) {
            if (NameKey::of($row['name']) !== $key) {
                continue;
            }
            $distance = (float) $row['distance_m'];

            return [
                'ref' => $row['ref'],
                'name' => $row['name'],
                'distanceM' => $distance,
                'confident' => $distance <= self::TIGHT_M,
            ];
        }

        return null;
    }

    /**
     * The nearest OSM objects of this letter, for a curator to choose from.
     *
     * Unlike {@see candidateFor} this does not filter by name key: the rider's
     * "Uitkijkpunt bos" and OSM's unnamed viewpoint 40 m away are exactly the
     * pair a human is better at than a string rule, which is why the approval
     * gate shows this list instead of deciding (catalog-data-model.md §5b).
     *
     * @return list<array{ref: string, name: ?string, distanceM: float}>
     */
    public function nearby(string $letter, float $lat, float $lng, int $limit = 5): array
    {
        /** @var list<array{ref: string, name: ?string, distance_m: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT cp.ref, cp.name,
                    ST_Distance(cp.geom::geography, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography) AS distance_m
               FROM coverage_poi cp
              WHERE cp.letter = :letter
                AND ST_DWithin(cp.geom::geography, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography, :radius)
              ORDER BY distance_m
              LIMIT :lim',
            ['letter' => $letter, 'lat' => $lat, 'lng' => $lng, 'radius' => self::LOOSE_M, 'lim' => $limit],
        );

        return array_map(static fn (array $r): array => [
            'ref' => $r['ref'],
            'name' => null !== $r['name'] && '' !== $r['name'] ? $r['name'] : null,
            'distanceM' => round((float) $r['distance_m'], 1),
        ], $rows);
    }

    /**
     * True when another served row of this letter already claims that OSM object.
     *
     * Identity is exclusive: two served rows pointing at one OSM object are a
     * duplicate wearing a link. The linker refuses rather than creating one,
     * and the duplicate desk is where that pair gets sorted out.
     */
    public function refIsTaken(string $osmRef, string $letter, int $exceptItemId): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT 1 FROM item
              WHERE letter = :letter AND id <> :id
                AND state IN '.ItemState::servedSqlTuple().'
                AND (osm_ref = :ref OR (source = \'osm\' AND source_ref = :ref))
              LIMIT 1',
            ['letter' => $letter, 'id' => $exceptItemId, 'ref' => $osmRef],
        );
    }
}
