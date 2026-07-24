# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""coverage_poi schema bootstrap + per-region atomic load (coverage-provider.md §2-§3).

The table is a pipeline-owned disposable cache: idempotent CREATE at run start,
deliberately outside Doctrine migrations (coverage-provider.md §2).
load_region swaps one src_region slice in a single transaction — readers never
see a half-loaded region; a drift abort keeps last week's slice serving.
"""

from __future__ import annotations

import json
import os
import sys
from collections.abc import Iterable
from dataclasses import dataclass

import psycopg
from psycopg import sql

from coverage.parse import PoiRow

# Abort the swap when the new row count drops more than 40 % below the previous
# run for the same region — a truncated download/filter must not wipe a region.
DRIFT_ABORT_RATIO = 0.4

# Degrees (SRID 4326) a region-less POI may sit outside every region polygon and
# still snap to the nearest region of its own country (region-scoping-design.md
# §6, finding 5). ~0.01° ≈ 1.1 km at Belgian latitudes — wide enough for
# polygon-simplification gaps, tight enough that a genuine outside-coverage point
# stays unstamped. That 1.1 km figure is NORTH-SOUTH only, where a degree of
# latitude is ~constant; east-west a degree of longitude shrinks with cos(lat),
# so the same 0.01° is only ~0.70 km at 50°N and ~0.64 km at 54.8°N. Now that
# the ownership filter (C1, 2026-07-23-border-overlap-ownership-design.md §3)
# leans on this constant too, that anisotropy is worth naming here, not just at
# the call sites.
BOUNDARY_SNAP_DEG = 0.01

# Which country each Geofabrik extract is configured to cover. Lives here rather
# than in run.py because it is now load-time DATA SEMANTICS, not orchestration:
# it decides which staged rows this extract OWNS
# (2026-07-23-border-overlap-ownership-design.md §3), not merely what to stamp.
COUNTRY_BY_REGION = {
    "europe/belgium": "BE", "europe/netherlands": "NL", "europe/germany": "DE",
    "europe/luxembourg": "LU",
}

# Per-session resource budget applied to the harvest connection at startup
# (2026-07-24-coverage-harvest-prod-safety-design.md §3.1). Every value is an
# env knob with a conservative default; maintenance_work_mem × (1 + parallel
# workers) is the RAM term that can OOM a co-tenant on the shared DB host, so
# these are SET SESSION-only (never ALTER SYSTEM) and load-bearing, not tuning.
_SESSION_BUDGET = (
    ("statement_timeout", "COVERAGE_STATEMENT_TIMEOUT", "10min"),
    ("lock_timeout", "COVERAGE_LOCK_TIMEOUT", "5s"),
    ("idle_in_transaction_session_timeout", "COVERAGE_IDLE_TXN_TIMEOUT", "30s"),
    ("synchronous_commit", "COVERAGE_SYNCHRONOUS_COMMIT", "off"),
    ("maintenance_work_mem", "COVERAGE_MAINTENANCE_WORK_MEM", "256MB"),
    ("work_mem", "COVERAGE_WORK_MEM", "32MB"),
    ("max_parallel_workers_per_gather", "COVERAGE_MAX_PARALLEL", "0"),
)


def apply_session_budget(conn: psycopg.Connection) -> None:
    """Apply the env-driven SET SESSION resource budget to the harvest
    connection (design §3.1). Session-scoped only — never changes global
    cluster config. Postgres validates each value on SET."""
    for setting, env, default in _SESSION_BUDGET:
        value = os.environ.get(env, default)
        conn.execute(
            sql.SQL("SET SESSION {} = {}").format(
                sql.Identifier(setting), sql.Literal(value)
            )
        )
    conn.commit()


def resolve_country(region: str) -> str | None:
    """Resolve a Geofabrik region slug to its configured country.

    Longest-prefix match on the slug's `/`-separated segments, so a sub-country
    extract (`europe/germany/bayern`, per the onboarding playbook's own advice
    for large countries) or any other descendant of an onboarded slug resolves
    via its ancestor, instead of a bare `COUNTRY_BY_REGION.get(region)` missing
    it entirely (final-review finding I1).

    `dev/fixture` is the only explicit skip — no configured country, ownership
    filtering intentionally disabled, offline fixture path only. Any OTHER
    unresolvable slug (including `planet`, which spans every country and has
    no single owner by construction) is a HARD FAILURE, not a silent skip:
    since C1 (2026-07-23-border-overlap-ownership-design.md §3) an unresolved
    country no longer just costs a missing `country_code` stamp — it silently
    reverts that whole extract to non-deterministic last-writer-wins ownership.
    """
    if region == "dev/fixture":
        return None
    parts = region.split("/")
    for depth in range(len(parts), 0, -1):
        cc = COUNTRY_BY_REGION.get("/".join(parts[:depth]))
        if cc is not None:
            return cc
    raise RuntimeError(
        f"{region!r} has no entry (or ancestor entry) in COUNTRY_BY_REGION "
        "(pipeline/coverage/load.py) — add it before harvesting this region. "
        "An unresolved country now silently disables the ownership filter for "
        "the WHOLE extract instead of merely leaving country_code unset "
        "(2026-07-23-border-overlap-ownership-design.md §3); dev/fixture is "
        "the only intentional skip."
    )

# coverage-provider.md §2 DDL (indexes named below). Provenance is normalized:
# the Geofabrik extract slug lives once in coverage_source and each POI carries a
# 2-byte src_region_id FK instead of repeating a ~16-byte string per row. The
# lookup self-fills via get-or-create in load_region (no enum DDL, no pre-seeding
# — any extract on Earth gets a row the first time it's harvested); smallint holds
# far more than Geofabrik's ~700 extracts.
#
# Sizing (corrected 2026-07-23 — earlier comments here claimed a "100M+ row
# target", which was naive area extrapolation from German POI density). The
# osm-data-architecture.md §5 subset is ≈ 4.7 M points planet-wide (taginfo
# breakdown: coverage-provider.md §10), so the whole table lands around 2 GB, not
# tens of GB. Measured at 375,078 rows (BE+NL+DE, compacted): 341 B/row heap +
# 176 B/row indexes. This normalization plus region_id bigint→int is worth
# 16.2 B/row (-4.2 % heap) — real, but do not oversell it.
_SOURCE_DDL = """
CREATE TABLE IF NOT EXISTS coverage_source (
    id   smallint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug varchar(64) NOT NULL UNIQUE      -- Geofabrik extract ('europe/belgium')
)
"""

_TABLE_DDL = """
CREATE TABLE IF NOT EXISTS coverage_poi (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ref           varchar(160) NOT NULL,  -- 'node/61146471' | 'way/…' = item.source_ref format
    letter        char(1)      NOT NULL,  -- C D E G H I J (osm-data-architecture.md §5)
    kind          varchar(16),            -- serviceKind for D (shop|station|pump), NULL otherwise
    name          varchar(255),           -- OSM name tag, NULL when unnamed
    geom          geometry(Point, 4326) NOT NULL, -- nodes as-is; ways centroid at load
    tags          jsonb        NOT NULL,  -- trimmed to contract storedTagKeys (parse.py), NOT the object's full tag set
    osm_version   int,                    -- upstream version (Plan 3 materialization snapshot)
    osm_ts        timestamptz,            -- upstream last-edit timestamp
    src_region_id smallint     NOT NULL REFERENCES coverage_source(id), -- harvest extract (normalized)
    country_code  char(2),                -- stamped from extract config
    region_id     int,                    -- ST_Contains(region.geom, geom) at load; soft ref to region.id (4 bytes: region count never nears int4, saves 4 bytes/row at scale)
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
    # src_region_id is the per-region atomic-swap key (previous-count / DELETE /
    # membership backfills all filter on it), so index it.
    "CREATE INDEX IF NOT EXISTS coverage_poi_src_region_id_idx ON coverage_poi (src_region_id)",
)

