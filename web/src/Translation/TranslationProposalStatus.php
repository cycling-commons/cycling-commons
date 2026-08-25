<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

/**
 * Curator decision lifecycle for a rider translation proposal.
 *
 * @see docs/specs/translations.md §3.1
 *
 * @api
 */
enum TranslationProposalStatus: string
{
    case Pending = 'pending';
    case NeedsInfo = 'needs_info';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
