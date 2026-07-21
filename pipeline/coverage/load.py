# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""coverage_poi schema bootstrap + per-region atomic load (coverage-provider.md §2-§3).

The table is a pipeline-owned disposable cache: idempotent CREATE at run start,
deliberately outside Doctrine migrations (coverage-provider.md §2).
load_region swaps one src_region slice in a single transaction — readers never
see a half-loaded region; a drift abort keeps last week's slice serving.
"""

from __future__ import annotations

import json
from collections.abc import Iterable
from dataclasses import dataclass

import psycopg

from coverage.parse import PoiRow

# Abort the swap when the new row count drops more than 40 % below the previous
# run for the same region — a truncated download/filter must not wipe a region.
DRIFT_ABORT_RATIO = 0.4

# Degrees (SRID 4326) a region-less POI may sit outside every region polygon and
# still snap to the nearest region of its own country (region-scoping-design.md
# §6, finding 5). ~0.01° ≈ 1.1 km at Belgian latitudes — wide enough for
# polygon-simplification gaps, tight enough that a genuine outside-coverage point
# stays unstamped.
BOUNDARY_SNAP_DEG = 0.01

# coverage-provider.md §2 DDL, verbatim (indexes named below).
_TABLE_DDL = """
CREATE TABLE IF NOT EXISTS coverage_poi (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ref          varchar(160) NOT NULL,   -- 'node/61146471' | 'way/…' = item.source_ref format
    letter       char(1)      NOT NULL,   -- C D E G H I J (osm-data-architecture.md §5)
    kind         varchar(16),             -- serviceKind for D (shop|station|pump), NULL otherwise
    name         varchar(255),            -- OSM name tag, NULL when unnamed
    geom         geometry(Point, 4326) NOT NULL, -- nodes as-is; ways centroid at load
    tags         jsonb        NOT NULL,   -- full filtered tag subset (drawer + Plan 3/4 source)
    osm_version  int,                     -- upstream version (Plan 3 materialization snapshot)
    osm_ts       timestamptz,             -- upstream last-edit timestamp
    src_region   varchar(64)  NOT NULL,   -- Geofabrik extract ('europe/belgium')
    country_code char(2),                 -- stamped from extract config
    region_id    bigint,                  -- ST_Contains(region.geom, geom) at load
    UNIQUE (ref, letter)                  -- one entity may carry two letters (matches item rule)
)
"""

_INDEX_DDL = (
    "CREATE INDEX IF NOT EXISTS coverage_poi_geom_idx ON coverage_poi USING gist (geom)",
    "CREATE INDEX IF NOT EXISTS coverage_poi_letter_idx ON coverage_poi (letter)",
    "CREATE INDEX IF NOT EXISTS coverage_poi_region_id_idx ON coverage_poi (region_id)",
    # country_code arm of /map/coverage/search|nearby|counts (region-scoping-design.md §3, §6):
    # this table is pipeline-owned, so its index lands here, not in web/migrations.
    "CREATE INDEX IF NOT EXISTS coverage_poi_country_code_idx ON coverage_poi (country_code)",
    "CREATE INDEX IF NOT EXISTS coverage_poi_name_trgm_idx ON coverage_poi USING gin (name gin_trgm_ops)",
)

# Same shape as coverage_poi minus the generated id and the backfilled region_id.
_STAGING_DDL = """
CREATE TEMP TABLE coverage_poi_staging (
    ref          varchar(160) NOT NULL,
    letter       char(1)      NOT NULL,
    kind         varchar(16),
    name         varchar(255),
    geom         geometry(Point, 4326) NOT NULL,
    tags         jsonb        NOT NULL,
    osm_version  int,
    osm_ts       timestamptz,
    src_region   varchar(64)  NOT NULL,
    country_code char(2)
) ON COMMIT DROP
"""

_COLUMNS = "ref, letter, kind, name, geom, tags, osm_version, osm_ts, src_region, country_code"


class DriftAbort(RuntimeError):
    """New extract shrank suspiciously vs the previous run — region left untouched."""


@dataclass(frozen=True)
class LoadResult:
    inserted: int
    previous: int


def ensure_schema(conn: psycopg.Connection) -> None:
    """Idempotent bootstrap: pg_trgm guard + coverage_poi table and indexes."""
    try:
        conn.execute("CREATE EXTENSION IF NOT EXISTS pg_trgm")
    except (psycopg.errors.InsufficientPrivilege, psycopg.errors.UndefinedFile) as exc:
        conn.rollback()
        raise RuntimeError(
            "pg_trgm is required for coverage name search but could not be installed "
            f"({exc}). Install it as a privileged role first — dev: developers/docker/db/init, "
            "prod: the DB-extensions bootstrap note in developers/docker/README.md."
        ) from exc
    conn.execute(_TABLE_DDL)
    for stmt in _INDEX_DDL:
        conn.execute(stmt)
    conn.commit()


def load_region(conn: psycopg.Connection, rows: Iterable[PoiRow], src_region: str) -> LoadResult:
    """Atomically replace one region's slice of coverage_poi.

    COPY into a same-shape TEMP staging table, drift-check against the previous
    run, then in the same transaction: DELETE the src_region slice, INSERT the
    staging rows, and backfill region_id via ST_Contains over region polygons
    (coverage-provider.md §3 step 4). A DriftAbort (or any error) rolls
    the whole swap back, keeping the last good slice.
    """
    with conn.transaction():
        with conn.cursor() as cur:
            previous = cur.execute(
                "SELECT count(*) FROM coverage_poi WHERE src_region = %s", (src_region,)
            ).fetchone()[0]
            cur.execute(_STAGING_DDL)
            with cur.copy(f"COPY coverage_poi_staging ({_COLUMNS}) FROM STDIN") as copy:
                for row in rows:
                    copy.write_row((
                        row.ref,
                        row.letter,
                        row.kind,
                        row.name,
                        f"SRID=4326;POINT({row.lon} {row.lat})",
                        json.dumps(row.tags, ensure_ascii=False),
                        row.osm_version,
                        row.osm_ts,
                        row.src_region,
                        row.country_code,
                    ))
            inserted = cur.execute("SELECT count(*) FROM coverage_poi_staging").fetchone()[0]
            if previous > 0 and inserted < previous * (1 - DRIFT_ABORT_RATIO):
                raise DriftAbort(
                    f"{src_region}: new extract has {inserted} rows vs {previous} previously "
                    f"(more than {DRIFT_ABORT_RATIO:.0%} drop) — aborting swap, keeping last good slice."
                )
            cur.execute("DELETE FROM coverage_poi WHERE src_region = %s", (src_region,))
            cur.execute(
                f"INSERT INTO coverage_poi ({_COLUMNS}) SELECT {_COLUMNS} FROM coverage_poi_staging"
            )
            # Smallest-area-wins on overlap (region-scoping-design.md §3): the
            # third membership writer besides RegionResolver and
            # ImportCatalogCommand::recomputeMembership. DISTINCT ON keeps one
            # region per POI, ordered by area then id, so the stamp is
            # deterministic once regions multiply past the Wallonia seed. Only
            # this slice's freshly-inserted rows are candidates; POIs in no
            # region keep the NULL they were inserted with.
            cur.execute(
                """
                UPDATE coverage_poi SET region_id = m.region_id
                FROM (
                    SELECT DISTINCT ON (c.id) c.id AS poi_id, r.id AS region_id
                    FROM coverage_poi c
                    JOIN region r ON ST_Contains(r.geom, c.geom)
                    WHERE c.src_region = %s
                    ORDER BY c.id, r.area_km2 ASC NULLS LAST, r.id ASC
                ) m
                WHERE coverage_poi.id = m.poi_id
                """,
                (src_region,),
            )
            # Boundary-miss rescue (region-scoping-design.md §6, finding 5): a POI
            # inside the extract but outside every region polygon — an ST_Contains
            # gap from polygon simplification, ~206 rows in dev Belgium — snaps to
            # the NEAREST region of its OWN country within BOUNDARY_SNAP_DEG,
            # smallest-area-wins on a tie. Constrained to the same country_code so
            # a true country-border row is never pulled across; a row with no cc,
            # or none near, stays NULL (a genuine outside-coverage point). Without
            # this a boundary POI carries cc but no rid, so it vanishes under a
            # region scope (the rail excludes region_id-NULL rows) yet the client
            # can only show it under the whole country.
            cur.execute(
                """
                UPDATE coverage_poi SET region_id = m.region_id
                FROM (
                    SELECT DISTINCT ON (c.id) c.id AS poi_id, r.id AS region_id
                    FROM coverage_poi c
                    JOIN region r ON r.country_code = c.country_code
                                 AND ST_DWithin(r.geom, c.geom, %s)
                    WHERE c.src_region = %s
                      AND c.region_id IS NULL
                      AND c.country_code IS NOT NULL
                    ORDER BY c.id, ST_Distance(r.geom, c.geom),
                             r.area_km2 ASC NULLS LAST, r.id ASC
                ) m
                WHERE coverage_poi.id = m.poi_id
                """,
                (BOUNDARY_SNAP_DEG, src_region),
            )
            # region ⇒ cc invariant (region-scoping-design.md §8 risk 10, finding
            # 8): the controller's 24-region cap is only safe if every
            # region-stamped row also carries cc (the cc arm is the completeness
            # net when the rid list truncates). Backfill cc from the region for
            # any stamped row whose extract left it NULL (~10 rows in dev), so a
            # region-stamped row can never be cc-less.
            cur.execute(
                """
                UPDATE coverage_poi SET country_code = r.country_code
                FROM region r
                WHERE coverage_poi.region_id = r.id
                  AND coverage_poi.country_code IS NULL
                  AND coverage_poi.src_region = %s
                """,
                (src_region,),
            )
    return LoadResult(inserted=inserted, previous=previous)
