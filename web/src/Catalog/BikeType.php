<?php

// SPDX-License-Identifier: AGPL-3.0-only

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

    /** Translation key of the bike type's name, shared by every form and desk that shows it. */
    public function labelKey(): string
    {
        return match ($this) {
            self::Road => 'map.bike_road',
            self::Gravel => 'map.bike_gravel',
            self::Mtb => 'map.bike_mtb',
            self::Ebike => 'map.bike_ebike',
            self::Handbike => 'map.bike_handbike',
            self::Recumbent => 'map.bike_recumbent',
            self::Trike => 'map.bike_trike',
            self::Tandem => 'map.bike_tandem',
        };
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
