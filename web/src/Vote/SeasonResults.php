<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Vote;

use App\Catalog\BikeSuitability;
use App\Catalog\BikeType;
use App\Catalog\ItemType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * A list's result: nothing while its ballot is open, stored once the ballot
 * has closed.
 *
 * A round's ballot closes when the round starts ({@see Round::votingClosesAt()}).
 * Until then no page counts it, so no standing can be read off the site while
 * riders still vote. The round is stored by the daily `app:vote:freeze` once
 * {@see BallotRules::FREEZE_GRACE} has passed after its ballot closed
 * ({@see self::freezeEveryClosed()}), and before an account that voted in it
 * is deleted ({@see SeasonVoteDeletionHook}). A list read before the timer
 * ran is stored by that read. Stored rows are never rewritten, so a closed
 * season keeps its result when an account goes, a place is retired or a
 * route changes the bikes it declares.
 *
 * Rounds are stored oldest first, because each needs the stored rounds before
 * it: last year's top 3 for the handicap, and every earlier first place for
 * the order inside a shared place.
 *
 * Counting ignores a row's current state: a place retired mid-round keeps its
 * votes and its place, and the page shows it without a map link.
 *
 * @phpstan-type Entry array{subjectId: int, name: string, votes: int, handicapped: bool, winsBefore: int, score: int, place: ?int, position: int}
 * @phpstan-type ListResult array{voters: int, ranked: bool, closed: bool, entries: list<Entry>}
 *
 * @see docs/specs/route-domain.md §8c, §8d
 *
 * @api
 */
