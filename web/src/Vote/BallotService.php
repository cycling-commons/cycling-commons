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
 * Casting and removing votes on the season ballot.
 *
 * A vote counts in the round open in the voted row's region now. Each rider
 * has three slots per list; the lowest free one is taken, and the unique
 * index on the slot refuses a fourth vote that two requests race to cast.
 * Writes go through DBAL, so a collision costs a savepoint and not the
 * entity manager.
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
        $round = Round::containing($now, $subject['hemisphere']);
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
                    /** @var list<array{subject_id: int|string, slot: int|string}> $mine */
                    $mine = $db->fetchAllAssociative(
                        'SELECT subject_id, slot FROM season_vote
                          WHERE user_id = :u AND region_id = :rid AND category = :cat AND round_start = :start',
                        ['u' => $row['user_id'], 'rid' => $row['region_id'], 'cat' => $row['category'], 'start' => $row['round_start']],
                    );
                    $taken = [];
                    foreach ($mine as $m) {
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
     * Removes the rider's vote for this row in its open round. A closed
     * round's votes are its result and stay.
     *
     * @throws BallotRefused
     */
    public function remove(User $user, ItemType $type, int $subjectId): bool
    {
        if (!$this->isOpen()) {
            throw new BallotRefused(BallotRefused::VOTING_CLOSED);
        }
        $this->consume($user);

        $now = $this->clock->now();
        /** @var list<array{id: int|string, season: string, round_start: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, season, round_start FROM season_vote WHERE user_id = :u AND category = :cat AND subject_id = :sid',
            ['u' => (int) $user->getId(), 'cat' => $type->value, 'sid' => $subjectId],
        );
        foreach ($rows as $r) {
            if (Round::fromStored($r['season'], $r['round_start'])->isOpenAt($now)) {
                return 1 === (int) $this->db->executeStatement('DELETE FROM season_vote WHERE id = :id', ['id' => (int) $r['id']]);
            }
        }

        return false;
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
