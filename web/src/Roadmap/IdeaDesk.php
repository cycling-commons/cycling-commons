<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Roadmap;

use App\Content\ReleaseNotes;
use App\Roadmap\Entity\RoadmapProposal;
use App\Roadmap\Entity\RoadmapProposalImage;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The decisions the roadmap desk takes about a rider's proposal.
 *
 * - **Open for votes**, optionally under the desk's own wording: the
 *   proposal shows on the Proposals tab, where riders vote for it and follow
 *   it. Taken again on an open proposal, it only changes the wording.
 * - **Promote** to the roadmap, into a group: a new roadmap item in the
 *   proposal's words and language, which the desk can then translate on the
 *   items desk. The proposal's votes and follows move to the item.
 * - **Decline**, always with a reason the proposer reads. An open proposal
 *   can be declined too: it leaves the Proposals tab and its marks go.
 *
 * The desk also chooses whether the rider's picture shows with it, which it
 * can only where the rider allowed that when sending.
 *
 * The proposer hears every change of status, with the note. Followers hear a
 * promotion and a removal; the proposer is not told the same news twice.
 * Each decision is one transaction that holds the proposal's row.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
final class IdeaDesk
{
    public const string OPEN = 'open';
    public const string PROMOTE = 'promote';
    public const string DECLINE = 'decline';

