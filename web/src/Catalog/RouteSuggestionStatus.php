<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Curator resolution state of a route_suggestion.
 *
 * @see docs/specs/route-domain.md §7
 */
enum RouteSuggestionStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Dismissed = 'dismissed';
}
