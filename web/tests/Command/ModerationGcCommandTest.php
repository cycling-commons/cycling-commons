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
 * `app:moderation:gc` (test-suite review 2026-08-24).
 *
 * RetentionTest owns the retention rules themselves. This pins the wrapper an
 * operator and a cron actually invoke: that it really deletes (there is no dry
 * run here, unlike expire-closures), that it reports honest counts, and that
 * it is safe to run twice.
 *
 * @see docs/specs/moderation-and-contribution.md §8
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

    public function testItDeletesADecidedRowPastTheCutoffAndSaysSo(): void
    {
        $old = $this->submission(SubmissionStatus::Rejected, '-4 months');
        $young = $this->submission(SubmissionStatus::Rejected, '-1 day');

        $tester = $this->run_();

        $tester->assertCommandIsSuccessful();
        // The count in the output is what a cron log shows; it has to be real.
        self::assertMatchesRegularExpression('/Deleted \d+ dismissed route correction\(s\) and [1-9]\d* rejected submission\(s\)/', $tester->getDisplay());
        self::assertFalse($this->exists($old), 'a rejected submission past the cutoff survived the sweep');
        self::assertTrue($this->exists($young), 'a recently rejected submission was deleted early');
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
        $this->submission(SubmissionStatus::Rejected, '-4 months');

        $this->run_();
        $second = $this->run_();

        $second->assertCommandIsSuccessful();
        // Idempotent: a cron that fires twice must not report phantom work.
        self::assertStringContainsString('Deleted 0 dismissed route correction(s) and 0 rejected submission(s).', $second->getDisplay());
    }

    /**
     * A swept submission takes its message thread with it, as Trash does
     * (MessageService::deleteThread()): a rider's inbox never keeps a
     * conversation about a row that no longer exists. Held and young rows
     * keep theirs. A dismissed route correction past the cutoff goes with its
     * thread too.
     */
    public function testASweptSubmissionTakesItsMessageThreadWithIt(): void
    {
        $rider = (new User())->setEmail('gc-thread-'.uniqid('', true).'@test.test');
        $rider->setPassword('x');
        $this->em->persist($rider);
        $this->em->flush();
        $uid = (int) $rider->getId();

        $rejected = $this->submission(SubmissionStatus::Rejected, '-4 months');
        $withdrawn = $this->submission(SubmissionStatus::Withdrawn, '-4 months');
        $held = $this->submission(SubmissionStatus::Rejected, '-4 months');
        $this->db->executeStatement('UPDATE submission SET escalated_at = NOW() WHERE id = ?', [$held->getId()]);
        $young = $this->submission(SubmissionStatus::Rejected, '-1 day');
        foreach ([$rejected, $withdrawn, $held, $young] as $sub) {
            $this->message($uid, 'submission', (int) $sub->getId());
            $this->message($uid, 'submission', (int) $sub->getId());
        }

        $route = (int) $this->db->fetchOne(
            "INSERT INTO recommended_route (name, geom, distance_m, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('gc thread route', ST_SetSRID(ST_GeomFromText('LINESTRING(5.2 50.4, 5.3 50.5)'), 4326), 5000, 'unverified', 'user', :ref, '{}', NOW(), NOW()) RETURNING id",
            ['ref' => 'user:gc-'.uniqid()],
        );
        $dismissed = (int) $this->db->fetchOne(
            "INSERT INTO route_suggestion (route_id, user_id, reason, status, created_at, resolved_at, resolved_by)
             VALUES (:r, :u, 'other', 'dismissed', NOW() - INTERVAL '5 months', NOW() - INTERVAL '4 months', 1) RETURNING id",
            ['r' => $route, 'u' => $uid],
        );
        $this->message($uid, 'correction', $dismissed);

        $this->run_()->assertCommandIsSuccessful();

        self::assertFalse($this->exists($rejected));
        self::assertFalse($this->exists($withdrawn));
        self::assertSame(0, $this->threadSize('submission', (int) $rejected->getId()), 'the rejected row left its thread behind');
        self::assertSame(0, $this->threadSize('submission', (int) $withdrawn->getId()), 'the withdrawn row left its thread behind');
        self::assertTrue($this->exists($held), 'a held row is never swept');
        self::assertSame(2, $this->threadSize('submission', (int) $held->getId()), 'a held row keeps its thread');
        self::assertSame(2, $this->threadSize('submission', (int) $young->getId()), 'a young row keeps its thread');
        self::assertSame(0, $this->threadSize('correction', $dismissed), 'the dismissed correction left its thread behind');
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

    private function submission(SubmissionStatus $status, ?string $decidedAt): Submission
    {
        $sub = (new Submission())
            ->setType(SubmissionType::NewItem)->setLetter('D')->setUserId(7)
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
