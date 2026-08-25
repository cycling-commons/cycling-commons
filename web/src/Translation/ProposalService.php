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
use App\Translation\Exception\KeyNotFoundException;
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
     * @throws TooManyRequestsHttpException over the hourly proposal limit
     * @throws ConsentRequiredException when the consent tick is false
     * @throws EnglishNotTranslatableException when locale is en
     * @throws KeyNotFoundException when the entry is marked absent
     * @throws EmptyTranslationException when the value is empty after trim
     * @throws \InvalidArgumentException invalid locale or value too long
     */
    public function submit(
        User $user,
        TranslationEntry $entry,
        string $locale,
        string $value,
        bool $consentTick,
    ): TranslationProposal {
        if (!$this->translationProposeLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Too many translation proposals.');
        }

        if (!$consentTick) {
            throw new ConsentRequiredException('Consent tick required.');
        }

        $consentRecord = $this->consent->record($user);

        if ('en' === $locale) {
            throw new EnglishNotTranslatableException('English is not proposed from the website.');
        }
        if (!TranslationLimits::isTranslatableLocale($locale)) {
            throw new \InvalidArgumentException(sprintf('Locale "%s" is not translatable.', $locale));
        }

        if (null !== $entry->getAbsentAt()) {
            throw new KeyNotFoundException(sprintf('Catalogue key "%s" is absent.', $entry->getMessageKey()));
        }

        $value = trim($value);
        if ('' === $value) {
            throw new EmptyTranslationException('Proposed translation must not be empty.');
        }
        if (\strlen($value) > TranslationLimits::PROPOSED_VALUE_MAX) {
            throw new \InvalidArgumentException(sprintf(
                'Proposed translation exceeds %d bytes.',
                TranslationLimits::PROPOSED_VALUE_MAX,
            ));
        }

        $open = $this->findOpenProposal((int) $user->getId(), $locale, $entry);
        if (null !== $open) {
            $open->setProposedValue($value);
            $open->setEnglishAtSubmit($entry->getEnglish());
            $open->setStatus(TranslationProposalStatus::Pending);
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
            (int) $user->getId(),
            $consentRecord->getId(),
        );
        $this->em->persist($proposal);
        $this->em->flush();

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
