<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Messaging;

/**
 * What a dashboard message is about: one kind per decision outcome per
 * channel, plus free-form curator messages and the rider's needs-info
 * reply.
 *
 * @see docs/specs/moderation-and-contribution.md §7
 *
 * @api Messaging vocabulary.
 */
enum UserMessageKind: string
{
    case SubmissionApproved = 'submission_approved';
    case SubmissionRejected = 'submission_rejected';
    case SubmissionNeedsInfo = 'submission_needs_info';
    case RouteApproved = 'route_approved';
    case RouteRejected = 'route_rejected';
    case RouteRetired = 'route_retired';
    case CorrectionDone = 'correction_done';
    case CorrectionDismissed = 'correction_dismissed';
    case CuratorMessage = 'curator_message';
    case RiderReply = 'rider_reply';

    /** @return list<string> @api */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
