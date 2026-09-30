<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\BugStatus;
use App\Support\Entity\BugReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A bug reporter's address is cleared 24 months after the outcome
 * (docs/specs/contact-and-support.md §5), through the daily `app:media:gc`.
 *
 * The address exists to tell the reporter the outcome. Once a report is
 * resolved or declined the outcome has gone out, and 24 months later, the same
 * period the privacy page gives mail, the address goes. The report itself
 * stays: it is project data, and the fix still helps everybody.
 */
final class BugReporterEmailRetentionTest extends KernelTestCase
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

    public function testAResolvedOrDeclinedReportLosesItsAddressAfterTwentyFourMonths(): void
    {
        $resolved = $this->report(BugStatus::Resolved, decidedMonthsAgo: 25);
        $declined = $this->report(BugStatus::Declined, decidedMonthsAgo: 25);

        $display = $this->gc();

        foreach ([$resolved, $declined] as $id) {
            $row = $this->row($id);
            self::assertNull($row['reporter_email'], 'the address goes');
            self::assertSame('Retention bug', $row['title'], 'the report stays');
        }
        self::assertMatchesRegularExpression('/cleared 2 expired bug reporter address/', $display);
    }

    public function testAReportDecidedTwentyThreeMonthsAgoKeepsItsAddress(): void
    {
        $recent = $this->report(BugStatus::Resolved, decidedMonthsAgo: 23);

        $this->gc();

        self::assertSame('reporter@example.org', $this->row($recent)['reporter_email']);
    }

    /** A bug still open has not been answered, however old the report is. */
    public function testAnOpenReportKeepsItsAddressHoweverOld(): void
    {
        $ids = [];
        foreach (BugStatus::open() as $status) {
            $ids[] = $this->report($status, decidedMonthsAgo: 30);
        }

        $this->gc();

        foreach ($ids as $id) {
            self::assertSame('reporter@example.org', $this->row($id)['reporter_email']);
        }
    }

    /** Told the outcome long ago, then reopened and decided again last month: the clock runs from the new decision. */
    public function testAReportDecidedAgainRecentlyKeepsItsAddress(): void
    {
        $again = $this->report(BugStatus::Resolved, decidedMonthsAgo: 30);
        $this->em->getConnection()->executeStatement(
            "UPDATE bug_report SET updated_at = NOW() - INTERVAL '1 month' WHERE id = ?",
            [$again],
        );

        $this->gc();

        self::assertSame('reporter@example.org', $this->row($again)['reporter_email']);
    }

    public function testASecondRunClearsNothing(): void
    {
        $this->report(BugStatus::Declined, decidedMonthsAgo: 26);

        self::assertMatchesRegularExpression('/cleared 1 expired bug reporter address/', $this->gc());
        self::assertMatchesRegularExpression('/cleared 0 expired bug reporter address/', $this->gc());
    }

    private function report(BugStatus $status, int $decidedMonthsAgo): int
    {
        $report = new BugReport('Retention bug', 'Something broke.');
        $report->setReporterEmail('reporter@example.org');
        $report->setStatus($status);
        $this->em->persist($report);
        $this->em->flush();
        $id = (int) $report->getId();

        $decided = (new \DateTimeImmutable())->modify(\sprintf('-%d months', $decidedMonthsAgo))->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement(
            'UPDATE bug_report SET created_at = ?::timestamp - INTERVAL \'1 day\', updated_at = ?, notified_at = CASE WHEN ? THEN ?::timestamp ELSE NULL END WHERE id = ?',
            [$decided, $decided, $status->notifiesReporter() ? 'true' : 'false', $decided, $id],
        );
        $this->em->clear();

        return $id;
    }

    /** @return array{reporter_email: string|null, title: string} */
    private function row(int $id): array
    {
        /** @var array{reporter_email: string|null, title: string}|false $row */
        $row = $this->em->getConnection()->fetchAssociative('SELECT reporter_email, title FROM bug_report WHERE id = ?', [$id]);
        self::assertIsArray($row, 'the report row is still there');

        return $row;
    }

    private function gc(): string
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:media:gc'));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
