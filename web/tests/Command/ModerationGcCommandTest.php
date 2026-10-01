<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Command;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:moderation:gc`, the wrapper an operator and a timer invoke.
 *
 * It empties the curators' Trash of what has waited there longer than 30
 * days, with its thread, and touches nothing else: a settled submission and
 * its thread are on no timer (owner 2026-10-01). TrashBinTest owns the bin's
 * own rules; this pins that the command really deletes, reports honest
 * counts, is safe to run twice, and leaves settled work alone.
 *
 * @see docs/specs/moderation-and-contribution.md §6, §8
 */
final class ModerationGcCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->db = static::getContainer()->get(Connection::class);
    }

    public function testItEmptiesTrashPastThirtyDaysAndSaysSo(): void
    {
        $old = $this->trashed(SubmissionStatus::Rejected, '-31 days');
        $young = $this->trashed(SubmissionStatus::Pending, '-29 days');

        $tester = $this->run_();

        $tester->assertCommandIsSuccessful();
        // The count in the output is what a timer's log shows; it has to be real.
        self::assertMatchesRegularExpression('/Deleted from Trash: [1-9]\d* submission\(s\)/', $tester->getDisplay());
        self::assertFalse($this->exists($old), 'a row 31 days in Trash survived the purge');
        self::assertTrue($this->exists($young), 'a row 29 days in Trash was deleted early');
    }

    /**
     * The old three-month retention sweep is gone: a rejected or withdrawn
     * submission and its thread stay as long as the rider's account, however
     * old the decision.
     */
    public function testASettledSubmissionAndItsThreadAreNeverCollected(): void
    {
        $uid = $this->rider();
        $rejected = $this->submission(SubmissionStatus::Rejected, '-4 months');
        $withdrawn = $this->submission(SubmissionStatus::Withdrawn, '-5 years');
        $this->message($uid, 'submission', (int) $rejected->getId());
        $this->message($uid, 'submission', (int) $withdrawn->getId());

        $this->run_()->assertCommandIsSuccessful();

        self::assertTrue($this->exists($rejected));
        self::assertTrue($this->exists($withdrawn));
        self::assertSame(1, $this->threadSize('submission', (int) $rejected->getId()));
        self::assertSame(1, $this->threadSize('submission', (int) $withdrawn->getId()));
    }

    public function testAPendingRowIsNeverCollected(): void
    {
        // Undecided work is not garbage, however old. Deleting it would drop a
        // contributor's submission on the floor with no decision and no notice.
        $pending = $this->submission(SubmissionStatus::Pending, null);

        $this->run_();

        self::assertTrue($this->exists($pending));
    }

    public function testASecondRunIsAQuietNoOp(): void
    {
        $this->trashed(SubmissionStatus::Pending, '-40 days');

        $this->run_();
        $second = $this->run_();

        $second->assertCommandIsSuccessful();
        // Idempotent: a timer that fires twice must not report phantom work.
        self::assertStringContainsString('Deleted from Trash: 0 submission(s), 0 route correction(s), 0 route', $second->getDisplay());
    }

    /**
     * A purged row takes its message thread with it. A row under legal hold
     * stays in the bin with its thread, however long it has been there.
     */
    public function testAPurgedRowTakesItsMessageThreadWithIt(): void
    {
        $uid = $this->rider();
        $gone = $this->trashed(SubmissionStatus::NeedsInfo, '-31 days');
        $held = $this->trashed(SubmissionStatus::Pending, '-90 days');
        $this->db->executeStatement('UPDATE submission SET escalated_at = NOW() WHERE id = ?', [$held->getId()]);
        foreach ([$gone, $held] as $sub) {
            $this->message($uid, 'submission', (int) $sub->getId());
            $this->message($uid, 'submission', (int) $sub->getId());
        }

        $route = (int) $this->db->fetchOne(
            "INSERT INTO recommended_route (name, geom, distance_m, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('gc thread route', ST_SetSRID(ST_GeomFromText('LINESTRING(5.2 50.4, 5.3 50.5)'), 4326), 5000, 'unverified', 'user', :ref, '{}', NOW(), NOW()) RETURNING id",
            ['ref' => 'user:gc-'.uniqid()],
        );
        $correction = (int) $this->db->fetchOne(
            "INSERT INTO route_suggestion (route_id, user_id, reason, status, created_at, trashed_at, trashed_by, trashed_from)
             VALUES (:r, :u, 'other', 'trashed', NOW() - INTERVAL '40 days', NOW() - INTERVAL '31 days', 1, 'pending') RETURNING id",
            ['r' => $route, 'u' => $uid],
        );
        $this->message($uid, 'correction', $correction);

        $this->run_()->assertCommandIsSuccessful();

        self::assertFalse($this->exists($gone));
        self::assertSame(0, $this->threadSize('submission', (int) $gone->getId()), 'the purged row left its thread behind');
        self::assertTrue($this->exists($held), 'a held row is never purged');
        self::assertSame(2, $this->threadSize('submission', (int) $held->getId()), 'a held row keeps its thread');
        self::assertFalse((bool) $this->db->fetchOne('SELECT 1 FROM route_suggestion WHERE id = ?', [$correction]));
        self::assertSame(0, $this->threadSize('correction', $correction), 'the purged correction left its thread behind');
    }

    private function rider(): int
    {
        $rider = (new User())->setEmail('gc-thread-'.uniqid('', true).'@test.test');
        $rider->setPassword('x');
        $this->em->persist($rider);
        $this->em->flush();

        return (int) $rider->getId();
    }

    private function message(int $userId, string $channel, int $refId): void
    {
        $this->db->executeStatement(
            "INSERT INTO user_message (user_id, kind, sender, channel, ref_id, ref_label, body_key, body_params, created_at)
             VALUES (:u, 'decision', 'curator', :c, :r, 'gc fixture', 'x', '{}', NOW())",
            ['u' => $userId, 'c' => $channel, 'r' => $refId],
        );
    }

    private function threadSize(string $channel, int $refId): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM user_message WHERE channel = :c AND ref_id = :r', ['c' => $channel, 'r' => $refId]);
    }

    private function run_(): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:moderation:gc'));
        $tester->execute([]);

        return $tester;
    }

    /** A row put in the bin `$ago`, the way Trash leaves it. */
    private function trashed(SubmissionStatus $from, string $ago): Submission
    {
        $sub = $this->submission($from, null);
        $sub->moveToTrash(1, new \DateTimeImmutable($ago));
        $this->em->flush();

        return $sub;
    }

    private function submission(SubmissionStatus $status, ?string $decidedAt): Submission
    {
        $sub = (new Submission())
            ->setType(SubmissionType::Edit)->setLetter('D')->setUserId(7)
            ->setStatus($status)->setTitle('gc-cmd fixture '.uniqid())
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

    private function exists(Submission $sub): bool
    {
        return (bool) $this->db->fetchOne('SELECT 1 FROM submission WHERE id = :id', ['id' => $sub->getId()]);
    }
}
