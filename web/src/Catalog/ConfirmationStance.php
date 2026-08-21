<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Community stance on a confirmable item. Votable types use the vote/best-of funnel instead.
 *
 * @see docs/specs/moderation-and-contribution.md §10.1
 *
 * @api
 */
enum ConfirmationStance: string
{
    case Potable = 'potable';
    case NotPotable = 'not_potable';
    case Exists = 'exists';
    /** Warning, not a vouching — counts in the tally, never verifies. docs/specs/edit-items/A-road-surface.md (Confirmation: "as described") */
    case NotAsDescribed = 'not_as_described';
}
