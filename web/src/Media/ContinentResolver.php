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

    /**
     * The same answer from a country code we already hold.
     *
     * A catalogue item stores its `country_code`, so asking which polygon its
     * centroid falls inside is a slower way to reach a fact the row already
     * states. It also fails on a coastline, where the centroid can land just
     * outside every region, and a photo that resolves nowhere is refused
     * rather than filed under a neighbour (photo-uploads.md §1). Same contract
     * as resolve(): unresolvable means null, never a guess.
     */
    public function forCountry(?string $iso2): ?string
    {
        if (null === $iso2 || 2 !== \strlen($iso2)) {
            return null;
        }

        $code = $this->db->fetchOne(
            'SELECT c.code
             FROM world_country wc
             JOIN world_continent c ON c.id = wc.continent_id
             WHERE wc.iso2 = :iso2',
            ['iso2' => strtoupper($iso2)],
        );

        return \is_string($code) && 2 === \strlen($code) ? strtoupper($code) : null;
    }
}
