<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Ops;

use App\Ops\DailyJobs;
use App\Ops\JobHealth;
use App\Ops\JobRunStore;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The daily jobs run from timers outside this repository, so the site keeps
 * its own record of each job's last good run and the admin dashboard warns
 * when one is late (docs/specs/operations.md, daily jobs). A dry run and a
 * failed run do not count: both leave the job's work undone.
 */
final class DailyJobsTest extends KernelTestCase
{
    /** @param array<string, mixed> $args */
    private function finish(string $name, array $args, int $exitCode): void
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $command = $application->find($name);
        $input = new ArrayInput($args, $command->getDefinition());
        $dispatcher = static::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->dispatch(new ConsoleTerminateEvent($command, $input, new NullOutput(), $exitCode), ConsoleEvents::TERMINATE);
    }

    private function store(): JobRunStore
    {
        $store = static::getContainer()->get(JobRunStore::class);
        \assert($store instanceof JobRunStore);

        return $store;
    }

    public function testASuccessfulRunOfADailyJobIsRecorded(): void
    {
        self::bootKernel();
        $this->finish('app:moderation:gc', [], 0);

        self::assertArrayHasKey('app:moderation:gc', $this->store()->lastRuns());
    }

    public function testARunWithoutForceIsADryRunAndDoesNotCount(): void
    {
        self::bootKernel();
        $this->finish('app:accounts:purge-unverified', [], 0);
        self::assertArrayNotHasKey('app:accounts:purge-unverified', $this->store()->lastRuns());

        $this->finish('app:accounts:purge-unverified', ['--force' => true], 0);
        self::assertArrayHasKey('app:accounts:purge-unverified', $this->store()->lastRuns());
    }

    public function testARunWithoutWriteIsADryRunAndDoesNotCount(): void
    {
        self::bootKernel();
        $this->finish('app:catalog:link-osm', [], 0);
        self::assertArrayNotHasKey('app:catalog:link-osm', $this->store()->lastRuns());

        $this->finish('app:catalog:link-osm', ['--write' => true], 0);
        self::assertArrayHasKey('app:catalog:link-osm', $this->store()->lastRuns());
    }

    public function testAFailedRunDoesNotCount(): void
    {
        self::bootKernel();
        $this->finish('app:vote:freeze', [], 1);

        self::assertArrayNotHasKey('app:vote:freeze', $this->store()->lastRuns());
    }

    public function testACommandThatIsNotADailyJobIsNotRecorded(): void
    {
        self::bootKernel();
        $this->finish('app:climbs:recompute', [], 0);

        self::assertArrayNotHasKey('app:climbs:recompute', $this->store()->lastRuns());
    }

    public function testAJobIsLateAfter26HoursAndWhenItNeverRan(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable('2026-10-07 10:00:00 UTC');
        $this->store()->record('app:media:gc', $now->modify('-25 hours'));
        $this->store()->record('app:moderation:gc', $now->modify('-27 hours'));

        $report = [];
        foreach ((new JobHealth($this->store(), new MockClock($now)))->report() as $row) {
            $report[$row['command']] = $row['late'];
        }

        self::assertSame(DailyJobs::COMMANDS, array_keys($report));
        self::assertFalse($report['app:media:gc']);
        self::assertTrue($report['app:moderation:gc']);
        self::assertTrue($report['app:links:recheck'], 'never ran');
    }
}
