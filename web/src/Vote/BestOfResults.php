<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Vote;

use App\Catalog\BestOfPreview;
use App\Catalog\BikeType;
use App\Catalog\ItemType;
use App\Catalog\Season;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * What /best shows once the ballot is open: each region's real list for one
 * category and season, in the shape {@see BestOfPreview::byRegion()} hands
 * the page, so the page and its cards stay the ones the preview designed.
 *
 * A list is ranked from 5 voters; before that it reads "No ranking yet" and
 * shows the most confirmed places with their votes so far.
 *
 * No season chosen means each region's open round, so a country south of the
 * equator shows its own season. A chosen season means each region's most
 * recent round of it.
 *
 * @phpstan-import-type Ranked from BestOfPreview
 * @phpstan-import-type RegionRow from BallotRegions
 *
 * @phpstan-type Standing array{round: Round, voters: int, ranked: bool, closed: bool}
 * @phpstan-type Group array{slug: string, name: string, top: list<Ranked>, result: Standing}
 * @phpstan-type Picked array{id: int, name: string, votes: int, place: ?int, handicapped: bool}
 *
 * @see docs/specs/route-domain.md §8b, §8d
 *
 * @api
 */
final class BestOfResults
{
    /** The Everywhere view: the ranked lists with the most voters, not every region on earth. */
    public const int EVERYWHERE_REGIONS = 12;

