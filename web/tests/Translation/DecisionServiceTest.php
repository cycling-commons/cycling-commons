<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Moderation\MissingQuestionException;
use App\Translation\DecisionService;
use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\Entity\TranslationProposal;
use App\Translation\Exception\SelfReviewException;
use App\Translation\OverlayCatalogue;
use App\Translation\ProposalService;
use App\Translation\StaleIndex;
use App\Translation\TranslationCaches;
use App\Translation\TranslationConsent;
use App\Translation\TranslationProposalStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Curator decide() for translation proposals (translations.md §5).
 */
final class DecisionServiceTest extends KernelTestCase
{
    use FindsOrCreatesTranslationEntry;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function decisions(): DecisionService
    {
        return static::getContainer()->get(DecisionService::class);
    }

    private function proposals(): ProposalService
    {
        return static::getContainer()->get(ProposalService::class);
    }

    private function user(EntityManagerInterface $em, string $email, array $roles = []): User
    {
        $u = (new User())->setEmail($email)->setDisplayName(strstr($email, '@', true) ?: $email);
        $u->setPassword('x');
        $u->setRoles($roles);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function entry(EntityManagerInterface $em, string $key, string $english): TranslationEntry
    {
        return $this->findOrCreateEntry($em, $key, $english);
    }

    public function testApproveWritesOverlayAndTranslatorReturnsIt(): void
    {
        self::bootKernel();
        $em = $this->em();
        $rider = $this->user($em, 'dec-approve-rider@test.test');
        $curator = $this->user($em, 'dec-approve-curator@test.test', ['ROLE_CURATOR']);
        $entry = $this->entry($em, 'nav.map', 'Map');

        $proposal = $this->proposals()->submit($rider, $entry, 'fr', 'Carte (approve)', true);
        $proposalId = (int) $proposal->getId();

        $this->decisions()->decide($proposalId, 'approve', $curator, null);
        $em->clear();

        $overlay = $em->getRepository(TranslationOverlay::class)->findOneBy([
            'entry' => $em->find(TranslationEntry::class, $entry->getId()),
            'locale' => 'fr',
        ]);
        self::assertNotNull($overlay);
        self::assertSame('Carte (approve)', $overlay->getValue());

        $decided = $em->find(TranslationProposal::class, $proposalId);
        self::assertNotNull($decided);
        self::assertSame(TranslationProposalStatus::Approved, $decided->getStatus());

        $t = static::getContainer()->get('translator');
        self::assertSame('Carte (approve)', $t->trans('nav.map', [], 'messages', 'fr'));
        self::assertSame('Carte (approve)', $decided->getPublishedValue());
    }

    public function testApproveCanPublishACuratorEditWithoutChangingTheRiderText(): void
    {
        self::bootKernel();
        $em = $this->em();
        $rider = $this->user($em, 'dec-edit-rider@test.test');
        $curator = $this->user($em, 'dec-edit-curator@test.test', ['ROLE_CURATOR']);
        $entry = $this->entry($em, 'nav.map', 'Map');

        $proposal = $this->proposals()->submit($rider, $entry, 'fr', 'Carte orignal', true);
        $proposalId = (int) $proposal->getId();

        $this->decisions()->decide($proposalId, 'approve', $curator, null, 'Carte original');
        $em->clear();

        $decided = $em->find(TranslationProposal::class, $proposalId);
        self::assertNotNull($decided);
        self::assertSame('Carte orignal', $decided->getProposedValue());
        self::assertSame('Carte original', $decided->getPublishedValue());

        $overlay = $em->getRepository(TranslationOverlay::class)->findOneBy([
            'entry' => $em->find(TranslationEntry::class, $entry->getId()),
            'locale' => 'fr',
        ]);
        self::assertNotNull($overlay);
        self::assertSame('Carte original', $overlay->getValue());
    }

    public function testSecondApproveReplacesOverlayValue(): void
    {
        self::bootKernel();
        $em = $this->em();
        $riderA = $this->user($em, 'dec-second-a@test.test');
        $riderB = $this->user($em, 'dec-second-b@test.test');
        $curator = $this->user($em, 'dec-second-curator@test.test', ['ROLE_CURATOR']);
        $entry = $this->entry($em, 'nav.map', 'Map');

        $first = $this->proposals()->submit($riderA, $entry, 'fr', 'Carte first', true);
        $this->decisions()->decide((int) $first->getId(), 'approve', $curator, null);

        $second = $this->proposals()->submit($riderB, $entry, 'fr', 'Carte second', true);
        $this->decisions()->decide((int) $second->getId(), 'approve', $curator, null);
        $em->clear();

        $overlay = $em->getRepository(TranslationOverlay::class)->findOneBy([
            'entry' => $em->find(TranslationEntry::class, $entry->getId()),
            'locale' => 'fr',
        ]);
        self::assertNotNull($overlay);
        self::assertSame('Carte second', $overlay->getValue());

        $t = static::getContainer()->get('translator');
        self::assertSame('Carte second', $t->trans('nav.map', [], 'messages', 'fr'));

        self::assertSame(2, (int) $em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(TranslationProposal::class, 'p')
            ->where('p.entry = :entry')
            ->andWhere('p.locale = :locale')
            ->andWhere('p.status = :status')
            ->setParameter('entry', $em->find(TranslationEntry::class, $entry->getId()))
            ->setParameter('locale', 'fr')
            ->setParameter('status', TranslationProposalStatus::Approved)
            ->getQuery()
            ->getSingleScalarResult());
    }

    public function testRejectLeavesPreviousOverlay(): void
    {
        self::bootKernel();
        $em = $this->em();
        $riderA = $this->user($em, 'dec-reject-a@test.test');
        $riderB = $this->user($em, 'dec-reject-b@test.test');
        $curator = $this->user($em, 'dec-reject-curator@test.test', ['ROLE_CURATOR']);
        $entry = $this->entry($em, 'nav.map', 'Map');

        $first = $this->proposals()->submit($riderA, $entry, 'fr', 'Carte kept', true);
        $this->decisions()->decide((int) $first->getId(), 'approve', $curator, null);

        $second = $this->proposals()->submit($riderB, $entry, 'fr', 'Carte rejected', true);
        $this->decisions()->decide((int) $second->getId(), 'reject', $curator, 'Not idiomatic.');
        $em->clear();

        $overlay = $em->getRepository(TranslationOverlay::class)->findOneBy([
            'entry' => $em->find(TranslationEntry::class, $entry->getId()),
            'locale' => 'fr',
        ]);
        self::assertNotNull($overlay);
        self::assertSame('Carte kept', $overlay->getValue());

        $rejected = $em->find(TranslationProposal::class, $second->getId());
        self::assertNotNull($rejected);
        self::assertSame(TranslationProposalStatus::Rejected, $rejected->getStatus());

        $t = static::getContainer()->get('translator');
        self::assertSame('Carte kept', $t->trans('nav.map', [], 'messages', 'fr'));
    }

    public function testApprovingEnglishBumpsTheEntryAndInvalidatesStale(): void
    {
        self::bootKernel();
        $em = $this->em();
        $entry = $this->entry($em, 'nav.map', 'Map');
        $author = $this->user($em, 'en-author@test.test', ['ROLE_CURATOR']);
        $reviewer = $this->user($em, 'en-reviewer@test.test', ['ROLE_CURATOR']);
        $proposal = new TranslationProposal($entry, 'en', 'Map view', 'Map', (int) $author->getId(), null, 1);
        $em->persist($proposal);
        $em->flush();

        $this->decisions()->decide((int) $proposal->getId(), 'approve', $reviewer, null);
        $em->refresh($entry);

        self::assertSame('Map view', $entry->getEnglish());
        self::assertSame(2, $entry->getEnglishVersion());
        self::assertSame(1, $entry->getYamlEnglishVersion());
        $overlay = $em->getRepository(TranslationOverlay::class)->findOneBy(['entry' => $entry, 'locale' => 'en']);
        self::assertNotNull($overlay);
        self::assertSame('Map view', $overlay->getValue());
        self::assertSame(2, $overlay->getEnglishVersion());
        self::assertTrue(static::getContainer()->get(StaleIndex::class)->isStale((int) $entry->getId(), 'nl'));
        self::assertSame('Map view', static::getContainer()->get('translator')->trans('nav.map', [], 'messages', 'en'));
    }

    public function testApprovingATranslationStoresTheCurrentVersionAndClearsStale(): void
    {
        self::bootKernel();
        $em = $this->em();
        $entry = $this->entry($em, 'nav.map', 'Map');
        $entry->applyApprovedEnglish('Map view'); // v2, nl stale
        $rider = $this->user($em, 'nl-rider@test.test');
        $reviewer = $this->user($em, 'nl-reviewer@test.test', ['ROLE_CURATOR']);
        // A real ConsentRecord row: consent_record_id carries a foreign key
        // (translations.md §3.1), so a proposal built by hand needs one too.
        $consent = new ConsentRecord(
            Uuid::v4(),
            (int) $rider->getId(),
            TranslationConsent::KIND,
            TranslationConsent::VERSION,
            TranslationConsent::hash('contract'),
        );
        $em->persist($consent);
        $proposal = new TranslationProposal($entry, 'nl', 'Kaartweergave', 'Map view', (int) $rider->getId(), $consent->getId(), 2);
        $em->persist($proposal);
        $em->flush();
        static::getContainer()->get(TranslationCaches::class)->invalidateAll();
        self::assertTrue(static::getContainer()->get(StaleIndex::class)->isStale((int) $entry->getId(), 'nl'));

        $this->decisions()->decide((int) $proposal->getId(), 'approve', $reviewer, null);

        $overlay = $em->getRepository(TranslationOverlay::class)->findOneBy(['entry' => $entry, 'locale' => 'nl']);
        self::assertNotNull($overlay);
        self::assertSame(2, $overlay->getEnglishVersion());
        self::assertFalse(static::getContainer()->get(StaleIndex::class)->isStale((int) $entry->getId(), 'nl'));
    }

    public function testNeedsInfoWithoutNoteThrows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $rider = $this->user($em, 'dec-needsinfo-rider@test.test');
        $curator = $this->user($em, 'dec-needsinfo-curator@test.test', ['ROLE_CURATOR']);
        $entry = $this->entry($em, 'nav.map', 'Map');
        $proposal = $this->proposals()->submit($rider, $entry, 'fr', 'Carte?', true);

        $this->expectException(MissingQuestionException::class);
        $this->decisions()->decide((int) $proposal->getId(), 'needs_info', $curator, '   ');
    }

