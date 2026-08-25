<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Entity\User;
use App\Moderation\MissingQuestionException;
use App\Translation\DecisionService;
use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationOverlay;
use App\Translation\Entity\TranslationProposal;
use App\Translation\Exception\SelfReviewException;
use App\Translation\OverlayCatalogue;
use App\Translation\ProposalService;
use App\Translation\TranslationProposalStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
}
