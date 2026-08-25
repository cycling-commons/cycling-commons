<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
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
