<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Consent ledger for translation proposals. Append-only; reuses ConsentRecord.
 * Not a generalization of Media\ConsentService.
 *
 * @see docs/specs/translations.md §4, §6
 *
 * @api
 */
final class TranslationConsentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** The exact words shown to this rider, in this rider's language. */
    public function contractText(): string
    {
        return $this->translator->trans(TranslationConsent::TEXT_KEY);
    }

    public function record(User $user): ConsentRecord
    {
        $record = new ConsentRecord(
            Uuid::v4(),
            (int) $user->getId(),
            TranslationConsent::KIND,
            TranslationConsent::VERSION,
            TranslationConsent::hash($this->contractText()),
        );
        $this->em->persist($record);
        $this->em->flush();

        return $record;
    }

    /** Latest row for this rider and the current wording version, or null. */
    public function current(User $user): ?ConsentRecord
    {
        return $this->em->getRepository(ConsentRecord::class)->findOneBy(
            [
                'userId' => (int) $user->getId(),
                'kind' => TranslationConsent::KIND,
                'version' => TranslationConsent::VERSION,
            ],
            ['consentedAt' => 'DESC'],
        );
    }
}
