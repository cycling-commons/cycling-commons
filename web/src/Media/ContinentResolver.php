<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which bucket a photo belongs in (docs/specs/photo-uploads.md §1.2): a point
 * resolves to its containing region, the region names its country, and the
 * world reference data maps that country to a continent.
 *
 * Smallest-area-wins mirrors {@see \App\Contribution\SpatialResolver}, so a
 * point inside both an operational region and its containing infrastructure-only
 * country outline anchors to the smaller one. A point in the sea, in a country
 * that has not been onboarded, or with no coordinates at all resolves to
 * MEDIA_DEFAULT_CONTINENT — honestly, without guessing a nearest country: the
 * shard is a storage detail, and a wrong guess would be a lie recorded on the
 * row.
 *
 * @api Called by MediaController when persisting an upload.
 */
final class ContinentResolver
{
    public function __construct(
        private readonly Connection $db,
        #[Autowire('%media.default_continent%')]
        private readonly string $defaultContinent,
    ) {
    }

    public function resolve(?float $lat, ?float $lng): string
    {
        $fallback = strtoupper($this->defaultContinent);
        if (null === $lat || null === $lng || !is_finite($lat) || !is_finite($lng)) {
            return $fallback;
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

        return \is_string($code) && 2 === \strlen($code) ? strtoupper($code) : $fallback;
    }
}
