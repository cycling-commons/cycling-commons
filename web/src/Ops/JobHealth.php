<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Ops;

use Psr\Clock\ClockInterface;

/**
 * Each daily job with its last good run, and whether it is late: never run,
 * or last run longer ago than {@see DailyJobs::MAX_AGE_HOURS}.
 *
 * @api
 */
final readonly class JobHealth
{
    public function __construct(
        private JobRunStore $store,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<array{command: string, lastRun: \DateTimeImmutable|null, late: bool}> */
    public function report(): array
    {
        $runs = $this->store->lastRuns();
        $cutoff = $this->clock->now()->modify(sprintf('-%d hours', DailyJobs::MAX_AGE_HOURS));
        $report = [];
        foreach (DailyJobs::COMMANDS as $command) {
            $last = $runs[$command] ?? null;
            $report[] = ['command' => $command, 'lastRun' => $last, 'late' => null === $last || $last < $cutoff];
        }

        return $report;
    }
}
