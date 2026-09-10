<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use App\Catalog\Import\DuplicateGuard;
use App\Catalog\Import\NameKey;
use App\Catalog\Import\OsmLinker;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The mechanical checks behind the curator data desk.
 *
 * Read-only, always. It answers "what looks wrong" and never acts on the
 * answer: `app:catalog:dedupe` and `app:catalog:link-osm` apply things from a
 * terminal, `app:catalog:findings` files them for a human, and the desk is
 * where a human decides. Keeping the scan itself free of side effects is what
 * lets all three share it without any of them surprising the others.
 *
 * @see docs/specs/catalog-data-model.md §5c
 *
 * @api
 */
final readonly class CatalogScanner
{
    public function __construct(
        private Connection $db,
        private OsmLinker $linker,
    ) {
    }

    /**
     * Served rows that describe one place, grouped.
     *
     * `blocked` marks a group containing a curator-edited row. Those are
     * reported and never acted on: the edit is human work that a source
     * ranking cannot weigh, and the same reasoning already shields edited rows
     * from being overwritten by a re-import (catalog-data-model.md §3).
     *
     * @return list<array{key: string, letter: string, keeper: array<string, mixed>, losers: list<array<string, mixed>>, blocked: bool}>
     */
    public function duplicateGroups(?string $letter = null, int $radius = DuplicateGuard::RADIUS_M): array
    {
        $byKey = [];
        foreach ($this->servedRows($letter) as $row) {
            $key = NameKey::of((string) $row['name']);
            if ('' === $key) {
                continue;   // a name that reduces to nothing identifies nothing
            }
            $byKey[$row['letter'].'|'.$key][] = $row;
        }

        $groups = [];
        foreach ($byKey as $label => $members) {
            if (\count($members) < 2) {
                continue;
            }
            foreach ($this->clusters($members, $radius) as $cluster) {
                if (\count($cluster) < 2) {
                    continue;
                }
                // Best rank wins; a tie goes to the oldest row, which is the
                // one other things are most likely to already point at.
                usort($cluster, static fn (array $a, array $b): int => [self::rank($b), (int) $a['id']] <=> [self::rank($a), (int) $b['id']]);
                $keeper = array_shift($cluster);

                $groups[] = [
                    'key' => $label,
                    'letter' => (string) $keeper['letter'],
                    'keeper' => $keeper,
                    'losers' => $cluster,
                    'blocked' => [] !== array_filter($cluster, static fn (array $r): bool => (bool) $r['edited']),
                ];
            }
        }

        return $groups;
    }

    /**
     * Non-OSM rows that look like a record of an OSM object.
     *
     * `confident` follows {@see OsmLinker}: inside its tight band the link is
     * safe to write, outside it the name is doing all the work and only a human
     * should decide. `taken` means another served row already claims that OSM
     * object, which is a duplicate wearing a link rather than a link.
     *
     * @return list<array{item: array<string, mixed>, ref: string, osmName: string, distanceM: float, confident: bool, taken: bool}>
     */
    public function osmLinkCandidates(?string $letter = null, bool $includeLinked = false): array
    {
        $sql = "SELECT i.id, i.letter, i.source, i.name, i.osm_ref,
                       ST_Y(ST_Centroid(i.geom)) AS lat, ST_X(ST_Centroid(i.geom)) AS lng
                  FROM item i
                 WHERE i.source NOT IN ('osm', 'auto')
                   AND i.state IN ".ItemState::servedSqlTuple()."
                   AND i.name IS NOT NULL AND i.name <> ''
                   AND i.geom IS NOT NULL";
        $params = [];
        if (!$includeLinked) {
            $sql .= ' AND i.osm_ref IS NULL';
        }
        if (null !== $letter) {
            $sql .= ' AND i.letter = :letter';
            $params['letter'] = $letter;
        }

        $out = [];
        foreach ($this->db->fetchAllAssociative($sql.' ORDER BY i.letter, i.name, i.id', $params) as $row) {
            $found = $this->linker->candidateFor(
                (string) $row['letter'], (string) $row['name'],
                (float) $row['lat'], (float) $row['lng'],
            );
            if (null === $found) {
                continue;
            }
            $out[] = [
                'item' => $row,
                'ref' => $found['ref'],
                'osmName' => $found['name'],
                'distanceM' => $found['distanceM'],
                'confident' => $found['confident'],
                'taken' => $this->linker->refIsTaken($found['ref'], (string) $row['letter'], (int) $row['id']),
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    public static function rank(array $row): int
    {
        return ItemSource::tryFrom((string) $row['source'])?->dedupeRank() ?? 0;
    }

    /**
     * Single-linkage clusters within `radius` metres, on true geometry distance
     * so a climb's LINE is measured as a line and not as its midpoint.
     *
     * @param list<array<string, mixed>> $members
     *
     * @return list<list<array<string, mixed>>>
     */
    private function clusters(array $members, int $radius): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $members);
        /** @var list<array{a: int, b: int}> $near */
        $near = $this->db->fetchAllAssociative(
            'SELECT a.id AS a, b.id AS b
               FROM item a JOIN item b ON b.id > a.id
              WHERE a.id IN (:ids) AND b.id IN (:ids)
                AND ST_DWithin(a.geom::geography, b.geom::geography, :radius)',
            ['ids' => $ids, 'radius' => $radius],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $parent = array_combine($ids, $ids);
        // Iterative with path halving: a long chain of merges would otherwise
        // recurse once per link.
        $find = static function (int $x) use (&$parent): int {
            while ($parent[$x] !== $x) {
                $x = $parent[$x] = $parent[$parent[$x]];
            }

            return $x;
        };
        foreach ($near as $pair) {
            $ra = $find((int) $pair['a']);
            $rb = $find((int) $pair['b']);
            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        }

        $out = [];
        foreach ($members as $row) {
            $out[$find((int) $row['id'])][] = $row;
        }

        return array_values($out);
    }

    /** @return list<array<string, mixed>> */
    private function servedRows(?string $letter): array
    {
        $sql = 'SELECT i.id, i.letter, i.name, i.source, i.state, i.country_code, i.region_id,
                       EXISTS (SELECT 1 FROM change_history ch WHERE ch.item_id = i.id) AS edited
                  FROM item i
                 WHERE i.state IN '.ItemState::servedSqlTuple()."
                   AND i.geom IS NOT NULL AND i.name IS NOT NULL AND i.name <> ''";
        $params = [];
        if (null !== $letter) {
            $sql .= ' AND i.letter = :letter';
            $params['letter'] = $letter;
        }

        return $this->db->fetchAllAssociative($sql.' ORDER BY i.letter, i.name, i.id', $params);
    }
}
