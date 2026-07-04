<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Contribution;

use Doctrine\DBAL\Connection;

/**
 * Point → containing region + country, the intake-side twin of the importer's
 * membership recompute (same ST_Contains predicate; country comes from
 * region.country_code, added for phase B).
 *
 * @api Used by CatalogContributionService at submit time.
 */
final class SpatialResolver
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{regionId: ?int, countryCode: string} */
    public function resolve(float $lat, float $lng): array
    {
        /** @var array{id: int|string, country_code: string}|false $row */
        $row = $this->db->fetchAssociative(
            'SELECT id, country_code FROM region
             WHERE ST_Contains(geom, ST_SetSRID(ST_Point(:lng, :lat), 4326)) LIMIT 1',
            ['lng' => $lng, 'lat' => $lat],
        );

        return $row
            ? ['regionId' => (int) $row['id'], 'countryCode' => $row['country_code']]
            : ['regionId' => null, 'countryCode' => ''];
    }
}
