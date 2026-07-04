<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Moderation lifecycle of a submission. Pending and NeedsInfo rows appear
 * in the curator queue; Approved/Rejected are terminal (audit record).
 *
 * @api Catalog domain enum.
 */
enum SubmissionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case NeedsInfo = 'needs_info';
}
