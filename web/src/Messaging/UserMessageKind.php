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
    /** Outcomes of a photo takedown request (docs/specs/photo-uploads.md §6b). */
    case MediaTakedownGranted = 'media_takedown_granted';
    case MediaTakedownDeclined = 'media_takedown_declined';
    /**
     * A third party's report was granted and the uploader's photo removed
     * (docs/specs/photo-uploads.md §6c). Its own kind because "your request
     * was granted" would be a lie to somebody who asked for nothing — and the
     * body must not hint at who reported it.
     */
    case MediaRemovedOnReport = 'media_removed_on_report';

    /** @return list<string> @api */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
