<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Consent ledger: append-only; every answer comes from a matching row.
 *
 * @see docs/specs/photo-uploads.md §3, §4
 *
 * @api
 */
final class ConsentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** The exact words shown to this rider, in this rider's language. */
    public function contractText(): string
    {
        return $this->translator->trans(MediaConsent::TEXT_KEY);
    }

    public function record(User $user): ConsentRecord
    {
        $record = new ConsentRecord(
            Uuid::v4(),
            (int) $user->getId(),
            MediaConsent::KIND,
            MediaConsent::VERSION,
            MediaConsent::hash($this->contractText()),
        );
        $this->em->persist($record);
        $this->em->flush();

        return $record;
    }

    public function current(User $user): ?ConsentRecord
    {
        return $this->em->getRepository(ConsentRecord::class)->findOneBy(
            [
                'userId' => (int) $user->getId(),
                'kind' => MediaConsent::KIND,
                'version' => MediaConsent::VERSION,
            ],
            ['consentedAt' => 'DESC'],
        );
    }

    /** Server-side upload gate: no valid row → ConsentMissing. */
    public function assertValid(User $user, ?string $consentId): ConsentRecord
    {
        if (null === $consentId || '' === $consentId || !Uuid::isValid($consentId)) {
            throw new ConsentMissing('No consent id supplied.');
        }

        $record = $this->em->find(ConsentRecord::class, Uuid::fromString($consentId));
        if (null === $record
            || $record->getUserId() !== (int) $user->getId()
            || MediaConsent::KIND !== $record->getKind()
            || MediaConsent::VERSION !== $record->getVersion()
        ) {
            throw new ConsentMissing('No valid consent record for this caller.');
        }

        return $record;
    }
}
