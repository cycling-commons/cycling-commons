<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use Doctrine\DBAL\Connection;

/**
 * Pin → region → country → continent. Unresolvable means refuse, never guess.
 *
 * @see docs/specs/photo-uploads.md §1
 *
 * @api
 */
final class ContinentResolver
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function resolve(?float $lat, ?float $lng): ?string
    {
        if (null === $lat || null === $lng || !is_finite($lat) || !is_finite($lng)) {
            return null;
        }

        $code = $this->db->fetchOne(
            'SELECT c.code
             FROM region r
             JOIN world_country wc ON wc.iso2 = r.country_code
             JOIN world_continent c ON c.id = wc.continent_id
             WHERE ST_Contains(r.geom, ST_SetSRID(ST_Point(:lng, :lat), 4326))
             ORDER BY r.area_km2 ASC NULLS LAST, r.id ASC
             LIMIT 1',
            ['lng' => $lng, 'lat' => $lat],
        );

        return \is_string($code) && 2 === \strlen($code) ? strtoupper($code) : null;
    }
}
