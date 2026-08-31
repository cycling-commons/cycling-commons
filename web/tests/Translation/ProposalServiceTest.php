<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Translation;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Translation\Entity\TranslationEntry;
use App\Translation\Entity\TranslationProposal;
use App\Translation\Exception\ConsentRequiredException;
use App\Translation\Exception\EmptyTranslationException;
use App\Translation\Exception\EnglishNotTranslatableException;
use App\Translation\Exception\InvalidLocaleException;
use App\Translation\Exception\KeyNotFoundException;
use App\Translation\Exception\ProtectedKeyException;
use App\Translation\Exception\TranslationTooLongException;
use App\Translation\ProposalService;
use App\Translation\TranslationConsent;
use App\Translation\TranslationLimits;
use App\Translation\TranslationProposalStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class ProposalServiceTest extends KernelTestCase
{
    use FindsOrCreatesTranslationEntry;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function svc(): ProposalService
    {
        return static::getContainer()->get(ProposalService::class);
    }

    /**
     * @param list<string> $roles
     */
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

    private function consentCount(EntityManagerInterface $em, int $userId): int
    {
        return (int) $em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(ConsentRecord::class, 'c')
            ->where('c.userId = :uid')
            ->andWhere('c.kind = :kind')
            ->setParameter('uid', $userId)
            ->setParameter('kind', TranslationConsent::KIND)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function proposalCount(EntityManagerInterface $em): int
    {
        return (int) $em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(TranslationProposal::class, 'p')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function testNoTickCreatesNeitherProposalNorConsent(): void
    {
        self::bootKernel();
        $em = $this->em();
        $user = $this->user($em, 'prop-notick@test.test');
        $entry = $this->entry($em, 'home.cta_map', 'Explore the map');
        $beforeConsent = $this->consentCount($em, (int) $user->getId());
        $beforeProposal = $this->proposalCount($em);

        try {
            $this->svc()->submit($user, $entry, 'fr', 'Explorer la carte', false);
            self::fail('Expected ConsentRequiredException');
        } catch (ConsentRequiredException) {
        }

        self::assertSame($beforeConsent, $this->consentCount($em, (int) $user->getId()));
        self::assertSame($beforeProposal, $this->proposalCount($em));
    }

    /**
     * The consent contract is the text a rider agreed to; the ledger keeps a
     * hash of it under a VERSION and standing consent is keyed on that VERSION
     * alone. Reworded through the overlay, every earlier record would cover
     * words its rider never saw. So the key is refused here, and ignored by
     * the loader even if a row exists (translations.md §4).
     */
    public function testAConsentContractCannotBeProposed(): void
    {
        self::bootKernel();
        $em = $this->em();
        $user = $this->user($em, 'prop-protected@test.test');
        $entry = $this->entry($em, TranslationConsent::TEXT_KEY, 'I license this translation under CC BY-SA 4.0.');
        $beforeProposal = $this->proposalCount($em);

        try {
            $this->svc()->submit($user, $entry, 'fr', 'Je cède tous mes droits.', true);
            self::fail('Expected ProtectedKeyException');
        } catch (ProtectedKeyException) {
        }

        self::assertSame($beforeProposal, $this->proposalCount($em));
    }

    public function testTickRecordsConsentThenProposal(): void
    {
        self::bootKernel();
        $em = $this->em();
        $user = $this->user($em, 'prop-tick@test.test');
        $entry = $this->entry($em, 'nav.map', 'Map');

        $proposal = $this->svc()->submit($user, $entry, 'fr', 'Carte', true);
        $em->clear();

        $found = $em->find(TranslationProposal::class, $proposal->getId());
        self::assertNotNull($found);
        self::assertSame('Carte', $found->getProposedValue());
        self::assertSame('Map', $found->getEnglishAtSubmit());
        self::assertSame('fr', $found->getLocale());
        self::assertSame(TranslationProposalStatus::Pending, $found->getStatus());
        self::assertSame((int) $user->getId(), $found->getSubmitterId());

        self::assertNotNull($found->getConsentRecordId());
        $consent = $em->find(ConsentRecord::class, $found->getConsentRecordId());
        self::assertNotNull($consent);
        self::assertSame(TranslationConsent::KIND, $consent->getKind());
        self::assertSame(TranslationConsent::VERSION, $consent->getVersion());
        self::assertSame((int) $user->getId(), $consent->getUserId());
    }

    public function testSecondSubmitUpdatesSamePendingId(): void
    {
        self::bootKernel();
        $em = $this->em();
        $user = $this->user($em, 'prop-update@test.test');
        $entry = $this->entry($em, 'home.title', 'Home');

        $first = $this->svc()->submit($user, $entry, 'nl', 'Thuis', true);
        $firstId = $first->getId();
        self::assertNotNull($firstId);

        $second = $this->svc()->submit($user, $entry, 'nl', 'Startpagina', true);
        self::assertSame($firstId, $second->getId());
        self::assertSame('Startpagina', $second->getProposedValue());
        self::assertSame(TranslationProposalStatus::Pending, $second->getStatus());

        $em->clear();
        self::assertSame(1, (int) $em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(TranslationProposal::class, 'p')
            ->where('p.submitterId = :uid')
            ->andWhere('p.locale = :locale')
            ->andWhere('p.entry = :entry')
            ->setParameter('uid', (int) $user->getId())
            ->setParameter('locale', 'nl')
            ->setParameter('entry', $entry)
            ->getQuery()
            ->getSingleScalarResult());
    }

    public function testEmptyValueThrows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $user = $this->user($em, 'prop-empty@test.test');
        $entry = $this->entry($em, 'nav.home', 'Home');

        $beforeConsent = $this->consentCount($em, (int) $user->getId());

        try {
            $this->svc()->submit($user, $entry, 'de', "  \t  ", true);
            self::fail('Expected EmptyTranslationException');
        } catch (EmptyTranslationException) {
        }

        self::assertSame($beforeConsent, $this->consentCount($em, (int) $user->getId()));
    }

    public function testTooLongValueThrows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $user = $this->user($em, 'prop-long@test.test');
        $entry = $this->entry($em, 'nav.about', 'About');

        $beforeConsent = $this->consentCount($em, (int) $user->getId());

        try {
            $this->svc()->submit(
                $user,
                $entry,
                'es',
                str_repeat('a', TranslationLimits::PROPOSED_VALUE_MAX + 1),
                true,
            );
            self::fail('Expected TranslationTooLongException');
        } catch (TranslationTooLongException) {
        }

        self::assertSame($beforeConsent, $this->consentCount($em, (int) $user->getId()));
    }

    public function testEnglishLocaleThrows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $user = $this->user($em, 'prop-en@test.test');
        $entry = $this->entry($em, 'nav.help', 'Help');

        $this->expectException(EnglishNotTranslatableException::class);
        $this->svc()->submit($user, $entry, 'en', 'Help', true);
    }

    /**
     * A plain rider has no ROLE_CURATOR reachable, so English stays refused
     * (translations.md §4.2).
     */
    public function testRiderCannotProposeEnglish(): void
    {
        self::bootKernel();
        $em = $this->em();
        $rider = $this->user($em, 'en-rider@test.test');
        $entry = $this->entry($em, 'nav.map', 'Map');

        $this->expectException(EnglishNotTranslatableException::class);
        $this->svc()->submit($rider, $entry, 'en', 'Map view', true);
    }

    public function testCuratorProposesEnglishWithoutConsentAndWithVersion(): void
    {
        self::bootKernel();
        $em = $this->em();
        $curator = $this->user($em, 'en-curator@test.test', ['ROLE_CURATOR']);
        $entry = $this->entry($em, 'nav.map', 'Map');
        $entry->applyApprovedEnglish('Map (v2)');
        $em->flush();

        $p = $this->svc()->submit($curator, $entry, 'en', 'Map view', false);

        self::assertSame('en', $p->getLocale());
        self::assertNull($p->getConsentRecordId());
        self::assertSame('Map (v2)', $p->getEnglishAtSubmit());
        self::assertSame(2, $p->getEnglishVersionAtSubmit());
    }

    public function testAdminMayProposeEnglishThroughTheRoleHierarchy(): void
    {
        self::bootKernel();
        $em = $this->em();
        $admin = $this->user($em, 'en-admin@test.test', ['ROLE_ADMIN']);
        $entry = $this->entry($em, 'nav.map', 'Map');

        $p = $this->svc()->submit($admin, $entry, 'en', 'Map view', false);
        self::assertSame('en', $p->getLocale());
    }

    public function testRiderProposalRecordsEnglishVersionAtSubmit(): void
    {
        self::bootKernel();
        $em = $this->em();
        $rider = $this->user($em, 'v-rider@test.test');
        $entry = $this->entry($em, 'nav.map', 'Map');

        $p = $this->svc()->submit($rider, $entry, 'nl', 'Kaart', true);
        self::assertSame($entry->getEnglishVersion(), $p->getEnglishVersionAtSubmit());
    }

    public function testUnknownLocaleThrows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $user = $this->user($em, 'prop-xx@test.test');
        $entry = $this->entry($em, 'nav.search', 'Search');

        $this->expectException(InvalidLocaleException::class);
        $this->svc()->submit($user, $entry, 'xx', 'Buscar', true);
    }

    public function testAbsentEntryThrows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $user = $this->user($em, 'prop-absent@test.test');
        $entry = $this->entry($em, 'gone.key', 'Gone');
        $entry->markAbsent();
        $em->flush();

        $this->expectException(KeyNotFoundException::class);
        $this->svc()->submit($user, $entry, 'fr', 'Parti', true);
    }

    public function testSameEnglishDifferentKeysAreSeparateRows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $user = $this->user($em, 'prop-same-en@test.test');
        $a = $this->entry($em, 'ballot.submit', 'Submit');
        $b = $this->entry($em, 'route.submit', 'Submit');

        $pa = $this->svc()->submit($user, $a, 'fr', 'Envoyer', true);
        $pb = $this->svc()->submit($user, $b, 'fr', 'Soumettre', true);

        self::assertNotSame($pa->getId(), $pb->getId());
        self::assertSame($a->getId(), $pa->getEntry()->getId());
        self::assertSame($b->getId(), $pb->getEntry()->getId());
        self::assertSame('Envoyer', $pa->getProposedValue());
        self::assertSame('Soumettre', $pb->getProposedValue());
    }

    public function testStandingConsentAllowsALaterKeyWithoutATick(): void
    {
        self::bootKernel();
        $em = $this->em();
        $user = $this->user($em, 'prop-standing@test.test');
        $first = $this->entry($em, 'nav.map', 'Map');
        $later = $this->entry($em, 'nav.home', 'Home');

        $a = $this->svc()->submit($user, $first, 'nl', 'Kaart', true);
        $beforeConsent = $this->consentCount($em, (int) $user->getId());
        self::assertGreaterThan(0, $beforeConsent);

        $b = $this->svc()->submit($user, $later, 'nl', 'Start', false);
        self::assertNotNull($a->getConsentRecordId());
        self::assertNotNull($b->getConsentRecordId());
        self::assertSame($a->getConsentRecordId()->toRfc4122(), $b->getConsentRecordId()->toRfc4122());
        self::assertSame($beforeConsent, $this->consentCount($em, (int) $user->getId()));
        self::assertSame('Start', $b->getProposedValue());
    }

    public function testNeedsInfoResubmitUpdatesInPlaceAndResetsPending(): void
    {
        self::bootKernel();
        $em = $this->em();
        $user = $this->user($em, 'prop-needs-info@test.test');
        $entry = $this->entry($em, 'flash.saved', 'Saved');

        $reviewer = $this->user($em, 'prop-needs-info-reviewer@test.test');
        $first = $this->svc()->submit($user, $entry, 'de', 'Gespeichert', true);
        $first->setStatus(TranslationProposalStatus::NeedsInfo);
        $first->setReviewerId((int) $reviewer->getId());
        $first->setReviewerNote('Please shorten.');
        $first->setDecidedAt(new \DateTimeImmutable('2026-08-01T12:00:00+00:00'));
        $em->flush();
        $id = $first->getId();

        $again = $this->svc()->submit($user, $entry, 'de', 'Gesichert', true);
        self::assertSame($id, $again->getId());
        self::assertSame('Gesichert', $again->getProposedValue());
        self::assertSame(TranslationProposalStatus::Pending, $again->getStatus());
        self::assertNull($again->getReviewerId());
        self::assertNull($again->getReviewerNote());
        self::assertNull($again->getDecidedAt());
    }

    public function testRateLimitRejectsTheSixtyFirstSubmit(): void
    {
        self::bootKernel();
        $em = $this->em();
        $user = $this->user($em, 'prop-limit@test.test');
        $entry = $this->entry($em, 'nav.map', 'Map');

        /** @var RateLimiterFactoryInterface $factory */
        $factory = static::getContainer()->get('limiter.translation_propose');
        $limiter = $factory->create('user-'.(int) $user->getId());
        for ($i = 0; $i < 60; ++$i) {
            self::assertTrue($limiter->consume()->isAccepted());
        }

        $this->expectException(TooManyRequestsHttpException::class);
        $this->svc()->submit($user, $entry, 'fr', 'Carte', true);
    }
}
