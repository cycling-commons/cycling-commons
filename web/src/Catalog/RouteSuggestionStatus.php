<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Curator resolution state of a route_suggestion. Trashed is the curators' bin:
 * kept 30 days, restorable to the status in `trashed_from`, then deleted.
 *
 * @see docs/specs/route-domain.md §7
 */
enum RouteSuggestionStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Dismissed = 'dismissed';
    case Trashed = 'trashed';
}
