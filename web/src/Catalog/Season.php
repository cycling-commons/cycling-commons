<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Season a rider tags a route vote with. `current()` is Northern-hemisphere; used only to pre-select the picker.
 *
 * @see docs/specs/route-domain.md §2.2
 *
 * @api
 */
enum Season: string
{
    case Spring = 'spring';
    case Summer = 'summer';
    case Autumn = 'autumn';
    case Winter = 'winter';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /** Translation key of the season's name. */
    public function labelKey(): string
    {
        return 'map.season_'.$this->value;
    }

    public static function current(\DateTimeImmutable $now): self
    {
        return match ((int) $now->format('n')) {
            3, 4, 5 => self::Spring,
            6, 7, 8 => self::Summer,
            9, 10, 11 => self::Autumn,
            default => self::Winter,
        };
    }
}
