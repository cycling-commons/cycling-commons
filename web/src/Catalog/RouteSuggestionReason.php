<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Preset correction reasons on a route drawer.
 *
 * @see docs/specs/route-domain.md §7
 */
enum RouteSuggestionReason: string
{
    case BrokenTrack = 'broken-track';
    case TrimPrivacy = 'trim-privacy';
    case Duplicate = 'duplicate';
    case NotRideable = 'not-rideable';
    case Other = 'other';

    /**
     * @return list<string>
     *
     * @api
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
