<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * The climbs a recommended route really rides, in order along the route.
 *
 * A climb (letter N) is a line from foot to summit, stored as `route` in its
 * attributes. It counts on a route when the route follows at least 30% of that
 * line, in the climbing direction:
 *
 * - "follows" means inside a band of TOLERANCE_M either side of the route, so a
 *   GPX recorded a few metres off the mapped road still matches;
 * - "30%" is the length of the climb line inside that band over the climb
 *   line's own length (MIN_SHARE). A race route often rides only part of a
 *   climb (Liège-Bastogne-Liège, owner 2026-09-15); a road the route only
 *   crosses shares a few dozen metres and does not count;
 * - "climbing direction" is ridesFootToSummit(): the shared part of the climb,
 *   read foot to summit, moves forward along the route. A route that rides the
 *   climb downhill passes it but does not climb it.
 *
 * `alongKm` is where the route joins the shared part from the foot side: the
 * climb's foot when the route rides the whole climb.
 *
 * The served rules are the catalog payload's (CatalogProvider::itemRows()):
 * a served state (ItemState) and not reported gone (GoneRows). A climb is
 * listed here only when the map draws it.
 *
 * @see docs/specs/route-domain.md §6.4
 * @see docs/specs/map-and-search.md §6.3
 *
 * @api
 */
final class RouteClimbService
{
    /** Metres either side of the route: GPS offset between a recorded ride and the mapped road. */
    public const int TOLERANCE_M = 40;

    /** Share of the climb line the route must follow. */
    public const float MIN_SHARE = 0.3;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Null when there is no such route, or it is not served and $allowSubmitted does not let a waiting route through.
     *
     * @return list<array{id: int, name: string, alongKm: float, avgGradient: string|null, ll: array{0: float, 1: float}}>|null
     */
    public function climbsOn(int $routeId, bool $allowSubmitted): ?array
    {
        $states = $allowSubmitted ? ItemState::servedOrSubmittedSqlTuple() : ItemState::servedSqlTuple();

        $exists = $this->db->fetchOne('SELECT 1 FROM recommended_route WHERE id = :id AND state IN '.$states, ['id' => $routeId]);
        if (false === $exists) {
            return null;
        }

        // MATERIALIZED: the route buffer is built once, not per climb.
        // The climb line is assembled from attributes.route ([lat, lng] pairs,
        // foot first); a non-numeric pair is skipped rather than failing the cast.
        // `fracs` are the route fractions of the shared part's vertices, in the
        // order they sit along the climb (foot to summit).
        /** @var list<array{id: int|string, name: string, avg_gradient: string|null, len_m: string|float, share: string|float|null, fracs: string, foot_lat: string|float, foot_lng: string|float}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'WITH route AS MATERIALIZED (
                 SELECT r.geom AS g, ST_Length(r.geom::geography) AS len_m,
                        ST_Buffer(r.geom::geography, :tol)::geometry AS b
                   FROM recommended_route r
                  WHERE r.id = :id
             ),
             climb AS MATERIALIZED (
                 SELECT i.id, i.name, i.attributes->>\'avgGradient\' AS avg_gradient,
                        ST_SetSRID(ST_MakeLine(ARRAY(
                            SELECT ST_MakePoint((e.p->>1)::float8, (e.p->>0)::float8)
                              FROM jsonb_array_elements(i.attributes->\'route\') WITH ORDINALITY AS e(p, n)
                             WHERE jsonb_typeof(e.p->0) = \'number\' AND jsonb_typeof(e.p->1) = \'number\'
                             ORDER BY e.n
                        )), 4326) AS line
                   FROM item i
                  WHERE i.letter = \'N\'
                    AND i.state IN '.ItemState::servedSqlTuple().'
                    AND '.GoneRows::notGoneSql('i').'
                    AND jsonb_typeof(i.attributes->\'route\') = \'array\'
                    AND jsonb_array_length(i.attributes->\'route\') >= 2
             ),
             shared AS (
                 SELECT c.id, c.name, c.avg_gradient, c.line, ST_Intersection(c.line, r.b) AS part, r.g, r.len_m
                   FROM climb c, route r
                  WHERE ST_NPoints(c.line) >= 2
                    AND c.line && r.b
                    AND ST_Intersects(c.line, r.b)
             )
             SELECT s.id, s.name, s.avg_gradient, s.len_m,
                    ST_Length(s.part::geography) / NULLIF(ST_Length(s.line::geography), 0) AS share,
                    ARRAY(
                        SELECT ST_LineLocatePoint(s.g, d.geom)
                          FROM ST_DumpPoints(s.part) AS d
                         ORDER BY ST_LineLocatePoint(s.line, d.geom), d.path
                    )::text AS fracs,
                    ST_Y(ST_StartPoint(s.line)) AS foot_lat, ST_X(ST_StartPoint(s.line)) AS foot_lng
               FROM shared s',
            ['id' => $routeId, 'tol' => self::TOLERANCE_M],
        );

        $climbs = [];
        foreach ($rows as $row) {
            if ((float) ($row['share'] ?? 0.0) < self::MIN_SHARE) {
                continue;
            }
            $fracs = self::parseFloatArray($row['fracs']);
            if (!self::ridesFootToSummit($fracs)) {
                continue;
            }
            $climbs[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'alongKm' => round($fracs[0] * (float) $row['len_m'] / 1000.0, 1),
                'avgGradient' => $row['avg_gradient'],
                'll' => [(float) $row['foot_lat'], (float) $row['foot_lng']],
                'frac' => $fracs[0],
            ];
        }
        usort($climbs, static fn (array $a, array $b): int => [$a['frac'], $a['id']] <=> [$b['frac'], $b['id']]);

        return array_map(static function (array $c): array {
            unset($c['frac']);

            return $c;
        }, $climbs);
    }

    /**
     * Whether the shared part, read foot to summit, moves forward along the route.
     *
     * $routeFracs are route fractions (0 at the start, 1 at the end) of points
     * on the climb, in foot-to-summit order. Each step between neighbours votes
     * with its length. A step longer than half the route is the seam of a loop
     * (the route starts or ends partway up the climb) and is read the short way
     * round. Fewer than two points say nothing about direction.
     *
     * @param list<float> $routeFracs
     */
    public static function ridesFootToSummit(array $routeFracs): bool
    {
        $sum = 0.0;
        for ($i = 1, $n = \count($routeFracs); $i < $n; ++$i) {
            $step = $routeFracs[$i] - $routeFracs[$i - 1];
            if ($step > 0.5) {
                $step -= 1.0;
            } elseif ($step < -0.5) {
                $step += 1.0;
            }
            $sum += $step;
        }

        return $sum > 0.0;
    }

    /**
     * A Postgres float array literal, "{0.1,0.25}".
     *
     * @return list<float>
     */
    private static function parseFloatArray(string $literal): array
    {
        $inner = trim($literal, '{}');

        return '' === $inner ? [] : array_map(floatval(...), explode(',', $inner));
    }
}
