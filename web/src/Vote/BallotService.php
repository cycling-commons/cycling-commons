<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Vote;

use App\Catalog\BikeType;
use App\Catalog\ItemType;
use App\Entity\User;
use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Casting, ordering and removing votes on the season ballot.
 *
 * A vote counts for the round after the one open in the voted row's region
 * now ({@see Round::votingAt()}). Each rider has three slots per list, and the
 * slot is the rider's rank: 1 is their first choice. A new vote takes the
 * lowest free slot, a remove closes the gap, and a move swaps two
 * neighbours. The unique index on the slot refuses a fourth vote that two
 * requests race to cast. The ballot is a draft until the rider submits it
 * with every vote filled; only a submitted ballot counts, and it is final. Writes go through DBAL, so a collision costs a
 * savepoint and not the entity manager.
 *
 * @see docs/specs/route-domain.md §8c, §8d
 *
 * @api
 */
final class BallotService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly SettingsProviderInterface $settings,
        private readonly VoterEligibility $eligibility,
        private readonly BallotCandidates $candidates,
        private readonly RateLimiterFactoryInterface $seasonVoteLimiter,
    ) {
    }

    public function isOpen(): bool
    {
        return 1 === $this->settings->get(SettingsRegistry::COMMUNITY_VOTING_LIVE);
    }

    /** @throws BallotRefused */
    public function cast(User $user, ItemType $type, int $subjectId, ?BikeType $bike): Round
    {
        if (!$this->isOpen()) {
            throw new BallotRefused(BallotRefused::VOTING_CLOSED);
        }
        if ([] !== $this->eligibility->missing($user)) {
            throw new BallotRefused(BallotRefused::NOT_ELIGIBLE);
        }
        if (!$type->isVotable()) {
            throw new BallotRefused(BallotRefused::NOT_VOTABLE);
        }
        $subject = $this->candidates->subject($type, $subjectId);
        if (null === $subject) {
            throw new BallotRefused(BallotRefused::NOT_CANDIDATE);
        }
        $isRoute = ItemType::QualityRides === $type;
        if ($isRoute && null === $bike) {
            throw new BallotRefused(BallotRefused::BIKE_REQUIRED);
        }
        if ($isRoute && $bike->isSpecialty() && !\in_array($bike, $this->candidates->bikesFor([$subject['id']])[$subject['id']] ?? [], true)) {
            throw new BallotRefused(BallotRefused::BIKE_NOT_DECLARED);
        }
        $this->consume($user);

        $now = $this->clock->now();
        $round = Round::votingAt($now, $subject['hemisphere']);
        $row = [
            'user_id' => (int) $user->getId(),
            'region_id' => $subject['regionId'],
            'category' => $type->value,
            'subject_id' => $subject['id'],
            'bike_type' => $isRoute ? $bike->value : null,
            'season' => $round->season->value,
            'round_start' => $round->startDate(),
            'created_at' => $now->format('Y-m-d H:i:s'),
        ];

        for ($attempt = 0; $attempt < BallotRules::VOTES_PER_LIST; ++$attempt) {
            try {
                $this->db->transactional(static function (Connection $db) use ($row): bool {
                    /** @var list<array{subject_id: int|string, slot: int|string, submitted_at: ?string}> $mine */
                    $mine = $db->fetchAllAssociative(
                        'SELECT subject_id, slot, submitted_at FROM season_vote
                          WHERE user_id = :u AND region_id = :rid AND category = :cat AND round_start = :start',
                        ['u' => $row['user_id'], 'rid' => $row['region_id'], 'cat' => $row['category'], 'start' => $row['round_start']],
                    );
                    $taken = [];
                    foreach ($mine as $m) {
                        if (null !== $m['submitted_at']) {
                            throw new BallotRefused(BallotRefused::BALLOT_SUBMITTED);
                        }
                        if ((int) $m['subject_id'] === $row['subject_id']) {
                            throw new BallotRefused(BallotRefused::ALREADY_VOTED);
                        }
                        $taken[] = (int) $m['slot'];
                    }
                    $free = array_values(array_diff(range(1, BallotRules::VOTES_PER_LIST), $taken));
                    if ([] === $free) {
                        throw new BallotRefused(BallotRefused::BALLOT_FULL);
                    }
                    $db->insert('season_vote', $row + ['slot' => $free[0]]);

                    return true;
                });

                return $round;
            } catch (UniqueConstraintViolationException) {
                // Another request of this rider took that slot or this vote a
                // moment earlier: read the ballot again and decide again.
                continue;
            }
        }

        throw new BallotRefused(BallotRefused::BALLOT_FULL);
    }

    /**
     * Removes the rider's vote for this row from the ballot that is still
     * open, and moves the votes ranked below it up one place. A closed
     * ballot's votes are its result and stay.
     *
     * @throws BallotRefused
     */
    public function remove(User $user, ItemType $type, int $subjectId): bool
    {
        if (!$this->isOpen()) {
            throw new BallotRefused(BallotRefused::VOTING_CLOSED);
        }
        $this->consume($user);

        $vote = $this->openVote($user, $type, $subjectId);
        if (null === $vote) {
            return false;
        }
        if ($vote['submitted']) {
            throw new BallotRefused(BallotRefused::BALLOT_SUBMITTED);
        }

        return $this->db->transactional(static function (Connection $db) use ($vote): bool {
            if (1 !== (int) $db->executeStatement('DELETE FROM season_vote WHERE id = :id', ['id' => $vote['id']])) {
                return false;
            }
            // Lowest first, so each slot moves into one that is already free.
            /** @var list<int|string> $below */
            $below = $db->fetchFirstColumn(
                'SELECT id FROM season_vote
                  WHERE user_id = :u AND region_id = :rid AND category = :cat AND round_start = :start AND slot > :slot
                  ORDER BY slot',
                ['u' => $vote['user_id'], 'rid' => $vote['region_id'], 'cat' => $vote['category'], 'start' => $vote['round_start'], 'slot' => $vote['slot']],
            );
            $slot = $vote['slot'];
            foreach ($below as $id) {
                $db->executeStatement('UPDATE season_vote SET slot = :slot WHERE id = :id', ['slot' => $slot, 'id' => (int) $id]);
                ++$slot;
            }

            return true;
        });
    }

    /**
     * Moves the rider's vote for this row one place up (towards first choice)
     * or down on the ballot that is still open, swapping it with its
     * neighbour. Nothing happens at either end.
     *
     * @throws BallotRefused
     */
    public function move(User $user, ItemType $type, int $subjectId, bool $up): bool
    {
        if (!$this->isOpen()) {
            throw new BallotRefused(BallotRefused::VOTING_CLOSED);
        }
        $this->consume($user);

        $vote = $this->openVote($user, $type, $subjectId);
        if (null === $vote) {
            return false;
        }
        if ($vote['submitted']) {
            throw new BallotRefused(BallotRefused::BALLOT_SUBMITTED);
        }

        return $this->db->transactional(static function (Connection $db) use ($vote, $up): bool {
            $other = $db->fetchAssociative(
                'SELECT * FROM season_vote
                  WHERE user_id = :u AND region_id = :rid AND category = :cat AND round_start = :start AND slot = :slot
                  FOR UPDATE',
                ['u' => $vote['user_id'], 'rid' => $vote['region_id'], 'cat' => $vote['category'], 'start' => $vote['round_start'],
                    'slot' => $vote['slot'] + ($up ? -1 : 1)],
            );
            if (false === $other) {
                return false;
            }
            $mine = $db->fetchAssociative('SELECT * FROM season_vote WHERE id = :id FOR UPDATE', ['id' => $vote['id']]);
            if (false === $mine) {
                return false;
            }
            // The slot index is checked row by row, so the two rows are
            // written again with their slots swapped rather than updated.
            $db->executeStatement('DELETE FROM season_vote WHERE id IN (:a, :b)', ['a' => $mine['id'], 'b' => $other['id']]);
            $db->insert('season_vote', ['slot' => $other['slot']] + $mine);
            $db->insert('season_vote', ['slot' => $mine['slot']] + $other);

            return true;
        });
    }

    /**
     * The rider's vote for this row on a ballot that is still open.
     *
     * @return array{id: int, user_id: int, region_id: int, category: string, round_start: string, slot: int, submitted: bool}|null
     */
    private function openVote(User $user, ItemType $type, int $subjectId): ?array
    {
        $now = $this->clock->now();
        /** @var list<array{id: int|string, user_id: int|string, region_id: int|string, category: string, season: string, round_start: string, slot: int|string, submitted_at: ?string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, user_id, region_id, category, season, round_start, slot, submitted_at FROM season_vote
              WHERE user_id = :u AND category = :cat AND subject_id = :sid',
            ['u' => (int) $user->getId(), 'cat' => $type->value, 'sid' => $subjectId],
        );
        foreach ($rows as $r) {
            if (Round::fromStored($r['season'], $r['round_start'])->isVotingOpenAt($now)) {
                return ['id' => (int) $r['id'], 'user_id' => (int) $r['user_id'], 'region_id' => (int) $r['region_id'],
                    'category' => $r['category'], 'round_start' => $r['round_start'], 'slot' => (int) $r['slot'],
                    'submitted' => null !== $r['submitted_at']];
            }
        }

        return null;
    }

    /**
     * Makes the rider's ballot for this list final: every vote filled, all
     * stamped at once, and from then on it counts and cannot change.
     *
     * @throws BallotRefused
     */
    public function submit(User $user, ItemType $type, int $regionId): Round
    {
        if (!$this->isOpen()) {
            throw new BallotRefused(BallotRefused::VOTING_CLOSED);
        }
        $this->consume($user);

        $now = $this->clock->now();
        $userId = (int) $user->getId();

        return $this->db->transactional(static function (Connection $db) use ($userId, $type, $regionId, $now): Round {
            /** @var list<array{season: string, round_start: string, submitted_at: ?string}> $rows */
            $rows = $db->fetchAllAssociative(
                'SELECT season, round_start, submitted_at FROM season_vote
                  WHERE user_id = :u AND region_id = :rid AND category = :cat
                  FOR UPDATE',
                ['u' => $userId, 'rid' => $regionId, 'cat' => $type->value],
            );
            $round = null;
            $count = 0;
            foreach ($rows as $r) {
                $candidate = Round::fromStored($r['season'], $r['round_start']);
                if (!$candidate->isVotingOpenAt($now)) {
                    continue;
                }
                if (null !== $r['submitted_at']) {
                    throw new BallotRefused(BallotRefused::BALLOT_SUBMITTED);
                }
                $round = $candidate;
                ++$count;
            }
            if (null === $round || $count < BallotRules::VOTES_PER_LIST) {
                throw new BallotRefused(BallotRefused::BALLOT_INCOMPLETE);
            }
            $db->executeStatement(
                'UPDATE season_vote SET submitted_at = :at
                  WHERE user_id = :u AND region_id = :rid AND category = :cat AND round_start = :start AND submitted_at IS NULL',
                ['at' => $now->format('Y-m-d H:i:s'), 'u' => $userId, 'rid' => $regionId, 'cat' => $type->value, 'start' => $round->startDate()],
            );

            return $round;
        });
    }

    /** When the rider submitted this list's ballot in this round; null while it is a draft. */
    public function submittedAt(User $user, ItemType $type, int $regionId, Round $round): ?\DateTimeImmutable
    {
        $at = $this->db->fetchOne(
            'SELECT MAX(submitted_at) FROM season_vote
              WHERE user_id = :u AND region_id = :rid AND category = :cat AND round_start = :start',
            ['u' => (int) $user->getId(), 'rid' => $regionId, 'cat' => $type->value, 'start' => $round->startDate()],
        );

        return \is_string($at) ? new \DateTimeImmutable($at, new \DateTimeZone('UTC')) : null;
    }

    /**
     * The categories whose ballot the rider has submitted in this region and
     * round: a ✓ on their tabs (owner 2026-10-03).
     *
     * @return list<string> `ItemType` values
     */
    public function submittedCategories(User $user, int $regionId, Round $round): array
    {
        return array_map(strval(...), $this->db->fetchFirstColumn(
            'SELECT DISTINCT category FROM season_vote
              WHERE user_id = :u AND region_id = :rid AND round_start = :start AND submitted_at IS NOT NULL',
            ['u' => (int) $user->getId(), 'rid' => $regionId, 'start' => $round->startDate()],
        ));
    }

    /** @return array<int, ?string> subject id => bike type, in slot order */
    public function mine(User $user, ItemType $type, int $regionId, Round $round): array
    {
        /** @var list<array{subject_id: int|string, bike_type: ?string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT subject_id, bike_type FROM season_vote
              WHERE user_id = :u AND region_id = :rid AND category = :cat AND round_start = :start
              ORDER BY slot',
            ['u' => (int) $user->getId(), 'rid' => $regionId, 'cat' => $type->value, 'start' => $round->startDate()],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['subject_id']] = $r['bike_type'];
        }

        return $out;
    }

    /** @throws BallotRefused */
    private function consume(User $user): void
    {
        if (!$this->seasonVoteLimiter->create('user-'.(string) $user->getId())->consume()->isAccepted()) {
            throw new BallotRefused(BallotRefused::RATE_LIMITED);
        }
    }
}
