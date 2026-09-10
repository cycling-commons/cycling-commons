<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

/**
 * Content-free audit actions for Trash.
 *
 * @see docs/specs/moderation-and-contribution.md §6
 *
 * @api
 */
final class TrashActions
{
    public const string TrashSubmission = 'trash_submission';
    public const string TrashCorrection = 'trash_correction';
    public const string TrashRouteProposal = 'trash_route_proposal';
}
