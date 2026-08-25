<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Account\DataExportService;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Service\UserDeletionService;
use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\Entity\TranslationProposal;
use App\Translation\TranslationConsent;
use App\Translation\TranslationProposalStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Account erasure keeps licensed translation strings; only identity columns null.
 *
 * @see docs/specs/translations.md §3.2
 * @see docs/specs/account-and-auth.md §11
 */
final class TranslationDeletionHookTest extends KernelTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(EntityManagerInterface $em, string $email): User
    {
        $u = (new User())->setEmail($email)->setDisplayName(strstr($email, '@', true) ?: $email);
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return $u;
    }

    public function testPurgeNullsIdentityKeepsStringsAndExportListsProposals(): void
    {
        self::bootKernel();
        $em = $this->em();

        $rider = $this->user($em, 'trans-purge-rider@test.test');
        $riderId = (int) $rider->getId();

        $entry = new TranslationEntry('nav.map', 'Map');
        $em->persist($entry);

        $consent = new ConsentRecord(
            Uuid::v4(),
            $riderId,
            TranslationConsent::KIND,
            TranslationConsent::VERSION,
            TranslationConsent::hash('contract'),
        );
        $em->persist($consent);

        // Direct persist: same user on all three identity columns so one purge
        // exercises submitter_id, reviewer_id, and approved_by_id UPDATEs.
        $proposal = new TranslationProposal(
            $entry,
            'fr',
            'Carte du purge',
            'Map',
            $riderId,
            $consent->getId(),
        );
        $proposal->setStatus(TranslationProposalStatus::Approved);
        $proposal->setReviewerId($riderId);
        $proposal->setDecidedAt(new \DateTimeImmutable());
        $em->persist($proposal);

        $overlay = new TranslationOverlay($entry, 'fr', 'Carte du purge', $proposal, $riderId);
        $em->persist($overlay);
        $em->flush();

        $proposalId = (int) $proposal->getId();
        $overlayId = (int) $overlay->getId();
        $consentId = $consent->getId()->toRfc4122();

        $zipPath = static::getContainer()->get(DataExportService::class)->export($rider);
        try {
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($zipPath));
            $raw = $zip->getFromName('translations.json');
            self::assertIsString($raw, 'translations.json must be in the GDPR ZIP');
            $decoded = json_decode($raw, true);
            self::assertIsArray($decoded);
            self::assertCount(1, $decoded);
            self::assertSame('Carte du purge', $decoded[0]['proposed_value']);
            self::assertSame('fr', $decoded[0]['locale']);
            $zip->close();
        } finally {
            @unlink($zipPath);
        }

        static::getContainer()->get(UserDeletionService::class)->purge($rider);
        $em->flush();
        $em->clear();

        self::assertNull($em->find(User::class, $riderId));

        $keptProposal = $em->find(TranslationProposal::class, $proposalId);
        self::assertNotNull($keptProposal);
        self::assertNull($keptProposal->getSubmitterId());
        self::assertNull($keptProposal->getReviewerId());
        self::assertSame('Carte du purge', $keptProposal->getProposedValue());

        $keptOverlay = $em->find(TranslationOverlay::class, $overlayId);
        self::assertNotNull($keptOverlay);
        self::assertSame('Carte du purge', $keptOverlay->getValue());
        self::assertNull($keptOverlay->getApprovedById());

        $keptConsent = $em->find(ConsentRecord::class, Uuid::fromString($consentId));
        self::assertNotNull($keptConsent, 'consent_record rows must survive Art. 17(3)(e)');
        self::assertSame($riderId, $keptConsent->getUserId());
    }
}
