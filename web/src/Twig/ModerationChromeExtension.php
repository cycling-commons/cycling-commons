<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Messaging\CuratorRoom;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\SubmissionQueue;
use App\Support\SupportRepository;
use App\Translation\DecisionService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Open-queue counts for curator chrome (desk tabs and the account chip).
 *
 * Twig functions rather than controller variables: these tabs render on pages
 * owned by several controllers, and a badge that only appears on some of them
 * is worse than no badge at all.
 *
 * @see docs/specs/translations.md §5
 * @see docs/specs/moderation-and-contribution.md §13.7
 *
 * @api
 */
final class ModerationChromeExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly DecisionService $decisions,
        private readonly SubmissionQueue $submissions,
        private readonly ModerationScopeProvider $scopes,
        private readonly CuratorRoom $room,
        private readonly SupportRepository $support,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('pending_translation_count', $this->pendingTranslationCount(...)),
            new TwigFunction('pending_submission_count', $this->pendingSubmissionCount(...)),
            new TwigFunction('curator_room_unread', $this->curatorRoomUnread(...)),
            new TwigFunction('open_contact_count', $this->openContactCount(...)),
            new TwigFunction('open_bug_count', $this->openBugCount(...)),
            new TwigFunction('open_report_count', $this->openReportCount(...)),
        ];
    }

    /**
     * Unanswered contact messages (contact-and-support.md §8).
     *
     * Unscoped, like the desk itself: a GDPR request has no region, and a
     * badge shared out by geography would leave one sitting behind whichever
     * curator happens to be away.
     */
    public function openContactCount(): int
    {
        if (!$this->security->getUser() instanceof User || !$this->security->isGranted('ROLE_CURATOR')) {
            return 0;
        }

        return $this->support->openMessageCount();
    }

    /** Bugs still costing somebody something (contact-and-support.md §9). */
    public function openBugCount(): int
    {
        if (!$this->security->getUser() instanceof User || !$this->security->isGranted('ROLE_CURATOR')) {
            return 0;
        }

        return $this->support->openBugCount();
    }

    /**
     * Reports nobody has answered (content-reports.md §9).
     *
     * Unscoped, like takedowns and the inbox. A DSA Article 16 report has a
     * clock on it and no region, so a badge shared out by geography would leave
     * one waiting behind whichever curator happens to be away.
     */
    public function openReportCount(): int
    {
        if (!$this->security->getUser() instanceof User || !$this->security->isGranted('ROLE_CURATOR')) {
            return 0;
        }

        return $this->support->openReportCount();
    }

    public function pendingTranslationCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->security->isGranted('ROLE_CURATOR')) {
            return 0;
        }

        return $this->decisions->pendingCount();
    }

    public function pendingSubmissionCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->security->isGranted('ROLE_CURATOR')) {
            return 0;
        }

        return $this->submissions->total($this->scopes->scopeFor($user));
    }

    /**
     * Room posts since this curator last had the room open (§13.7).
     */
    public function curatorRoomUnread(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->security->isGranted('ROLE_CURATOR')) {
            return 0;
        }

        return $this->room->unreadCount((int) $user->getId());
    }
}
