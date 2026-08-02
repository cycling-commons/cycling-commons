<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\ChangeHistory;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Community\ItemConfirmationService;
use App\Entity\User;
use App\Media\MediaDecisionService;
use App\Media\MediaDisposalService;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Service\AdminActionLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The ONLY write-path for moderation decisions. Approve applies the change
 * to the catalog inside one transaction and appends change_history rows.
 * History records the item's ACTUAL value at apply time, never the
 * submitter's possibly-stale snapshot.
 *
 * @see docs/specs/moderation-and-contribution.md §4
 *
 * @api Called by ModerateController::decide()/trash().
 */
final class ModerationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageService $messages,
        private readonly AdminActionLogger $adminLog,
        private readonly ModerationScopeProvider $scopeProvider,
        private readonly MediaDecisionService $mediaDecisions,
        private readonly MediaDisposalService $mediaDisposal,
        private readonly ItemConfirmationService $confirmations,
    ) {
    }

    /**
     * @param list<string> $rejectMediaIds uuids of pending photos the curator
     *                                     unticked; only meaningful on approve
     *                                     (docs/specs/photo-uploads.md §5)
     */
    public function decide(int $submissionId, string $decision, User $curator, ?string $note, array $rejectMediaIds = []): Submission
    {
        if (!\in_array($decision, ['approve', 'reject', 'needs_info'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown decision "%s"', $decision));
        }
        // A needs-info decision IS the question. Without a note the rider gets
        // "a curator needs more information" and nothing else — no way to know
        // what to answer — while the submission leaves the map queue until
        // they answer. So the note is not optional for this one decision,
        // and the guard lives here because this is the only write path.
        if ('needs_info' === $decision && '' === trim((string) $note)) {
            throw new MissingQuestionException('A needs-info decision must carry the question to ask the rider.');
        }

        return $this->em->wrapInTransaction(function () use ($submissionId, $decision, $curator, $note, $rejectMediaIds): Submission {
            // Pessimistic row lock: two curators deciding the same submission
            // concurrently would otherwise both read it as pending and both
            // apply. The second now blocks until the first commits, then sees
            // the decided status and is rejected below.
            $submission = $this->em->find(Submission::class, $submissionId, LockMode::PESSIMISTIC_WRITE);
            if (null === $submission) {
                throw new \InvalidArgumentException(sprintf('Unknown submission %d', $submissionId));
            }
            if (!$this->scopeProvider->allowsRegion($this->scopeProvider->scopeFor($curator), $submission->getRegionId())) {
                throw new OutOfScopeException('Submission outside the curator\'s assigned areas.');
            }
            if (!\in_array($submission->getStatus(), [SubmissionStatus::Pending, SubmissionStatus::NeedsInfo], true)) {
                throw new AlreadyDecidedException(sprintf('Submission %d is already %s', $submissionId, $submission->getStatus()->value));
            }

            $item = null !== $submission->getItemId() ? $this->em->find(Item::class, $submission->getItemId()) : null;

            switch ($decision) {
                case 'approve':
                    // A submission bound to an item (itemId set) whose target row
                    // has since vanished cannot be applied. Fail loudly (rolling
                    // back the whole transaction) instead of silently marking it
                    // approved with no effect.
                    if (null !== $submission->getItemId() && null === $item) {
                        throw new \InvalidArgumentException(sprintf('Cannot approve submission %d: its target item %d no longer exists', $submissionId, $submission->getItemId()));
                    }
                    $submission->setStatus(SubmissionStatus::Approved);
                    if (null !== $item) {
                        match ($submission->getType()) {
                            SubmissionType::NewItem => $this->approveNew($item, $submission, $curator),
                            SubmissionType::Edit => $this->applyEdit($item, $submission, $curator),
                            SubmissionType::Hazard, SubmissionType::Photo => throw new \LogicException(sprintf('Approval of %s submissions is not supported yet', $submission->getType()->value)),
                        };
                    }
                    break;
                case 'reject':
                    $submission->setStatus(SubmissionStatus::Rejected);
                    if (null !== $item && SubmissionType::NewItem === $submission->getType()) {
                        $item->setState(ItemState::Rejected);
                    }
                    break;
                case 'needs_info':
                    $submission->setStatus(SubmissionStatus::NeedsInfo);
                    break;
            }

            // Photos ride the same decision, in the same transaction, and the
            // item-side record is the same change_history row every other
            // applied change gets (docs/specs/photo-uploads.md §5, §5b).
            $photoChange = $this->mediaDecisions->apply($submission, $item, $decision, $curator, $note, $rejectMediaIds);
            if (null !== $photoChange && null !== $item) {
                $this->history($item, $submission, $curator, 'photos', $photoChange['old'], $photoChange['new']);
            }

            $submission->setDecisionNote($note)
                ->setDecidedBy((int) $curator->getId())
                ->setDecidedAt(new \DateTimeImmutable());

            $kind = match ($decision) {
                'approve' => UserMessageKind::SubmissionApproved,
                'reject' => UserMessageKind::SubmissionRejected,
                'needs_info' => UserMessageKind::SubmissionNeedsInfo,
            };
            // M2: the outcome message rides the decision transaction. Atomic,
            // duplicate-proof (the AlreadyDecidedException guard above means
            // at most one message per outcome).
            $this->messages->sendSystem(
                $submission->getUserId(), $kind,
                'submission', $submissionId, 'SUB-'.$submissionId,
                'messages.body.'.$kind->value, ['%title%' => $submission->getTitle()],
                $note,
            );

            return $submission;
        });
    }

    /**
     * Trash (M9): a hard, permanent delete of a submission row in ANY status.
     * Spam and abuse need no state check, unlike a route proposal. Audited
     * content-free FIRST (AdminActionLogger::log() flushes its own row inside
     * this transaction), never a UserMessage - trashing never feeds spam.
     *
     * @see docs/specs/moderation-and-contribution.md §6
     */
    public function trashSubmission(int $id, User $curator): void
    {
        $this->em->wrapInTransaction(function () use ($id, $curator): void {
            $submission = $this->em->find(Submission::class, $id);
            if (null === $submission) {
                throw new \InvalidArgumentException(sprintf('Unknown submission %d', $id));
            }
            if (!$this->scopeProvider->allowsRegion($this->scopeProvider->scopeFor($curator), $submission->getRegionId())) {
                throw new OutOfScopeException('Submission outside the curator\'s assigned areas.');
            }

            $this->adminLog->log($curator, TrashActions::TrashSubmission, null, sprintf('SUB-%d · type=%s', $id, $submission->getType()->value));
            // Trash means no content survives — the photos go with the words,
            // immediately and with no retention window
            // (docs/specs/photo-uploads.md §6). The content-free audit row above
            // is the only trace either leaves.
            $this->mediaDisposal->purgeForSubmission($id);
            $this->em->remove($submission);
        });
    }

    private function approveNew(Item $item, Submission $submission, User $curator): void
    {
        $item->setState(ItemState::Unverified);
        $this->history($item, $submission, $curator, 'state', ItemState::Submitted->value, ItemState::Unverified->value);
        $this->carryOwnAnswer($item, $submission);
    }

    /**
     * The submitter already answered the map's own question on the improve
     * form ("Potable?"), so record it as their stance rather than asking them
     * again the first time they open the place they just added.
     *
     * Recorded as `form`-sourced, which keeps it out of the public tally and
     * out of the verified derivation: it is the claim, not a confirmation of
     * it, and a rider must not be able to verify their own contribution
     * (ConfirmationSource, moderation-and-contribution.md §6.3).
     */
    private function carryOwnAnswer(Item $item, Submission $submission): void
    {
        $stance = self::stanceFromAnswer($submission->getChanges()['potable']['now'] ?? null);
        if (null === $stance || ItemType::WaterFood !== ItemType::fromParam($item->getLetter())) {
            return;
        }

        $this->confirmations->recordFromSubmission($item, $submission->getUserId(), $stance);
    }

    /**
     * The improve form's potability answer as a stance, or null when it is not
     * a claim either way — "Unsigned — use judgement" says the rider does not
     * know, which is exactly the case the map should still ask about.
     */
    private static function stanceFromAnswer(mixed $answer): ?ConfirmationStance
    {
        if (!\is_string($answer)) {
            return null;
        }

        return match (true) {
            str_starts_with($answer, 'Yes') => ConfirmationStance::Potable,
            str_starts_with($answer, 'No') => ConfirmationStance::NotPotable,
            default => null,
        };
    }

    private function applyEdit(Item $item, Submission $submission, User $curator): void
    {
        $attributes = $item->getAttributes();
        $changed = false;
        foreach ($submission->getChanges() as $field => $pair) {
            $now = $pair['now'] ?? null;
            $actualOld = Item::NAME_FIELD === $field ? $item->getName() : ($attributes[$field] ?? null);
            if ($actualOld === $now) {
                continue; // nothing left to apply for this field
            }
            if (Item::NAME_FIELD === $field) {
                $item->setName((string) $now);
            } elseif (null === $now) {
                // A cleared field: remove the attribute rather than storing a
                // null (the rider emptied a prefilled value on the improve form).
                unset($attributes[$field]);
            } else {
                $attributes[$field] = $now;
            }
            $changed = true;
            $this->history($item, $submission, $curator, $field, $actualOld, $now);
        }
        if ($changed) {
            $item->setAttributes($attributes); // also bumps updated_at
        }
    }

    private function history(Item $item, Submission $submission, User $curator, string $field, mixed $old, mixed $new): void
    {
        $this->em->persist((new ChangeHistory())
            ->setItemId((int) $item->getId())
            ->setSubmissionId($submission->getId())
            ->setField($field)
            ->setOldValue($old)
            ->setNewValue($new)
            ->setChangedBy((int) $curator->getId()));
    }
}
