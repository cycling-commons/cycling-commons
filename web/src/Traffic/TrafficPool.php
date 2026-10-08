<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The waiting room (docs/specs/traffic-measurements.md §4.3).
 *
 * Every line waits here on its own row, sealed, under a random id, with no
 * account and no time column, in the block of its road, direction, part of the
 * day and day type. A block leaves only whole, once it holds MIN_LINES lines
 * from MIN_DAY_GROUPS distinct (quarter, day group) pairs, and at most one
 * block leaves per call, chosen at random among the ready ones. So a ride's
 * lines reach the totals at different moments, mixed with other rides, and
 * the totals never show what one send changed.
 *
 * @api
 */
final class TrafficPool
{
    public const int MIN_LINES = 5;
    public const int MIN_DAY_GROUPS = 3;

    public function __construct(
        private readonly Connection $db,
        private readonly TrafficKeys $keys,
        private readonly TrafficCipher $cipher,
        private readonly TrafficStore $store,
    ) {
    }

    /** @param array<string, mixed> $line a validated line (TrafficLine) */
    public function add(array $line): void
    {
        unset($line['blocks']);   // the fingerprints are spent; they stay in traffic_seen only
        $id = random_bytes(16);
        $this->db->executeStatement('INSERT INTO traffic_pool (id, block_key, payload) VALUES (?, ?, ?)',
            [$id, $this->keys->blockKey($line), $this->cipher->seal($line, self::row($id))],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY]);
    }

    /**
     * Moves one ready block, chosen at random, into the totals.
     *
     * @return int the lines moved; 0 when no block is ready
     */
    public function releaseOne(): int
    {
        $candidates = array_map(self::bytes(...), $this->db->fetchFirstColumn(
            'SELECT block_key FROM traffic_pool GROUP BY block_key HAVING COUNT(*) >= ?', [self::MIN_LINES]));
        shuffle($candidates);
        foreach ($candidates as $block) {
            // Read without locks to choose; lock only the block that goes, so
            // two writers never hold two blocks in opposite order.
            if (!self::ready($this->lines($block, false))) {
                continue;
            }
            $lines = $this->lines($block, true);
            if (!self::ready($lines)) {
                continue;
            }
            shuffle($lines);
            usort($lines, static fn (array $a, array $b): int => strcmp($a['line']['quarter'], $b['line']['quarter']));
            foreach ($lines as $l) {
                $this->store->addToTotal($l['line']);
            }
            $this->db->executeStatement('DELETE FROM traffic_pool WHERE id IN (?)',
                [array_column($lines, 'id')], [ArrayParameterType::BINARY]);

            return \count($lines);
        }

        return 0;
    }

    /**
     * Every waiting line, decrypted, for the development dump.
     *
     * @return list<array<string, mixed>>
     */
    public function waiting(): array
    {
        $out = [];
        foreach ($this->db->iterateAssociative('SELECT id, payload FROM traffic_pool') as $r) {
            $id = self::bytes($r['id']);
            $out[] = $this->cipher->open(self::bytes($r['payload']), self::row($id));
        }

        return $out;
    }

    /** @return list<array{id: string, line: array<string, mixed>}> */
    private function lines(string $block, bool $lock): array
    {
        $sql = 'SELECT id, payload FROM traffic_pool WHERE block_key = ?'.($lock ? ' FOR UPDATE' : '');
        $out = [];
        foreach ($this->db->fetchAllAssociative($sql, [$block], [ParameterType::BINARY]) as $r) {
            $id = self::bytes($r['id']);
            $out[] = ['id' => $id, 'line' => $this->cipher->open(self::bytes($r['payload']), self::row($id))];
        }

        return $out;
    }

    /** @param list<array{id: string, line: array<string, mixed>}> $lines */
    private static function ready(array $lines): bool
    {
        if (\count($lines) < self::MIN_LINES) {
            return false;
        }
        $days = [];
        foreach ($lines as $l) {
            $days[$l['line']['quarter'].'|'.$l['line']['dayGroup']] = true;
        }

        return \count($days) >= self::MIN_DAY_GROUPS;
    }

    private static function row(string $id): string
    {
        return 'traffic_pool|'.bin2hex($id);
    }

    private static function bytes(mixed $value): string
    {
        return \is_resource($value) ? (string) stream_get_contents($value) : (string) $value;
    }
}
