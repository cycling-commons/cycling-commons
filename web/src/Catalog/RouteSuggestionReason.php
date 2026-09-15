<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Preset correction reasons on a route drawer, plus the photo correction.
 *
 * @see docs/specs/route-domain.md §4.5, §7
 */
enum RouteSuggestionReason: string
{
    case BrokenTrack = 'broken-track';
    case TrimPrivacy = 'trim-privacy';
    case Duplicate = 'duplicate';
    case NotRideable = 'not-rideable';
    case Other = 'other';
    /**
     * Photos for the route, sent on `/propose-route?route=<id>`, never through
     * the drawer's correction endpoint (route-domain.md §4.5).
     */
    case Photo = 'photo';

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
