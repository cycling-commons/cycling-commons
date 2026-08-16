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
    /* An automatic receipt, not a person writing: sent the moment a curator
       application arrives, so the wait for a human decision does not start in
       silence. Its own kind rather than CuratorMessage because the subject
       line is derived from the kind, and "Message from a curator" is not what
       this is (owner 2026-08-14). */
    case CuratorApplicationReceived = 'curator_app_received';
    /* An admin changed which regions this moderator covers. Their scope decides
       what they can see and act on, so a silent change means finding out by
       noticing a desk has gone quiet (owner 2026-08-14). */
    case ModeratorAreasChanged = 'areas_changed';
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
    /**
     * A report has hidden the uploader's photo while a curator reviews it
     * (docs/specs/photo-uploads.md §6c). Hidden, not removed — only a curator
     * removes anything, and the photo comes straight back if the report does
     * not hold up. Told at once because a contributor who finds their photo
     * missing with no explanation has every reason to think we deleted it.
     */
    case MediaHiddenPendingReview = 'media_hidden_pending_review';
    /** …and the other half of that promise: it is back. */
    case MediaRestoredAfterReview = 'media_restored_after_review';
    /**
     * The checks finished after the wizard had stopped waiting
     * (docs/specs/media-storage-architecture.md §3.3). Sent ONLY when the
     * release took longer than the wizard's patience window, because that is
     * exactly when a rider was promised "we will let you know" - a message for
     * every photo that cleared in milliseconds would be noise nobody asked
     * for.
     */
    case MediaReady = 'media_ready';
    /**
     * The worker refused the file: the scanner found something, or the bytes
     * would not decode into a photo at all. Its own kind, and not a rejection
     * by a curator - nobody looked at it, and the rider's next step is to send
     * a different file rather than to argue with a decision.
     */
    case MediaScanRejected = 'media_scan_rejected';

    /** @return list<string> @api */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
