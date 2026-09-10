<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Opening view mode. `Auto` lets the region decide; Everything/Confirmed/Curated override it. Stored on the profile so a shared device does not leak the previous rider's choice.
 *
 * @see docs/specs/map-and-search.md §4.2
 *
 * @api
 */
enum MapViewMode: string
{
    case Auto = 'auto';
    case Everything = 'everything';
    case Confirmed = 'confirmed';
    case Curated = 'curated';

    /**
     * Token for `#mode button[data-m]`. Not the enum value: the toggle has always said `all`, not `everything`. `Auto` has no token — it resolves per region at load.
     */
    public function clientToken(): ?string
    {
        return match ($this) {
            self::Auto => null,
            self::Everything => 'all',
            self::Confirmed => 'confirmed',
            self::Curated => 'curated',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }
}
