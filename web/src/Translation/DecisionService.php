<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

use App\Entity\User;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\MissingQuestionException;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\Entity\TranslationProposal;
use App\Translation\Exception\SelfReviewException;
use App\Translation\Exception\UnknownProposalException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Curator approve / reject / needs_info for translation proposals.
 *
 * Site-wide — no region scope check (translations.md §5).
 *
 * @api
 */
final class DecisionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageService $messages,
        private readonly OverlayCatalogue $overlays,
    ) {
    }

    /**
     * @throws UnknownProposalException proposal id does not exist
     * @throws MissingQuestionException needs_info without a note
     * @throws AlreadyDecidedException  proposal already settled
     * @throws SelfReviewException      curator is the submitter
     */
    public function decide(int $proposalId, string $decision, User $curator, ?string $note): TranslationProposal
    {
        if (!\in_array($decision, ['approve', 'reject', 'needs_info'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown decision "%s"', $decision));
        }
        if ('needs_info' === $decision && '' === trim((string) $note)) {
            throw new MissingQuestionException('A needs-info decision must carry the question to ask the rider.');
        }

        $proposal = $this->em->wrapInTransaction(function () use ($proposalId, $decision, $curator, $note): TranslationProposal {
            $proposal = $this->em->find(TranslationProposal::class, $proposalId, LockMode::PESSIMISTIC_WRITE);
            if (null === $proposal) {
                throw new UnknownProposalException(sprintf('Unknown proposal %d', $proposalId));
            }
            if (!\in_array($proposal->getStatus(), [
                TranslationProposalStatus::Pending,
                TranslationProposalStatus::NeedsInfo,
            ], true)) {
                throw new AlreadyDecidedException(sprintf('Proposal %d is already %s', $proposalId, $proposal->getStatus()->value));
            }
            if ((int) $curator->getId() === $proposal->getSubmitterId()) {
                throw new SelfReviewException(sprintf('Curator %d cannot review their own proposal %d', (int) $curator->getId(), $proposalId));
            }

            $entry = $proposal->getEntry();
            $locale = $proposal->getLocale();
            $messageKey = $entry->getMessageKey();

            switch ($decision) {
                case 'approve':
                    $this->upsertOverlay($proposal, (int) $curator->getId());
                    $proposal->setStatus(TranslationProposalStatus::Approved);
                    break;
                case 'reject':
                    $proposal->setStatus(TranslationProposalStatus::Rejected);
                    break;
                case 'needs_info':
                    $proposal->setStatus(TranslationProposalStatus::NeedsInfo);
                    break;
            }

            $proposal->setReviewerId((int) $curator->getId());
            $proposal->setReviewerNote($note);
            $proposal->setDecidedAt(new \DateTimeImmutable());

            $kind = match ($decision) {
                'approve' => UserMessageKind::TranslationApproved,
                'reject' => UserMessageKind::TranslationRejected,
                'needs_info' => UserMessageKind::TranslationNeedsInfo,
            };

            $submitterId = $proposal->getSubmitterId();
            if (null !== $submitterId) {
                $this->messages->sendSystem(
                    $submitterId,
                    $kind,
                    'translation',
                    $proposalId,
                    $messageKey,
                    'messages.body.'.$kind->value,
                    ['%key%' => $messageKey, '%locale%' => $locale],
                    $note,
                );
            }

            return $proposal;
        });

        // After commit — same order as DeleteTranslationOverlayCommand (flush, then invalidate).
        if ('approve' === $decision) {
            $this->overlays->invalidate($proposal->getLocale());
        }

        return $proposal;
    }

    /** Open proposals awaiting a curator (pending + needs_info). */
    public function pendingCount(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(TranslationProposal::class, 'p')
            ->where('p.status IN (:statuses)')
            ->setParameter('statuses', [
                TranslationProposalStatus::Pending,
                TranslationProposalStatus::NeedsInfo,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Open proposal for the detail page, or unknown if missing / already settled.
     *
     * @throws UnknownProposalException
     */
    public function getOpen(int $id): TranslationProposal
    {
        $proposal = $this->em->find(TranslationProposal::class, $id);
        if (null === $proposal || !\in_array($proposal->getStatus(), [
            TranslationProposalStatus::Pending,
            TranslationProposalStatus::NeedsInfo,
        ], true)) {
            throw new UnknownProposalException(sprintf('Unknown proposal %d', $id));
        }

        return $proposal;
    }

    /**
     * Open queue, oldest first.
     *
     * @return list<TranslationProposal>
     */
    public function openQueue(): array
    {
        /** @var list<TranslationProposal> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('p', 'e')
            ->from(TranslationProposal::class, 'p')
            ->join('p.entry', 'e')
            ->where('p.status IN (:statuses)')
            ->setParameter('statuses', [
                TranslationProposalStatus::Pending,
                TranslationProposalStatus::NeedsInfo,
            ])
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    private function upsertOverlay(TranslationProposal $proposal, int $approvedById): void
    {
        $entry = $proposal->getEntry();
        $locale = $proposal->getLocale();

        /** @var TranslationOverlay|null $existing */
        $existing = $this->em->createQueryBuilder()
            ->select('o')
            ->from(TranslationOverlay::class, 'o')
            ->where('o.entry = :entry')
            ->andWhere('o.locale = :locale')
            ->setParameter('entry', $entry)
            ->setParameter('locale', $locale)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $existing) {
            $this->em->persist(new TranslationOverlay(
                $entry,
                $locale,
                $proposal->getProposedValue(),
                $proposal,
                $approvedById,
            ));

            return;
        }

        $existing->applyApproval(
            $proposal->getProposedValue(),
            $proposal,
            $approvedById,
        );
    }
}
