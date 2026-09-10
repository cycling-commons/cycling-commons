<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Account;

use App\Account\UpdatesConsent;
use App\Account\UpdatesSubscription;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The release list: opting in leaves proof, opting out asks for none.
 *
 * @see docs/specs/roadmap-and-changelog.md §4
 */
final class UpdatesSubscriptionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UpdatesSubscription $updates;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->updates = self::getContainer()->get(UpdatesSubscription::class);
    }

    public function testNobodyIsOnTheListWithoutAsking(): void
    {
        self::assertFalse($this->rider()->isUpdatesOptIn(), 'the default has to be off, not "off unless migrated"');
    }

    public function testOptingInRecordsWhatWasAgreedTo(): void
    {
        $user = $this->rider();

        $user->setUpdatesOptIn(true);
        $this->updates->applied($user, wasOptedIn: false);
        $this->em->flush();

        self::assertSame(1, $this->consentCount($user));
    }

    /** Saving settings without touching the toggle must not pile up records. */
    public function testSavingAgainRecordsNothingNew(): void
    {
        $user = $this->rider();
        $user->setUpdatesOptIn(true);
        $this->updates->applied($user, wasOptedIn: false);
        $this->em->flush();

        $this->updates->applied($user, wasOptedIn: true);
        $this->em->flush();

        self::assertSame(1, $this->consentCount($user));
    }

    /**
     * The record is evidence that consent was given, not a claim that it still
     * holds, so withdrawing leaves it alone. The flag is the current answer.
     */
    public function testWithdrawingLeavesTheRecordStanding(): void
    {
        $user = $this->rider();
        $user->setUpdatesOptIn(true);
        $this->updates->applied($user, wasOptedIn: false);
        $this->em->flush();

        $user->setUpdatesOptIn(false);
        $this->updates->applied($user, wasOptedIn: true);
        $this->em->flush();

        self::assertFalse($user->isUpdatesOptIn());
        self::assertSame(1, $this->consentCount($user));
    }

    public function testTheUnsubscribeLinkWorksWithoutSigningIn(): void
    {
        $user = $this->rider();
        $user->setUpdatesOptIn(true);
        $this->em->flush();

        $params = $this->updates->linkParameters($user);
        self::assertTrue($this->updates->unsubscribe($params['u'], $params['t']));
        self::assertFalse($user->isUpdatesOptIn());
    }

    /** The uuid, never the id: the link must not leak how many riders there are. */
    public function testTheLinkCarriesTheUuidAndASignature(): void
    {
        $user = $this->rider();
        $params = $this->updates->linkParameters($user);

        self::assertSame((string) $user->getUuid(), $params['u']);
        self::assertNotSame((string) $user->getId(), $params['u']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $params['t']);
    }

    public function testAForgedTokenChangesNothing(): void
    {
        $user = $this->rider();
        $user->setUpdatesOptIn(true);
        $this->em->flush();

        self::assertFalse($this->updates->unsubscribe((string) $user->getUuid(), str_repeat('a', 32)));
        self::assertTrue($user->isUpdatesOptIn(), 'a bad signature must not unsubscribe anybody');
    }

    /**
     * Same answer for an unknown account as for a real one, so the URL cannot
     * be used to test whether a uuid exists.
     */
    public function testAnUnknownAccountIsNotDistinguishable(): void
    {
        self::assertFalse($this->updates->unsubscribe('00000000-0000-4000-8000-000000000000', str_repeat('b', 32)));
    }

    private function consentCount(User $user): int
    {
        return \count($this->em->getRepository(ConsentRecord::class)->findBy([
            'userId' => $user->getId(),
            'kind' => UpdatesConsent::KIND,
        ]));
    }

    private function rider(): User
    {
        $user = (new User())
            ->setEmail('updates-'.bin2hex(random_bytes(4)).'@example.test')
            ->setPassword('x')
            ->setDisplayName('Updates rider');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
