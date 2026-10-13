<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Roadmap;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Votes and follows on roadmap items and open proposals, the two marks a
 * rider can put on one.
 *
 * Both are a toggle: one row per rider per item, or none. Written with DBAL,
 * so a double click that races itself meets the unique index and changes
 * nothing, instead of closing the entity manager.
 *
 * The page shows how many riders voted for an item, never who; a follow is
 * never shown to anybody but the rider.
 *
 * @see docs/specs/roadmap-and-changelog.md §8
 *
 * @api
 */
final class RoadmapMarks
{
    public const string VOTE = 'roadmap_vote';
    public const string FOLLOW = 'roadmap_follow';

    public function __construct(private readonly Connection $db)
    {
    }

    /** Flips the rider's vote on the item and answers whether it is now on. */
    public function toggleVote(int $userId, string $ref): bool
    {
        return $this->toggle(self::VOTE, $userId, $ref);
    }

    /** Flips the rider's follow on the item and answers whether it is now on. */
    public function toggleFollow(int $userId, string $ref): bool
    {
        return $this->toggle(self::FOLLOW, $userId, $ref);
    }

    /** Sets a follow without flipping it: a proposer follows their own idea. */
    public function follow(int $userId, string $ref): void
    {
        $this->db->executeStatement(
            'INSERT INTO roadmap_follow (user_id, item_ref, created_at) VALUES (:u, :r, NOW()) ON CONFLICT (user_id, item_ref) DO NOTHING',
            ['u' => $userId, 'r' => $ref],
        );
    }

    /**
     * Votes per item.
     *
     * @param list<string> $refs
     *
     * @return array<string, int>
     */
    public function voteCounts(array $refs): array
    {
        if ([] === $refs) {
            return [];
        }
        /** @var list<array{item_ref: string, n: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT item_ref, COUNT(*) AS n FROM roadmap_vote WHERE item_ref IN (:refs) GROUP BY item_ref',
            ['refs' => $refs],
            ['refs' => ArrayParameterType::STRING],
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['item_ref']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * The items one rider voted for or follows.
     *
     * @return array{votes: array<string, true>, follows: array<string, true>}
     */
    public function mine(int $userId): array
    {
        return [
            'votes' => array_fill_keys($this->refsOf(self::VOTE, $userId), true),
            'follows' => array_fill_keys($this->refsOf(self::FOLLOW, $userId), true),
        ];
    }

    /** @return list<string> */
    public function refsOf(string $table, int $userId): array
    {
        \assert(self::VOTE === $table || self::FOLLOW === $table);

        return array_map(strval(...), $this->db->fetchFirstColumn(
            'SELECT item_ref FROM '.$table.' WHERE user_id = :u ORDER BY created_at',
            ['u' => $userId],
        ));
    }

    /** @return list<int> the riders following the item */
    public function followers(string $ref): array
    {
        return array_map(intval(...), $this->db->fetchFirstColumn(
            'SELECT user_id FROM roadmap_follow WHERE item_ref = :r ORDER BY user_id',
            ['r' => $ref],
        ));
    }

    /**
     * Drops every vote and follow on an item that has left the roadmap. They
     * were about an item on the list; once it is off the list they have no
     * use left.
     */
    public function forget(string $ref): void
    {
        $this->db->executeStatement('DELETE FROM roadmap_vote WHERE item_ref = :r', ['r' => $ref]);
        $this->db->executeStatement('DELETE FROM roadmap_follow WHERE item_ref = :r', ['r' => $ref]);
    }

    /**
     * Hands every vote and follow on one ref to another: a promoted proposal's
     * marks become its item's. A rider who already marked both keeps one.
     */
    public function moveRef(string $from, string $to): void
    {
        foreach ([self::VOTE, self::FOLLOW] as $table) {
            $this->db->executeStatement(
                'INSERT INTO '.$table.' (user_id, item_ref, created_at)
                 SELECT user_id, :to, created_at FROM '.$table.' WHERE item_ref = :from
                 ON CONFLICT (user_id, item_ref) DO NOTHING',
                ['from' => $from, 'to' => $to],
            );
            $this->db->executeStatement('DELETE FROM '.$table.' WHERE item_ref = :from', ['from' => $from]);
        }
    }

    /** Account deletion: every mark the rider put on any item. */
    public function forgetRider(int $userId): void
    {
        $this->db->executeStatement('DELETE FROM roadmap_vote WHERE user_id = :u', ['u' => $userId]);
        $this->db->executeStatement('DELETE FROM roadmap_follow WHERE user_id = :u', ['u' => $userId]);
    }

    private function toggle(string $table, int $userId, string $ref): bool
    {
        $removed = $this->db->executeStatement(
            'DELETE FROM '.$table.' WHERE user_id = :u AND item_ref = :r',
            ['u' => $userId, 'r' => $ref],
        );
        if ($removed > 0) {
            return false;
        }
        $this->db->executeStatement(
            'INSERT INTO '.$table.' (user_id, item_ref, created_at) VALUES (:u, :r, NOW()) ON CONFLICT (user_id, item_ref) DO NOTHING',
            ['u' => $userId, 'r' => $ref],
        );

        return true;
    }
}
