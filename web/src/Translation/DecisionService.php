<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation;

use App\Entity\User;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\MissingQuestionException;
use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\Entity\TranslationProposal;
use App\Translation\Exception\EmptyTranslationException;
use App\Translation\Exception\SelfReviewException;
use App\Translation\Exception\TranslationTooLongException;
use App\Translation\Exception\UnknownProposalException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
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
        private readonly TranslationCaches $caches,
    ) {
    }

    /**
     * @throws UnknownProposalException    proposal id does not exist
     * @throws MissingQuestionException    needs_info without a note
     * @throws AlreadyDecidedException     proposal already settled
     * @throws SelfReviewException         curator is the submitter
     * @throws EmptyTranslationException   approve with an empty published string
     * @throws TranslationTooLongException approve over the byte cap
     */
    public function decide(int $proposalId, string $decision, User $curator, ?string $note, ?string $published = null): TranslationProposal
    {
        if (!\in_array($decision, ['approve', 'reject', 'needs_info'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown decision "%s"', $decision));
        }
        if ('needs_info' === $decision && '' === trim((string) $note)) {
            throw new MissingQuestionException('A needs-info decision must carry the question to ask the rider.');
        }

        $proposal = $this->em->wrapInTransaction(function () use ($proposalId, $decision, $curator, $note, $published): TranslationProposal {
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
                    $text = null !== $published ? trim($published) : $proposal->getProposedValue();
                    if ('' === $text) {
                        throw new EmptyTranslationException('Published translation must not be empty.');
                    }
                    if (\strlen($text) > TranslationLimits::PROPOSED_VALUE_MAX) {
                        throw new TranslationTooLongException(sprintf('Published translation exceeds %d bytes.', TranslationLimits::PROPOSED_VALUE_MAX));
                    }
                    $proposal->setPublishedValue($text);
                    // upsertOverlay() runs before applyApprovedEnglish(), so the
                    // entry still carries the OLD version at this point. The
                    // overlay records the version the approval creates
                    // (translations.md §3.3), computed once, up front.
                    $versionForOverlay = 'en' === $locale ? $entry->getEnglishVersion() + 1 : $entry->getEnglishVersion();
                    $this->upsertOverlay($proposal, (int) $curator->getId(), $versionForOverlay);
                    if ('en' === $locale) {
                        $entry->applyApprovedEnglish($text);
                    }
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
        // TranslationCaches::invalidateAll() covers every locale because an
        // approved English change makes all four translations stale
        // (translations.md §3.3, §4.2).
        if ('approve' === $decision) {
            $this->caches->invalidateAll();
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
     * One proposal for the detail page, open or settled.
     *
     * @throws UnknownProposalException
     */
    public function get(int $id): TranslationProposal
    {
        $proposal = $this->em->find(TranslationProposal::class, $id);
        if (null === $proposal) {
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

    /**
     * Previous approvals for this key+locale, oldest first.
     *
     * @return list<TranslationProposal>
     */
    public function approvedHistory(TranslationEntry $entry, string $locale): array
    {
        /** @var list<TranslationProposal> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('p')
            ->from(TranslationProposal::class, 'p')
            ->where('p.entry = :entry')
            ->andWhere('p.locale = :locale')
            ->andWhere('p.status = :status')
            ->setParameter('entry', $entry)
            ->setParameter('locale', $locale)
            ->setParameter('status', TranslationProposalStatus::Approved)
            ->orderBy('p.decidedAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * How many (locale, key) groups have a settled proposal.
     */
    public function settledCount(): int
    {
        [$sql, $params, $types] = $this->latestSettledFrom();

        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) '.$sql, $params, $types);
    }

    /**
     * Latest settled proposal per (locale, key), newest submission first.
     *
     * @return list<TranslationProposal>
     */
    public function settled(int $offset, int $limit): array
    {
        return $this->proposalsByIds(
            $this->latestSettledIds($offset, $limit),
        );
    }

    /**
     * Latest settled id per (locale, key), newest groups first.
     *
     * @return list<int>
     */
    private function latestSettledIds(int $offset, int $limit): array
    {
        [$from, $params, $types] = $this->latestSettledFrom();
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        /** @var list<int|string> $raw */
        $raw = $this->em->getConnection()->fetchFirstColumn(
            'SELECT latest.id '.$from.'
             ORDER BY latest.created_at DESC, latest.id DESC
             LIMIT :limit OFFSET :offset',
            $params,
            $types,
        );

        $ids = [];
        foreach ($raw as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, ParameterType>}
     */
    private function latestSettledFrom(): array
    {
        $from = "FROM (
            SELECT DISTINCT ON (p.locale, p.entry_id) p.id, p.created_at
            FROM translation_proposal p
            WHERE p.status IN ('approved', 'rejected')
            ORDER BY p.locale, p.entry_id, p.created_at DESC, p.id DESC
        ) latest";

        return [$from, [], []];
    }

    /**
     * @param list<int> $ids
     *
     * @return list<TranslationProposal>
     */
    private function proposalsByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<TranslationProposal> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('p', 'e')
            ->from(TranslationProposal::class, 'p')
            ->join('p.entry', 'e')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($rows as $proposal) {
            $byId[(int) $proposal->getId()] = $proposal;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    private function upsertOverlay(TranslationProposal $proposal, int $approvedById, int $englishVersion): void
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
                $proposal->getPublishedValue(),
                $proposal,
                $approvedById,
                $englishVersion,
            ));

            return;
        }

        $existing->applyApproval(
            $proposal->getPublishedValue(),
            $proposal,
            $approvedById,
            $englishVersion,
        );
    }
}
