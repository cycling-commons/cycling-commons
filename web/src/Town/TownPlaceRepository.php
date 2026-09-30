<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Town;

use App\Contribution\SpatialResolver;
use Doctrine\DBAL\Connection;

/**
 * Where a town lies, one point per OpenStreetMap ref.
 *
 * The first town card reader's map names the point (the Photon hit the card
 * opened from) and it is kept: a later request cannot move it. It is what
 * decides which region a town text belongs to, and so which curators approve
 * it and whose direct pen reaches it (moderation-and-contribution.md §3.1b).
 *
 * @see docs/specs/map-and-search.md §6.5
 *
 * @api
 */
final readonly class TownPlaceRepository
{
    public function __construct(private Connection $db, private SpatialResolver $resolver)
    {
    }

    /** Keep the point unless the town already has one. Out-of-range input is ignored. */
    public function record(string $osmRef, float $lat, float $lng): void
    {
        if (!self::valid($lat, $lng)) {
            return;
        }
        $this->db->executeStatement(
            'INSERT INTO town_place (osm_ref, lat, lng, recorded_at) VALUES (:r, :lat, :lng, NOW()) ON CONFLICT (osm_ref) DO NOTHING',
            ['r' => $osmRef, 'lat' => $lat, 'lng' => $lng],
        );
    }

    /** @return array{lat: float, lng: float}|null */
    public function find(string $osmRef): ?array
    {
        $row = $this->db->fetchAssociative('SELECT lat, lng FROM town_place WHERE osm_ref = :r', ['r' => $osmRef]);

        return false === $row ? null : ['lat' => (float) $row['lat'], 'lng' => (float) $row['lng']];
    }

    /**
     * The town's point and the region it lies in, or null while nobody has
     * opened the town on the map. A town outside every region has a point and
     * a null region.
     *
     * @return array{lat: float, lng: float, regionId: ?int, countryCode: string}|null
     */
    public function locate(string $osmRef): ?array
    {
        $point = $this->find($osmRef);
        if (null === $point) {
            return null;
        }

        return $point + $this->resolver->resolve($point['lat'], $point['lng']);
    }

    public static function valid(float $lat, float $lng): bool
    {
        return is_finite($lat) && is_finite($lng) && abs($lat) <= 90.0 && abs($lng) <= 180.0 && !(0.0 === $lat && 0.0 === $lng);
    }
}