    public function testSelfApproveThrowsAndWritesNothing(): void
    {
        self::bootKernel();
        $em = $this->em();
        $curator = $this->user($em, 'dec-self@test.test', ['ROLE_CURATOR']);
        $entry = $this->entry($em, 'nav.map', 'Map');
        $proposal = $this->proposals()->submit($curator, $entry, 'fr', 'Self carte', true);
        $proposalId = (int) $proposal->getId();

        try {
            $this->decisions()->decide($proposalId, 'approve', $curator, null);
            self::fail('Expected SelfReviewException');
        } catch (SelfReviewException) {
        }

        $em->clear();
        $still = $em->find(TranslationProposal::class, $proposalId);
        self::assertNotNull($still);
        self::assertSame(TranslationProposalStatus::Pending, $still->getStatus());

        self::assertSame(0, (int) $em->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(TranslationOverlay::class, 'o')
            ->getQuery()
            ->getSingleScalarResult());

        static::getContainer()->get(OverlayCatalogue::class)->invalidate('fr');
        $t = static::getContainer()->get('translator');
        self::assertSame('Carte', $t->trans('nav.map', [], 'messages', 'fr'));
    }

    /**
     * The headline control of the English-edit feature: a curator cannot
     * approve their OWN English edit, a SECOND curator has to
     * (translations.md §4.2).
     *
     * The guard itself carries no locale in its condition and runs before the
     * decision switch, so it has always covered this. But every other test
     * that reaches it submits a rider-locale proposal, which left the one
     * case the feature exists for pinned only by inference. It is pinned
     * here: nothing is written, and in particular the live English and its
     * version do not move.
     */
    public function testACuratorCannotApproveTheirOwnEnglishEdit(): void
    {
        self::bootKernel();
        $em = $this->em();
        $curator = $this->user($em, 'dec-self-en@test.test', ['ROLE_CURATOR']);
        $entry = $this->entry($em, 'nav.map', 'Map');
        $englishBefore = $entry->getEnglish();
        $versionBefore = $entry->getEnglishVersion();
        // English carries no CC BY-SA grant, so no consent tick (§6).
        $proposal = $this->proposals()->submit($curator, $entry, 'en', 'Map view', false);
        $proposalId = (int) $proposal->getId();
        $entryId = (int) $entry->getId();

        try {
            $this->decisions()->decide($proposalId, 'approve', $curator, null);
            self::fail('Expected SelfReviewException');
        } catch (SelfReviewException) {
        }

        $em->clear();
        $still = $em->find(TranslationProposal::class, $proposalId);
        self::assertNotNull($still);
        self::assertSame(TranslationProposalStatus::Pending, $still->getStatus());

        $reloaded = $em->find(TranslationEntry::class, $entryId);
        self::assertNotNull($reloaded);
        self::assertSame($englishBefore, $reloaded->getEnglish(), 'the live English must not move');
        self::assertSame($versionBefore, $reloaded->getEnglishVersion());

        self::assertSame(0, (int) $em->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(TranslationOverlay::class, 'o')
            ->where("o.locale = 'en'")
            ->getQuery()
            ->getSingleScalarResult());
    }
}
