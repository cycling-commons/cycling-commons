<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Shared bike-type enum for suitability, votes, and ride confirmations.
 *
 * @see docs/specs/route-domain.md §8.3
 *
 * @api
 */
enum BikeType: string
{
    case Road = 'Road';
    case Gravel = 'Gravel';
    case Mtb = 'MTB';
    case Ebike = 'E-bike';
    case Handbike = 'Handbike';
    case Recumbent = 'Recumbent';
    case Trike = 'Trike';
    case Tandem = 'Tandem';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    /**
     * Specialty hardware: best-of is gated by declared suitability.
     *
     * @see docs/specs/route-domain.md §8.3
     *
     * @api
     */
    public function isSpecialty(): bool
    {
        return match ($this) {
            self::Handbike, self::Recumbent, self::Trike, self::Tandem => true,
            default => false,
        };
    }
}
