<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * The four seasons a rider tags a route vote with (docs/specs/route-domain.md §7, §4.2).
 * `current()` maps a date to a Northern-hemisphere season (route-domain spec
 * §8.1). The harvested data is Wallonia, so this is only used to pre-select
 * the voter's picker; it is not exact for other hemispheres.
 *
 * @api Route-domain vocabulary (typed votes).
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