    /** MessageService refuses a longer note. */
    public const int NOTE_MAX = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RoadmapNotices $notices,
        private readonly RoadmapMarks $marks,
        private readonly ItemDesk $items,
    ) {
    }

    /**
     * @throws RoadmapRefused when the decision does not fit the proposal's status, or a field is wrong
     */
    /**
     * @param bool|null $showPicture the desk's choice to show the rider's picture publicly (null: leave it as it is);
     *                               only taken where the rider allowed it
     */
    public function decide(RoadmapProposal $proposal, string $action, ?string $group, ?string $publicTitle, ?string $publicBody, ?string $note, int $deciderId, ?bool $showPicture = null): void
    {
        $note = self::clean($note);
        if (null !== $note && mb_strlen($note) > self::NOTE_MAX) {
            throw new RoadmapRefused('roadmap.desk.error_note_long');
        }
        if (!\in_array($action, [self::OPEN, self::PROMOTE, self::DECLINE], true)) {
            throw new RoadmapRefused('roadmap.desk.error_action');
        }
        // Fields not sent at all keep the wording there is; sent empty, they
        // clear it, so the rider's own words show.
        $keepWording = null === $publicTitle && null === $publicBody;
        $publicTitle = self::clean($publicTitle);
        $publicBody = self::clean($publicBody);
        if (null !== $publicTitle && mb_strlen($publicTitle) > RoadmapProposal::TITLE_MAX) {
            throw new RoadmapRefused('roadmap.desk.error_title_long');
        }
        if (null !== $publicBody && mb_strlen($publicBody) > RoadmapProposal::TEXT_MAX) {
            throw new RoadmapRefused('roadmap.desk.error_body_long');
        }
        if (self::PROMOTE === $action && (null === $group || !\in_array($group, ReleaseNotes::STATUSES, true))) {
            throw new RoadmapRefused('roadmap.desk.error_group');
        }

        // DBAL's own transaction rather than wrapInTransaction(): a refusal
        // must leave the entity manager open, so the desk page can render
        // the form again with what the admin typed.
        $db = $this->em->getConnection();
        $db->beginTransaction();
        try {
            $this->em->refresh($proposal, LockMode::PESSIMISTIC_WRITE);
            if ($keepWording) {
                $publicTitle = $proposal->getPublicTitle();
                $publicBody = $proposal->getPublicBody();
            }
            match ($action) {
                self::OPEN => $this->open($proposal, $publicTitle, $publicBody, $note, $deciderId),
                self::PROMOTE => $this->promote($proposal, (string) $group, $publicTitle, $publicBody, $note, $deciderId),
                default => $this->decline($proposal, $note, $deciderId),
            };
            $this->showPicture($proposal, self::DECLINE === $action ? false : $showPicture);
            $this->em->flush();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    private function open(RoadmapProposal $proposal, ?string $publicTitle, ?string $publicBody, ?string $note, int $deciderId): void
    {
        $before = $proposal->getStatus();
        if (ProposalStatus::Promoted === $before) {
            throw new RoadmapRefused('roadmap.desk.error_promoted');
        }
        $proposal->setPublicWording($publicTitle, $publicBody);
        if (ProposalStatus::Open === $before) {
            // Already open: only the wording changes. Nothing goes to the
            // proposer, so a note would be sent to nobody.
            if (null !== $note) {
                throw new RoadmapRefused('roadmap.desk.error_note_update');
            }

            return;
        }
        $proposal->decide(ProposalStatus::Open, $note, $deciderId);
        $this->notices->proposalDecided($proposal, $note);
        if (null !== $proposal->getUserId()) {
            $this->marks->follow($proposal->getUserId(), $proposal->getRef());
        }
    }

    private function promote(RoadmapProposal $proposal, string $group, ?string $publicTitle, ?string $publicBody, ?string $note, int $deciderId): void
    {
        $before = $proposal->getStatus();
        if (ProposalStatus::Pending !== $before && ProposalStatus::Open !== $before) {
            throw new RoadmapRefused(ProposalStatus::Promoted === $before ? 'roadmap.desk.error_promoted' : 'roadmap.desk.error_decided');
        }
        $proposal->setPublicWording($publicTitle, $publicBody);
        $item = $this->items->newItem($group, ItemDesk::checkTexts([
            $proposal->getLocale() => ['title' => $proposal->getShownTitle(), 'body' => $proposal->getShownBody()],
        ]));
        $item->setSourceProposal($proposal);
        // The item needs its id before the marks can move to it.
        $this->em->flush();

        $proposal->decide(ProposalStatus::Promoted, $note, $deciderId, $item);
        $this->notices->proposalDecided($proposal, $note);
        $this->notices->proposalMoved($proposal, $group, $proposal->getUserId());
        $this->marks->moveRef($proposal->getRef(), $item->getRef());
        if (null !== $proposal->getUserId()) {
            $this->marks->follow($proposal->getUserId(), $item->getRef());
        }
    }

    private function decline(RoadmapProposal $proposal, ?string $note, int $deciderId): void
    {
        $before = $proposal->getStatus();
        if (null === $note) {
            throw new RoadmapRefused('roadmap.desk.error_reason');
        }
        if (ProposalStatus::Pending !== $before && ProposalStatus::Open !== $before) {
            throw new RoadmapRefused(ProposalStatus::Promoted === $before ? 'roadmap.desk.error_promoted' : 'roadmap.desk.error_decided');
        }
        $proposal->decide(ProposalStatus::Declined, $note, $deciderId);
        $this->notices->proposalDecided($proposal, $note);
        if (ProposalStatus::Open === $before) {
            $this->notices->proposalMoved($proposal, RoadmapNotices::REMOVED, $proposal->getUserId());
            $this->marks->forget($proposal->getRef());
        }
    }

    /**
     * Sets whether the proposal's picture shows publicly. A picture the rider
     * did not allow stays private whatever the desk ticked; a declined
     * proposal's picture is never shown.
     */
    private function showPicture(RoadmapProposal $proposal, ?bool $show): void
    {
        if (null === $show) {
            return;
        }
        $image = $this->em->getRepository(RoadmapProposalImage::class)->findOneBy(['proposal' => $proposal]);
        if ($image instanceof RoadmapProposalImage) {
            $image->show($show && $image->mayBeShown());
        }
    }

    private static function clean(?string $text): ?string
    {
        $text = null === $text ? '' : trim(str_replace("\r\n", "\n", $text));

        return '' === $text ? null : $text;
    }
}
