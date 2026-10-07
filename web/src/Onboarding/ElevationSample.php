<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Onboarding;

use App\Catalog\OperationalRegions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * A grid of points inside a country's operational regions, for the elevation check.
 *
 * @api
 */
final class ElevationSample
{
    public const int POINTS = 25;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<array{lat: float, lon: float}> */
    public function points(string $cc): array
    {
        $rows = [];
        for ($n = 5; $n <= 40; $n += 5) {
            $rows = $this->grid($cc, $n);
            if (\count($rows) >= self::POINTS) {
                break;
            }
        }
        $count = \count($rows);
        $out = [];
        for ($i = 0, $take = min($count, self::POINTS); $i < $take; ++$i) {
            $row = $rows[intdiv($i * $count, $take)];
            $out[] = ['lat' => (float) $row['lat'], 'lon' => (float) $row['lon']];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function grid(string $cc, int $n): array
    {
        return $this->db->fetchAllAssociative(
            'WITH c AS (
                 SELECT ST_Union(r.geom) AS g FROM region r
                  WHERE r.country_code = :cc AND r.geom IS NOT NULL AND '.OperationalRegions::predicate('r').'
             ), b AS (
                 SELECT g, ST_XMin(g) AS x0, ST_YMin(g) AS y0, ST_XMax(g) AS x1, ST_YMax(g) AS y1 FROM c WHERE g IS NOT NULL
             )
             SELECT ST_Y(p.pt) AS lat, ST_X(p.pt) AS lon
               FROM b
              CROSS JOIN generate_series(0, CAST(:n AS int) - 1) AS gi(i)
              CROSS JOIN generate_series(0, CAST(:n AS int) - 1) AS gj(j)
              CROSS JOIN LATERAL (SELECT ST_SetSRID(ST_MakePoint(
                     b.x0 + (gi.i + 0.5) * (b.x1 - b.x0) / CAST(:n AS int),
                     b.y0 + (gj.j + 0.5) * (b.y1 - b.y0) / CAST(:n AS int)), 4326) AS pt) p
              WHERE ST_Contains(b.g, p.pt)
              ORDER BY gj.j, gi.i',
            ['cc' => $cc, 'n' => $n],
            ['n' => ParameterType::INTEGER],
        );
    }
}
