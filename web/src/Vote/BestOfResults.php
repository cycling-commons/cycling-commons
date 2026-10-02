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
 * @phpstan-import-type ListResult from SeasonResults
 *
 * @phpstan-type Standing array{round: Round, voters: int, ranked: bool, closed: bool}
 * @phpstan-type Group array{slug: string, name: string, top: list<Ranked>, result: Standing}
 * @phpstan-type Named array{slug: string, name: string}
 * @phpstan-type Picked array{id: int, name: string, votes: int, score: int, place: ?int, handicapped: bool}
 *
 * @see docs/specs/route-domain.md §8b, §8d
 *
 * @api
 */
final class BestOfResults
{
    /** The Everywhere view: the ranked lists with the most voters, not every region on earth. */
    public const int EVERYWHERE_REGIONS = 12;

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
     * Every operational region of a country. The ones with nothing of the
     * kind on the map go to `quiet`; the ones whose list the difficulty and
     * length filters emptied go to `filtered`, because "nothing here yet"
     * would be false for them.
     *
     * Two grouped queries for the whole country say which regions have votes
     * in their round (counted or stored) and which have anything to vote
     * for; a list is read only for a region with votes, and a region with
     * neither costs nothing more.
     *
     * @param list<string> $difficulties
     * @param list<string> $lengths
     *
     * @return array{ranked: list<Group>, quiet: list<Named>, filtered: list<Named>}
     */
    public function byRegion(ItemType $type, ?Season $season, string $countryCode, ?BikeType $bike, array $difficulties = [], array $lengths = []): array
    {
        $bike = self::bikeFor($type, $bike);
        $regions = $this->regions->inCountry($countryCode);
        $voted = $this->votersByRegion($regions, $type, $season, $bike, 1);
        $stocked = $this->candidates->regionsWithCandidates($type, array_column($regions, 'id'), $bike);

        $ranked = [];
        $quiet = [];
        $filtered = [];
        foreach ($regions as $region) {
            $named = ['slug' => $region['slug'], 'name' => $region['name']];
            $hasVotes = isset($voted[$region['id']]);
            $hasCandidates = isset($stocked[$region['id']]);
            if (!$hasVotes && !$hasCandidates) {
                $quiet[] = $named;

                continue;
            }
            $round = $this->round($region, $season);
            $list = $hasVotes
                ? $this->results->list(new ListKey($region['id'], $type, $bike), $round)
                : ['voters' => 0, 'ranked' => false, 'closed' => $round->hasClosedBy($this->clock->now()), 'entries' => []];
            if (!$list['ranked'] && !$hasCandidates) {
                $quiet[] = $named;

                continue;
            }
            $group = $this->group($region, $type, $round, $list, $bike, $difficulties, $lengths);
            if ([] === $group['top']) {
                $filtered[] = $named;
            } else {
                $ranked[] = $group;
            }
        }

        return ['ranked' => $ranked, 'quiet' => $quiet, 'filtered' => $filtered];
    }

    /**
     * The ranked lists anywhere, most voters first, at most
     * {@see self::EVERYWHERE_REGIONS}.
     *
     * The regions are walked busiest first and the walk stops as soon as no
     * region left could make the cut, so only the lists shown (and the few
     * that fall short on the way) are computed.
     *
     * @param list<string> $difficulties
     * @param list<string> $lengths
     *
     * @return array{ranked: list<Group>, quiet: list<Named>, filtered: list<Named>}
     */
    public function everywhere(ItemType $type, ?Season $season, ?BikeType $bike, array $difficulties = [], array $lengths = []): array
    {
        $bike = self::bikeFor($type, $bike);
        $byId = [];
        foreach ($this->regions->all() as $region) {
            $byId[$region['id']] = $region;
        }
        $busy = $this->votersByRegion(array_values($byId), $type, $season, $bike, BallotRules::RANKING_THRESHOLD);
        // The order the page shows: most voters first, then by name.
        $order = static fn (int $voters, string $name): array => [-$voters, $name];
        uksort($busy, static fn (int $a, int $b): int => $order($busy[$a], $byId[$a]['name']) <=> $order($busy[$b], $byId[$b]['name']));

        $groups = [];
        foreach ($busy as $id => $atMost) {
            // A region's count here is its list's voters, or more for a
            // specialty bike, never fewer. Once the next region cannot beat
            // the last list kept, no region after it can.
            $last = $groups[self::EVERYWHERE_REGIONS - 1] ?? null;
            if (null !== $last && $order($atMost, $byId[$id]['name']) >= $order($last['result']['voters'], $last['name'])) {
                break;
            }
            $round = $this->round($byId[$id], $season);
            $list = $this->results->list(new ListKey($id, $type, $bike), $round);
            if (!$list['ranked']) {
                continue;
            }
            $group = $this->group($byId[$id], $type, $round, $list, $bike, $difficulties, $lengths);
            if ([] === $group['top']) {
                continue;
            }
            $groups[] = $group;
            usort($groups, static fn (array $a, array $b): int => $order($a['result']['voters'], $a['name']) <=> $order($b['result']['voters'], $b['name']));
            $groups = \array_slice($groups, 0, self::EVERYWHERE_REGIONS);
        }

        return ['ranked' => $groups, 'quiet' => [], 'filtered' => []];
    }

