<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Account;

use App\Account\UpdatesCadence;
use App\Account\UpdatesConsent;
use App\Account\UpdatesSubscription;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

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

    public function testNobodyStartsOnMoreThanBigNews(): void
    {
        self::assertSame(UpdatesCadence::Big, $this->rider()->getUpdatesCadence());
    }

    public function testOptingInRecordsWhatWasAgreedTo(): void
    {
        $user = $this->rider();

        $user->setUpdatesOptIn(true);
        $this->updates->applied($user, wasOptedIn: false, wasCadence: UpdatesCadence::Big);
        $this->em->flush();

        self::assertSame(1, $this->consentCount($user));
        self::assertSame(['v2-big'], $this->versions($user));
    }

    /** Each cadence is its own promise, so each records its own version and hash. */
    public function testOptingInRecordsTheCadenceChosen(): void
    {
        $big = $this->rider();
        $big->setUpdatesOptIn(true);
        $this->updates->applied($big, wasOptedIn: false, wasCadence: UpdatesCadence::Big);

        $every = $this->rider();
        $every->setUpdatesOptIn(true)->setUpdatesCadence(UpdatesCadence::Every);
        $this->updates->applied($every, wasOptedIn: false, wasCadence: UpdatesCadence::Big);
        $this->em->flush();

        self::assertSame(['v2-big'], $this->versions($big));
        self::assertSame(['v2-every'], $this->versions($every));
        self::assertSame(UpdatesConsent::hash(UpdatesCadence::Big), $this->records($big)[0]->getTextHash());
        self::assertSame(UpdatesConsent::hash(UpdatesCadence::Every), $this->records($every)[0]->getTextHash());
        self::assertNotSame(UpdatesConsent::hash(UpdatesCadence::Big), UpdatesConsent::hash(UpdatesCadence::Every));
        self::assertSame(UpdatesCadence::Every, $every->getUpdatesCadence());
    }

    /** How often is part of what was agreed: a new answer is a new consent. */
    public function testChangingTheCadenceRecordsANewConsent(): void
    {
        $user = $this->rider();
        $user->setUpdatesOptIn(true);
        $this->updates->applied($user, wasOptedIn: false, wasCadence: UpdatesCadence::Big);
        $this->em->flush();

        $user->setUpdatesCadence(UpdatesCadence::Every);
        $this->updates->applied($user, wasOptedIn: true, wasCadence: UpdatesCadence::Big);
        $this->em->flush();

        $user->setUpdatesCadence(UpdatesCadence::Big);
        $this->updates->applied($user, wasOptedIn: true, wasCadence: UpdatesCadence::Every);
        $this->em->flush();

        self::assertSame(['v2-big', 'v2-big', 'v2-every'], $this->versions($user));
    }

    /** A cadence picked while off is a preference, not a consent: nothing to record. */
    public function testChangingTheCadenceWhileOffRecordsNothing(): void
    {
        $user = $this->rider();
        $user->setUpdatesCadence(UpdatesCadence::Every);
        $this->updates->applied($user, wasOptedIn: false, wasCadence: UpdatesCadence::Big);
        $this->em->flush();

        self::assertSame(0, $this->consentCount($user));
    }

    /**
     * A rider who opted in under v1 ("a few times a year") is on Big news, and
     * their record keeps saying v1 until they give a new answer.
     */
    public function testAV1RecordStaysAsItWasAgreed(): void
    {
        $user = $this->rider();
        $user->setUpdatesOptIn(true);
        $v1Hash = hash('sha256', UpdatesConsent::KIND.'|v1|'.UpdatesConsent::V1_TEXT_KEY);
        $this->em->persist(new ConsentRecord(Uuid::v4(), (int) $user->getId(), UpdatesConsent::KIND, 'v1', $v1Hash));
        $this->em->flush();

        // Saving settings untouched adds nothing; the v1 record is the proof.
        $this->updates->applied($user, wasOptedIn: true, wasCadence: UpdatesCadence::Big);
        $this->em->flush();
        self::assertSame(['v1'], $this->versions($user));
        self::assertSame(UpdatesCadence::Big, $user->getUpdatesCadence());

        // Choosing every update is a new consent; the v1 record is left as written.
        $user->setUpdatesCadence(UpdatesCadence::Every);
        $this->updates->applied($user, wasOptedIn: true, wasCadence: UpdatesCadence::Big);
        $this->em->flush();

        self::assertSame(['v1', 'v2-every'], $this->versions($user));
        $v1 = array_values(array_filter($this->records($user), static fn (ConsentRecord $r): bool => 'v1' === $r->getVersion()));
        self::assertCount(1, $v1);
        self::assertSame($v1Hash, $v1[0]->getTextHash());
    }

    /** Any record can be shown with the words it stands for, the v1 text included. */
    public function testEachVersionPointsAtItsWords(): void
    {
        self::assertSame(['settings.updates_consent'], UpdatesConsent::textKeys('v1'));
        self::assertSame(['settings.updates_consent_v2', 'settings.updates_cadence_big'], UpdatesConsent::textKeys('v2-big'));
        self::assertSame(['settings.updates_consent_v2', 'settings.updates_cadence_every'], UpdatesConsent::textKeys('v2-every'));
        self::assertSame([], UpdatesConsent::textKeys('v9'));

        $translator = self::getContainer()->get('translator');
        foreach (['v1', 'v2-big', 'v2-every'] as $version) {
            foreach (UpdatesConsent::textKeys($version) as $key) {
                self::assertNotSame($key, $translator->trans($key, [], 'messages', 'en'), $key.' must stay in the catalogue');
            }
        }
    }

    /** Saving settings without touching the toggle must not pile up records. */
    public function testSavingAgainRecordsNothingNew(): void
    {
        $user = $this->rider();
        $user->setUpdatesOptIn(true);
        $this->updates->applied($user, wasOptedIn: false, wasCadence: UpdatesCadence::Big);
        $this->em->flush();

        $this->updates->applied($user, wasOptedIn: true, wasCadence: UpdatesCadence::Big);
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
        $this->updates->applied($user, wasOptedIn: false, wasCadence: UpdatesCadence::Big);
        $this->em->flush();

        $user->setUpdatesOptIn(false);
        $this->updates->applied($user, wasOptedIn: true, wasCadence: UpdatesCadence::Big);
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

    /** The link turns the list off at every cadence, and leaves the records standing. */
    public function testTheUnsubscribeLinkClearsEveryUpdateToo(): void
    {
        $user = $this->rider();
        $user->setUpdatesOptIn(true)->setUpdatesCadence(UpdatesCadence::Every);
        $this->updates->applied($user, wasOptedIn: false, wasCadence: UpdatesCadence::Big);
        $this->em->flush();

        $params = $this->updates->linkParameters($user);
        self::assertTrue($this->updates->unsubscribe($params['u'], $params['t']));

        $this->em->clear();
        $fresh = $this->em->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);
        self::assertFalse($fresh->isUpdatesOptIn());
        self::assertSame(['v2-every'], $this->versions($fresh));
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
        return \count($this->records($user));
    }

    /** @return list<ConsentRecord> */
    private function records(User $user): array
    {
        return array_values($this->em->getRepository(ConsentRecord::class)->findBy([
            'userId' => $user->getId(),
            'kind' => UpdatesConsent::KIND,
        ]));
    }

    /**
     * Sorted, because records written in the same second have no order of their own.
     *
     * @return list<string>
     */
    private function versions(User $user): array
    {
        $versions = array_map(static fn (ConsentRecord $r): string => $r->getVersion(), $this->records($user));
        sort($versions);

        return $versions;
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
