<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * True metres along a line (a route or a ride), for every "km along" the map lists. One definition, shared. Do not fork the SQL.
 *
 * ST_LineLocatePoint() on a lng/lat line gives a fraction of the line's length
 * in degrees, and a degree of longitude is shorter than a degree of latitude
 * by the cosine of the latitude, so that fraction times the line's length in
 * metres is kilometres out on a long route that turns. Here the line is cut
 * into its segments, each with
 * its geodesic length and the geodesic length before it; a point on a segment
 * is that segment's start plus its share of the segment. A segment is short
 * and straight, so the share is the same in degrees and in metres.
 *
 * Two uses:
 * - segmentsCte(): one row per segment, for a query that cuts the line
 *   segment by segment (RouteClimbService) and adds
 *   `from_m + seg_m * ST_LineLocatePoint(seg, point)`;
 * - indexCte() and metresJoin(): a whole-line fraction from
 *   ST_LineLocatePoint(line, point) turned into metres by a binary search
 *   (width_bucket) over the segments (RideCheckService's corridor rows).
 *
 * Both give the same metres for the same point.
 *
 * @see docs/specs/route-domain.md §6.4
 * @see docs/specs/map-and-search.md §9
 */
final class LineMetres
{
    /**
     * CTE `$name`: one row per segment of $lineSql (an expression for a LineString, SRID 4326), in line order.
     *
     * Columns: `n` (segment path), `seg` (the segment), `seg_m` (its geodesic
     * length), `from_m` (geodesic length of the line before it), `seg_flat`
     * and `from_flat` (the same in degrees, the length ST_LineLocatePoint()
     * divides by).
     */
    public static function segmentsCte(string $name, string $lineSql): string
    {
        return $name.' AS MATERIALIZED (
                 SELECT s.n, s.seg, s.seg_m, s.seg_flat,
                        COALESCE(SUM(s.seg_m) OVER w, 0) AS from_m,
                        COALESCE(SUM(s.seg_flat) OVER w, 0) AS from_flat
                   FROM (SELECT d.path AS n, d.geom AS seg,
                                ST_Length(d.geom::geography) AS seg_m, ST_Length(d.geom) AS seg_flat
                           FROM ST_DumpSegments('.$lineSql.') AS d) s
                 WINDOW w AS (ORDER BY s.n ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING)
             )';
    }

    /**
     * CTE `$name`: the segments CTE `$segments` folded into one row of arrays, in line order, plus `flat` and `len_m`, the line's length in degrees and in metres.
     */
    public static function indexCte(string $name, string $segments): string
    {
        return $name.' AS MATERIALIZED (
                 SELECT array_agg(from_flat ORDER BY n)::float8[] AS from_flat,
                        array_agg(seg_flat ORDER BY n)::float8[] AS seg_flat,
                        array_agg(from_m ORDER BY n)::float8[] AS from_m,
                        array_agg(seg_m ORDER BY n)::float8[] AS seg_m,
                        COALESCE(SUM(seg_flat), 0) AS flat,
                        COALESCE(SUM(seg_m), 0) AS len_m
                   FROM '.$segments.'
             )';
    }

    /**
     * A join that adds column `m` under $alias: metres along the line at $fracSql, a fraction ST_LineLocatePoint() gave on the whole line, reading the index row aliased $index.
     *
     * width_bucket() finds the segment whose span in degrees holds the
     * fraction; the metres are that segment's start plus its share of the
     * segment's geodesic length. A zero-length segment adds nothing.
     */
    public static function metresJoin(string $fracSql, string $index, string $alias): string
    {
        return 'CROSS JOIN LATERAL (
                 SELECT '.$index.'.from_m[b.w] + '.$index.'.seg_m[b.w]
                        * COALESCE(LEAST(1.0, GREATEST(0.0, (b.at - '.$index.'.from_flat[b.w]) / NULLIF('.$index.'.seg_flat[b.w], 0))), 0) AS m
                   FROM (SELECT a.at, GREATEST(1, width_bucket(a.at, '.$index.'.from_flat)) AS w
                           FROM (SELECT (('.$fracSql.') * '.$index.'.flat)::float8 AS at) a) b
             ) '.$alias;
    }
}
