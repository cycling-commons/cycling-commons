<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * `Form` is the submitter's own answer: recorded, never counted toward verification or the public tally.
 *
 * @see docs/specs/moderation-and-contribution.md §6.3
 *
 * @api
 */
enum ConfirmationSource: string
{
    case Drawer = 'drawer';
    case Form = 'form';
}
