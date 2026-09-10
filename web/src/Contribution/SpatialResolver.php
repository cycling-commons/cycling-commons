<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use Doctrine\DBAL\Connection;

/**
 * Point → containing region + country.
 *
 * @api
 */
final class SpatialResolver
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{regionId: ?int, countryCode: string} */
    public function resolve(float $lat, float $lng): array
    {
        // Smallest-area-wins, mirroring RegionResolver: else an L2 country outline can win over an L4 region.
        /** @var array{id: int|string, country_code: string}|false $row */
        $row = $this->db->fetchAssociative(
            'SELECT id, country_code FROM region
             WHERE ST_Contains(geom, ST_SetSRID(ST_Point(:lng, :lat), 4326))
             ORDER BY area_km2 ASC NULLS LAST, id ASC LIMIT 1',
            ['lng' => $lng, 'lat' => $lat],
        );

        return $row
            ? ['regionId' => (int) $row['id'], 'countryCode' => $row['country_code']]
            : ['regionId' => null, 'countryCode' => ''];
    }
}
