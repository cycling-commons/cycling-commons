<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Canonical bikeTypes shape: a list of BikeType values. Normalizes legacy
 * single strings, the retired `Any`, and the old separate `handbike`
 * attribute (Handbike is now a bikeTypes value).
 *
 * @see docs/specs/route-domain.md §9
 *
 * @api Used by the proposal/curator forms, RouteProposalService, CatalogProvider.
 */
final class BikeTypeVocabulary
{
    /** @return list<string> */
    public static function normalize(mixed $stored, mixed $legacyHandbike = null): array
    {
        $valid = BikeType::values();
        $list = match (true) {
            'Any' === $stored => ['Road', 'Gravel', 'MTB', 'E-bike'],
            \is_array($stored) => $stored,
            \is_string($stored) && '' !== $stored => [$stored],
            default => [],
        };

        $out = [];
        foreach ($list as $t) {
            if (\is_string($t) && \in_array($t, $valid, true) && !\in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
        if ((\is_string($legacyHandbike) && 'No' !== $legacyHandbike && 'Unknown' !== $legacyHandbike && '' !== $legacyHandbike || true === $legacyHandbike) && !\in_array('Handbike', $out, true)) {
            $out[] = 'Handbike';
        }

        return $out;
    }
}
