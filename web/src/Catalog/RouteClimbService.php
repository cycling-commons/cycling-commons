<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use Doctrine\DBAL\Connection;

/**
 * The climbs a recommended route, or a ride's track in the ride check, really
 * rides, in order along it, and the climbs near it that it does not ride. One
 * rule and one code path for both lines (climbsOn() for the route drawer's
 * "Climbs on this route", climbsAlong() for the ride check and the along
 * lists); below, "route" is either line.
 *
 * A climb (letter N) is a line from foot to summit, stored as `route` in its
 * attributes. It counts on a route when the route climbs at least 30% of that
 * line in one go and reaches its top part:
 *
 * - "follows" means inside a band of TOLERANCE_M either side of the route, so a
 *   GPX recorded a few metres off the mapped road still matches;
 * - "30%" is the length of the climb line inside that band over the climb
 *   line's own length (MIN_SHARE). A race route often rides only part of a
 *   climb (Liège-Bastogne-Liège, owner 2026-09-15); a road the route only
 *   crosses shares a few dozen metres and does not count;
 * - "in one go" is ascent(): the route is cut into its passes over the climb,
 *   and the climb counts when one ascent, the stretches the route rides foot
 *   to summit in route order, covers MIN_SHARE of the climb line. Each pass is
 *   read on its own, so an out-and-back route that rides the same road up and
 *   down counts for its way up. A route that rides the climb only downhill
 *   passes it but does not climb it;
 * - "its top part" is TOP_M: that ascent ends no further than TOP_M below the
 *   summit, measured along the climb line (owner 2026-09-30). A route that
 *   rides the lower or middle part of a long climb and turns off well below
 *   the top passes it.
 *
 * `alongKm` is where the route's ascent of the climb starts: the climb's foot
 * when the route rides the whole climb. For a climb near the route, it is the
 * route's point closest to the climb line. It is true distance along the
 * route in metres (LineMetres), the same km the route drawer's "Along this
 * route" list and the ride check show.
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

    /** Share of the climb line one ascent must ride foot to summit. */
    public const float MIN_SHARE = 0.3;

    /** Metres below the summit, along the climb line, within which that ascent must end. */
    public const float TOP_M = 2_000.0;

    /** Metres from the route's start and end within which an ascent runs on through the seam of a loop. */
    private const float SEAM_M = 1.0;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * The route drawer's "Climbs on this route": the climbs the route rides, in order along it.
     *
     * `alongKm` is where the ascent starts, in true distance along the route
     * (LineMetres). Null when there is no such route, or it is not served and
     * $allowSubmitted does not let a waiting route through.
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

        return array_map(static fn (array $c): array => [
            'id' => $c['id'],
            'name' => $c['name'],
            'alongKm' => round($c['atM'] / 1000.0, 1),
            'avgGradient' => $c['avgGradient'],
            'll' => $c['ll'],
        ], $this->ridden('SELECT r.geom AS g FROM recommended_route r WHERE r.id = :id', ['id' => $routeId]));
    }

    /**
     * The climbs a line rides and the climbs near it that it does not ride, each in order along it.
     *
     * $geoJson is the line as a GeoJSON LineString: a ride's track in the ride
     * check, or a route's own line for the route drawer's along list. `ridden`
     * holds the climbs it rides, by the rule above; `near` every other climb
     * whose line comes within $radiusM of it, so no climb is in both. `alongKm`
     * is where the ascent starts on a ridden row and the line's point closest
     * to the climb line on a near row, as a share of the line's geodesic
     * length (LineMetres) times $lengthM, the line's own distance: the scale
     * of the corridor rows beside them. `distM` is the metres between the
     * climb line and the line. Every row stands at the climb's foot (`ll`),
     * where the map pins it.
     *
     * @return array{
     *     ridden: list<array{id: int, name: string, alongKm: float, avgGradient: string|null, ll: array{0: float, 1: float}, distM: int}>,
     *     near: list<array{id: int, name: string, alongKm: float, avgGradient: string|null, ll: array{0: float, 1: float}, distM: int}>
     * }
     */
    public function climbsAlong(string $geoJson, float $lengthM, int $radiusM): array
    {
        $lineSql = 'SELECT ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326) AS g';
        $params = ['geom' => $geoJson];
        $row = static fn (array $c): array => [
            'id' => $c['id'],
            'name' => $c['name'],
            'alongKm' => round($c['lineM'] > 0.0 ? $c['atM'] / $c['lineM'] * $lengthM / 1000.0 : 0.0, 1),
            'avgGradient' => $c['avgGradient'],
            'll' => $c['ll'],
            'distM' => (int) round($c['offM']),
        ];

        $ridden = $this->ridden($lineSql, $params);
        $riddenIds = array_column($ridden, 'id');
        $near = array_values(array_filter(
            $this->near($lineSql, $params, $radiusM),
            static fn (array $c): bool => !\in_array($c['id'], $riddenIds, true),
        ));

        return ['ridden' => array_map($row, $ridden), 'near' => array_map($row, $near)];
    }

    /**
     * Every climb whose line comes within $radiusM of the line from $lineSql (one row, column `g`), ridden or not, in order along it.
     *
     * `atM` is the metres along the line at its point closest to the climb
     * line (LineMetres); `lineM` the line's geodesic length; `offM` the metres
     * between climb and line. `ll` is the climb's foot, where the map pins it.
     *
     * @param array<string, mixed> $params the parameters $lineSql binds
     *
     * @return list<array{id: int, name: string, avgGradient: string|null, ll: array{0: float, 1: float}, atM: float, lineM: float, offM: float}>
     */
    private function near(string $lineSql, array $params, int $radiusM): array
    {
        // MATERIALIZED as in ridden(): the line, its corridor and its
        // segments are built once, not per climb. `at` is the line's segment
        // closest to the climb line, the earliest one when the climb touches
        // the line more than once (distances compared to the metre), and the
        // metres along the line at its point closest to the climb: the
        // segment's start plus its share of the segment (LineMetres).
        /** @var list<array{id: int|string, name: string, avg_gradient: string|null, dist_m: string|float, at_m: string|float, len_m: string|float, foot_lat: string|float, foot_lng: string|float}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'WITH track AS MATERIALIZED (SELECT l.g FROM ('.$lineSql.') l),
                  corridor AS MATERIALIZED (SELECT ST_Buffer((SELECT g FROM track)::geography, :radius)::geometry AS b),
                  '.self::climbCte().',
                  '.LineMetres::segmentsCte('seg', '(SELECT g FROM track)').',
                  hit AS MATERIALIZED (
                      SELECT c.id, c.name, c.avg_gradient, c.line,
                             ST_Buffer(c.line::geography, :radius)::geometry AS band,
                             ST_Distance(c.line::geography, (SELECT g FROM track)::geography) AS dist_m
                        FROM climb c
                       WHERE ST_NPoints(c.line) >= 2
                         AND c.line && (SELECT b FROM corridor)
                         AND ST_Intersects(c.line, (SELECT b FROM corridor))
                  )
             SELECT h.id, h.name, h.avg_gradient, h.dist_m, at.m AS at_m,
                    (SELECT COALESCE(SUM(seg_m), 0) FROM seg) AS len_m,
                    ST_Y(ST_StartPoint(h.line)) AS foot_lat, ST_X(ST_StartPoint(h.line)) AS foot_lng
               FROM hit h
              CROSS JOIN LATERAL (
                  SELECT s.from_m + s.seg_m * ST_LineLocatePoint(s.seg, ST_ClosestPoint(s.seg, h.line)) AS m
                    FROM seg s
                   WHERE s.seg && h.band
                   ORDER BY round(ST_Distance(s.seg::geography, h.line::geography)), s.n
                   LIMIT 1
              ) at
              ORDER BY at.m, h.id',
            [...$params, 'radius' => $radiusM],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'avgGradient' => $r['avg_gradient'],
            'll' => [(float) $r['foot_lat'], (float) $r['foot_lng']],
            'atM' => (float) $r['at_m'],
            'lineM' => (float) $r['len_m'],
            'offM' => (float) $r['dist_m'],
        ], $rows);
    }

    /**
     * CTE `climb`: every served climb with its line (`line`), assembled from
     * attributes.route ([lat, lng] pairs, foot first). A non-numeric pair is
     * skipped rather than failing the cast; a climb with fewer than two pairs
     * has no line and is left out.
     */
    private static function climbCte(): string
    {
        return 'climb AS MATERIALIZED (
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
                    AND CASE WHEN jsonb_typeof(i.attributes->\'route\') = \'array\'
                             THEN jsonb_array_length(i.attributes->\'route\') >= 2 ELSE false END
             )';
    }

    /**
     * The climbs the line from $lineSql (one row, column `g`) rides, in order along it.
     *
     * `atM` is where the ascent starts, in metres along the line; `lineM` is
     * the line's geodesic length; `offM` the metres between climb and line.
     *
     * @param array<string, mixed> $params the parameters $lineSql binds
     *
     * @return list<array{id: int, name: string, avgGradient: string|null, ll: array{0: float, 1: float}, atM: float, lineM: float, offM: float}>
     */
    private function ridden(string $lineSql, array $params): array
    {
        // MATERIALIZED: the route buffer and the route's segments are built
        // once, not per climb. The climb lines come from climbCte(). `shared`
        // keeps the climbs the route follows for at least MIN_SHARE of their
        // line, with a band of TOLERANCE_M around each climb line. `pieces` cuts every route segment with that band:
        // one entry per piece of the route inside the band,
        // [from_m, to_m, from_cf, to_cf, foot_m].
        // - from_m/to_m: metres along the route at the piece's two ends, in
        //   route order. Each piece is located on its own segment, never on
        //   another pass over the same road, and its metres are the segment's
        //   start plus its share of the segment, both geodesic (LineMetres),
        //   the km the drawer's other lists and the ride check show.
        // - from_cf/to_cf: the climb fractions (0 foot, 1 summit) of those ends,
        //   located on `flat`, the climb line with longitude scaled by the
        //   cosine of its latitude, so a fraction is a share of the line's
        //   length in metres without the cost of a geography locate.
        // - foot_m: metres along the route level with the lower of the two
        //   climb points, where an ascent that starts on this piece begins.
        /** @var list<array{id: int|string, name: string, avg_gradient: string|null, route_m: string|float, climb_m: string|float, off_m: string|float, pieces: string, foot_lat: string|float, foot_lng: string|float}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'WITH route AS MATERIALIZED (
                 SELECT l.g, ST_Length(l.g::geography) AS len_m,
                        ST_Buffer(l.g::geography, :tol)::geometry AS b
                   FROM ('.$lineSql.') l
             ),
             '.self::climbCte().',
             shared AS MATERIALIZED (
                 SELECT c.id, c.name, c.avg_gradient, c.line,
                        ST_Buffer(c.line::geography, :tol)::geometry AS band,
                        cos(radians(ST_Y(ST_StartPoint(c.line)))) AS k,
                        ST_Scale(c.line, cos(radians(ST_Y(ST_StartPoint(c.line)))), 1) AS flat
                   FROM climb c, route r
                  WHERE ST_NPoints(c.line) >= 2
                    AND c.line && r.b
                    AND ST_Intersects(c.line, r.b)
                    AND ST_Length(ST_Intersection(c.line, r.b)::geography)
                        >= :share * ST_Length(c.line::geography)
             ),
             '.LineMetres::segmentsCte('seg', '(SELECT g FROM route)').',
             piece AS (
                 SELECT s.id, g.seg, g.seg_m, g.from_m, s.line, s.flat, s.k,
                        ST_StartPoint(p.geom) AS a, ST_EndPoint(p.geom) AS z
                   FROM shared s
                   JOIN seg g ON g.seg && s.band AND ST_Intersects(g.seg, s.band)
                  CROSS JOIN LATERAL ST_Dump(ST_Intersection(g.seg, s.band)) AS p
                  WHERE ST_Dimension(p.geom) = 1
             ),
             located AS (
                 SELECT p.id,
                        p.from_m + p.seg_m * ST_LineLocatePoint(p.seg, p.a) AS a_m,
                        p.from_m + p.seg_m * ST_LineLocatePoint(p.seg, p.z) AS z_m,
                        ST_LineLocatePoint(p.flat, ST_Scale(p.a, p.k, 1)) AS a_cf,
                        ST_LineLocatePoint(p.flat, ST_Scale(p.z, p.k, 1)) AS z_cf,
                        p.from_m + p.seg_m * ST_LineLocatePoint(p.seg, ST_ClosestPoint(p.line, p.a)) AS a_foot_m,
                        p.from_m + p.seg_m * ST_LineLocatePoint(p.seg, ST_ClosestPoint(p.line, p.z)) AS z_foot_m
                   FROM piece p
             )
             SELECT s.id, s.name, s.avg_gradient, r.len_m AS route_m, ST_Length(s.line::geography) AS climb_m,
                    ST_Distance(s.line::geography, r.g::geography) AS off_m,
                    COALESCE((
                        SELECT json_agg(CASE WHEN l.a_m <= l.z_m
                            THEN json_build_array(l.a_m, l.z_m, l.a_cf, l.z_cf, CASE WHEN l.a_cf <= l.z_cf THEN l.a_foot_m ELSE l.z_foot_m END)
                            ELSE json_build_array(l.z_m, l.a_m, l.z_cf, l.a_cf, CASE WHEN l.a_cf <= l.z_cf THEN l.a_foot_m ELSE l.z_foot_m END)
                        END)
                          FROM located l
                         WHERE l.id = s.id
                    ), \'[]\')::text AS pieces,
                    ST_Y(ST_StartPoint(s.line)) AS foot_lat, ST_X(ST_StartPoint(s.line)) AS foot_lng
               FROM shared s, route r',
            [...$params, 'tol' => self::TOLERANCE_M, 'share' => self::MIN_SHARE],
        );

        $climbs = [];
        foreach ($rows as $row) {
            /** @var list<array{0: float, 1: float, 2: float, 3: float, 4: float}> $pieces */
            $pieces = json_decode($row['pieces'], true, 3, \JSON_THROW_ON_ERROR);
            $ascent = self::ascent($pieces, (float) $row['route_m'], (float) $row['climb_m']);
            if (null === $ascent) {
                continue;
            }
            $climbs[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'avgGradient' => $row['avg_gradient'],
                'll' => [(float) $row['foot_lat'], (float) $row['foot_lng']],
                'atM' => $ascent,
                'lineM' => (float) $row['route_m'],
                'offM' => (float) $row['off_m'],
            ];
        }
        usort($climbs, static fn (array $a, array $b): int => [$a['atM'], $a['id']] <=> [$b['atM'], $b['id']]);

        return $climbs;
    }

    /**
     * Where the route's ascent of a climb starts, in metres along the route, or
     * null when the route does not climb it: no ascent covers MIN_SHARE of the
     * climb line and ends within TOP_M of its summit.
     *
     * $pieces are the pieces of the route inside the climb's band, each
     * [fromM, toM, fromCf, toCf, footM]: metres along the route at its two ends
     * (fromM <= toM), the climb fractions (0 foot, 1 summit) of those ends, and
     * the metres along the route level with its lower climb point.
     *
     * Every pass over the climb is read on its own: a piece rides foot to summit
     * when its climb fraction rises in route order, so on an out-and-back route
     * the way up rises and the way down does not. Riding down never counts.
     *
     * Rising pieces make one ascent while each carries on from the last
     * (continues()), across a stretch where the route leaves the band and, for
     * a loop that starts partway up the climb, through the route's seam. An
     * ascent climbs the climb when its pieces together cover MIN_SHARE of the
     * line, each part of the line counted once (riding the same stretch up
     * twice does not add up), and its highest piece ends no more than TOP_M
     * below the summit, measured along the climb line ($climbM). The route's
     * ascent is the one of those that covers the most of the climb; it starts
     * at its lowest piece.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float, 4: float}> $pieces
     */
    public static function ascent(array $pieces, float $routeM, float $climbM): ?float
    {
        $rising = array_values(array_filter($pieces, static fn (array $p): bool => $p[3] > $p[2]));
        if ([] === $rising) {
            return null;
        }
        usort($rising, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        /** @var list<non-empty-list<array{0: float, 1: float, 2: float, 3: float, 4: float}>> $ascents */
        $ascents = [];
        foreach ($rising as $piece) {
            $last = \count($ascents) - 1;
            if ($last >= 0 && self::continues($ascents[$last][\count($ascents[$last]) - 1], $piece, $climbM)) {
                $ascents[$last][] = $piece;
            } else {
                $ascents[] = [$piece];
            }
        }
        $last = \count($ascents) - 1;
        $tail = $ascents[$last][\count($ascents[$last]) - 1];
        if ($last > 0 && $ascents[0][0][0] <= self::SEAM_M && $tail[1] >= $routeM - self::SEAM_M && self::continues($tail, $ascents[0][0], $climbM)) {
            $ascents[0] = [...$ascents[$last], ...$ascents[0]];
            array_pop($ascents);
        }

        $start = null;
        $bestCover = 0.0;
        foreach ($ascents as $ascent) {
            $cover = self::covered(array_map(static fn (array $p): array => [$p[2], $p[3]], $ascent));
            $belowTopM = (1.0 - max(array_column($ascent, 3))) * $climbM;
            if ($cover >= self::MIN_SHARE && $belowTopM <= self::TOP_M && $cover > $bestCover) {
                $bestCover = $cover;
                usort($ascent, static fn (array $a, array $b): int => $a[2] <=> $b[2]);
                $start = $ascent[0][4];
            }
        }

        return $start;
    }

    /**
     * Whether rising piece $next carries on the ascent that $previous is part of.
     *
     * It must start no lower on the climb than $previous ends (TOLERANCE_M of
     * slack), and the route may not ride further between them than the climb
     * line runs, with a band's width (twice TOLERANCE_M) of slack. A route that
     * leaves the band and comes back a little higher on the climb carries on; a
     * route that comes back to the climb far along the route, or lower down, is
     * on another pass.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float} $previous
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float} $next
     */
    private static function continues(array $previous, array $next, float $climbM): bool
    {
        $climbGapM = ($next[2] - $previous[3]) * $climbM;

        return $climbGapM >= -self::TOLERANCE_M
            && $next[0] - $previous[1] <= max(0.0, $climbGapM) + 2 * self::TOLERANCE_M;
    }

    /**
     * Length of the union of [from, to] spans on the climb line, as a fraction of it.
     *
     * @param list<array{0: float, 1: float}> $spans
     */
    private static function covered(array $spans): float
    {
        usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $total = 0.0;
        $reach = null;
        foreach ($spans as [$from, $to]) {
            if (null === $reach || $from > $reach) {
                $total += $to - $from;
                $reach = $to;
            } elseif ($to > $reach) {
                $total += $to - $reach;
                $reach = $to;
            }
        }

        return $total;
    }
}
