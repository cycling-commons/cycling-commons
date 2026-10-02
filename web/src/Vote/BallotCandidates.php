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
     * @return list<Candidate>
     */
    public function top(ItemType $type, int $regionId, int $limit, ?BikeType $bike = null): array
    {
        $s = $this->source($type);
        $where = $s['where'];
        $params = ['rid' => $regionId] + $s['params'];
        if (ItemType::QualityRides === $type && null !== $bike && $bike->isSpecialty()) {
            $where .= ' AND '.BikeSuitability::declares('s', 'bike');
            $params['bike'] = $bike->value;
        }

        /** @var list<array{id: int|string, name: string, confirmations: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT s.id, s.name, '.$s['count'].' AS confirmations
               '.$s['from'].'
              WHERE '.$where.' AND s.region_id = :rid
              ORDER BY confirmations DESC, s.created_at DESC, s.id DESC
              LIMIT '.max(1, $limit),
            $params,
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'confirmations' => (int) $r['confirmations'],
        ], $rows);
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
     * puts a row on the ballot, and the confirmation count.
     *
     * @return array{from: string, where: string, count: string, params: array<string, string>}
     */
    private function source(ItemType $type): array
    {
        $from = 'FROM '.self::table($type).' s JOIN region g ON g.id = s.region_id';
        $where = 'g.geom IS NOT NULL AND '.OperationalRegions::predicate('g')." AND s.name <> ''";

        if (ItemType::QualityRides === $type) {
            return [
                'from' => $from,
                'where' => $where." AND s.state = 'verified'",
                'count' => '(SELECT COUNT(DISTINCT rr.user_id) FROM route_ride rr
                              WHERE rr.route_id = s.id AND rr.user_id <> COALESCE(s.proposed_by, -1))',
                'params' => [],
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