    /** Below the threshold, enough candidates to fill a list after the route filters. */
    private const int CANDIDATE_POOL = 30;

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
        private readonly SeasonResults $results,
        private readonly BallotCandidates $candidates,
        private readonly BallotRegions $regions,
        private readonly BestOfPreview $cards,
    ) {
    }

    /**
     * Every operational region of a country; the ones with nothing of the
     * kind on the map go to `quiet`.
     *
     * @param list<string> $difficulties
     * @param list<string> $lengths
     *
     * @return array{ranked: list<Group>, quiet: list<array{slug: string, name: string}>}
     */
    public function byRegion(ItemType $type, ?Season $season, string $countryCode, ?BikeType $bike, array $difficulties = [], array $lengths = []): array
    {
        $bike = self::bikeFor($type, $bike);
        $ranked = [];
        $quiet = [];
        foreach ($this->regions->inCountry($countryCode) as $region) {
            $group = $this->group($region, $type, $season, $bike, $difficulties, $lengths);
            if (null === $group) {
                $quiet[] = ['slug' => $region['slug'], 'name' => $region['name']];

                continue;
            }
            $ranked[] = $group;
        }

        return ['ranked' => $ranked, 'quiet' => $quiet];
    }

    /**
     * The ranked lists anywhere, most voters first. Only regions whose votes
     * could reach the threshold are counted at all.
     *
     * @param list<string> $difficulties
     * @param list<string> $lengths
     *
     * @return array{ranked: list<Group>, quiet: list<array{slug: string, name: string}>}
     */
    public function everywhere(ItemType $type, ?Season $season, ?BikeType $bike, array $difficulties = [], array $lengths = []): array
    {
        $bike = self::bikeFor($type, $bike);
        $byId = [];
        foreach ($this->regions->all() as $region) {
            $byId[$region['id']] = $region;
        }

        $groups = [];
        foreach ($this->busyRegions(array_values($byId), $type, $season, $bike) as $id) {
            $group = $this->group($byId[$id], $type, $season, $bike, $difficulties, $lengths);
            if (null !== $group && $group['result']['ranked'] && [] !== $group['top']) {
                $groups[] = $group;
            }
        }
        usort($groups, static fn (array $a, array $b): int => [$b['result']['voters'], $a['name']] <=> [$a['result']['voters'], $b['name']]);

        return ['ranked' => \array_slice($groups, 0, self::EVERYWHERE_REGIONS), 'quiet' => []];
    }

    /**
     * The regions with at least the threshold of voters in their own round of
     * this list, counted or stored. Every condition is on the indexed
     * (region_id, category, round_start) of the two tables. A specialty bike's
     * route gate is left to the list itself, so this may name a region that
     * then falls short, never miss one.
     *
     * @param list<RegionRow> $regions
     *
     * @return list<int>
     */
    private function busyRegions(array $regions, ItemType $type, ?Season $season, ?BikeType $bike): array
    {
        $byStart = [];
        foreach ($regions as $region) {
            $byStart[$this->round($region, $season)->startDate()][] = $region['id'];
        }
        if ([] === $byStart) {
            return [];
        }

        $terms = [];
        $params = ['cat' => $type->value, 'min' => BallotRules::RANKING_THRESHOLD, 'bikecol' => (string) $bike?->value];
        $types = [];
        $n = 0;
        foreach ($byStart as $start => $ids) {
            $terms[] = "(region_id IN (:ids$n) AND round_start = :start$n)";
            $params["ids$n"] = $ids;
            $params["start$n"] = $start;
            $types["ids$n"] = ArrayParameterType::INTEGER;
            ++$n;
        }
        $lists = '('.implode(' OR ', $terms).') AND category = :cat';
        $bikeVote = null !== $bike ? ' AND bike_type = :bikecol' : '';

        return array_map(intval(...), $this->db->fetchFirstColumn(
            "SELECT region_id FROM season_vote
              WHERE $lists$bikeVote
              GROUP BY region_id HAVING COUNT(DISTINCT user_id) >= :min
             UNION
             SELECT region_id FROM season_result
              WHERE $lists AND bike_type = :bikecol AND voters >= :min",
            $params,
            $types,
        ));
    }

    /** Only a route list is narrowed by bike ({@see ListKey}). */
    private static function bikeFor(ItemType $type, ?BikeType $bike): ?BikeType
    {
        return ItemType::QualityRides === $type ? $bike : null;
    }

    /** @param RegionRow $region */
    private function round(array $region, ?Season $season): Round
    {
        $now = $this->clock->now();
        $hemisphere = Hemisphere::ofLatitude($region['mid']);

        return null === $season ? Round::containing($now, $hemisphere) : Round::latestStarted($season, $hemisphere, $now);
    }

    /**
     * One region's list, or null when it has nothing to show: nothing of the
     * kind on the map, or nothing the route filters keep.
     *
     * @param RegionRow    $region
     * @param list<string> $difficulties
     * @param list<string> $lengths
     *
     * @return Group|null
     */
    private function group(array $region, ItemType $type, ?Season $season, ?BikeType $bike, array $difficulties, array $lengths): ?array
    {
        $round = $this->round($region, $season);
        $list = $this->results->list(new ListKey($region['id'], $type, $bike), $round);

        $placeCounts = [];
        if ($list['ranked']) {
            $picked = [];
            foreach ($list['entries'] as $e) {
                $picked[] = ['id' => $e['subjectId'], 'name' => $e['name'], 'votes' => $e['votes'], 'place' => $e['place'], 'handicapped' => $e['handicapped']];
                if (null !== $e['place']) {
                    $placeCounts[$e['place']] = ($placeCounts[$e['place']] ?? 0) + 1;
                }
            }
        } else {
            // No ranking yet: most confirmed first, newest first where none, with the votes so far.
            $votes = array_column($list['entries'], 'votes', 'subjectId');
            $picked = array_map(static fn (array $c): array => [
                'id' => $c['id'], 'name' => $c['name'], 'votes' => $votes[$c['id']] ?? 0, 'place' => null, 'handicapped' => false,
            ], $this->candidates->top($type, $region['id'], self::CANDIDATE_POOL, $bike));
        }
        // Difficulty and length hide rows of a route list; the places stay the list's own.
        if (ItemType::QualityRides === $type) {
            $keep = $this->cards->routesMatching(array_column($picked, 'id'), $difficulties, $lengths);
            $picked = array_values(array_filter($picked, static fn (array $p): bool => \in_array($p['id'], $keep, true)));
        }
        $picked = \array_slice($picked, 0, BallotRules::TOP_N);
        if ([] === $picked) {
            return null;
        }

        $ids = array_column($picked, 'id');
        $rides = $this->candidates->confirmations($type, $ids);
        $onMap = $this->candidates->onBallot($type, $ids);
        $cards = $this->cards->cards($type, $ids);
        // Against the first row, as the preview's bar reads: how close to first place.
        $lead = max([1, ...array_column($picked, 'votes')]);

        $top = [];
        foreach ($picked as $p) {
            $card = $cards[$p['id']] ?? ['note' => null, 'photo' => null, 'hue' => 0];
            $top[] = [
                'id' => $p['id'],
                'ref' => null,
                'name' => $p['name'],
                'kind' => $type->value,
                'letter' => $type->letter(),
                'note' => $card['note'],
                'photo' => $card['photo'],
                'notePlaceholder' => false,
                'photoPlaceholder' => null === $card['photo'],
                'commonsFile' => null,
                'filler' => null,
                'hue' => $card['hue'],
                'votes' => $p['votes'],
                'rides' => $rides[$p['id']] ?? 0,
                'share' => (int) round(100 * $p['votes'] / $lead),
                'place' => $p['place'],
                'shared' => null !== $p['place'] && ($placeCounts[$p['place']] ?? 0) > 1,
                'handicapped' => $p['handicapped'],
                'onMap' => \in_array($p['id'], $onMap, true),
                'simulated' => false,
            ];
        }

        return [
            'slug' => $region['slug'],
            'name' => $region['name'],
            'top' => $top,
            'result' => ['round' => $round, 'voters' => $list['voters'], 'ranked' => $list['ranked'], 'closed' => $list['closed']],
        ];
    }
}
