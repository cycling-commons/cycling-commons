<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * The shared bike-type enum (route-domain spec D6): one value set for route
 * suitability declarations and — in later phases — typed votes and ride
 * confirmations. Handbike is deliberately first-class (accessibility lists
 * rank per bike type, spec goal 9).
 *
 * @api Route-domain vocabulary.
 */
enum BikeType: string
{
    case Road = 'Road';
    case Gravel = 'Gravel';
    case Mtb = 'MTB';
    case Ebike = 'E-bike';
    case Handbike = 'Handbike';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
