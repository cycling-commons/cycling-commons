<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Kind of riding, excluding hardware (that is {@see BikeType}).
 *
 * @see docs/specs/map-and-search.md §4.4
 *
 * @api
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
