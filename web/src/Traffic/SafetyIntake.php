<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Takes one answer's felt-safety marks into storage
 * (docs/specs/traffic-measurements.md §10), in one transaction: each road's
 * mark waits in its own block (SafetyPool), in random order, then at most one
 * ready block, any block, moves to the counts. Which counts change says
 * nothing about who marked, and roads picked together reach the counts at
 * different moments. Nothing dedupes marks: a rider who marks a road twice
 * counts twice, bounded by the per-account limit.
 *
 * @api
 */
final class SafetyIntake
{
    public function __construct(
        private readonly Connection $db,
        private readonly SafetyPool $pool,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The marks one answer makes, checked (SafetyMark), before anything is stored: the controller charges the
     * limit one token per mark first.
     *
     * @param array<array-key, mixed> $payload the decoded request body
     *
     * @return non-empty-list<array{way: int, label: string, region: int|null, band: int, dayType: string, quarter: string, felt: string}>
     *
     * @throws TrafficPayloadRefused
     */
    public function accept(array $payload): array
    {
        return SafetyMark::accept($payload, $this->clock->now());
    }

    /**
     * @param list<array{way: int, label: string, region: int|null, band: int, dayType: string, quarter: string, felt: string}> $marks from accept()
     */
    public function store(array $marks): void
    {
        shuffle($marks);
        $this->db->transactional(function () use ($marks): void {
            foreach ($marks as $mark) {
                $this->pool->add($mark);
            }
            $this->pool->releaseOne();
        });
    }
}
