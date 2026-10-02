<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Vote;

/**
 * Orders one list's tallies and gives each its place.
 *
 * A place is shared by every item less than one vote below the first item of
 * that place, measured from the first item and not chained along the list.
 * Inside a shared place the item that won fewer times before comes first.
 * Places skip after a shared one (1, 1, 3). Below the voter threshold nothing
 * gets a place.
 *
 * @phpstan-type Tally array{subjectId: int, votes: int, handicapped: bool, winsBefore: int}
 * @phpstan-type Placed array{subjectId: int, votes: int, handicapped: bool, winsBefore: int, score: int, place: ?int, position: int}
 *
 * @see docs/specs/route-domain.md §8c, §8d
 *
 * @api
 */
final class PlaceRanker
{
    /**
     * @param list<Tally> $tallies
     *
     * @return list<Placed>
     */
    public static function rank(array $tallies, int $voters): array
    {
        $rows = [];
        foreach ($tallies as $t) {
            $rows[] = $t + [
                'score' => $t['votes'] * ($t['handicapped'] ? BallotRules::HANDICAP_QUARTERS : BallotRules::FULL_QUARTERS),
                'place' => null,
                'position' => 0,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['score'], $a['winsBefore'], $b['votes'], $a['subjectId']]
            <=> [$a['score'], $b['winsBefore'], $a['votes'], $b['subjectId']]);

        if ($voters < BallotRules::RANKING_THRESHOLD) {
            foreach (array_keys($rows) as $i) {
                $rows[$i]['position'] = $i + 1;
            }

            return $rows;
        }

        $out = [];
        $n = \count($rows);
        for ($i = 0; $i < $n;) {
            $lead = $rows[$i]['score'];
            $group = [];
            while ($i < $n && $lead - $rows[$i]['score'] < BallotRules::CLOSE_CALL_QUARTERS) {
                $group[] = $rows[$i];
                ++$i;
            }
            usort($group, static fn (array $a, array $b): int => [$a['winsBefore'], $b['score'], $a['subjectId']]
                <=> [$b['winsBefore'], $a['score'], $b['subjectId']]);

            $place = \count($out) + 1;
            foreach ($group as $row) {
                $row['place'] = $place;
                $row['position'] = \count($out) + 1;
                $out[] = $row;
            }
        }

        return $out;
    }
}
