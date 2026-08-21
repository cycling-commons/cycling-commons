<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Service;

use App\Catalog\OperationalRegions;
use Doctrine\DBAL\Connection;

/**
 * My-area regions from a coarse base point + radius. Cap 8; operational regions only.
 *
 * @see docs/specs/map-and-search.md §4.5
 *
 * @api
 */
final class BaseAreaResolver
{
    public const int MAX_REGIONS = 8;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{regionIds: list<int>, countryCodes: list<string>} */
    public function resolve(float $lat, float $lng, int $radiusKm): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT r.id, r.country_code
               FROM region r
              WHERE r.geom IS NOT NULL
                AND ST_DWithin(r.geom::geography, ST_SetSRID(ST_Point(:lng, :lat), 4326)::geography, :m)
                AND '.OperationalRegions::predicate('r').'
              ORDER BY ST_Contains(r.geom, ST_SetSRID(ST_Point(:lng, :lat), 4326)) DESC,
                       ST_Distance(r.geom::geography, ST_SetSRID(ST_Point(:lng, :lat), 4326)::geography) ASC,
                       r.area_km2 ASC NULLS LAST, r.id ASC
              LIMIT '.self::MAX_REGIONS,
            ['lat' => $lat, 'lng' => $lng, 'm' => (float) $radiusKm * 1000.0],
        );

        $ids = [];
        $ccs = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
            $cc = strtoupper((string) $row['country_code']);
            if ('' !== $cc && !\in_array($cc, $ccs, true)) {
                $ccs[] = $cc;
            }
        }

        return ['regionIds' => $ids, 'countryCodes' => $ccs];
    }
}
