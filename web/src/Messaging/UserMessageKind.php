<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Messaging;

/**
 * Dashboard message kind.
 *
 * @see docs/specs/moderation-and-contribution.md §7
 *
 * @api
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
    case CuratorApplicationReceived = 'curator_app_received';
    case ModeratorAreasChanged = 'areas_changed';
    case CuratorMessage = 'curator_message';
    case RiderReply = 'rider_reply';
    /** Photo takedown (docs/specs/photo-uploads.md §6b). */
    case MediaTakedownGranted = 'media_takedown_granted';
    case MediaTakedownDeclined = 'media_takedown_declined';
    /** Third-party report granted (docs/specs/photo-uploads.md §6c). */
    case MediaRemovedOnReport = 'media_removed_on_report';
    /** Photo hidden pending review (docs/specs/photo-uploads.md §6c). */
    case MediaHiddenPendingReview = 'media_hidden_pending_review';
    case MediaRestoredAfterReview = 'media_restored_after_review';
    /** Release slower than the wizard window (docs/specs/media-storage-architecture.md §3.3). */
    case MediaReady = 'media_ready';
    case MediaScanRejected = 'media_scan_rejected';
    /** In-site translation proposal (docs/specs/translations.md §5). */
    case TranslationApproved = 'translation_approved';
    case TranslationRejected = 'translation_rejected';
    case TranslationNeedsInfo = 'translation_needs_info';
    /** A curator answered a bug report (docs/specs/contact-and-support.md §9). */
    case BugOutcome = 'bug_outcome';

    /** @return list<string>
     *
     * @api
     */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
