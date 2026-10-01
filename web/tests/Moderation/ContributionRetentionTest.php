<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Account\DormancySweep;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\RouteSuggestionStatus;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Moderation\TrashBin;
use App\Service\UserDeletionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Messages about a contribution live as long as the rider's account (owner
 * 2026-10-01). Nothing deletes a settled submission or its thread on a
 * timer; deleting the account, by the rider or by the dormancy sweep, takes
 * the rider's threads and their turned-down rows with it and leaves what was
 * approved on the map. Legal hold outlives all of it.
 *
 * @see docs/specs/moderation-and-contribution.md §8
 * @see docs/specs/account-and-auth.md §6.3
 */
final class ContributionRetentionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;
    private MessageService $messages;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = static::getContainer()->get(Connection::class);
        $this->messages = static::getContainer()->get(MessageService::class);
    }

    public function testARejectedSubmissionAndItsThreadOutliveTheOldThreeMonthCutoff(): void
    {
        $rider = $this->user('rider');
        $curator = $this->user('curator');
        $rejected = $this->submission($rider, SubmissionStatus::Rejected, '-4 months');
        $withdrawn = $this->submission($rider, SubmissionStatus::Withdrawn, '-3 years');
        $this->thread($rider, $curator, 'submission', (int) $rejected->getId());
        $this->thread($rider, $curator, 'submission', (int) $withdrawn->getId());

        // Every sweep there is: the Trash purge, through its service and its command.
        static::getContainer()->get(TrashBin::class)->purgeExpired(new \DateTimeImmutable('+10 years'));
        (new CommandTester((new Application(self::$kernel))->find('app:moderation:gc')))->execute([]);

        $this->em->clear();
        self::assertNotNull($this->em->find(Submission::class, $rejected->getId()));
        self::assertNotNull($this->em->find(Submission::class, $withdrawn->getId()));
        self::assertSame(3, $this->threadSize('submission', (int) $rejected->getId()));
        self::assertSame(3, $this->threadSize('submission', (int) $withdrawn->getId()));
    }

    public function testARiderDeletingTheirAccountTakesTheirThreadsAndTurnedDownRows(): void
    {
        $rider = $this->user('rider');
        $curator = $this->user('curator');
        $other = $this->user('other');
        $rows = $this->contributions($rider, $curator);
        $kept = $this->submission($other, SubmissionStatus::Rejected, '-1 day');
        $this->thread($other, $curator, 'submission', (int) $kept->getId());

        $deletions = static::getContainer()->get(UserDeletionService::class);
        $deletions->requestDeletion($rider);
        self::assertTrue($deletions->confirmDeletion($rider, (string) $rider->getDeletionCode()));

        $this->assertGoneTheRightWay($rows, (int) $rider->getId());
        self::assertNotNull($this->em->find(Submission::class, $kept->getId()), 'another rider\'s rejected row stays');
        self::assertSame(3, $this->threadSize('submission', (int) $kept->getId()), 'and so does their thread');
    }

    public function testTheDormancySweepDeletesInTheSameWay(): void
    {
        $now = new \DateTimeImmutable();
        $rider = $this->user('dormant');
        $rider->recordLogin($now->modify('-25 months'));
        foreach (['m12', 'm22', 'm23_final'] as $code) {
            $rider->recordDormancyNotice($code, $now->modify('-2 months'));
        }
        $this->em->flush();
        $curator = $this->user('curator');
        $rows = $this->contributions($rider, $curator);
        $riderId = (int) $rider->getId();

        static::getContainer()->get(DormancySweep::class)->run($now);
        $this->em->flush();

        self::assertNull($this->em->find(User::class, $riderId), 'the dormant account is gone');
        $this->assertGoneTheRightWay($rows, $riderId);
    }

    public function testLegalHoldOutlivesAccountDeletion(): void
    {
        $rider = $this->user('held');
        $curator = $this->user('curator');
        $held = $this->submission($rider, SubmissionStatus::Rejected, '-1 day');
        $this->db->executeStatement('UPDATE submission SET escalated_at = NOW() WHERE id = ?', [$held->getId()]);
        $this->thread($rider, $curator, 'submission', (int) $held->getId());
        $riderId = (int) $rider->getId();

        $deletions = static::getContainer()->get(UserDeletionService::class);
        $deletions->purge($rider);
        $this->em->flush();

        $this->em->clear();
        self::assertNull($this->em->find(User::class, $riderId));
        self::assertNotNull($this->em->find(Submission::class, $held->getId()), 'a held row outlives the account');
        self::assertSame(3, $this->threadSize('submission', (int) $held->getId()), 'with its whole thread');
        self::assertSame(2, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM user_message WHERE channel = 'submission' AND ref_id = ? AND user_id IS NULL",
            [$held->getId()],
        ), 'the messages to the rider keep their place, addressed to nobody');
    }

    /**
     * One of everything a rider leaves behind, each with a thread.
     *
     * @return array<string, int>
     */
    private function contributions(User $rider, User $curator): array
    {
        $rows = [];
        foreach ([
            'approved' => SubmissionStatus::Approved,
            'pending' => SubmissionStatus::Pending,
            'rejected' => SubmissionStatus::Rejected,
            'withdrawn' => SubmissionStatus::Withdrawn,
        ] as $key => $status) {
            $sub = $this->submission($rider, $status, SubmissionStatus::Pending === $status ? null : '-1 day');
            $rows[$key] = (int) $sub->getId();
            $this->thread($rider, $curator, 'submission', $rows[$key]);
        }
        $binned = $this->submission($rider, SubmissionStatus::Rejected, '-1 day');
        $binned->moveToTrash((int) $curator->getId(), new \DateTimeImmutable());
        $this->em->flush();
        $rows['trashed_rejected'] = (int) $binned->getId();
        $this->thread($rider, $curator, 'submission', $rows['trashed_rejected']);

        $route = (new RecommendedRoute())->setName('Retention route '.uniqid())
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(5000)->setState(ItemState::Unverified)
            ->setSource(ItemSource::User)->setSourceRef('user:ret-'.uniqid());
        $this->em->persist($route);
        $this->em->flush();
        foreach (['dismissed' => RouteSuggestionStatus::Dismissed, 'done' => RouteSuggestionStatus::Done] as $key => $status) {
            $s = new RouteSuggestion((int) $route->getId(), (int) $rider->getId(), RouteSuggestionReason::Other, 'note');
            $this->em->persist($s);
            $this->em->flush();
            $s->resolve($status, (int) $curator->getId());
            $this->em->flush();
            $rows['correction_'.$key] = (int) $s->getId();
            $this->thread($rider, $curator, 'correction', $rows['correction_'.$key]);
        }

        return $rows;
    }

    /** @param array<string, int> $rows */
    private function assertGoneTheRightWay(array $rows, int $riderId): void
    {
        $this->em->clear();
        self::assertNotNull($this->em->find(Submission::class, $rows['approved']), 'an approved contribution stays');
        self::assertNotNull($this->em->find(Submission::class, $rows['pending']), 'a pending one stays for a curator to decide');
        self::assertNull($this->em->find(Submission::class, $rows['rejected']));
        self::assertNull($this->em->find(Submission::class, $rows['withdrawn']));
        self::assertNull($this->em->find(Submission::class, $rows['trashed_rejected']), 'a rejected row in Trash goes too');
        self::assertNull($this->em->find(RouteSuggestion::class, $rows['correction_dismissed']));
        self::assertNotNull($this->em->find(RouteSuggestion::class, $rows['correction_done']), 'an applied correction stays');

        self::assertSame(0, (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM user_message WHERE user_id = :u OR (sender = 'rider' AND sender_id = :u)",
            ['u' => $riderId],
        ), 'nothing to or from the rider is left');
        foreach (['approved', 'pending', 'rejected', 'withdrawn', 'trashed_rejected'] as $key) {
            self::assertSame(0, $this->threadSize('submission', $rows[$key]), $key.' kept its thread');
        }
        self::assertSame(0, $this->threadSize('correction', $rows['correction_dismissed']));
        self::assertSame(0, $this->threadSize('correction', $rows['correction_done']));
    }

    /** The curator's question, the rider's answer, the curator's note. */
    private function thread(User $rider, User $curator, string $channel, int $refId): void
    {
        $riderId = (int) $rider->getId();
        $curatorId = (int) $curator->getId();
        $this->messages->sendSystem($riderId, UserMessageKind::SubmissionNeedsInfo, $channel, $refId, 'REF-'.$refId, 'messages.body.submission_needs_info', ['%title%' => 'x'], 'A question');
        $this->em->flush();
        $this->messages->sendRiderReply($curatorId, $riderId, $channel, $refId, 'REF-'.$refId, 'An answer');
        $this->messages->sendCurator($riderId, $curatorId, $channel, $refId, 'REF-'.$refId, 'A note');
    }

    private function threadSize(string $channel, int $refId): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_message WHERE channel = ? AND ref_id = ?', [$channel, $refId]);
    }

    private function submission(User $rider, SubmissionStatus $status, ?string $decidedAt): Submission
    {
        $sub = (new Submission())
            ->setType(SubmissionType::Edit)->setLetter('D')->setUserId((int) $rider->getId())
            ->setStatus($status)->setTitle('Retention fixture '.uniqid())
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')
            ->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        if (null !== $decidedAt) {
            $sub->setDecidedAt(new \DateTimeImmutable($decidedAt));
        }
        $this->em->persist($sub);
        $this->em->flush();

        return $sub;
    }

    private function user(string $kind): User
    {
        $u = (new User())->setEmail($kind.'-'.uniqid('', true).'@retention.test')->setDisplayName(ucfirst($kind));
        $u->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }
}
