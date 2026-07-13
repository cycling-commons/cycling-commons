<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

/**
 * Audit-log action constants for Trash (moderation-feedback spec M9) — an
 * immediate, permanent hard delete of spam/abusive contributions. Every
 * trash action is audited content-free: the AdminActionLogger note carries a
 * channel + ref + a couple of content-free facts, never the rider's body or
 * curator note text.
 *
 * @api Read by ModerationService/RouteModerationService when logging trash actions.
 */
final class TrashActions
{
    public const string TrashSubmission = 'trash_submission';
    public const string TrashCorrection = 'trash_correction';
    public const string TrashRouteProposal = 'trash_route_proposal';

    private function __construct()
    {
        // Static holder only.
    }
}
