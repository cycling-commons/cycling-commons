<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Support\Entity\ContentReport;
use App\Support\ReportGround;
use App\Support\ReportStatus;
use App\Support\ReportTarget;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * A reporter's reply address is deleted 90 days after the decision
 * (docs/specs/content-reports.md §10), through the daily `app:media:gc`.
 *
 * The address exists to answer one report. Once the answer is out and the
 * window for a follow-up has passed, keeping it is keeping personal data for
 * nothing. A report still waiting keeps it, because the reporter has not been
 * answered yet, and a report whose target is under legal hold keeps it,
 * because the hold preserves the data around the material as well
 * (docs/specs/photo-uploads.md §6d).
 */
final class ReportContactRetentionTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function testADecidedReportOlderThanNinetyDaysLosesItsAddress(): void
    {
        $ids = [];
        foreach ([ReportStatus::Upheld, ReportStatus::Rejected, ReportStatus::Moot] as $status) {
            $ids[] = $this->report(ReportTarget::Route, '41', $status->value.'@example.org', $status, 91)->getId();
        }

        $display = $this->gc();

        foreach ($ids as $id) {
            self::assertNull($this->reload($id)->getReporterContact(), 'a decided report past 90 days keeps no address');
        }
        self::assertMatchesRegularExpression('/cleared 3 expired content report contact/', $display);
    }

    public function testAnOpenReportKeepsItsAddress(): void
    {
        $open = $this->report(ReportTarget::Route, '42', 'open@example.org', null, 0, createdDaysAgo: 400);
        $taken = $this->report(ReportTarget::Route, '43', 'taken@example.org', null, 0, createdDaysAgo: 400);
        $taken->takeUp(ReportStatus::InProgress);
        $this->em->flush();

        $this->gc();

        self::assertSame('open@example.org', $this->reload($open->getId())->getReporterContact());
        self::assertSame('taken@example.org', $this->reload($taken->getId())->getReporterContact());
    }

    /** A report decided, then taken up again, is waiting once more: its old decision time does not count. */
    public function testAReopenedReportKeepsItsAddress(): void
    {
        $reopened = $this->report(ReportTarget::Route, '44', 'reopened@example.org', ReportStatus::Rejected, 200);
        $reopened->takeUp(ReportStatus::InProgress);
        $this->em->flush();

        $this->gc();

        self::assertSame('reopened@example.org', $this->reload($reopened->getId())->getReporterContact());
    }

    public function testAReportDecidedEightyNineDaysAgoKeepsItsAddress(): void
    {
        $recent = $this->report(ReportTarget::Route, '45', 'recent@example.org', ReportStatus::Upheld, 89);

        $this->gc();

        self::assertSame('recent@example.org', $this->reload($recent->getId())->getReporterContact());
    }

    /** Legal hold outlives retention (docs/specs/photo-uploads.md §6d), for the report and the photo request alike. */
    public function testAReportOnAHeldPhotoKeepsItsAddress(): void
    {
        $owner = $this->rider('retention-held@example.com');
        $held = $this->upload($owner);
        $this->em->getConnection()->executeStatement(
            "UPDATE media_upload SET takedown_contact = 'held@example.org', takedown_resolved_at = NOW() - INTERVAL '200 days' WHERE id = ?",
            [$held->getId()->toRfc4122()],
        );
        $this->em->refresh($held);
        $held->escalate((int) $owner->getId(), 'Suspected illegal content.');
        $this->em->flush();

        // The target id is written as a reporter's link may carry it, upper case.
        $report = $this->report(ReportTarget::Photo, strtoupper($held->getId()->toRfc4122()), 'held@example.org', ReportStatus::Upheld, 200);
        $free = $this->report(ReportTarget::Photo, Uuid::v4()->toRfc4122(), 'free@example.org', ReportStatus::Upheld, 200);

        $this->gc();

        self::assertSame('held@example.org', $this->reload($report->getId())->getReporterContact(), 'the hold keeps the report address');
        self::assertNull($this->reload($free->getId())->getReporterContact(), 'a photo not under hold is swept as usual');
        self::assertSame('held@example.org', $this->em->getConnection()->fetchOne(
            'SELECT takedown_contact FROM media_upload WHERE id = ?',
            [$held->getId()->toRfc4122()],
        ), 'the hold keeps the photo request address');
    }

    public function testASecondRunClearsNothing(): void
    {
        $this->report(ReportTarget::Route, '46', 'twice@example.org', ReportStatus::Rejected, 120);

        self::assertMatchesRegularExpression('/cleared 1 expired content report contact/', $this->gc());
        self::assertMatchesRegularExpression('/cleared 0 expired content report contact/', $this->gc());
    }

    private function report(
        ReportTarget $target,
        string $targetId,
        string $contact,
        ?ReportStatus $status,
        int $decidedDaysAgo,
        int $createdDaysAgo = 0,
    ): ContentReport {
        $now = new \DateTimeImmutable();
        $created = $now->modify(\sprintf('-%d days', max($createdDaysAgo, $decidedDaysAgo + 1)));
        $report = new ContentReport(Uuid::v4(), $target, $targetId, ReportGround::Untrue, 'Retention test.', str_repeat('a', 64), $created);
        $report->setReporterContact($contact);
        if (null !== $status) {
            $report->decide($status, 'Decided.', 1, $now->modify(\sprintf('-%d days', $decidedDaysAgo)));
        }
        $this->em->persist($report);
        $this->em->flush();

        return $report;
    }

    private function reload(Uuid $id): ContentReport
    {
        $this->em->clear();
        $report = $this->em->find(ContentReport::class, $id);
        self::assertInstanceOf(ContentReport::class, $report);

        return $report;
    }

    private function gc(): string
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:media:gc'));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function rider(string $email): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName('Retention Rider');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function upload(User $owner): MediaUpload
    {
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);
        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $this->em->persist($upload);
        $this->em->flush();

        return $upload;
    }
}
