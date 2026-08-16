<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Moderation lifecycle of a submission. Pending and NeedsInfo rows appear
 * in the curator queue; Approved/Rejected are terminal (audit record).
 * Withdrawn is the rider's own exit (owner 2026-08-16): terminal like
 * Rejected and swept on the same retention clock, but it was never a
 * curator's decision - it never counts in moderation activity and sends
 * no outcome message.
 *
 * @api Catalog domain enum.
 */
enum SubmissionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NeedsInfo = 'needs_info';
    case Withdrawn = 'withdrawn';
}