    /**
     * The regions with at least `$min` voters in their own round of this
     * list, counted or stored, with that number. Every condition is on the
     * indexed (region_id, category, round_start) of the two tables. A
     * specialty bike's route gate is left to the list itself, so for one the
     * number is an upper bound: a region may then fall short, none is missed.
     *
     * @param list<RegionRow> $regions
     *
     * @return array<int, int> region id => voters
     */
    private function votersByRegion(array $regions, ItemType $type, ?Season $season, ?BikeType $bike, int $min): array
    {
        $byStart = [];
        foreach ($regions as $region) {
            $byStart[$this->round($region, $season)->startDate()][] = $region['id'];
        }
        if ([] === $byStart) {
            return [];
        }

        $terms = [];
        $params = ['cat' => $type->value, 'min' => $min, 'bikecol' => (string) $bike?->value];
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

        /** @var list<array{region_id: int|string, voters: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT region_id, MAX(voters) AS voters FROM (
                 SELECT region_id, COUNT(DISTINCT user_id) AS voters FROM season_vote
                  WHERE $lists$bikeVote
                  GROUP BY region_id HAVING COUNT(DISTINCT user_id) >= :min
                 UNION ALL
                 SELECT region_id, MAX(voters) AS voters FROM season_result
                  WHERE $lists AND bike_type = :bikecol AND voters >= :min
                  GROUP BY region_id
             ) busy
             GROUP BY region_id",
            $params,
            $types,
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['region_id']] = (int) $r['voters'];
        }

        return $out;
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
     * One region's list as the page shows it. A ranked list keeps its own
     * places and the route filters only hide rows; below the threshold the
     * rows are the most confirmed candidates the filters keep, with their
     * votes so far. A list the filters emptied has no rows.
     *
     * @param RegionRow    $region
     * @param ListResult   $list
     * @param list<string> $difficulties
     * @param list<string> $lengths
     *
     * @return Group
     */
    private function group(array $region, ItemType $type, Round $round, array $list, ?BikeType $bike, array $difficulties, array $lengths): array
    {
        $isRoute = ItemType::QualityRides === $type;
        $placeCounts = [];
        if ($list['ranked']) {
            $picked = [];
            foreach ($list['entries'] as $e) {
                $picked[] = ['id' => $e['subjectId'], 'name' => $e['name'], 'votes' => $e['votes'], 'score' => $e['score'], 'place' => $e['place'], 'handicapped' => $e['handicapped']];
                if (null !== $e['place']) {
                    $placeCounts[$e['place']] = ($placeCounts[$e['place']] ?? 0) + 1;
                }
            }
            // Difficulty and length hide rows of a route list; the places stay the list's own.
            if ($isRoute) {
                $keep = $this->cards->routesMatching(array_column($picked, 'id'), $difficulties, $lengths);
                $picked = array_values(array_filter($picked, static fn (array $p): bool => \in_array($p['id'], $keep, true)));
            }
            $picked = \array_slice($picked, 0, BallotRules::TOP_N);
            $ids = array_column($picked, 'id');
            $rides = $this->candidates->confirmations($type, $ids);
            $onMap = $this->candidates->onBallot($type, $ids);
        } else {
            // No ranking yet: most confirmed first, newest first where none, with the votes so far.
            $votes = array_column($list['entries'], 'votes', 'subjectId');
            $filter = $isRoute ? BestOfPreview::routeFilter($difficulties, $lengths, 's') : null;
            $top = $this->candidates->top($type, $region['id'], BallotRules::TOP_N, $bike, $filter);
            $picked = array_map(static fn (array $c): array => [
                'id' => $c['id'], 'name' => $c['name'], 'votes' => $votes[$c['id']] ?? 0, 'score' => 0, 'place' => null, 'handicapped' => false,
            ], $top);
            // A candidate is on the ballot and on the map by definition, with its count already read.
            $rides = array_column($top, 'confirmations', 'id');
            $ids = array_column($picked, 'id');
            $onMap = $ids;
        }

        $cards = $this->cards->cards($type, $ids);
        // Against the first row, as the preview's bar reads: how close to first place.
        $lead = max([1, ...array_column($picked, 'votes')]);

        $top = [];
        foreach ($picked as $p) {
            $card = $cards[$p['id']];
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
