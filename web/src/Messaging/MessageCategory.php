<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Messaging;

/**
 * The three shelves a rider's inbox sorts onto (docs/specs/moderation-and-contribution.md §7.9).
 *
 * The split is by WHO STARTED IT, not by which subsystem wrote the row —
 * because that is the question a reader is actually asking when they filter:
 *
 * - **Contributions** — an answer to something the rider offered or asked for.
 *   They submitted a place, proposed a route, flagged a correction, asked for
 *   their own photo to come down. Something they did has an outcome.
 * - **Notices** — something the platform did that the rider did not start. A
 *   stranger reported their photo and it was hidden, removed, or put back.
 *   These arrive unbidden, which is exactly why they get their own shelf: they
 *   are the ones nobody should have to dig for.
 * - **General** — a person wrote to them. Today that is a curator's free-form
 *   note; anything else human-authored belongs here too.
 *
 * `RiderReply` is on no shelf on purpose. It is the rider's own answer to a
 * needs-info question, addressed to the deciding curator so the desk can find
 * it, and the inbox has always excluded it (see {@see MessageService::listFor}).
 *
 * @api Consumed by MessageService and the messages dashboard.
 */
enum MessageCategory: string
{
    case Contributions = 'contributions';
    case Notices = 'notices';
    case General = 'general';

    /**
     * The kinds on this shelf.
     *
     * @return list<UserMessageKind>
     */
    public function kinds(): array
    {
        return match ($this) {
            self::Contributions => [
                UserMessageKind::SubmissionApproved,
                UserMessageKind::SubmissionRejected,
                UserMessageKind::SubmissionNeedsInfo,
                UserMessageKind::RouteApproved,
                UserMessageKind::RouteRejected,
                UserMessageKind::RouteRetired,
                UserMessageKind::CorrectionDone,
                UserMessageKind::CorrectionDismissed,
                // The rider ASKED for these two, so they answer something the
                // rider did — unlike the report-driven trio below.
                UserMessageKind::MediaTakedownGranted,
                UserMessageKind::MediaTakedownDeclined,
                // The rider offered a photo, and this is what became of it:
                // it finished checking after they had stopped waiting, or the
                // worker refused the file. Contributions, not Notices -
                // nothing arrived unbidden, they sent us the photo.
                UserMessageKind::MediaReady,
                UserMessageKind::MediaScanRejected,
            ],
            self::Notices => [
                UserMessageKind::MediaRemovedOnReport,
                UserMessageKind::MediaHiddenPendingReview,
                UserMessageKind::MediaRestoredAfterReview,
            ],
            self::General => [
                UserMessageKind::CuratorMessage,
                UserMessageKind::CuratorApplicationReceived,
                UserMessageKind::ModeratorAreasChanged,
            ],
        };
    }

    /**
     * The stored values of {@see kinds()}, for a SQL `IN`.
     *
     * @return list<string>
     */
    public function kindValues(): array
    {
        return array_map(static fn (UserMessageKind $k): string => $k->value, $this->kinds());
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Contributions => 'messages.filter.contributions',
            self::Notices => 'messages.filter.notices',
            self::General => 'messages.filter.general',
        };
    }

    /**
     * Every kind that reaches the inbox, on some shelf.
     *
     * A guard, not a convenience: a new UserMessageKind that nobody files
     * here would be invisible under every filter but "All", which is the kind
     * of bug that only surfaces when a rider says they never got told. The
     * test asserts this covers the enum minus RiderReply.
     *
     * @return list<UserMessageKind>
     */
    public static function allFiledKinds(): array
    {
        return array_merge(...array_map(static fn (self $c): array => $c->kinds(), self::cases()));
    }
}
