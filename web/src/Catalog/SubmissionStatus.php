<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * Moderation lifecycle. Withdrawn is the rider's own exit: terminal like Rejected, never a curator decision.
 * Trashed is a curator's bin: kept 30 days, restorable to the status in `trashed_from`, then deleted.
 *
 * @see docs/specs/moderation-and-contribution.md §3.4
 * @see docs/specs/moderation-and-contribution.md §6
 *
 * @api
 */
enum SubmissionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NeedsInfo = 'needs_info';
    case Withdrawn = 'withdrawn';
    case Trashed = 'trashed';
}
