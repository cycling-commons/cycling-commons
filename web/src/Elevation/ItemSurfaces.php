<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Elevation;

use App\Catalog\ItemState;
use Doctrine\DBAL\Connection;

/**
 * The road surface rows (layer A) the map shows within REACH_M of a planned leg (docs/specs/route-planner.md section
 * 4.4a), read from `item`: only the served states, so a rejected or a waiting row never reaches a rider's figures.
 *
 * @api
 */
final class ItemSurfaces implements CuratedSurfaces
{
    /** How far from the leg a row is read, metres: the edges are matched to it closer still (LegSurfaces::ON_CURATED_M). */
    private const float REACH_M = 15.0;

    public function __construct(private readonly Connection $db)
    {
    }

    #[\Override]
    public function near(array $coords): array
    {
        if (\count($coords) < 2) {
            return [];
        }
        $wkt = 'LINESTRING('.implode(',', array_map(static fn (array $c): string => $c[1].' '.$c[0], $coords)).')';
        $rows = $this->db->fetchAllAssociative(
            "SELECT ST_AsGeoJSON(geom) AS line, attributes->>'surface' AS surface, attributes->>'smoothness' AS smoothness
               FROM item
              WHERE letter = 'A' AND state IN ".ItemState::servedSqlTuple().'
                AND ST_DWithin(geom::geography, ST_SetSRID(ST_GeomFromText(:wkt), 4326)::geography, :reach)',
            ['wkt' => $wkt, 'reach' => self::REACH_M],
        );
        $out = [];
        foreach ($rows as $row) {
            $geo = json_decode(\is_string($row['line']) ? $row['line'] : '', true);
            $lines = match (\is_array($geo) ? ($geo['type'] ?? null) : null) {
                'LineString' => [$geo['coordinates']],
                'MultiLineString' => $geo['coordinates'],
                default => [],
            };
            foreach ($lines as $line) {
                $out[] = [
                    'line' => array_map(static fn (array $p): array => [(float) $p[1], (float) $p[0]], $line),
                    'surface' => \is_string($row['surface']) ? $row['surface'] : null,
                    'smoothness' => \is_string($row['smoothness']) ? $row['smoothness'] : null,
                ];
            }
        }

        return $out;
    }
}
