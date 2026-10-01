<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

/**
 * Content-free audit actions for Trash: into the bin, out of it by a
 * curator's restore, and deleted for good by the purge (no actor).
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
    public const string RestoreSubmission = 'restore_submission';
    public const string RestoreCorrection = 'restore_correction';
    public const string RestoreRouteProposal = 'restore_route_proposal';
    public const string PurgeSubmission = 'purge_trashed_submission';
    public const string PurgeCorrection = 'purge_trashed_correction';
    public const string PurgeRouteProposal = 'purge_trashed_route_proposal';
}
