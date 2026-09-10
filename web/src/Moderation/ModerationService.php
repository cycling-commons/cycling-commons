<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\ChangeHistory;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\Import\OsmCandidates;
use App\Catalog\Import\OsmLinker;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Community\ItemConfirmationService;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Media\MediaDecisionService;
use App\Media\MediaDisposalService;
use App\Media\PhotoAltSuggestion;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Service\AdminActionLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Write-path for moderation decisions. History records the item's value at apply time.
 *
 * @see docs/specs/moderation-and-contribution.md §4
 *
 * @api
 */
final class ModerationService
{
    /** Content-free audit actions for the escalation path (docs/specs/photo-uploads.md §6d). */
    public const string ACTION_ESCALATE = 'submission_escalated';
    public const string ACTION_ESCALATE_RELEASE = 'submission_escalation_released';

    /** Rows per page in the admin held-submission list. */
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
        private readonly OsmCandidates $osmCandidates,
        private readonly OsmLinker $osmLinker,
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
        // Needs-info is the question; a blank note is refused (docs/specs/moderation-and-contribution.md §5.3).
        if ('needs_info' === $decision && '' === trim((string) $note)) {
            throw new MissingQuestionException('A needs-info decision must carry the question to ask the rider.');
        }

        return $this->em->wrapInTransaction(function () use ($submissionId, $decision, $curator, $note, $rejectMediaIds): Submission {
            // Pessimistic lock: two concurrent decides must not both apply.
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
                    // Bound item missing: fail loudly rather than approve with no effect.
                    if (null !== $submission->getItemId() && null === $item) {
                        throw new \InvalidArgumentException(sprintf('Cannot approve submission %d: its target item %d no longer exists', $submissionId, $submission->getItemId()));
                    }
                    // catalog-data-model.md §5b: a new place is not admitted
                    // until somebody has said which OSM object it is, or that
                    // there is none. Checked before the status changes so a
                    // refusal leaves the submission exactly as it was.
                    if (SubmissionType::NewItem === $submission->getType() && null !== $item && !$item->osmAnswered()) {
                        throw new OsmUnansweredException('Link this place to OSM, or record that it has no OSM counterpart, before approving it.');
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

            // Photos ride the same decision transaction (docs/specs/photo-uploads.md §5, §5b).
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
            // Outcome message rides the decision transaction.
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
     * Rider takes back their own undecided submission (docs/specs/moderation-and-contribution.md §3.4).
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
     * Hard-delete a submission in any status. Audited content-free first.
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
            // Legal hold is beyond every deletion path (docs/specs/photo-uploads.md §6d).
            if ($submission->isEscalated()) {
                throw new \LogicException(sprintf('SUB-%d is under legal hold and cannot be trashed.', $id));
            }

            $this->adminLog->log($curator, TrashActions::TrashSubmission, null, sprintf('SUB-%d · type=%s', $id, $submission->getType()->value));
            // Photos go immediately, no retention window (docs/specs/photo-uploads.md §6).
            $this->mediaDisposal->purgeForSubmission($id);
            $this->removeUnapprovedNewItem($submission);
            $this->em->remove($submission);
        });
    }

    /** Trashing a NEW-place submission takes its still-submitted item with it. */
    private function removeUnapprovedNewItem(Submission $submission): void
    {
        if (SubmissionType::NewItem !== $submission->getType() || null === $submission->getItemId()) {
            return;
        }
        $item = $this->em->find(Item::class, $submission->getItemId());
        if (null === $item || ItemState::Submitted !== $item->getState()) {
            return;
        }
        // Only a still-submitted item: an approved place has other dependents.
        $this->em->remove($item);
    }

    private function approveNew(Item $item, Submission $submission, User $curator): void
    {
        $item->setState(ItemState::Unverified);
        $this->history($item, $submission, $curator, 'state', ItemState::Submitted->value, ItemState::Unverified->value);
        $this->carryOwnAnswer($item, $submission);
    }

    /**
     * Record the submitter's own form answer as form-sourced, not a confirmation
     * (docs/specs/moderation-and-contribution.md §6.3).
     */
    private function carryOwnAnswer(Item $item, Submission $submission): void
    {
        $stance = self::stanceFromAnswer($submission->getChanges()['potable']['now'] ?? null);
        if (null === $stance || ItemType::WaterFood !== ItemType::fromParam($item->getLetter())) {
            return;
        }

        $this->confirmations->recordFromSubmission($item, $submission->getUserId(), $stance);
    }