# Staging carries every per-row column EXCEPT src_region_id: the whole batch
# shares one source, so load_region resolves the id once (get-or-create) and
# writes it as a constant on the INSERT rather than repeating it per staged row.
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
    country_code char(2)
) ON COMMIT DROP
"""

_STAGING_COLS = "ref, letter, kind, name, geom, tags, osm_version, osm_ts, country_code"


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
    conn.execute(_SOURCE_DDL)
    conn.execute(_TABLE_DDL)
    for stmt in _INDEX_DDL:
        conn.execute(stmt)
    conn.commit()


def load_region(
    conn: psycopg.Connection,
    rows: Iterable[PoiRow],
    src_region: str,
    country_code: str | None = None,
) -> LoadResult:
    """Atomically replace one region's slice of coverage_poi.

    COPY into a same-shape TEMP staging table, drift-check against the previous
    run, then in the same transaction: DELETE the src_region slice, INSERT the
    staging rows, and backfill region_id via ST_Contains over region polygons
    (coverage-provider.md §3 step 4). A DriftAbort (or any error) rolls
    the whole swap back, keeping the last good slice.
    """
    with conn.transaction():
        with conn.cursor() as cur:
            # Resolve the extract slug to its coverage_source id, self-filling the
            # lookup on first sight (get-or-create). ON CONFLICT DO NOTHING keeps
            # the id stable across weekly reloads of the same region; the SELECT
            # then always returns it whether it was just inserted or already there.
            cur.execute(
                "INSERT INTO coverage_source (slug) VALUES (%s) ON CONFLICT (slug) DO NOTHING",
                (src_region,),
            )
            src_id = cur.execute(
                "SELECT id FROM coverage_source WHERE slug = %s", (src_region,)
            ).fetchone()[0]
            # Rows this source currently owns. Ownership is now decided by geometry,
            # not by which extract ran last (2026-07-23-border-overlap-ownership-design.md
            # §3, C1 final-review fix), so this count no longer flaps week to week from
            # a neighbour reclaiming shared border rows — it is a stable baseline for
            # the drift guard below, which is exactly what the design set out to fix
            # (design §2.2).
            previous = cur.execute(
                "SELECT count(*) FROM coverage_poi WHERE src_region_id = %s", (src_id,)
            ).fetchone()[0]
            cur.execute(_STAGING_DDL)
            with cur.copy(f"COPY coverage_poi_staging ({_STAGING_COLS}) FROM STDIN") as copy:
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
                        row.country_code,
                    ))
            # Ownership by geometry, not by write order
            # (2026-07-23-border-overlap-ownership-design.md §3). Nearest-region-wins
            # (final-review C1): find the SINGLE closest region to the row, across ALL
            # onboarded countries, not just the extract's own — containment (distance 0)
            # always wins, so decision 2's BOUNDARY_SNAP_DEG rescue is preserved exactly.
            # This extract keeps the row only if that nearest region's country is its
            # own; every other extract's run deletes it. That makes the predicate
            # mutually exclusive: a row within the snap of BOTH its own and a
            # neighbour's region (Geofabrik's overlap buffer is 0.1026 deg, 10x the
            # 0.01 deg snap, so this band is not an edge case) used to pass both
            # extracts' independent "is a region of MY country within range" checks and
            # fall back to ON CONFLICT / last-writer-wins — the C1 defect this replaces.
            # Two classes are deleted here:
            #   * a border entity whose nearest region belongs to a NEIGHBOURING extract.
            #     Geofabrik's cuts overlap, so one entity arrives in several extracts;
            #     ON CONFLICT below used to hand it to whoever ran last (319 rows were
            #     mis-owned). Now exactly one extract ever stages it.
            #   * a row with no region within BOUNDARY_SNAP_DEG of ANY onboarded country
            #     (decision 1: COALESCE(..., '') never equals a real two-letter cc). All
            #     380 measured today are foreign or offshore — Czech/Austrian viewpoints,
            #     the Wadden Sea, France, Luxembourg — not "in the country but outside
            #     every province", which the snap already rescues. Onboarding those
            #     countries re-harvests them WITH correct region stamps.
            # `r.geom && ST_Expand(s.geom, %(snap)s)` is a lossless bbox prefilter (C2):
            # without it the 16-row `region` table is seq-scanned and ST_DWithin runs
            # full-detail Bundesland polygon math ~7x per staged row (~17 min on
            # Germany); the bbox makes the GiST index on region.geom usable and the
            # bounding condition is a necessary (never over-eager) precondition of
            # ST_DWithin, so no rows are lost.
            # `staged` is captured BEFORE the filter and `inserted` after, so a
            # DriftAbort (or the printed summary in run.py) can tell "the extract really
            # shrank" apart from "the ownership filter did its job" (I3) — both used to
            # collapse into one number, which points an operator at the wrong cause.
            # dev/fixture has no configured country; it keeps the old unfiltered
            # behaviour so the offline fixture path still works.
            staged = cur.execute("SELECT count(*) FROM coverage_poi_staging").fetchone()[0]
            if country_code is None:
                print(f"[coverage] {src_region}: no configured country — "
                      "ownership filter skipped", file=sys.stderr)
            else:
                # I2 guard: an unseeded/mid-reseed `region` table for this country would
                # otherwise make the filter below delete every staged row silently. With
                # previous > 0 the drift guard below would catch that; with previous == 0
                # (a brand-new extract — exactly the onboarding case) nothing else would.
                has_regions = cur.execute(
                    "SELECT count(*) FROM region WHERE country_code = %s", (country_code,)
                ).fetchone()[0]
                if has_regions == 0:
                    raise RuntimeError(
                        f"{src_region}: no `region` rows for country {country_code!r} — "
                        "the ownership filter would drop every staged row. Run region "
                        "onboarding step 5 (region seeding) for this country before "
                        "loading coverage (tools/divisions/README.md)."
                    )
                # This guard only covers THIS extract's country, and deliberately so.
                # Nearest-wins reads every onboarded country's regions, so a NEIGHBOUR
                # whose regions are missing or mid-reseed makes this extract win border
                # rows it would normally cede. That direction over-claims rather than
                # drops, so nothing here can silently empty a slice — it self-corrects
                # on the neighbour's next run once its regions are back.
                cur.execute(
                    """
                    DELETE FROM coverage_poi_staging s
                    WHERE COALESCE((
                        SELECT r.country_code FROM region r
                        WHERE r.geom && ST_Expand(s.geom, %(snap)s)
                          AND ST_DWithin(r.geom, s.geom, %(snap)s)
                          -- region.country_code is NOT NULL DEFAULT '', so a
                          -- cc-less region is possible. It must not be able to WIN
                          -- the owner slot: COALESCE(...,'') below cannot tell ''
                          -- apart from "no owner", so such a region would silently
                          -- delete the row from EVERY extract. Under the previous
                          -- `r.country_code = <cc>` predicate a cc-less region was
                          -- inert; under nearest-wins it is not. Latent today
                          -- (BE 3 / DE 16 / NL 12 regions, none empty).
                          AND r.country_code <> ''
                        ORDER BY ST_Distance(r.geom, s.geom), r.area_km2 ASC NULLS LAST, r.id ASC
                        LIMIT 1
                    ), '') <> %(cc)s
                    """,
                    {"snap": BOUNDARY_SNAP_DEG, "cc": country_code},
                )
            inserted = cur.execute("SELECT count(*) FROM coverage_poi_staging").fetchone()[0]
            if previous > 0 and inserted < previous * (1 - DRIFT_ABORT_RATIO):
                raise DriftAbort(
                    f"{src_region}: {staged} rows staged, {inserted} after the ownership "
                    f"filter, vs {previous} previously (more than {DRIFT_ABORT_RATIO:.0%} "
                    "drop) — aborting swap, keeping last good slice."
                )
            # Diff-merge (2026-07-24-coverage-harvest-prod-safety-design.md §3.3):
            # write only the delta instead of DELETE-all-slice + INSERT-all-slice.
            # OSM week-over-week churn is a few hundred–few thousand rows out of
            # ~318k, so this collapses the per-country transaction from minutes to
            # sub-second and stops the weekly WAL burst / autovacuum bloat (retires
            # the VACUUM FULL question).
            #
            # UPSERT arm: an unchanged, same-owner row fails the IS DISTINCT FROM
            # guard → no heap write, no WAL. A changed row or an ownership-takeover
            # (src_region_id flip — Geofabrik extracts overlap at shared borders,
            # 203 refs shared BE↔NL) updates and resets region_id = NULL so the
            # membership steps below re-derive it: the ST_Contains recompute only
            # SETs region_id for rows inside a polygon and never clears a stale one,
            # and the boundary-snap is gated on region_id IS NULL, so a reclaimed
            # boundary-miss row that kept the previous owner's region_id would skip
            # the snap and get its cc re-stamped from the wrong region (region ⇒ cc
            # self-consistent but wrong). Conflicts are staging-vs-table (one row per
            # (ref, letter) per extract), so DO UPDATE never hits "affect a row a
            # second time"; an intra-batch dup fails loud and rolls the swap back —
            # the generic-error guarantee.
            cur.execute(
                f"INSERT INTO coverage_poi ({_STAGING_COLS}, src_region_id) "
                f"SELECT {_STAGING_COLS}, %s FROM coverage_poi_staging "
                f"ON CONFLICT (ref, letter) DO UPDATE SET "
                f"kind = EXCLUDED.kind, name = EXCLUDED.name, geom = EXCLUDED.geom, "
                f"tags = EXCLUDED.tags, osm_version = EXCLUDED.osm_version, "
                f"osm_ts = EXCLUDED.osm_ts, src_region_id = EXCLUDED.src_region_id, "
                f"country_code = EXCLUDED.country_code, region_id = NULL "
                f"WHERE coverage_poi.geom          IS DISTINCT FROM EXCLUDED.geom "
                f"   OR coverage_poi.name          IS DISTINCT FROM EXCLUDED.name "
                f"   OR coverage_poi.kind          IS DISTINCT FROM EXCLUDED.kind "
                f"   OR coverage_poi.tags          IS DISTINCT FROM EXCLUDED.tags "
                f"   OR coverage_poi.osm_version   IS DISTINCT FROM EXCLUDED.osm_version "
                f"   OR coverage_poi.osm_ts        IS DISTINCT FROM EXCLUDED.osm_ts "
                f"   OR coverage_poi.country_code  IS DISTINCT FROM EXCLUDED.country_code "
                f"   OR coverage_poi.src_region_id IS DISTINCT FROM EXCLUDED.src_region_id",
                (src_id,),
            )
            # Delete-disappeared arm: rows this extract owned but no longer carries.
            # Scoped to THIS src_region's slice only (never a neighbour's), so a
            # non-owning extract can neither delete nor resurrect another's row
            # (2026-07-23-border-overlap-ownership-design.md §4). geom comparison
            # above uses PostGIS's `=` operator; for POINT geometries (every
            # coverage row) that is exact coordinate equality (a point's bbox is
            # the point itself), and the deterministic POINT(lon lat) EWKT yields
            # identical geometry across weeks, so an unchanged point compares equal.
            cur.execute(
                "DELETE FROM coverage_poi c WHERE c.src_region_id = %s "
                "AND NOT EXISTS (SELECT 1 FROM coverage_poi_staging s "
                "WHERE s.ref = c.ref AND s.letter = c.letter)",
                (src_id,),
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
                    WHERE c.src_region_id = %s
                    ORDER BY c.id, r.area_km2 ASC NULLS LAST, r.id ASC
                ) m
                WHERE coverage_poi.id = m.poi_id
                """,
                (src_id,),
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
                    WHERE c.src_region_id = %s
                      AND c.region_id IS NULL
                      AND c.country_code IS NOT NULL
                    ORDER BY c.id, ST_Distance(r.geom, c.geom),
                             r.area_km2 ASC NULLS LAST, r.id ASC
                ) m
                WHERE coverage_poi.id = m.poi_id
                """,
                (BOUNDARY_SNAP_DEG, src_id),
            )
            # region ⇒ cc invariant (region-scoping-design.md §8 risk 10, finding
            # 8): the controller's 24-region cap is only safe if every
            # region-stamped row also carries cc (the cc arm is the completeness
            # net when the rid list truncates). country_code is AUTHORITATIVE from
            # the region, not the extract: a stamped row takes its region's cc
            # whenever they differ — this both backfills an extract NULL (~10 rows
            # in dev) AND corrects a border entity that a neighbouring extract
            # stamped with the wrong country (an NL-extract row that ST_Contains
            # placed in a BE region must read BE, not NL). Guarded on
            # r.country_code IS NOT NULL so a cc-less region never wipes a POI cc.
            cur.execute(
                """
                UPDATE coverage_poi SET country_code = r.country_code
                FROM region r
                WHERE coverage_poi.region_id = r.id
                  AND r.country_code IS NOT NULL
                  AND coverage_poi.country_code IS DISTINCT FROM r.country_code
                  AND coverage_poi.src_region_id = %s
                """,
                (src_id,),
            )
    return LoadResult(inserted=inserted, previous=previous)
