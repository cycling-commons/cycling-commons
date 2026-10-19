<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;

/**
 * The felt-safety waiting room (docs/specs/traffic-measurements.md §10), the
 * traffic waiting room's rules for marks (§4.3).
 *
 * Every mark waits on its own row, sealed under the `safety-enc` key, under a
 * random id, with no account and no time column, in the block of its road,
 * part of the day and day type. A block leaves once it holds MIN_MARKS marks
 * (one over MAX_RELEASE marks leaves in random parts), at most one block per
 * call, chosen at random among the ready ones. A mark carries no date and no
 * ride, so there is no day-group rule.
 *
 * A payload is sealed to its row id and its block key, so a row moved to
 * another block does not open. A block whose chosen rows include one that does
 * not open is passed over, neither released nor deleted, with a warning that
 * names no content.
 *
 * @api
 */
final class SafetyPool
{
    public const int MIN_MARKS = 5;
    /** Blocks one call looks at, drawn at random among those with MIN_MARKS rows or more. */
    public const int MAX_CANDIDATES = 32;
    /** Marks one release moves at most; a bigger block leaves in parts. */
    public const int MAX_RELEASE = 1000;

    private readonly TrafficCipher $cipher;

    public function __construct(
        private readonly Connection $db,
        private readonly TrafficKeys $keys,
        private readonly SafetyStore $store,
        private readonly LoggerInterface $logger,
    ) {
        $this->cipher = new TrafficCipher($keys, TrafficKeys::SAFETY_ENC);
    }

    /** @param array{way: int, label: string, region: int|null, band: int, dayType: string, quarter: string, felt: string} $mark a validated mark (SafetyMark) */
    public function add(array $mark): void
    {
        $id = random_bytes(16);
        $block = $this->keys->safetyBlockKey($mark);
        $this->db->executeStatement('INSERT INTO safety_pool (id, block_key, payload) VALUES (?, ?, ?)',
            [$id, $block, $this->cipher->seal($mark, self::row($id, $block))],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY]);
    }

    /**
     * Moves one ready block, chosen at random, into the counts and deletes its
     * rows. One call looks at no more than MAX_CANDIDATES blocks, drawn at
     * random, so the work of a mark stays bounded however full the room is.
     *
     * @return int the marks moved; 0 when no block is ready
     */
    public function releaseOne(): int
    {
        $candidates = array_map(self::bytes(...), $this->db->fetchFirstColumn(
            'SELECT block_key FROM safety_pool GROUP BY block_key HAVING COUNT(*) >= ? ORDER BY random() LIMIT ?',
            [self::MIN_MARKS, self::MAX_CANDIDATES], [ParameterType::INTEGER, ParameterType::INTEGER]));
        foreach ($candidates as $block) {
            // Choose without locks; lock only the rows that go, in id order,
            // so two writers never hold rows in opposite order. A row another
            // writer released meanwhile is gone from the locked read.
            $ids = array_map(self::bytes(...), $this->db->fetchFirstColumn(
                'SELECT id FROM safety_pool WHERE block_key = ? ORDER BY random() LIMIT ?',
                [$block, self::MAX_RELEASE], [ParameterType::BINARY, ParameterType::INTEGER]));
            $marks = $this->marks($block, $ids);
            if (null === $marks || \count($marks) < self::MIN_MARKS) {
                continue;
            }
            $this->store->addToTotals(array_column($marks, 'mark'));
            $this->db->executeStatement('DELETE FROM safety_pool WHERE id IN (?)',
                [array_column($marks, 'id')], [ArrayParameterType::BINARY]);

            return \count($marks);
        }

        return 0;
    }

    /**
     * Every waiting mark, decrypted, for the development dump.
     *
     * @return list<array<string, mixed>>
     */
    public function waiting(): array
    {
        $out = [];
        foreach ($this->db->iterateAssociative('SELECT id, block_key, payload FROM safety_pool') as $r) {
            try {
                $out[] = $this->cipher->open(self::bytes($r['payload']), self::row(self::bytes($r['id']), self::bytes($r['block_key'])));
            } catch (\RuntimeException|\JsonException) {
                // A row that does not open has nothing to show.
            }
        }

        return $out;
    }

    /**
     * The rows of $ids still in the block, locked and decrypted; null when one
     * of them does not open.
     *
     * @param list<string> $ids
     *
     * @return list<array{id: string, mark: array<string, mixed>}>|null
     */
    private function marks(string $block, array $ids): ?array
    {
        if ([] === $ids) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, payload FROM safety_pool WHERE id IN (?) AND block_key = ? ORDER BY id FOR UPDATE',
            [$ids, $block], [ArrayParameterType::BINARY, ParameterType::BINARY]) as $r) {
            $id = self::bytes($r['id']);
            try {
                $out[] = ['id' => $id, 'mark' => $this->cipher->open(self::bytes($r['payload']), self::row($id, $block))];
            } catch (\RuntimeException|\JsonException) {
                // The message names neither the row nor the block: an id would
                // let a log reader follow one mark through the waiting room.
                $this->logger->warning('A felt-safety waiting-room row does not open; its block is passed over.');

                return null;
            }
        }

        return $out;
    }

    /** The associated data a payload is sealed to: its table, row id and block key. */
    private static function row(string $id, string $block): string
    {
        return 'safety_pool|'.bin2hex($id).'|'.bin2hex($block);
    }

    private static function bytes(mixed $value): string
    {
        return \is_resource($value) ? (string) stream_get_contents($value) : (string) $value;
    }
}