final class SeasonResults
{
    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly BallotCandidates $candidates,
    ) {
    }

    /**
     * The round's list once its ballot has closed; an empty, unranked list
     * while riders can still vote for it.
     *
     * @return ListResult
     */
    public function list(ListKey $key, Round $round): array
    {
        $now = $this->clock->now();
        if ($now < $round->votingClosesAt()) {
            return ['voters' => 0, 'ranked' => false, 'closed' => false, 'entries' => []];
        }
        $this->freezeClosed($key);
        if ($round->votingClosesAt()->modify(BallotRules::FREEZE_GRACE) <= $now) {
            return $this->stored($key, $round);
        }

        return $this->compute($key, $round);
    }

    /**
     * Stores every list whose ballot has closed, with votes, that is not stored yet: per region
     * and category the list of every bike, and for routes the list of each
     * bike voted on.
     *
     * @return int the lists that stored a round
     */
    public function freezeEveryClosed(): int
    {
        $this->dropClosedDrafts();

        /** @var list<array{region_id: int|string, category: string, bike_type: ?string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT DISTINCT sv.region_id, sv.category, sv.bike_type
               FROM season_vote sv
              WHERE sv.round_start <= :open AND sv.submitted_at IS NOT NULL
                AND (NOT EXISTS (SELECT 1 FROM season_result r
                                  WHERE r.region_id = sv.region_id AND r.category = sv.category
                                    AND r.bike_type = '' AND r.round_start = sv.round_start)
                     OR (sv.bike_type IS NOT NULL
                         AND NOT EXISTS (SELECT 1 FROM season_result r
                                          WHERE r.region_id = sv.region_id AND r.category = sv.category
                                            AND r.bike_type = sv.bike_type AND r.round_start = sv.round_start)))
              ORDER BY sv.region_id, sv.category",
            ['open' => self::openStart($this->clock->now())],
        );
        $keys = [];
        foreach ($rows as $r) {
            $type = ItemType::from($r['category']);
            $all = new ListKey((int) $r['region_id'], $type);
            $keys[$all->regionId.'/'.$type->value.'/'] = $all;
            if (null !== $r['bike_type'] && ItemType::QualityRides === $type) {
                $keys[$all->regionId.'/'.$type->value.'/'.$r['bike_type']] = new ListKey($all->regionId, $type, BikeType::from($r['bike_type']));
            }
        }

        $stored = 0;
        foreach ($keys as $key) {
            if ($this->freezeClosed($key) > 0) {
                ++$stored;
            }
        }

        return $stored;
    }

    /**
     * Deletes the draft votes of every ballot that has closed: a ballot never
     * submitted counts for nothing, and a vote is personal data
     * (route-domain.md §8c, §8d privacy).
     *
     * @return int the draft votes deleted
     */
    public function dropClosedDrafts(): int
    {
        return (int) $this->db->executeStatement(
            'DELETE FROM season_vote WHERE submitted_at IS NULL AND round_start <= :open',
            ['open' => self::openStart($this->clock->now())],
        );
    }

    /**
     * Stores every round of this list whose ballot has closed, that holds
     * votes and is not stored yet, oldest first.
     *
     * @param bool $ignoreGrace store a round the moment its ballot closed: the deletion hook may not wait
     *
     * @return int the rounds stored
     */
    public function freezeClosed(ListKey $key, bool $ignoreGrace = false): int
    {
        $now = $this->clock->now();
        [$join, $where, $params] = $this->scope($key);
        /** @var list<array{season: string, round_start: string}> $rounds */
        $rounds = $this->db->fetchAllAssociative(
            "SELECT DISTINCT sv.season, sv.round_start
               FROM season_vote sv $join
              WHERE $where AND sv.round_start <= :open
                AND NOT EXISTS (SELECT 1 FROM season_result r
                                 WHERE r.region_id = sv.region_id AND r.category = sv.category
                                   AND r.bike_type = :bikecol AND r.round_start = sv.round_start)
              ORDER BY sv.round_start",
            $params + ['bikecol' => $key->bikeColumn(), 'open' => self::openStart($now)],
        );
        $stored = 0;
        foreach ($rounds as $row) {
            $round = Round::fromStored($row['season'], $row['round_start']);
            $closes = $ignoreGrace ? $round->votingClosesAt() : $round->votingClosesAt()->modify(BallotRules::FREEZE_GRACE);
            if ($closes <= $now && $this->freeze($key, $round)) {
                ++$stored;
            }
        }

        return $stored;
    }

    /**
     * The first day of the rounds open at `$now`. Both hemispheres change
     * season on the same days, so every round that starts on or before it
     * has started, and its ballot has closed.
     */
    private static function openStart(\DateTimeImmutable $now): string
    {
        return Round::containing($now, Hemisphere::North)->startDate();
    }

    /**
     * Counts a round whose ballot has closed. Never called for a ballot that
     * is still open.
     *
     * @return ListResult
     */
    private function compute(ListKey $key, Round $round): array
    {
        [$join, $where, $params] = $this->scope($key);
        $where .= ' AND sv.round_start = :start';
        $params['start'] = $round->startDate();

        $voters = $this->countVoters($key, $round);
        $points = 'CASE sv.slot';
        foreach (BallotRules::POINTS_BY_SLOT as $slot => $worth) {
            $points .= ' WHEN '.$slot.' THEN '.$worth;
        }
        $points .= ' ELSE 0 END';
        /** @var list<array{subject_id: int|string, votes: int|string, points: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT sv.subject_id, COUNT(*) AS votes, SUM($points) AS points FROM season_vote sv $join WHERE $where GROUP BY sv.subject_id",
            $params,
        );
        if ([] === $rows) {
            return ['voters' => 0, 'ranked' => false, 'closed' => true, 'entries' => []];
        }

        $ids = array_map(static fn (array $r): int => (int) $r['subject_id'], $rows);
        $top = $this->placedTopIn($key, $round->yearBefore());
        $wins = $this->winsBefore($key, $round, $ids);
        $tallies = [];
        foreach ($rows as $r) {
            $id = (int) $r['subject_id'];
            $tallies[] = ['subjectId' => $id, 'votes' => (int) $r['votes'], 'points' => (int) $r['points'], 'handicapped' => \in_array($id, $top, true), 'winsBefore' => $wins[$id] ?? 0];
        }

        $names = $this->candidates->names($key->category, $ids);
        $entries = [];
        foreach (PlaceRanker::rank($tallies, $voters) as $p) {
            $entries[] = [
                'subjectId' => $p['subjectId'],
                'name' => $names[$p['subjectId']] ?? '',
                'votes' => $p['votes'],
                'handicapped' => $p['handicapped'],
                'winsBefore' => $p['winsBefore'],
                'score' => $p['score'],
                'place' => $p['place'],
                'position' => $p['position'],
            ];
        }

        return ['voters' => $voters, 'ranked' => $voters >= BallotRules::RANKING_THRESHOLD, 'closed' => true, 'entries' => $entries];
    }

    private function countVoters(ListKey $key, Round $round): int
    {
        [$join, $where, $params] = $this->scope($key);

        return (int) $this->db->fetchOne(
            "SELECT COUNT(DISTINCT sv.user_id) FROM season_vote sv $join WHERE $where AND sv.round_start = :start",
            $params + ['start' => $round->startDate()],
        );
    }

    /** @return bool whether the round had entries to store */
    private function freeze(ListKey $key, Round $round): bool
    {
        $result = $this->compute($key, $round);
        if ([] === $result['entries']) {
            return false;
        }
        $at = $this->clock->now()->format('Y-m-d H:i:s');
        $this->db->transactional(static function (Connection $db) use ($key, $round, $result, $at): void {
            foreach ($result['entries'] as $e) {
                $db->executeStatement(
                    'INSERT INTO season_result (region_id, category, bike_type, season, round_start, subject_id, subject_name,
                                                votes, score, handicapped, place, wins_before, list_position, voters, frozen_at)
                     VALUES (:rid, :cat, :bike, :season, :start, :sid, :name,
                             :votes, :score, :hc, :place, :wins, :pos, :voters, :at)
                     ON CONFLICT (region_id, category, bike_type, round_start, subject_id) DO NOTHING',
                    [
                        'rid' => $key->regionId, 'cat' => $key->category->value, 'bike' => $key->bikeColumn(),
                        'season' => $round->season->value, 'start' => $round->startDate(), 'sid' => $e['subjectId'],
                        'name' => mb_substr($e['name'], 0, 200), 'votes' => $e['votes'], 'score' => $e['score'],
                        'hc' => $e['handicapped'], 'place' => $e['place'], 'wins' => $e['winsBefore'],
                        'pos' => $e['position'], 'voters' => $result['voters'], 'at' => $at,
                    ],
                    ['hc' => ParameterType::BOOLEAN, 'place' => null === $e['place'] ? ParameterType::NULL : ParameterType::INTEGER],
                );
            }
        });

        return true;
    }

    /** @return ListResult */
    private function stored(ListKey $key, Round $round): array
    {
        /** @var list<array{subject_id: int|string, subject_name: string, votes: int|string, score: int|string, handicapped: bool, place: int|string|null, wins_before: int|string, list_position: int|string, voters: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT subject_id, subject_name, votes, score, handicapped, place, wins_before, list_position, voters
               FROM season_result
              WHERE region_id = :rid AND category = :cat AND bike_type = :bike AND round_start = :start
              ORDER BY list_position',
            ['rid' => $key->regionId, 'cat' => $key->category->value, 'bike' => $key->bikeColumn(), 'start' => $round->startDate()],
        );
        $voters = [] === $rows ? 0 : (int) $rows[0]['voters'];
        $entries = array_map(static fn (array $r): array => [
            'subjectId' => (int) $r['subject_id'],
            'name' => $r['subject_name'],
            'votes' => (int) $r['votes'],
            'handicapped' => (bool) $r['handicapped'],
            'winsBefore' => (int) $r['wins_before'],
            'score' => (int) $r['score'],
            'place' => null === $r['place'] ? null : (int) $r['place'],
            'position' => (int) $r['list_position'],
        ], $rows);

        return ['voters' => $voters, 'ranked' => $voters >= BallotRules::RANKING_THRESHOLD, 'closed' => true, 'entries' => $entries];
    }

    /**
     * The submitted votes that belong to this list, whatever the round: its
     * region and category, and for a narrowed route list its bike. A specialty bike also
     * needs the route to declare that bike (route-domain.md §8.3).
     *
     * @return array{0: string, 1: string, 2: array<string, int|string>}
     */
    private function scope(ListKey $key): array
    {
        $params = ['rid' => $key->regionId, 'cat' => $key->category->value];
        // Only a submitted ballot counts; a draft counts for nothing (route-domain.md §8c).
        $where = 'sv.region_id = :rid AND sv.category = :cat AND sv.submitted_at IS NOT NULL';
        $join = '';
        if (null !== $key->bike) {
            $where .= ' AND sv.bike_type = :bike';
            $params['bike'] = $key->bike->value;
            if ($key->bike->isSpecialty()) {
                $join = 'JOIN recommended_route rr ON rr.id = sv.subject_id AND '.BikeSuitability::declares('rr', 'biketext');
                $params['biketext'] = $key->bike->value;
            }
        }

        return [$join, $where, $params];
    }

    /** @return list<int> what that round placed 1 to 3; nothing when it never reached a ranking */
    private function placedTopIn(ListKey $key, Round $round): array
    {
        return array_map(intval(...), $this->db->fetchFirstColumn(
            'SELECT subject_id FROM season_result
              WHERE region_id = :rid AND category = :cat AND bike_type = :bike AND round_start = :start
                AND place IS NOT NULL AND place <= :top',
            ['rid' => $key->regionId, 'cat' => $key->category->value, 'bike' => $key->bikeColumn(), 'start' => $round->startDate(), 'top' => BallotRules::HANDICAP_TOP],
        ));
    }

    /**
     * First places in every earlier round of this list, any season.
     *
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    private function winsBefore(ListKey $key, Round $round, array $ids): array
    {
        /** @var list<array{subject_id: int|string, wins: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT subject_id, COUNT(*) AS wins FROM season_result
              WHERE region_id = :rid AND category = :cat AND bike_type = :bike
                AND round_start < :start AND place = 1 AND subject_id IN (:ids)
              GROUP BY subject_id',
            ['rid' => $key->regionId, 'cat' => $key->category->value, 'bike' => $key->bikeColumn(), 'start' => $round->startDate(), 'ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['subject_id']] = (int) $r['wins'];
        }

        return $out;
    }
}