    /** Potability answer as a stance, or null when it is not a claim either way. */
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
                /* Apply the pin the curator approved; only POINT items move this way. */
                $parts = array_map('trim', explode(',', (string) $now));
                if (2 === \count($parts) && is_numeric($parts[0]) && is_numeric($parts[1])) {
                    $item->setGeom(json_encode([
                        'type' => 'Point',
                        'coordinates' => [(float) $parts[1], (float) $parts[0]],
                    ], \JSON_THROW_ON_ERROR));
                    // The place moved: its stored OSM-candidate list is stale
                    // (catalog-data-model.md §5b). Recompute at the new point,
                    // answered or not: the old answer was about the old spot.
                    $this->osmCandidates->refreshAt((int) $item->getId(), $item->getLetter(), (float) $parts[0], (float) $parts[1]);
                    // And if it now sits on top of an OSM object nobody claims,
                    // it IS that object: link it, so the raw pin under it goes
                    // (owner 2026-09-05, every letter). A row already linked to
                    // something else keeps its link only while that object is
                    // still the one it sits on.
                    $twin = $this->osmLinker->onTopOf($item->getLetter(), (float) $parts[0], (float) $parts[1], (int) $item->getId());
                    if (null !== $twin && $twin !== $item->getOsmRef()) {
                        $this->history($item, $submission, $curator, 'osmRef', $item->getOsmRef(), $twin);
                        $item->answerOsm($twin);
                    }
                    $changed = true;
                    $this->history($item, $submission, $curator, $field, $pair['was'] ?? null, $now);
                }
                continue;
            }
            $suggestedPhoto = PhotoAltSuggestion::photoOf($field);
            if (null !== $suggestedPhoto) {
                // A description somebody who did not upload the picture
                // suggested. It goes to the upload row AND to the gallery copy
                // the map reads, the same pair `MediaController::altText()`
                // writes, because the two must never disagree.
                $this->applyPhotoAlt($item, $suggestedPhoto, \is_string($now) ? $now : null, $attributes);
                $changed = true;
                $this->history($item, $submission, $curator, $field, $pair['was'] ?? null, $now);
                continue;
            }
            if (Item::NAME_FIELD === $field) {
                $item->setName((string) $now);
            } elseif (null === $now) {
                // Cleared field: remove the attribute rather than storing null.
                unset($attributes[$field]);
            } else {
                $attributes[$field] = $now;
                // A redrawn stretch updates geom too; the map reads the line from geom.
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

    /**
     * Write an approved description onto the photo and onto the gallery entry.
     *
     * `$attributes` is passed by reference because the caller writes it back
     * once at the end; touching the item twice would bump `updated_at` twice
     * for one decision.
     *
     * @param array<string, mixed> $attributes
     */
    private function applyPhotoAlt(Item $item, Uuid $photo, ?string $alt, array &$attributes): void
    {
        $upload = $this->em->find(MediaUpload::class, $photo);
        // Only a picture that is still on THIS item. A submission cannot be
        // used to retitle somebody else's photograph on another place.
        if (!$upload instanceof MediaUpload || $upload->getItemId() !== $item->getId()) {
            return;
        }

        $upload->setAltText($alt);

        $photos = $attributes['photos'] ?? null;
        if (!\is_array($photos)) {
            return;
        }
        foreach ($photos as $index => $entry) {
            if (!$this->mediaDecisions->isEntryFor($entry, $upload)) {
                continue;
            }
            if (null === $alt || '' === $alt) {
                unset($photos[$index]['alt']);
            } else {
                $photos[$index]['alt'] = $alt;
            }
        }
        $attributes['photos'] = array_values($photos);
    }

    private function history(Item $item, Submission $submission, User $curator, string $field, mixed $old, mixed $new): void
    {
        $this->em->persist((new ChangeHistory())
            ->setItemId((int) $item->getId())
            ->setSubmissionId($submission->getId())
            ->setField($field)
            ->setOldValue($old)
            ->setNewValue($new)
            /* Credit the submitter, not the approver (docs/specs/moderation-and-contribution.md §4.1). */
            ->setChangedBy($submission->getUserId()));
    }

    /**
     * Escalate as suspected illegal content (docs/specs/photo-uploads.md §6d).
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
        // Same write-guard as decide()/trash() above. Escalation is the
        // HEAVIEST action a curator has: it puts a row in legal hold, hides
        // its photos and mails a human, so it is the last one that should
        // have been reachable outside a curator's areas (security scan
        // 2026-08-25). The endpoint takes a bare submission id, so the queue
        // being scoped is not a guard.
        if (!$this->scopeProvider->allowsRegion($this->scopeProvider->scopeFor($curator), $submission->getRegionId())) {
            throw new OutOfScopeException('Submission outside the curator\'s assigned areas.');
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
        // Content-free: the audit records that SUB-N was escalated, never what it said.
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
