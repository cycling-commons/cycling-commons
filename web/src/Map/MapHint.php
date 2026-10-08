<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Map;

/**
 * A map hint a person may close once they have read it. Closed hints are
 * stored on the account (`User::$closedHints`), never in the browser, so a
 * hint read on a phone stays closed on the laptop. Only a case here can be
 * closed, which is what keeps the stored list to known names.
 *
 * @see docs/specs/map-and-search.md §4.5
 */
enum MapHint: string
{
    /** A curator's note: pins waiting for review follow the areas they moderate, not the region filter. */
    case PendingFollowsAreas = 'pending_follows_areas';

    /** A rider's note: their own pins waiting for review show wherever they added them, even outside the region. */
    case PendingYoursAnywhere = 'pending_yours_anywhere';
}
