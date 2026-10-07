<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Takes one traffic request into storage (docs/specs/traffic-measurements.md §4.3).
 *
 * A line whose block codes were already claimed is a duplicate, whoever sends
 * it: that is what keeps a re-sent ride, or the same ride from a second
 * account, from counting twice. Codes are claimed once per request, so two
 * lines of one ride that share a block both count. The curator view is not
 * refreshed here: it is recomputed on a fixed cadence (TrafficView), so no
 * single upload can be read off as the difference between two views.
 *
 * @api
 */
final class TrafficIntake
{
    public const int MAX_LINES = 2000;

    public function __construct(
        private readonly Connection $db,
        private readonly TrafficStore $store,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload the decoded request body
     *
     * @return array{added: int, duplicate: int, dropped: int}
     *
     * @throws TrafficPayloadRefused
     */
    public function receive(int $userId, array $payload): array
    {
        foreach (array_keys($payload) as $key) {
            if (\in_array($key, TrafficLine::TRACK_KEYS, true)) {
                throw new TrafficPayloadRefused('track_not_accepted');
            }
            if (!\in_array($key, ['v', 'lines'], true)) {
                throw new TrafficPayloadRefused('invalid');
            }
        }
        $lines = $payload['lines'] ?? null;
        if (1 !== ($payload['v'] ?? null) || !\is_array($lines) || !array_is_list($lines) || \count($lines) > self::MAX_LINES) {
            throw new TrafficPayloadRefused('invalid');
        }

        $now = $this->clock->now();
        $valid = [];
        $dropped = 0;
        foreach ($lines as $raw) {
            if (!\is_array($raw)) {
                throw new TrafficPayloadRefused('invalid');
            }
            $line = TrafficLine::accept($raw, $now);
            null === $line ? ++$dropped : $valid[] = $line;
        }

        return $this->db->transactional(function () use ($userId, $valid, $dropped): array {
            // Claim first: a code another request holds, even one still in
            // flight, is not claimed here, so a retry of a request that has
            // not finished yet counts nothing twice.
            $claimed = array_flip($this->store->claimCodes(array_values(array_unique(array_merge([], ...array_column($valid, 'blocks'))))));
            $fresh = [];
            $duplicate = 0;
            foreach ($valid as $line) {
                foreach ($line['blocks'] as $code) {
                    if (!isset($claimed[$code])) {
                        ++$duplicate;
                        continue 2;
                    }
                }
                $fresh[] = $line;
            }
            // One lock order for every request: by cell key, then by road.
            usort($fresh, fn (array $a, array $b): int => strcmp($this->store->cellKey($a), $this->store->cellKey($b)));
            foreach ($fresh as $line) {
                $this->store->addToCell($line);
            }
            usort($fresh, static fn (array $a, array $b): int => $a['way'] <=> $b['way']);
            foreach ($fresh as $line) {
                $this->store->addToRider($userId, $line);
            }

            return ['added' => \count($fresh), 'duplicate' => $duplicate, 'dropped' => $dropped];
        });
    }
}
