<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Map chrome theme (rail, drawer, legend), not the basemap. Stored on the profile so a shared device does not leak the previous rider's choice; anonymous visitors use localStorage.
 *
 * @see docs/specs/map-and-search.md §4.6
 *
 * @api
 */
enum MapTheme: string
{
    case Dark = 'dark';
    case Light = 'light';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
