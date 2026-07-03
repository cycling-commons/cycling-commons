<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Lifecycle state of a catalog row (edit-items funnel). Imports always enter
 * as Unverified; upsert-updates never touch state. @api Catalog domain enum.
 */
enum ItemState: string
{
    case Submitted = 'submitted';
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Retired = 'retired';
}
