<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Roadmap;

use App\Entity\User;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Roadmap\Entity\RoadmapItem;
use App\Roadmap\Entity\RoadmapProposal;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The inbox messages the roadmap sends: the desk's decision to the rider who
 * sent a proposal, and a move to everyone following an item or a proposal.
 *
 * A follower is told three things only: it moved to a group (a proposal
 * promoted to the roadmap moves into one too), it shipped, or it left the
 * roadmap without shipping. Wording edits and votes are not news. Each
 * message is a system message, so it also reaches the rider by email the way
 * every inbox message does.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
final class RoadmapNotices
{
    public const string CHANNEL = 'roadmap';

    /** What happened to a followed item: a group it moved to, or one of these two. */
    public const string SHIPPED = 'shipped';
    public const string REMOVED = 'removed';

    public function __construct(
        private readonly MessageService $messages,
        private readonly RoadmapMarks $marks,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Tells the proposer what the desk decided, with the note written for
     * this decision (null: none). The caller flushes.
     */
    public function proposalDecided(RoadmapProposal $proposal, ?string $note): void
    {
        $userId = $proposal->getUserId();
        if (null === $userId) {
            return;
        }
        $bodyKey = match ($proposal->getStatus()) {
            ProposalStatus::Open => 'messages.body.roadmap_idea_open',
            ProposalStatus::Promoted => 'messages.body.roadmap_idea_promoted_'.($proposal->getPromotedItem()?->getStatus() ?? 'later'),
            ProposalStatus::Declined => 'messages.body.roadmap_idea_declined',
            ProposalStatus::Pending => null,
        };
        if (null === $bodyKey) {
            return;
        }
        $this->messages->sendSystem(
            $userId,
            UserMessageKind::RoadmapIdeaOutcome,
            self::CHANNEL,
            (int) $proposal->getId(),
            $proposal->getTitle(),
            $bodyKey,
            [],
            $note,
        );
    }

    /**
     * Tells every follower of an item what happened to it, each in their own
     * language. The caller flushes.
     *
     * @param string   $what     `now`, `next`, `later`, {@see self::SHIPPED} or {@see self::REMOVED}
     * @param int|null $skipUser a rider told already as the proposer
     *
     * @return int how many riders were told
     */
    public function itemMoved(RoadmapItem $item, string $what, ?int $skipUser = null): int
    {
        return $this->tell(
            $item->getRef(),
            (int) $item->getId(),
            $what,
            static fn (?string $locale): string => $item->textFor($locale ?? RoadmapItem::FALLBACK_LOCALE)?->getTitle() ?? '',
            $skipUser,
        );
    }

    /**
     * Tells every follower of an open proposal what happened to it: promoted
     * into a group, or taken off the Proposals tab. The caller flushes.
     *
     * @param string $what `now`, `next`, `later` or {@see self::REMOVED}
     */
    public function proposalMoved(RoadmapProposal $proposal, string $what, ?int $skipUser = null): int
    {
        return $this->tell($proposal->getRef(), (int) $proposal->getId(), $what, $proposal->getShownTitle(), $skipUser);
    }

    /** @param string|\Closure(?string): string $label the name: one for everyone, or per rider's language (null: theirs is unknown) */
    private function tell(string $ref, int $refId, string $what, string|\Closure $label, ?int $skipUser): int
    {
        $told = 0;
        foreach ($this->marks->followers($ref) as $userId) {
            if ($userId === $skipUser) {
                continue;
            }
            $sent = $this->messages->sendSystem(
                $userId,
                UserMessageKind::RoadmapItemMoved,
                self::CHANNEL,
                $refId,
                mb_substr(\is_string($label) ? $label : $label($this->em->find(User::class, $userId)?->getLocale()), 0, 200),
                'messages.body.roadmap_moved_'.$what,
            );
            if (null !== $sent) {
                ++$told;
            }
        }

        return $told;
    }
}
