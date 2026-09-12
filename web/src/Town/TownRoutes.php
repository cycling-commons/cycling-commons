<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Town;

use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;

/**
 * The recommended routes that pass through a town.
 *
 * **Ours, not Wikidata's.** The town card already lists the races Wikidata
 * ties to a place, which answers "what happened here" and not "what can I
 * ride from here": the Westfriese Omringdijk at Hoorn and the Great Divide at
 * Banff are in the map's own routes layer, and finding them needs geometry
 * rather than a claim (known issue, 2026-09-06).
 *
 * **Read fresh, never cached with the text.** `town_summary` is keyed by
 * (ref, language) and settled once, which is right for a Wikipedia paragraph
 * that changes rarely and wrong for a layer riders add to: a route proposed
 * today would wait behind a cache with no expiry. The query is one index scan
 * on a table of thousands.
 *
 * @phpstan-type TownRoute array{id: int, name: string, distanceM: ?int}
 *
 * @see docs/specs/map-and-search.md §6.5
 *
 * @api
 */
final readonly class TownRoutes
{
    /**
     * How close a route has to come to count as passing through.
     *
     * A town is a single OpenStreetMap node at its centre, so this is the
     * distance from that centre and not from the built-up edge. One kilometre
     * takes in a route through the streets and a bypass along the edge, which
     * a rider standing in the town would call the same thing, without
     * reaching the next village.
     */
    public const int THROUGH_M = 1000;

    /** Enough to say what is here; the map is where you go through them all. */
    private const int LIMIT = 6;

    public function __construct(private Connection $db)
    {
    }

    /**
     * @return list<TownRoute>
     */
    public function near(float $lat, float $lng): array
    {
        /** @var list<array{id: int|string, name: string, distance_m: int|string|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, distance_m
               FROM recommended_route
              WHERE state IN '.ItemState::servedSqlTuple().'
                AND ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography, :m)
              ORDER BY ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(:lng, :lat), 4326)::geography), id
              LIMIT '.self::LIMIT,
            ['lat' => $lat, 'lng' => $lng, 'm' => self::THROUGH_M],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'distanceM' => null === $r['distance_m'] ? null : (int) $r['distance_m'],
        ], $rows);
    }
}
