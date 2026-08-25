<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Messaging;

/**
 * Inbox shelves: contributions the rider started, unbidden notices, and human notes.
 *
 * @see docs/specs/moderation-and-contribution.md §7.9
 *
 * @api
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
                UserMessageKind::MediaTakedownGranted,
                UserMessageKind::MediaTakedownDeclined,
                UserMessageKind::MediaReady,
                UserMessageKind::MediaScanRejected,
                UserMessageKind::TranslationApproved,
                UserMessageKind::TranslationRejected,
                UserMessageKind::TranslationNeedsInfo,
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
     * @return list<UserMessageKind>
     */
    public static function allFiledKinds(): array
    {
        return array_merge(...array_map(static fn (self $c): array => $c->kinds(), self::cases()));
    }
}
