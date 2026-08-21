<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
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
}
