<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Owns every base-location write so the derived set can never drift from the
 * point (region-scoping-design.md §3): settings save + the map "Set my area"
 * endpoint call apply()/clear(); ImportCatalogCommand calls rederiveAll()
 * inside its import transaction after recomputeMembership() - there is no
 * queue in this app, so re-derivation is transactional-inline by design.
 *
 * @api Autowired by the DI container; consumed by SettingsController,
 *      MyAreaController, and ImportCatalogCommand.
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
        // (request precision == stored precision, region-scoping-design.md §4).
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
     * Mass re-derivation after region geometry changes (region-scoping-design.md
     * §3): every rider with a base point gets a fresh region/country set from
     * BaseAreaResolver, in the same id ASC order the resolver's own query uses
     * for its tie-breaks. Runs inside the caller's transaction (ImportCatalogCommand,
     * directly after recomputeMembership()) so newly-imported region geometry is
     * already visible to the resolver's reads.
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
