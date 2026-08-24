<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Command;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
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
