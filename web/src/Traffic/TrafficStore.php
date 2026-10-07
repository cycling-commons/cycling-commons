<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Traffic;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Encrypted traffic storage (docs/specs/traffic-measurements.md §4.3).
 *
 * Three tables, none readable without TRAFFIC_SECRET: totals per time key
 * (no rider), one row per rider per road piece (distance and days per time key,
 * for the disclosure rules), and the dedupe codes. A row holds only its HMAC
 * key and a sealed payload bound to that key; nothing links two rows of one
 * road. Every add rewrites the payload with a fresh nonce.
 *
 * Callers run the adds inside one transaction, in key order. A row is created
 * empty before it is locked, so two requests that both add to a new row queue
 * on the same lock instead of both starting from zero.
 *
 * @api
 */
final class TrafficStore
{
    public const int SPEED_BINS = 16;

    public function __construct(
        private readonly Connection $db,
        private readonly TrafficKeys $keys,
        private readonly TrafficCipher $cipher,
    ) {
    }

    /**
     * Claims the codes for this request and returns those it claimed. A code
     * another request already holds, committed or still in flight, is not
     * returned: the insert waits for that request and then finds the row.
     *
     * @param list<string> $codes 64-hex codes from the browser
     *
     * @return list<string> the codes this request inserted, as given
     */
    public function claimCodes(array $codes): array
    {
        $claimed = [];
        $unique = array_values(array_unique($codes));
        sort($unique);   // one lock order for every request
        foreach ($unique as $code) {
            $inserted = $this->db->executeStatement('INSERT INTO traffic_seen (code) VALUES (?) ON CONFLICT DO NOTHING',
                [$this->keys->seenCode($code)], [ParameterType::BINARY]);
            if (1 === $inserted) {
                $claimed[] = $code;
            }
        }

        return $claimed;
    }

    /** @param array<string, mixed> $line a validated line (TrafficLine) */
    public function addToCell(array $line): void
    {
        $key = $this->keys->bucketKey($line);
        $cell = $this->lockedPayload('traffic_cell', 'bucket_key', $key) ?? [
            'way' => $line['way'], 'region' => $line['region'] ?? null, 'dir' => $line['dir'], 'label' => $line['label'], 'slot' => $line['slot'],
            'dayType' => $line['dayType'], 'season' => $line['season'], 'quarter' => $line['quarter'],
            'distanceM' => 0, 'timeS' => 0, 'passes' => 0, 'nearby' => 0, 'speedSum' => 0.0, 'speedPasses' => 0,
            'bins' => array_fill(0, self::SPEED_BINS, 0), 'contributions' => 0,
        ];
        // A road's region is the same on every line; a line without one keeps it.
        $cell['region'] ??= $line['region'] ?? null;
        $cell['distanceM'] += $line['distanceM'];
        $cell['timeS'] += $line['timeS'];
        $cell['passes'] += $line['passes'];
        $cell['nearby'] = ($cell['nearby'] ?? 0) + ($line['nearby'] ?? 0);
        $cell['speedSum'] += (float) ($line['avgSpeedKmh'] ?? 0) * $line['timeS'];
        if (\is_array($line['carSpeedBins'] ?? null)) {
            foreach ($line['carSpeedBins'] as $i => $n) {
                $cell['bins'][$i] += $n;
            }
            $cell['speedPasses'] += array_sum($line['carSpeedBins']);
        }
        ++$cell['contributions'];
        $this->write('traffic_cell', 'bucket_key', $key, $cell);
    }

    /** @param array<string, mixed> $line */
    public function addToRider(int $userId, array $line): void
    {
        $key = $this->keys->riderKey($userId, $line['way']);
        $rider = $this->lockedPayload('traffic_rider', 'rider_key', $key) ?? ['way' => $line['way'], 'buckets' => []];
        $bucket = bin2hex($this->keys->bucketKey($line));
        $entry = $rider['buckets'][$bucket] ?? ['d' => 0, 'days' => []];
        $entry['d'] += $line['distanceM'];
        if (!\in_array($line['day'], $entry['days'], true)) {
            $entry['days'][] = $line['day'];
            sort($entry['days']);
        }
        $rider['buckets'][$bucket] = $entry;
        $this->write('traffic_rider', 'rider_key', $key, $rider);
    }

