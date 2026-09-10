<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Deduplicated list of valid `BikeType` values; Handbike is one of them, not a separate field.
 *
 * @see docs/specs/route-domain.md §9
 *
 * @api
 */
final class BikeTypeVocabulary
{
    /**
     * @return list<string>
     */
    public static function normalize(mixed $stored): array
    {
        if (!\is_array($stored)) {
            return [];
        }

        $valid = BikeType::values();
        $out = [];
        foreach ($stored as $t) {
            if (\is_string($t) && \in_array($t, $valid, true) && !\in_array($t, $out, true)) {
                $out[] = $t;
            }
        }

        return $out;
    }
}
