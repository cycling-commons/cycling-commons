<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The consent ledger (docs/specs/photo-uploads.md §3, §4): append-only records
 * of riders granting the CC BY-SA 4.0 own-work licence, and the single place
 * any layer may ask whether consent exists.
 *
 * Fail-closed by construction. There is no boolean flag, no cache and no
 * session copy to go stale or be trusted: every answer comes from a row in
 * consent_record that belongs to the caller and matches the current kind AND
 * version. No method here can return a positive answer without such a row, and
 * every failure path returns the negative one.
 *
 * @api Called by MediaController; enforced independently of any UI gate.
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

    /**
     * The server-side guarantee behind every upload. The wizard's locked
     * controls are sequencing; this is the rule.
     */
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
