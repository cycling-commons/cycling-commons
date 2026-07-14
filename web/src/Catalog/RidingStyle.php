<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Rider riding-style vocabulary (spec 2026-07-14): what KIND of riding a
 * rider does, deliberately excluding hardware — bikes (E-bike, Handbike,
 * Recumbent, Trike, Tandem, …) are declared separately via BikeType. The
 * map's Discipline chips (today a visual stub) get re-based onto this enum
 * when preference prefiltering is built; this enum is that contract.
 *
 * @api User-preference vocabulary; consumed by SettingsType and User.
 */
enum RidingStyle: string
{
    case Road = 'Road';
    case Gravel = 'Gravel';
    case Touring = 'Touring';
    case Bikepacking = 'Bikepacking';
    case Trail = 'Trail';
    case Urban = 'Urban';
    case Leisure = 'Leisure';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
