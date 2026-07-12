<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\Entity\ChangeHistory;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemState;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The ONLY write-path for moderation decisions. Approve applies the change
 * to the catalog inside one transaction and appends change_history rows
 * (moderation spec §9: history records the item's ACTUAL value at apply
 * time — never the submitter's possibly-stale snapshot).
 *
 * @api Called by ModerateController::decide().
 */
final class ModerationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageService $messages,
    ) {
    }

    public function decide(int $submissionId, string $decision, User $curator, ?string $note): Submission
    {
        if (!\in_array($decision, ['approve', 'reject', 'needs_info'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown decision "%s"', $decision));
        }

        return $this->em->wrapInTransaction(function () use ($submissionId, $decision, $curator, $note): Submission {
            $submission = $this->em->find(Submission::class, $submissionId);
            if (null === $submission) {
                throw new \InvalidArgumentException(sprintf('Unknown submission %d', $submissionId));
            }
            if (!\in_array($submission->getStatus(), [SubmissionStatus::Pending, SubmissionStatus::NeedsInfo], true)) {
                throw new AlreadyDecidedException(sprintf('Submission %d is already %s', $submissionId, $submission->getStatus()->value));
            }

            $item = null !== $submission->getItemId() ? $this->em->find(Item::class, $submission->getItemId()) : null;

            switch ($decision) {
                case 'approve':
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

            $submission->setDecisionNote($note)
                ->setDecidedBy((int) $curator->getId())
                ->setDecidedAt(new \DateTimeImmutable());

            $kind = match ($decision) {
                'approve' => UserMessageKind::SubmissionApproved,
                'reject' => UserMessageKind::SubmissionRejected,
                'needs_info' => UserMessageKind::SubmissionNeedsInfo,
            };
            // M2: the outcome message rides the decision transaction — atomic,
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

    private function approveNew(Item $item, Submission $submission, User $curator): void
    {
        $item->setState(ItemState::Unverified);
        $this->history($item, $submission, $curator, 'state', ItemState::Submitted->value, ItemState::Unverified->value);
    }

    private function applyEdit(Item $item, Submission $submission, User $curator): void
    {
        $attributes = $item->getAttributes();
        $changed = false;
        foreach ($submission->getChanges() as $field => $pair) {
            $now = $pair['now'] ?? null;
            $actualOld = 'name' === $field ? $item->getName() : ($attributes[$field] ?? null);
            if ($actualOld === $now) {
                continue; // nothing left to apply for this field
            }
            if ('name' === $field) {
                $item->setName((string) $now);
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
