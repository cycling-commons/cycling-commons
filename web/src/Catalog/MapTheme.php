<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * A rider's stored preference for the map CHROME theme: the rail, drawer,
 * legend and panels around the map canvas (map-and-search.md §4.6). The
 * basemap tiles are the same in both — liberty is a light style already;
 * only our dark chrome gains a light twin.
 *
 * Dark is the default: it is the look the map has always had, so an existing
 * rider sees no change until they ask for one.
 *
 * Stored on the profile rather than in localStorage on purpose, same owner
 * decision as MapViewMode (2026-07-24): people share devices, and a
 * device-scoped default would leak one person's choice to the next. Anonymous
 * visitors have no profile, so their choice stays in localStorage.
 *
 * @api User-preference vocabulary; consumed by SettingsType, User and MapController.
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