    /**
     * The key a cell is filed under; callers sort their adds by it.
     *
     * @param array<string, mixed> $line
     */
    public function cellKey(array $line): string
    {
        return $this->keys->bucketKey($line);
    }

    /** @return iterable<array<string, mixed>> every cell, decrypted */
    public function cells(): iterable
    {
        foreach ($this->db->iterateAssociative("SELECT bucket_key, payload FROM traffic_cell WHERE payload <> ''::bytea") as $row) {
            yield $this->cipher->open(self::bytes($row['payload']), self::row('traffic_cell', self::bytes($row['bucket_key'])));
        }
    }

    /**
     * Every rider row, decrypted, with its bucket keys as given by bucketHex().
     *
     * @return list<array{way: int, buckets: array<string, array{d: int, days: list<int>}>}>
     */
    public function riders(): array
    {
        $out = [];
        foreach ($this->db->iterateAssociative("SELECT rider_key, payload FROM traffic_rider WHERE payload <> ''::bytea") as $row) {
            /** @var array{way: int, buckets: array<string, array{d: int, days: list<int>}>} $rider */
            $rider = $this->cipher->open(self::bytes($row['payload']), self::row('traffic_rider', self::bytes($row['rider_key'])));
            $out[] = $rider;
        }

        return $out;
    }

    /**
     * The key a cell is filed under in rider rows.
     *
     * @param array<string, mixed> $cell
     */
    public function bucketHex(array $cell): string
    {
        return bin2hex($this->keys->bucketKey($cell));
    }

    /**
     * Delete one rider's rows. The rows are read one at a time: each row's
     * road comes out of its payload, and the row is the rider's when its key
     * is the rider's key for that road.
     */
    public function deleteRidersOf(int $userId): int
    {
        $mine = [];
        foreach ($this->db->iterateAssociative("SELECT rider_key, payload FROM traffic_rider WHERE payload <> ''::bytea") as $row) {
            $key = self::bytes($row['rider_key']);
            $way = (int) ($this->cipher->open(self::bytes($row['payload']), self::row('traffic_rider', $key))['way'] ?? 0);
            if (hash_equals($this->keys->riderKey($userId, $way), $key)) {
                $mine[] = $key;
            }
        }
        if ([] === $mine) {
            return 0;
        }

        return (int) $this->db->executeStatement('DELETE FROM traffic_rider WHERE rider_key IN (?)',
            [$mine], [ArrayParameterType::BINARY]);
    }

    /** @return array<string, mixed>|null */
    private function lockedPayload(string $table, string $column, string $key): ?array
    {
        $this->db->executeStatement("INSERT INTO {$table} ({$column}, payload) VALUES (?, ''::bytea) ON CONFLICT DO NOTHING",
            [$key], [ParameterType::BINARY]);
        $blob = self::bytes($this->db->fetchOne("SELECT payload FROM {$table} WHERE {$column} = ? FOR UPDATE", [$key], [ParameterType::BINARY]));

        return '' === $blob ? null : $this->cipher->open($blob, self::row($table, $key));
    }

    /** @param array<string, mixed> $payload */
    private function write(string $table, string $column, string $key, array $payload): void
    {
        $this->db->executeStatement("UPDATE {$table} SET payload = ? WHERE {$column} = ?",
            [$this->cipher->seal($payload, self::row($table, $key)), $key],
            [ParameterType::BINARY, ParameterType::BINARY]);
    }

    private static function row(string $table, string $key): string
    {
        return $table.'|'.bin2hex($key);
    }

    private static function bytes(mixed $value): string
    {
        return \is_resource($value) ? (string) stream_get_contents($value) : (string) $value;
    }
}
