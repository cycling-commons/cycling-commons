<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Ops;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Records a daily job's run when it ends with success. A command with a
 * `--force` or `--write` switch is a dry run without it, and a dry run leaves
 * the work undone, so it does not count.
 */
#[AsEventListener(event: ConsoleEvents::TERMINATE)]
final readonly class JobRunRecorder
{
    /** The switches that turn a dry run into a real one. */
    private const array ACT_SWITCHES = ['force', 'write'];

    public function __construct(
        private JobRunStore $store,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ConsoleTerminateEvent $event): void
    {
        $command = $event->getCommand();
        if (null === $command || 0 !== $event->getExitCode() || !\in_array($command->getName(), DailyJobs::COMMANDS, true)) {
            return;
        }
        $input = $event->getInput();
        foreach (self::ACT_SWITCHES as $switch) {
            if ($command->getDefinition()->hasOption($switch) && true !== $input->getOption($switch)) {
                return;
            }
        }

        $this->store->record((string) $command->getName(), $this->clock->now());
    }
}
