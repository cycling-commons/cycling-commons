<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Account;

use App\Account\DormancyLadder;
use App\Account\DormancySweep;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The sweep that actually sends the mail and closes the accounts.
 *
 * `DormancyLadderTest` covers the arithmetic; this covers the consequences,
 * which are irreversible. The three that matter most are the negatives: an
 * active account is not touched, an unwarned account is not deleted, and a dry
 * run changes nothing.
 *
 * @see docs/specs/account-and-auth.md §6.5
 */
final class DormancySweepTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DormancySweep $sweep;
    private \DateTimeImmutable $now;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->sweep = self::getContainer()->get(DormancySweep::class);
        $this->now = new \DateTimeImmutable('2026-08-28T12:00:00+00:00');
    }

    public function testAnActiveAccountIsLeftAlone(): void
    {
        $user = $this->rider(monthsIdle: 3);

        $result = $this->sweep->run($this->now);

        self::assertSame(0, $result['notified']['m12']);
        self::assertSame(['m12' => false, 'm22' => false, 'm23_final' => false], $user->dormancyNoticesSent());
        self::assertNotNull($this->em->find(User::class, $user->getId()));
    }

    public function testTwelveMonthsEarnsTheFirstNotice(): void
    {
        $user = $this->rider(monthsIdle: 12);

        $result = $this->sweep->run($this->now);

        self::assertSame(1, $result['notified']['m12']);
        self::assertTrue($user->dormancyNoticesSent()['m12']);
    }

    /**
     * The dangerous one, and the reason the windows exist: an account already
     * two years idle when this first runs is past every notice window, so it is
     * neither warned nor deleted. Switching the sweep on cannot clear a backlog.
     */
    public function testAnAlreadyLongDormantAccountIsNeitherWarnedNorDeleted(): void
    {
        $user = $this->rider(monthsIdle: 25);
        $id = $user->getId();

        $result = $this->sweep->run($this->now);

        self::assertSame(0, $result['deleted'], 'never warned, so never deleted');
        self::assertSame([], array_filter($user->dormancyNoticesSent()), 'past every window');
        self::assertNotNull($this->em->find(User::class, $id));
    }

    public function testItDeletesOnceFullyWarnedAndPastTheDeadline(): void
    {
        $user = $this->rider(monthsIdle: 25);
        foreach (['m12', 'm22', 'm23_final'] as $code) {
            $user->recordDormancyNotice($code, $this->now);
        }
        $this->em->flush();
        $id = $user->getId();

        $result = $this->sweep->run($this->now);

        self::assertSame(1, $result['deleted']);
        $this->em->flush();
        $this->em->clear();
        self::assertNull($this->em->find(User::class, $id), 'the account is gone');
    }

    /** One account whose deletion fails is rolled back and logged; the next is still deleted and warned. */
    public function testOneFailedDeletionDoesNotStopTheSweep(): void
    {
        $failing = $this->warnedRider();
        $deletable = $this->warnedRider();
        $toWarn = $this->rider(monthsIdle: 12);
        $failingId = (int) $failing->getId();
        $deletableId = (int) $deletable->getId();
        $toWarnId = (int) $toWarn->getId();
        $logger = new SabotagedErasure();
        $deletions = SabotagedErasure::deletions(self::getContainer(), [$failingId => 'flush'], $logger);
        $sweep = new DormancySweep($this->em, $deletions, self::getContainer()->get(MailerInterface::class));

        $result = $sweep->run($this->now);

        self::assertSame(1, $result['deleted']);
        self::assertSame(1, $result['failed']);
        self::assertSame(1, $result['notified']['m12']);
        $this->em->clear();
        self::assertNotNull($this->em->find(User::class, $failingId), 'the failed one is untouched');
        self::assertNull($this->em->find(User::class, $deletableId), 'the next one is gone');
        self::assertTrue($this->em->find(User::class, $toWarnId)?->dormancyNoticesSent()['m12'], 'and the notice is on file');
        self::assertSame($failingId, $logger->records[0]['context']['user_id'] ?? null);
    }

    public function testADryRunChangesNothing(): void
    {
        $user = $this->rider(monthsIdle: 25);
        foreach (['m12', 'm22', 'm23_final'] as $code) {
            $user->recordDormancyNotice($code, $this->now);
        }
        $this->em->flush();
        $id = $user->getId();

        $result = $this->sweep->run($this->now, dryRun: true);

        self::assertSame(1, $result['deleted'], 'it still reports what it would do');
        self::assertNotNull($this->em->find(User::class, $id), 'but the account is still there');
    }

    /** Coming back clears the ladder, so the next warning is a year away. */
    public function testSigningInResetsEverything(): void
    {
        $user = $this->rider(monthsIdle: 23);
        $user->recordDormancyNotice('m12', $this->now);
        $user->recordDormancyNotice('m22', $this->now);

        $user->recordLogin($this->now);

        self::assertSame([], array_filter($user->dormancyNoticesSent()), 'coming back clears every rung');
        self::assertSame(0, $this->sweep->run($this->now)['notified']['m12']);
    }

    /** A rider already deleting their account has their own clock running. */
    /**
     * An administrator is never closed for silence: a sweep that removes the
     * last operator of the site is a lockout, not housekeeping. Neither the
     * notices nor, with all three on file, the deletion.
     */
    public function testAnAdministratorIsNeverWarnedNorDeleted(): void
    {
        $admin = $this->rider(monthsIdle: DormancyLadder::DELETE_AFTER_MONTHS);
        $admin->setRoles(['ROLE_ADMIN']);
        foreach (array_keys(DormancyLadder::NOTICES) as $code) {
            $admin->recordDormancyNotice($code, $this->now->modify('-2 months'));
        }
        $this->em->flush();
        $id = $admin->getId();

        $result = $this->sweep->run($this->now);

        self::assertSame(0, $result['considered']);
        self::assertSame(0, $result['deleted']);
        self::assertNotNull($this->em->find(User::class, $id));
    }

    public function testAnAccountAlreadyOnItsWayOutIsSkipped(): void
    {
        $user = $this->rider(monthsIdle: 25);
        $user->setDeletionRequestedAt($this->now);
        $this->em->flush();

        self::assertSame(0, $this->sweep->run($this->now)['considered']);
    }

    public function testAnAbandonedDeletionCodeDoesNotExemptAnAccount(): void
    {
        // The code lives an hour; a rider who never typed it is still a rider.
        $user = $this->rider(monthsIdle: 25);
        $user->setDeletionRequestedAt($this->now->modify('-2 hours'));
        $this->em->flush();

        self::assertSame(1, $this->sweep->run($this->now)['considered']);
    }

    private function warnedRider(): User
    {
        $user = $this->rider(monthsIdle: 25);
        foreach (['m12', 'm22', 'm23_final'] as $code) {
            $user->recordDormancyNotice($code, $this->now);
        }
        $this->em->flush();

        return $user;
    }

    private function rider(int $monthsIdle): User
    {
        $user = (new User())
            ->setEmail('dormant-'.bin2hex(random_bytes(4)).'@example.test')
            ->setPassword('x')
            ->setDisplayName('Dormant rider');
        $user->recordLogin($this->now->modify(sprintf('-%d months', $monthsIdle)));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
