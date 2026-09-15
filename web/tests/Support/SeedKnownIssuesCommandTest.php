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

/** The seeded known issues reach /known-issues once, and only once. */
final class SeedKnownIssuesCommandTest extends KernelTestCase
{
    public function testTheRepositoryFileSeedsPublicIssuesAndARerunAddsNothing(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $before = \count($em->getRepository(BugReport::class)->findBy(['isPublic' => true]));

        $tester = new CommandTester((new Application(self::$kernel))->find('app:bugs:seed-known'));
        self::assertSame(0, $tester->execute([]));
        $filed = \count($em->getRepository(BugReport::class)->findBy(['isPublic' => true])) - $before;
        self::assertGreaterThan(0, $filed, 'the repository file has entries and they were filed');
        self::assertStringContainsString(sprintf('%d filed', $filed), $tester->getDisplay());

        $surface = $em->getRepository(BugReport::class)->findOneBy(['publicTitle' => 'Surfaces view: a road without a surface tag draws nothing']);
        self::assertNotNull($surface);
        self::assertTrue($surface->isPublic());
        self::assertSame('map', $surface->getArea()->value);
        // The body is the entry's prose and changes as the issue does, so this
        // pins what the seed must carry across rather than a sentence: the
        // status, which is the field a reader of /known-issues sorts by.
        self::assertNotSame('', (string) $surface->getPublicBody());
        self::assertSame('resolved', $surface->getStatus()->value);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('0 filed', $tester->getDisplay(), 'a second run files nothing');
    }

    /**
     * `--update` replays the file over entries already filed.
     *
     * Without it a fixed issue could only stop saying it is broken by hand, in
     * every environment. With it as the DEFAULT a curator's own wording on the
     * desk would be overwritten by the next deploy, which is why the plain run
     * still skips.
     */
    public function testUpdateReplaysTheFileOverEntriesAlreadyFiled(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tester = new CommandTester((new Application(static::$kernel))->find('app:bugs:seed-known'));

        self::assertSame(0, $tester->execute([]));
        $title = 'Surfaces view: a road without a surface tag draws nothing';
        $report = $em->getRepository(BugReport::class)->findOneBy(['publicTitle' => $title]);
        self::assertNotNull($report);

        // Somebody moves it on the desk; a plain re-run must leave that alone.
        $report->setStatus(BugStatus::New);
        $em->flush();
        self::assertSame(0, $tester->execute([]));
        $em->refresh($report);
        self::assertSame('new', $report->getStatus()->value, 'a plain run overwrote a desk edit');

        self::assertSame(0, $tester->execute(['--update' => true]));
        self::assertStringContainsString('updated', $tester->getDisplay());
        $em->refresh($report);
        self::assertSame('resolved', $report->getStatus()->value, 'the file did not reach an entry already filed');
    }

    /** An entry may say how to test it; the curator's bug page shows those steps (report.steps). */
    public function testAnEntryCarriesItsTestSteps(): void
    {
        self::bootKernel();
        $file = sys_get_temp_dir().'/known-issues-steps-'.uniqid('', true).'.yaml';
        $title = 'Steps entry '.uniqid('', true);
        file_put_contents($file, "issues:\n  - public_title: '".$title."'\n    severity: minor\n    area: map\n    status: new\n    body: |\n      What is wrong.\n    steps: |\n      1. Open the map.\n      2. Look.\n");
        $em = static::getContainer()->get(EntityManagerInterface::class);

        try {
            $tester = new CommandTester((new Application(self::$kernel))->find('app:bugs:seed-known'));
            self::assertSame(0, $tester->execute(['file' => $file]));
        } finally {
            @unlink($file);
        }

        $report = $em->getRepository(BugReport::class)->findOneBy(['publicTitle' => $title]);
        self::assertNotNull($report);
        self::assertSame("1. Open the map.\n2. Look.", $report->getSteps());
    }

    public function testAFileWithABadEntryFilesNothing(): void
    {
        self::bootKernel();
        $bad = sys_get_temp_dir().'/known-issues-bad-'.uniqid('', true).'.yaml';
        file_put_contents($bad, "issues:\n  - public_title: 'Half an entry'\n    severity: minor\n");
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $before = \count($em->getRepository(BugReport::class)->findAll());

        $tester = new CommandTester((new Application(self::$kernel))->find('app:bugs:seed-known'));
        try {
            $tester->execute(['file' => $bad]);
            self::fail('a half entry must not be filed');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('"body"', $e->getMessage());
        } finally {
            @unlink($bad);
        }
        self::assertCount($before, $em->getRepository(BugReport::class)->findAll());
    }
}
