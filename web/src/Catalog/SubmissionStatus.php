<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Moderation lifecycle. Withdrawn is the rider's own exit: terminal like Rejected, never a curator decision.
 *
 * @see docs/specs/moderation-and-contribution.md §3.4
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
}
