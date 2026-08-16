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
use App\Media\Entity\MediaUpload;
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
    /** Content-free audit actions for the escalation path (photo-uploads.md §6d). */
    public const string ACTION_ESCALATE = 'submission_escalated';
    public const string ACTION_ESCALATE_RELEASE = 'submission_escalation_released';

    /**
     * Rows per page in the admin's held-submission list. Matches
     * MediaEscalationService::PER_PAGE — the two lists share one page, and a
     * reader should not meet two page sizes on it.
     */
    public const int HELD_PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageService $messages,
        private readonly AdminActionLogger $adminLog,
        private readonly ModerationScopeProvider $scopeProvider,
        private readonly MediaDecisionService $mediaDecisions,
        private readonly MediaDisposalService $mediaDisposal,
        private readonly ItemConfirmationService $confirmations,
        private readonly EscalationAlert $escalationAlert,
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
     * A rider takes back their own undecided submission (owner 2026-08-16).
     *
     * Withdrawal reuses the REJECT mechanics on purpose - a new-item's
     * materialized row goes to state Rejected (so the map drops it, the OSM
     * ref stays claimed for a possible revive, exactly like a rejection) and
     * pending photos are rejected into the retention sweep - but it is not a
     * decision: no curator, no scope check, no outcome message to the person
     * who did it themselves, and it never counts in moderation activity
     * (that view filters approved/rejected). `withdrawn` is terminal;
     * decidedBy records the rider, decidedAt starts the retention clock.
     *
     * @throws AlreadyDecidedException  when the submission is already settled
     * @throws NotTheSubmitterException when it is somebody else's
     */
    public function withdraw(int $submissionId, User $rider): Submission
    {
        return $this->em->wrapInTransaction(function () use ($submissionId, $rider): Submission {
            $submission = $this->em->find(Submission::class, $submissionId, LockMode::PESSIMISTIC_WRITE);
            if (null === $submission) {
                throw new \InvalidArgumentException(sprintf('Unknown submission %d', $submissionId));
            }
            if ($submission->getUserId() !== (int) $rider->getId()) {
                throw new NotTheSubmitterException(sprintf('Submission %d is not %d\'s to withdraw', $submissionId, (int) $rider->getId()));
            }
            if (!\in_array($submission->getStatus(), [SubmissionStatus::Pending, SubmissionStatus::NeedsInfo], true)) {
                throw new AlreadyDecidedException(sprintf('Submission %d is already %s', $submissionId, $submission->getStatus()->value));
            }

            $item = null !== $submission->getItemId() ? $this->em->find(Item::class, $submission->getItemId()) : null;

            $submission->setStatus(SubmissionStatus::Withdrawn);
            if (null !== $item && SubmissionType::NewItem === $submission->getType()) {
                $item->setState(ItemState::Rejected);
            }
            $this->mediaDecisions->apply($submission, $item, 'reject', $rider, null);

            $submission->setDecidedBy((int) $rider->getId())
                ->setDecidedAt(new \DateTimeImmutable());

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
            // A submission under legal hold is beyond every deletion path
            // (docs/specs/photo-uploads.md §6d): Trash exists to destroy
            // content, and this content has to survive until it has been
            // reported.
            if ($submission->isEscalated()) {
                throw new \LogicException(sprintf('SUB-%d is under legal hold and cannot be trashed.', $id));
            }

            $this->adminLog->log($curator, TrashActions::TrashSubmission, null, sprintf('SUB-%d · type=%s', $id, $submission->getType()->value));
            // Trash means no content survives — the photos go with the words,
            // immediately and with no retention window
            // (docs/specs/photo-uploads.md §6). The content-free audit row above
            // is the only trace either leaves.
            $this->mediaDisposal->purgeForSubmission($id);
            $this->removeUnapprovedNewItem($submission);
            $this->em->remove($submission);
        });
    }

    /**
     * Trashing a NEW-place submission takes its unapproved item with it.
     *
     * Intake creates the `Item` immediately, in state `submitted` — that is how
     * the pending pin reaches the curator's map before anyone has decided
     * anything (CatalogContributionService). Trash used to remove only the
     * submission row, which left that item behind for good: state `submitted`,
     * `source_ref` = `sub:<id>` pointing at a submission that no longer exists,
     * and nothing anywhere to sweep it — `RetentionService` does not touch
     * items. Invisible rather than harmful (ItemState::SERVED is
     * unverified+verified, so a `submitted` row is never served publicly), but
     * it is exactly the content Trash promises to destroy, still in the
     * database. Owner-reported 2026-08-03.
     *
     * The state check is the safety rail, not decoration: once a new item has
     * been APPROVED it is `unverified`/`verified`, a real catalogue entry that
     * riders may since have confirmed, photographed or edited. Trashing the
     * originating submission must never take that with it — only an item still
     * waiting on its first decision has nothing else depending on it.
     *
     * An `edit` submission is untouched by all of this: an edit applies on
     * approve, so a pending edit has changed nothing yet and trashing it can
     * only ever discard the proposal.
     */
    private function removeUnapprovedNewItem(Submission $submission): void
    {
        if (SubmissionType::NewItem !== $submission->getType() || null === $submission->getItemId()) {
            return;
        }
        $item = $this->em->find(Item::class, $submission->getItemId());
        if (null === $item || ItemState::Submitted !== $item->getState()) {
            return;
        }
        // Nothing else can be pointing at it: change_history is written on
        // approve, confirmations need a served item, and an item-attached photo
        // only gets its item_id on approve — the submission's own photos are
        // already gone via purgeForSubmission above.
        $this->em->remove($item);
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
            if (Item::LOCATION_FIELD !== $field) {
                $actualOld = Item::NAME_FIELD === $field ? $item->getName() : ($attributes[$field] ?? null);
                if ($actualOld === $now) {
                    continue; // nothing left to apply for this field
                }
            } else {
                $actualOld = null;
            }
            if (Item::LOCATION_FIELD === $field) {
                /* The pin the rider moved. Parsed back from the pair the diff
                   showed the curator, so what is applied is exactly what they
                   approved — no second source for the same number. Only a POINT
                   item moves this way; a segment or a climb carries its shape in
                   its own field. */
                $parts = array_map('trim', explode(',', (string) $now));
                if (2 === \count($parts) && is_numeric($parts[0]) && is_numeric($parts[1])) {
                    $item->setGeom(json_encode([
                        'type' => 'Point',
                        'coordinates' => [(float) $parts[1], (float) $parts[0]],
                    ], \JSON_THROW_ON_ERROR));
                    $changed = true;
                    $this->history($item, $submission, $curator, $field, $pair['was'] ?? null, $now);
                }
                continue;
            }
            if (Item::NAME_FIELD === $field) {
                $item->setName((string) $now);
            } elseif (null === $now) {
                // A cleared field: remove the attribute rather than storing a
                // null (the rider emptied a prefilled value on the improve form).
                unset($attributes[$field]);
            } else {
                $attributes[$field] = $now;
                // A redrawn stretch changes the item's GEOMETRY too: the map
                // reads the line from geom, not from the attribute, so an
                // approved segment edit that only updated attributes would
                // keep drawing the old road. Same derivation as submitDraft:
                // the routed/seeded line when there is one, the a→b chord
                // otherwise.
                if ('segment' === $field && \is_array($now) && isset($now['a'], $now['b'])) {
                    $path = \is_array($now['line'] ?? null) ? $now['line'] : [$now['a'], $now['b']];
                    $item->setGeom(json_encode(['type' => 'LineString', 'coordinates' => $path], \JSON_THROW_ON_ERROR));
                }
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
            /* The CREATOR is credited, not the approver (owner 2026-08-13:
               "we do not credit the creator of the surface item"). Every row
               here applies a change the SUBMITTER authored - the curator only
               let it through, and their act is already recorded where desk
               acts belong (submission.decided_by, the desk history). The
               public change log is provenance of the content. */
            ->setChangedBy($submission->getUserId()));
    }

    /**
     * Escalate a submission's contents as suspected illegal content
     * (docs/specs/photo-uploads.md §6d) — the same third verb the photo side
     * has, because words can be the material just as pixels can.
     *
     * It leaves the queue immediately (SubmissionQueue filters held rows out),
     * nothing can delete it, and an admin is alerted at once. Any photos
     * attached to it are escalated with it: they are the same act by the same
     * person, and leaving them decidable would defeat the hold.
     *
     * @throws \InvalidArgumentException on an unknown submission or an empty reason
     */
    public function escalateSubmission(int $id, User $curator, string $reason): void
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new \InvalidArgumentException('moderate.escalate.error.reason_required');
        }
        if (mb_strlen($reason) > 2000) {
            throw new \InvalidArgumentException('moderate.escalate.error.reason_too_long');
        }

        $submission = $this->em->find(Submission::class, $id);
        if (null === $submission) {
            throw new \InvalidArgumentException(sprintf('Unknown submission %d', $id));
        }
        if ($submission->isEscalated()) {
            return;   // idempotent: a double-submit must not re-alert
        }

        $submission->escalate((int) $curator->getId(), $reason);
        foreach ($this->em->getRepository(MediaUpload::class)->findBy(['submissionId' => $id]) as $upload) {
            if (!$upload->isEscalated()) {
                $upload->escalate((int) $curator->getId(), $reason);
            }
        }
        // Content-free, like every Trash row: what is audited is that a
        // curator escalated SUB-N, never what it said.
        $this->adminLog->log($curator, self::ACTION_ESCALATE, null, sprintf('SUB-%d', $id));
        $this->em->flush();

        $this->escalationAlert->escalated('submission', sprintf('SUB-%d', $id), $reason, '/admin/escalated');
    }

    /** An admin lifts the hold; the submission returns to the queue it left. */
    public function releaseSubmission(int $id, User $admin, ?string $note = null): bool
    {
        $submission = $this->em->find(Submission::class, $id);
        if (null === $submission || !$submission->isEscalated()) {
            return false;
        }

        $submission->releaseEscalation();
        foreach ($this->em->getRepository(MediaUpload::class)->findBy(['submissionId' => $id]) as $upload) {
            $upload->releaseEscalation();
        }
        $this->adminLog->log($admin, self::ACTION_ESCALATE_RELEASE, null, sprintf('SUB-%d%s', $id, null !== $note ? ' · '.$note : ''));
        $this->em->flush();

        return true;
    }

    /**
     * Held submissions for the admin area, newest first.
     *
     * @return list<array{id: int, title: string, reason: string, escalatedAt: \DateTimeImmutable, escalatedBy: string}>
     */
    public function heldSubmissions(int $page = 1, int $perPage = self::HELD_PER_PAGE): array
    {
        /** @var list<Submission> $rows */
        $rows = $this->em->createQuery(
            'SELECT s FROM '.Submission::class.' s WHERE s.escalatedAt IS NOT NULL ORDER BY s.escalatedAt DESC',
        )
            ->setFirstResult(max(0, (max(1, $page) - 1) * max(1, $perPage)))
            ->setMaxResults(max(1, $perPage))
            ->getResult();

        $out = [];
        foreach ($rows as $submission) {
            $at = $submission->getEscalatedAt();
            if (null === $at) {
                continue;
            }
            $by = $submission->getEscalatedById();
            $curator = null !== $by ? $this->em->find(User::class, $by) : null;
            $out[] = [
                'id' => (int) $submission->getId(),
                'title' => $submission->getTitle(),
                'reason' => $submission->getEscalatedReason() ?? '',
                'escalatedAt' => $at,
                'escalatedBy' => $curator?->getDisplayName() ?? '',
            ];
        }

        return $out;
    }

    /** How many submissions are under hold, for the pager. */
    public function heldSubmissionCount(): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(s.id) FROM '.Submission::class.' s WHERE s.escalatedAt IS NOT NULL',
        )->getSingleScalarResult();
    }
}
