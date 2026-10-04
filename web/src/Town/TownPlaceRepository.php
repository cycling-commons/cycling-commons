<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Town;

use App\Contribution\SpatialResolver;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Where a town lies, one point per OpenStreetMap ref.
 *
 * The point decides which region a town text belongs to, and so which curators
 * approve it and whose direct pen reaches it (moderation-and-contribution.md
 * §3.1b). It is therefore read from OpenStreetMap by the server and never
 * taken from a request: a point a client could name would let anyone file a
 * town in any region, or in none.
 *
 * A row with `from_osm` false holds a point a reader's map once sent; it is
 * not trusted, and the next {@see self::locate()} replaces it.
 *
 * @see docs/specs/map-and-search.md §6.5
 *
 * @api
 */
final readonly class TownPlaceRepository
{
    public function __construct(
        private Connection $db,
        private SpatialResolver $resolver,
        private TownPointSource $points,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The town's point and the region it lies in, or null while we cannot say:
     * nobody has opened the town on the map yet, OpenStreetMap does not have
     * it, or OpenStreetMap did not answer. A town outside every region has a
     * point and a null region.
     *
     * @return array{lat: float, lng: float, regionId: ?int, countryCode: string}|null
     */
    public function locate(string $osmRef): ?array
    {
        $point = $this->point($osmRef);
        if (null === $point) {
            return null;
        }

        return $point + $this->resolver->resolve($point['lat'], $point['lng']);
    }

    /** @return array{lat: float, lng: float}|null */
    private function point(string $osmRef): ?array
    {
        if (1 !== preg_match('~^(node|way|relation)/(\d{1,16})$~', $osmRef, $m)) {
            return null;
        }
        $row = $this->db->fetchAssociative('SELECT lat, lng, from_osm FROM town_place WHERE osm_ref = :r', ['r' => $osmRef]);
        if (false !== $row && true === $row['from_osm']) {
            return ['lat' => (float) $row['lat'], 'lng' => (float) $row['lng']];
        }
        // Only a town whose card somebody opened is looked up. That read spent
        // the third-party budget already (TownController), so this is one
        // OpenStreetMap request per town, not one per form post.
        if (false === $this->db->fetchOne('SELECT 1 FROM town_summary WHERE osm_ref = :r LIMIT 1', ['r' => $osmRef])) {
            return null;
        }

        try {
            $point = $this->points->point($m[1], (int) $m[2]);
        } catch (TownSourceUnavailable $e) {
            $this->logger->warning('Town point lookup failed', ['ref' => $osmRef, 'why' => $e->getMessage()]);

            return null;
        }
        if (null === $point || !self::valid($point['lat'], $point['lng'])) {
            return null;
        }
        $this->db->executeStatement(
            'INSERT INTO town_place (osm_ref, lat, lng, from_osm, recorded_at) VALUES (:r, :lat, :lng, TRUE, NOW())
             ON CONFLICT (osm_ref) DO UPDATE SET lat = EXCLUDED.lat, lng = EXCLUDED.lng, from_osm = TRUE, recorded_at = EXCLUDED.recorded_at',
            ['r' => $osmRef, 'lat' => $point['lat'], 'lng' => $point['lng']],
        );

        return $point;
    }

    private static function valid(float $lat, float $lng): bool
    {
        return is_finite($lat) && is_finite($lng) && abs($lat) <= 90.0 && abs($lng) <= 180.0 && !(0.0 === $lat && 0.0 === $lng);
    }
}
