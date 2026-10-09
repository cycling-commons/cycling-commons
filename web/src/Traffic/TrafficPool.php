<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;

/**
 * The waiting room (docs/specs/traffic-measurements.md §4.3).
 *
 * Every line waits here on its own row, sealed, under a random id, with no
 * account and no time column, in the block of its road, direction, part of the
 * day and day type. A block leaves whole, once it holds MIN_LINES lines from
 * MIN_DAY_GROUPS distinct (quarter, day group) pairs (one over MAX_RELEASE
 * lines leaves in random parts that each meet that rule), and at most one
 * block leaves per call, chosen at random among the ready ones. So a ride's
 * lines reach the totals at different moments, mixed with other rides, and
 * the totals never show what one send changed.
 *
 * A payload is sealed to its row id and its block key, so a row moved to
 * another block does not open. A block whose chosen rows include one that does
 * not open is passed over, neither released nor deleted, with a warning that
 * names no content.
 *
 * @api
 */
final class TrafficPool
{
    public const int MIN_LINES = 5;
    public const int MIN_DAY_GROUPS = 3;
    /** Blocks one call looks at, drawn at random among those with MIN_LINES rows or more. */
    public const int MAX_CANDIDATES = 32;
    /** Lines one release moves at most; a bigger block leaves in parts. */
    public const int MAX_RELEASE = 1000;

    public function __construct(
        private readonly Connection $db,
        private readonly TrafficKeys $keys,
        private readonly TrafficCipher $cipher,
        private readonly TrafficStore $store,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $line a validated line (TrafficLine) */
    public function add(array $line): void
    {
        unset($line['blocks']);   // the fingerprints are spent; they stay in traffic_seen only
        $id = random_bytes(16);
        $block = $this->keys->blockKey($line);
        $this->db->executeStatement('INSERT INTO traffic_pool (id, block_key, payload) VALUES (?, ?, ?)',
            [$id, $block, $this->cipher->seal($line, self::row($id, $block))],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY]);
    }

    /**
     * Moves one ready block, chosen at random, into the totals: the whole block
     * when it holds up to MAX_RELEASE lines, else MAX_RELEASE of its lines
     * drawn at random that meet the rule on their own. One call looks at no
     * more than MAX_CANDIDATES blocks, drawn at random, so the work of a send
     * stays bounded however full the waiting room is.
     *
     * @return int the lines moved; 0 when no block is ready
     */
    public function releaseOne(): int
    {
        $candidates = array_map(self::bytes(...), $this->db->fetchFirstColumn(
            'SELECT block_key FROM traffic_pool GROUP BY block_key HAVING COUNT(*) >= ? ORDER BY random() LIMIT ?',
            [self::MIN_LINES, self::MAX_CANDIDATES], [ParameterType::INTEGER, ParameterType::INTEGER]));
        foreach ($candidates as $block) {
            // Read without locks to choose; lock only the rows that go, in id
            // order, so two writers never hold rows in opposite order.
            $lines = $this->lines($block, 'SELECT id, payload FROM traffic_pool WHERE block_key = ? ORDER BY random() LIMIT ?',
                [$block, self::MAX_RELEASE], [ParameterType::BINARY, ParameterType::INTEGER]);
            if (null === $lines || !self::ready($lines)) {
                continue;
            }
            // A row another writer released meanwhile is gone from this read.
            $lines = $this->lines($block, 'SELECT id, payload FROM traffic_pool WHERE id IN (?) ORDER BY id FOR UPDATE',
                [array_column($lines, 'id')], [ArrayParameterType::BINARY]);
            if (null === $lines || !self::ready($lines)) {
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
        foreach ($this->db->iterateAssociative('SELECT id, block_key, payload FROM traffic_pool') as $r) {
            try {
                $out[] = $this->cipher->open(self::bytes($r['payload']), self::row(self::bytes($r['id']), self::bytes($r['block_key'])));
            } catch (\RuntimeException|\JsonException) {
                // A row that does not open has nothing to show.
            }
        }

        return $out;
    }

    /**
     * Lines of one block, read by $sql and decrypted; null when one of them
     * does not open.
     *
     * @param list<mixed>                            $params
     * @param list<ParameterType|ArrayParameterType> $types
     *
     * @return list<array{id: string, line: array<string, mixed>}>|null
     */
    private function lines(string $block, string $sql, array $params, array $types): ?array
    {
        $out = [];
        foreach ($this->db->fetchAllAssociative($sql, $params, $types) as $r) {
            $id = self::bytes($r['id']);
            try {
                $out[] = ['id' => $id, 'line' => $this->cipher->open(self::bytes($r['payload']), self::row($id, $block))];
            } catch (\RuntimeException|\JsonException) {
                // The message names neither the row nor the block: an id would
                // let a log reader follow one line through the waiting room.
                $this->logger->warning('A traffic waiting-room row does not open; its block is passed over.');

                return null;
            }
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

    /** The associated data a payload is sealed to: its table, row id and block key. */
    private static function row(string $id, string $block): string
    {
        return 'traffic_pool|'.bin2hex($id).'|'.bin2hex($block);
    }

    private static function bytes(mixed $value): string
    {
        return \is_resource($value) ? (string) stream_get_contents($value) : (string) $value;
    }
}
