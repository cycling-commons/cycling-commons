<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Ops;

use Doctrine\DBAL\Connection;

/**
 * The last good run of each daily job, one row per command (`job_run`).
 */
final readonly class JobRunStore
{
    public function __construct(private Connection $db)
    {
    }

    public function record(string $command, \DateTimeImmutable $at): void
    {
        $this->db->executeStatement(
            'INSERT INTO job_run (command, last_success_at) VALUES (:c, :at)
             ON CONFLICT (command) DO UPDATE SET last_success_at = EXCLUDED.last_success_at',
            ['c' => $command, 'at' => $at->format(\DateTimeInterface::ATOM)],
        );
    }

    /** @return array<string, \DateTimeImmutable> command => last good run */
    public function lastRuns(): array
    {
        $runs = [];
        foreach ($this->db->fetchAllKeyValue('SELECT command, last_success_at FROM job_run') as $command => $at) {
            $runs[(string) $command] = new \DateTimeImmutable((string) $at);
        }

        return $runs;
    }
}
