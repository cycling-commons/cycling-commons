<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Vote;

use App\Catalog\BikeSuitability;
use App\Catalog\BikeType;
use App\Catalog\ConfirmationStance;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Catalog\OperationalRegions;
use App\Catalog\StayKind;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The catalogue side of the ballot: which rows can get a vote, where they
 * are, what they are called and how often riders confirmed them.
 *
 * A vote goes to a catalogue row in an operational region: a served place of
 * a votable kind, or a verified route (voting opens at verified,
 * route-domain.md §6). An OpenStreetMap place that is not in the catalogue
 * has no id a vote could hold.
 *
 * "Confirmed" is the second number of route-domain.md §8b: drawer
 * confirmations that vouch for a place, distinct riders for a route with the
 * proposer left out, the same count the drawer shows.
 *
 * @phpstan-type Candidate array{id: int, name: string, confirmations: int}
 * @phpstan-type Subject array{id: int, name: string, regionId: int, hemisphere: Hemisphere}
 *
 * @see docs/specs/route-domain.md §8c, §8d
 *
 * @api
 */
final class BallotCandidates
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Most confirmed first, newest first among equals.
     *
     * For a specialty bike (`BikeType::isSpecialty()`) a route list only
     * lists routes that declare that bike in `attributes.bikeTypes`
     * (route-domain.md §8.3); a general bike narrows nothing here, same as
     * best-of. The bike is ignored for every other type.
     *
     * `$routeFilter` narrows a route list further before the limit: extra
     * conditions on the route `s` with their parameters and types
     * (`BestOfPreview::routeFilter($difficulties, $lengths, 's')`).
     *
     * @param array{0: string, 1: array<string, mixed>, 2: array<string, ArrayParameterType>}|null $routeFilter
     *
     * @return list<Candidate>
     */
    public function top(ItemType $type, int $regionId, int $limit, ?BikeType $bike = null, ?array $routeFilter = null): array
    {
        return $this->listed($type, $regionId, $limit, $bike, $routeFilter, 'confirmations DESC, s.created_at DESC, s.id DESC');
    }

    /**
     * Every candidate A to Z, for the ballot (owner 2026-10-03: "to make it
     * fair it should be alphabetically and not related to people that have
     * been there"). The cap is only a guard; a region's list is far shorter.
     *
     * @return list<Candidate>
     */
    public function alphabetical(ItemType $type, int $regionId, int $limit, ?BikeType $bike = null): array
    {
        return $this->listed($type, $regionId, $limit, $bike, null, 'LOWER(s.name), s.id');
    }

    /**
     * @param array{0: string, 1: array<string, mixed>, 2: array<string, ArrayParameterType>}|null $routeFilter
     *
     * @return list<Candidate>
     */
    private function listed(ItemType $type, int $regionId, int $limit, ?BikeType $bike, ?array $routeFilter, string $order): array
    {
        $s = $this->source($type, $bike);
        $where = $s['where'];
        $params = ['rid' => $regionId] + $s['params'];
        $types = [];
        if (ItemType::QualityRides === $type && null !== $routeFilter) {
            $where .= $routeFilter[0];
            $params += $routeFilter[1];
            $types = $routeFilter[2];
        }

        /** @var list<array{id: int|string, name: string, confirmations: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT s.id, s.name, '.$s['count'].' AS confirmations
               '.$s['from'].'
              WHERE '.$where.' AND s.region_id = :rid
              ORDER BY '.$order.'
              LIMIT '.max(1, $limit),
            $params,
            $types,
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'confirmations' => (int) $r['confirmations'],
        ], $rows);
    }

    /**
     * The kind of stay each of these places is, read from its type label
     * (`StayKind`; "unknown" when the label names no kind), and its town.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{kind: string, town: ?string}> id => kind and town
     */
    public function stayKinds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        /** @var list<array{id: int|string, type: ?string, t: ?string, town: ?string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, attributes->>'type' AS type, attributes->>'t' AS t, attributes->>'town' AS town FROM item WHERE id IN (:ids)",
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $out = [];
        foreach ($rows as $r) {
            $town = null !== $r['town'] && '' !== trim($r['town']) ? trim($r['town']) : null;
            // The Type a rider can change first, then the import's label.
            $out[(int) $r['id']] = ['kind' => StayKind::fromType($r['type']) ?? StayKind::fromLabel($r['t']) ?? StayKind::UNKNOWN, 'town' => $town];
        }

        return $out;
    }

    /**
     * The bikes a vote for each of these routes can name: every general bike,
     * and the specialty bikes the route declares (route-domain.md §8.3). A
     * vote on any other bike would count in no bike's list.
     *
     * @param list<int> $routeIds
     *
     * @return array<int, list<BikeType>> route id => bikes, in `BikeType` order; a route that does not exist is left out
     */
    public function bikesFor(array $routeIds): array
    {
        if ([] === $routeIds) {
            return [];
        }
        $specialty = array_values(array_filter(BikeType::cases(), static fn (BikeType $b): bool => $b->isSpecialty()));
        $columns = [];
        $params = ['ids' => $routeIds];
        foreach ($specialty as $i => $b) {
            $columns[] = BikeSuitability::declares('s', "b$i")." AS b$i";
            $params["b$i"] = $b->value;
        }
        /** @var list<array<string, int|string|bool>> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT s.id, '.implode(', ', $columns).' FROM recommended_route s WHERE s.id IN (:ids)',
            $params,
            ['ids' => ArrayParameterType::INTEGER],
        );

        $out = [];
        foreach ($rows as $r) {
            $declared = [];
            foreach ($specialty as $i => $b) {
                if (true === $r["b$i"]) {
                    $declared[] = $b;
                }
            }
            $out[(int) $r['id']] = array_values(array_filter(
                BikeType::cases(),
                static fn (BikeType $b): bool => !$b->isSpecialty() || \in_array($b, $declared, true),
            ));
        }

        return $out;
    }

    /**
     * The regions among these that have a row a vote can go to: one query
     * for a whole country, the same rows {@see self::top()} lists.
     *
     * @param list<int> $regionIds
     *
     * @return array<int, true>
     */
    public function regionsWithCandidates(ItemType $type, array $regionIds, ?BikeType $bike = null): array
    {
        if ([] === $regionIds) {
            return [];
        }
        $s = $this->source($type, $bike);
        $out = [];
        foreach ($this->db->fetchFirstColumn(
            'SELECT DISTINCT s.region_id '.$s['from'].' WHERE '.$s['where'].' AND s.region_id IN (:rids)',
            ['rids' => $regionIds] + $s['params'],
            ['rids' => ArrayParameterType::INTEGER],
        ) as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    /** @return Subject|null */
    public function subject(ItemType $type, int $id): ?array
    {
        if (!$type->isVotable()) {
            return null;
        }
        $s = $this->source($type);
        /** @var array{id: int|string, name: string, region_id: int|string, mid: float|string}|false $row */
        $row = $this->db->fetchAssociative(
            'SELECT s.id, s.name, s.region_id, (g.bbox_s + g.bbox_n) / 2 AS mid
               '.$s['from'].'
              WHERE '.$s['where'].' AND s.id = :id',
            ['id' => $id] + $s['params'],
        );
        if (false === $row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'regionId' => (int) $row['region_id'],
            'hemisphere' => Hemisphere::ofLatitude((float) $row['mid']),
        ];
    }

    /**
     * Names of the rows that exist, whatever their state now.
     *
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    public function names(ItemType $type, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $out = [];
        /** @var list<array{id: int|string, name: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name FROM '.self::table($type).' WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
        foreach ($rows as $r) {
            $out[(int) $r['id']] = $r['name'];
        }

        return $out;
    }

    /**
     * The ones a vote can still go to, and that the map still shows.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function onBallot(ItemType $type, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $s = $this->source($type);

        return array_map(intval(...), $this->db->fetchFirstColumn(
            'SELECT s.id '.$s['from'].' WHERE '.$s['where'].' AND s.id IN (:ids)',
            ['ids' => $ids] + $s['params'],
            ['ids' => ArrayParameterType::INTEGER],
        ));
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    public function confirmations(ItemType $type, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $out = [];
        /** @var list<array{id: int|string, n: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT s.id, '.$this->source($type)['count'].' AS n FROM '.self::table($type).' s WHERE s.id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (int) $r['n'];
        }

        return $out;
    }

    private static function table(ItemType $type): string
    {
        return ItemType::QualityRides === $type ? 'recommended_route' : 'item';
    }

    /**
     * FROM (the row as `s`, its operational region as `g`), the WHERE that
     * puts a row on the ballot, and the confirmation count. A route list
     * for a specialty bike also needs the route to declare it.
     *
     * @return array{from: string, where: string, count: string, params: array<string, string>}
     */
    private function source(ItemType $type, ?BikeType $bike = null): array
    {
        $from = 'FROM '.self::table($type).' s JOIN region g ON g.id = s.region_id';
        $where = 'g.geom IS NOT NULL AND '.OperationalRegions::predicate('g')." AND s.name <> ''";

        if (ItemType::QualityRides === $type) {
            $params = [];
            $where .= " AND s.state = 'verified'";
            if (null !== $bike && $bike->isSpecialty()) {
                $where .= ' AND '.BikeSuitability::declares('s', 'bike');
                $params['bike'] = $bike->value;
            }

            return [
                'from' => $from,
                'where' => $where,
                'count' => '(SELECT COUNT(*) FROM route_ride rr
                              WHERE rr.route_id = s.id AND (rr.user_id IS NULL OR rr.user_id <> COALESCE(s.proposed_by, -1)))',
                'params' => $params,
            ];
        }

        $vouching = "'".implode("', '", array_map(static fn (ConfirmationStance $c): string => $c->value, ConfirmationStance::vouching()))."'";

        return [
            'from' => $from,
            'where' => $where.' AND s.letter = :letter AND s.state IN '.ItemState::servedSqlTuple(),
            'count' => "(SELECT COUNT(*) FROM item_confirmation c
                          WHERE c.item_id = s.id AND c.source = 'drawer' AND c.stance IN ($vouching))",
            'params' => ['letter' => $type->letter()],
        ];
    }
}
