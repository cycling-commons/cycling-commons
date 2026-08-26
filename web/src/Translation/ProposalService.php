<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Pagination\Pager;
use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationProposal;
use App\Translation\Exception\ConsentRequiredException;
use App\Translation\Exception\EmptyTranslationException;
use App\Translation\Exception\EnglishNotTranslatableException;
use App\Translation\Exception\InvalidLocaleException;
use App\Translation\Exception\KeyNotFoundException;
use App\Translation\Exception\TranslationConflictException;
use App\Translation\Exception\TranslationTooLongException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * In-site translation proposal intake under CC BY-SA consent.
 *
 * @see docs/specs/translations.md §4, §6
 *
 * @api
 */
final class ProposalService
{
    public const int HISTORY_PER_PAGE = 25;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslationConsentService $consent,
        private readonly RateLimiterFactoryInterface $translationProposeLimiter,
    ) {
    }

    /**
     * @throws TooManyRequestsHttpException    over the hourly proposal limit
     * @throws ConsentRequiredException        when the consent tick is false
     * @throws EnglishNotTranslatableException when locale is en
     * @throws InvalidLocaleException          locale is not a translatable one
     * @throws KeyNotFoundException            when the entry is marked absent
     * @throws EmptyTranslationException       when the value is empty after trim
     * @throws TranslationTooLongException     value longer than the byte cap
     * @throws TranslationConflictException    concurrent open-proposal insert
     */
    public function submit(
        User $user,
        TranslationEntry $entry,
        string $locale,
        string $value,
        bool $consentTick,
    ): TranslationProposal {
        if (!$consentTick) {
            throw new ConsentRequiredException('Consent tick required.');
        }

        if ('en' === $locale) {
            throw new EnglishNotTranslatableException('English is not proposed from the website.');
        }
        if (!TranslationLimits::isTranslatableLocale($locale)) {
            throw new InvalidLocaleException(sprintf('Locale "%s" is not translatable.', $locale));
        }

        if (null !== $entry->getAbsentAt()) {
            throw new KeyNotFoundException(sprintf('Catalogue key "%s" is absent.', $entry->getMessageKey()));
        }

        $value = trim($value);
        if ('' === $value) {
            throw new EmptyTranslationException('Proposed translation must not be empty.');
        }
        if (\strlen($value) > TranslationLimits::PROPOSED_VALUE_MAX) {
            throw new TranslationTooLongException(sprintf('Proposed translation exceeds %d bytes.', TranslationLimits::PROPOSED_VALUE_MAX));
        }

        $userId = (int) $user->getId();
        $this->em->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(:uid, :key)',
            [
                'uid' => $userId,
                'key' => crc32($locale."\0".$entry->getMessageKey()) & 0x7FFFFFFF,
            ],
            [
                'uid' => ParameterType::INTEGER,
                'key' => ParameterType::INTEGER,
            ],
        );

        if (!$this->translationProposeLimiter->create('user-'.(string) $userId)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Too many translation proposals.');
        }

        $consentRecord = $this->consent->record($user);

        $open = $this->findOpenProposal($userId, $locale, $entry);
        if (null !== $open) {
            $open->setProposedValue($value);
            $open->setEnglishAtSubmit($entry->getEnglish());
            $open->setStatus(TranslationProposalStatus::Pending);
            $open->setReviewerId(null);
            $open->setReviewerNote(null);
            $open->setDecidedAt(null);
            if (!$this->consentStillCurrent($open->getConsentRecordId())) {
                $open->setConsentRecordId($consentRecord->getId());
            }
            $this->em->flush();

            return $open;
        }

        $proposal = new TranslationProposal(
            $entry,
            $locale,
            $value,
            $entry->getEnglish(),
            $userId,
            $consentRecord->getId(),
        );
        $this->em->persist($proposal);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new TranslationConflictException('An open proposal for this key already exists.');
        }

        return $proposal;
    }

    /**
     * This rider's open proposal for a key+locale, if any.
     */
    public function openFor(User $user, string $locale, TranslationEntry $entry): ?TranslationProposal
    {
        return $this->findOpenProposal((int) $user->getId(), $locale, $entry);
    }

    /**
     * This rider's (locale, key) groups, latest version of each (translations.md §4).
     *
     * @return array{
     *     rows: list<TranslationProposal>,
     *     pager: array{page: int, pages: int, total: int, perPage: int, offset: int, prev: ?int, next: ?int},
     *     statuses: list<string>,
     *     had_any: bool
     * }
     */
    public function historyFor(int $userId, ?TranslationProposalStatus $status, ?string $locale, int $page, int $perPage): array
    {
        $hadAny = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(TranslationProposal::class, 'p')
            ->where('p.submitterId = :uid')
            ->setParameter('uid', $userId)
            ->getQuery()
            ->getSingleScalarResult() > 0;

        $statusQb = $this->em->createQueryBuilder()
            ->select('DISTINCT p.status')
            ->from(TranslationProposal::class, 'p')
            ->where('p.submitterId = :uid')
            ->setParameter('uid', $userId);
        if (null !== $locale) {
            $statusQb->andWhere('p.locale = :locale')->setParameter('locale', $locale);
        }
        $statuses = $statusQb->getQuery()->getSingleColumnResult();
        $statusValues = [];
        foreach ($statuses as $row) {
            $statusValues[] = $row instanceof TranslationProposalStatus ? $row->value : (string) $row;
        }
        sort($statusValues);

        $total = $this->latestGroupCount($userId, $status, $locale);
        $pager = Pager::of($page, $total, $perPage);
        $rows = $this->proposalsByIds(
            $this->latestGroupIds($userId, $status, $locale, $pager['offset'], $pager['perPage']),
        );

        return [
            'rows' => $rows,
            'pager' => $pager,
            'statuses' => $statusValues,
            'had_any' => $hadAny,
        ];
    }

    /**
     * This rider's versions of one key+locale, newest first.
     *
     * @return list<TranslationProposal>
     */
    public function historyForKey(int $userId, string $locale, TranslationEntry $entry): array
    {
        /** @var list<TranslationProposal> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('p', 'e')
            ->from(TranslationProposal::class, 'p')
            ->join('p.entry', 'e')
            ->where('p.submitterId = :uid')
            ->andWhere('p.locale = :locale')
            ->andWhere('p.entry = :entry')
            ->setParameter('uid', $userId)
            ->setParameter('locale', $locale)
            ->setParameter('entry', $entry)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * How many (locale, key) groups this rider has.
     */
    private function latestGroupCount(int $userId, ?TranslationProposalStatus $status, ?string $locale): int
    {
        [$sql, $params, $types] = $this->latestGroupFrom($userId, $status, $locale);

        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) '.$sql, $params, $types);
    }

    /**
     * Latest proposal id per (locale, key), newest groups first.
     *
     * @return list<int>
     */
    private function latestGroupIds(
        int $userId,
        ?TranslationProposalStatus $status,
        ?string $locale,
        int $offset,
        int $limit,
    ): array {
        [$from, $params, $types] = $this->latestGroupFrom($userId, $status, $locale);
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
    private function latestGroupFrom(int $userId, ?TranslationProposalStatus $status, ?string $locale): array
    {
        $params = ['uid' => $userId];
        $types = ['uid' => ParameterType::INTEGER];
        $where = 'p.submitter_id = :uid';
        if (null !== $locale) {
            $where .= ' AND p.locale = :locale';
            $params['locale'] = $locale;
        }
        $statusWhere = '';
        if (null !== $status) {
            $statusWhere = ' WHERE latest.status = :status';
            $params['status'] = $status->value;
        }

        $from = 'FROM (
            SELECT DISTINCT ON (p.locale, p.entry_id) p.id, p.created_at, p.status
            FROM translation_proposal p
            WHERE '.$where.'
            ORDER BY p.locale, p.entry_id, p.created_at DESC, p.id DESC
        ) latest'.$statusWhere;

        return [$from, $params, $types];
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

    private function findOpenProposal(int $submitterId, string $locale, TranslationEntry $entry): ?TranslationProposal
    {
        return $this->em->createQueryBuilder()
            ->select('p')
            ->from(TranslationProposal::class, 'p')
            ->where('p.submitterId = :submitter')
            ->andWhere('p.locale = :locale')
            ->andWhere('p.entry = :entry')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('submitter', $submitterId)
            ->setParameter('locale', $locale)
            ->setParameter('entry', $entry)
            ->setParameter('statuses', [
                TranslationProposalStatus::Pending,
                TranslationProposalStatus::NeedsInfo,
            ])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function consentStillCurrent(Uuid $consentRecordId): bool
    {
        $record = $this->em->find(ConsentRecord::class, $consentRecordId);
        if (null === $record) {
            return false;
        }

        return TranslationConsent::KIND === $record->getKind()
            && TranslationConsent::VERSION === $record->getVersion();
    }
}
