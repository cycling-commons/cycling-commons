<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Base-location writes; derived regions stay in sync with the point.
 *
 * @see docs/specs/map-and-search.md §4.5
 *
 * @api
 */
final class BaseLocationService
{
    public function __construct(
        private readonly Connection $db,
        private readonly BaseAreaResolver $resolver,
    ) {
    }

    /** apply()/clear() do NOT flush - the caller owns the flush/transaction. */
    public function apply(User $user, float $lat, float $lng, ?string $place, int $radiusKm): void
    {
        $user->setBaseLocation($lat, $lng, $place);
        $user->setBaseRadiusKm($radiusKm);
        // Derive from the STORED (coarse, clamped) values - never the raw input
        // (request precision == stored precision, map-and-search.md §4.5).
        $derived = $this->resolver->resolve(
            $user->getBaseLat() ?? $lat,
            $user->getBaseLng() ?? $lng,
            $user->getBaseRadiusKm(),
        );
        $user->setBaseRegionIds($derived['regionIds']);
        $user->setBaseCountryCodes($derived['countryCodes']);
    }

    public function clear(User $user): void
    {
        $user->clearBaseLocation();
    }

    /**
     * Re-derive every rider's base regions after geometry changes (docs/specs/map-and-search.md §4.5).
     *
     * @return int users updated
     */
    public function rederiveAll(): int
    {
        /** @var list<array{id: int|string, base_lng: float|string, base_lat: float|string, base_radius_km: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, ST_X(base_point) AS base_lng, ST_Y(base_point) AS base_lat, base_radius_km
               FROM users
              WHERE base_point IS NOT NULL
              ORDER BY id ASC',
        );

        foreach ($rows as $row) {
            $derived = $this->resolver->resolve((float) $row['base_lat'], (float) $row['base_lng'], (int) $row['base_radius_km']);
            $this->db->executeStatement(
                'UPDATE users SET base_region_ids = :ids, base_country_codes = :ccs WHERE id = :id',
                [
                    'ids' => json_encode($derived['regionIds'], \JSON_THROW_ON_ERROR),
                    'ccs' => json_encode($derived['countryCodes'], \JSON_THROW_ON_ERROR),
                    'id' => $row['id'],
                ],
            );
        }

        return \count($rows);
    }

    /**
     * rederiveAll() for riders whose base area reaches within $bandDeg of the listed countries' regions; writes only changed rows.
     *
     * @param list<string> $countries ISO 3166-1 alpha-2, upper case
     *
     * @return int users updated
     */
    public function rederiveNear(array $countries, float $bandDeg): int
    {
        /** @var list<array{id: int|string, base_lng: float|string, base_lat: float|string, base_radius_km: int|string, ids: string, ccs: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT u.id, ST_X(u.base_point) AS base_lng, ST_Y(u.base_point) AS base_lat, u.base_radius_km,
                    u.base_region_ids::text AS ids, u.base_country_codes::text AS ccs
               FROM users u
              WHERE u.base_point IS NOT NULL
                AND EXISTS (SELECT 1 FROM region r
                             WHERE r.country_code IN (:ccs) AND r.geom IS NOT NULL
                               AND (ST_DWithin(r.geom, u.base_point, :deg)
                                    OR ST_DWithin(r.geom::geography, u.base_point::geography, u.base_radius_km * 1000.0)))
              ORDER BY u.id ASC',
            ['ccs' => $countries, 'deg' => $bandDeg],
            ['ccs' => ArrayParameterType::STRING],
        );

        $updated = 0;
        foreach ($rows as $row) {
            $derived = $this->resolver->resolve((float) $row['base_lat'], (float) $row['base_lng'], (int) $row['base_radius_km']);
            if (json_decode($row['ids'], true) === $derived['regionIds'] && json_decode($row['ccs'], true) === $derived['countryCodes']) {
                continue;
            }
            $this->db->executeStatement(
                'UPDATE users SET base_region_ids = :ids, base_country_codes = :ccs WHERE id = :id',
                [
                    'ids' => json_encode($derived['regionIds'], \JSON_THROW_ON_ERROR),
                    'ccs' => json_encode($derived['countryCodes'], \JSON_THROW_ON_ERROR),
                    'id' => $row['id'],
                ],
            );
            ++$updated;
        }

        return $updated;
    }
}
