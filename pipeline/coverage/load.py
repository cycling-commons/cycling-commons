# SPDX-License-Identifier: AGPL-3.0-only
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
from dataclasses import dataclass, field

import psycopg
from psycopg import sql

from coverage.parse import PoiRow
from coverage.tracker import ensure_tracker_schema

# Abort the swap when the new row count drops more than 40 % below the previous
# run for the same region — a truncated download/filter must not wipe a region.
DRIFT_ABORT_RATIO = 0.4

# Degrees (SRID 4326) a region-less POI may sit outside every region polygon and
# still snap to the nearest region of its own country (map-and-search.md §4.5
# §6, finding 5). ~0.01° ≈ 1.1 km at Belgian latitudes — wide enough for
# polygon-simplification gaps, tight enough that a genuine outside-coverage point
# stays unstamped. That 1.1 km figure is NORTH-SOUTH only, where a degree of
# latitude is ~constant; east-west a degree of longitude shrinks with cos(lat),
# so the same 0.01° is only ~0.70 km at 50°N and ~0.64 km at 54.8°N. Now that
# the ownership filter (C1, coverage-provider.md §1)
# leans on this constant too, that anisotropy is worth naming here, not just at
# the call sites.
BOUNDARY_SNAP_DEG = 0.01

# Which country each Geofabrik extract is configured to cover. Lives here rather
# than in run.py because it is now load-time DATA SEMANTICS, not orchestration:
# it decides which staged rows this extract OWNS,
# not merely what to stamp.
COUNTRY_BY_REGION = {
    "europe/belgium": "BE", "europe/netherlands": "NL", "europe/germany": "DE",
    "europe/luxembourg": "LU",
    # 2026-08-06 rollout.
    "europe/france": "FR", "europe/switzerland": "CH", "europe/italy": "IT",
    "europe/great-britain": "GB",
    # Northern Ireland has no extract of its own — Geofabrik ships it inside the
    # all-Ireland one, which also covers the Republic. Ireland is NOT onboarded,
    # so nearest-region-wins deletes Republic rows for having no onboarded region
    # within BOUNDARY_SNAP_DEG, EXCEPT in the ~1 km band along the border, where
    # the snap hands them to northern-ireland. That band is mis-stamped GB until
    # Ireland is onboarded, which re-harvests it with correct region stamps.
    "europe/ireland-and-northern-ireland": "GB",
    "australia-oceania/australia": "AU",
    "asia/japan": "JP",
    # State-level onboarding: only the two seeded states, not a north-america/us
    # ancestor, so an unonboarded state's extract still hard-fails resolve_country
    # instead of silently harvesting as US.
    "north-america/us/california": "US", "north-america/us/colorado": "US",
    # 2026-08-08 rollout. The Geofabrik extract covers the mainland, the
    # Balearics and the Canaries — the same three areas the ES bbox spans.
    "europe/spain": "ES",
    # ——— 2026-08-14 rollout ———
    "europe/slovenia": "SI",
    "africa/rwanda": "RW",
    # Geofabrik's South Africa extract BUNDLES Lesotho and Eswatini, the same
    # shared-extract case as Northern Ireland: neither is onboarded, so
    # nearest-region-wins deletes their rows for having no onboarded region
    # within BOUNDARY_SNAP_DEG — except in the ~1 km band along the borders,
    # which is mis-stamped ZA until one of them is onboarded. Lesotho is
    # entirely enclosed by South Africa, so its band is its whole perimeter.
    "africa/south-africa": "ZA",
    "south-america/colombia": "CO",
    "south-america/chile": "CL",
    "australia-oceania/new-zealand": "NZ",
    # Province-level onboarding, like the two US states: only the provinces
    # actually seeded, so an unonboarded province's extract still hard-fails
    # resolve_country instead of silently harvesting as CA.
    "north-america/canada/british-columbia": "CA",
    "north-america/canada/quebec": "CA",
}

# Per-session resource budget applied to the harvest connection at startup.
# Every value is an
# env knob with a conservative default; maintenance_work_mem × (1 + parallel
# workers) is the RAM term that can OOM a co-tenant on the shared DB host, so
# these are SET SESSION-only (never ALTER SYSTEM) and load-bearing, not tuning.
_SESSION_BUDGET = (
    # 60min, not 10. Production onboards a country unattended, on a timer, with
    # nobody watching the output — so the default has to be large enough for the
    # largest country anyone will onboard, or the job simply fails at 3 a.m. and
    # the region silently has no coverage. europe/spain failed at 10min on
    # 2026-08-08, exactly as europe/germany, europe/france and europe/italy had
    # during the 08-06 rollout (see load_region below for WHY the big ones are
    # slow). Raising it does not make a runaway query safe — it makes an honest
    # one possible; the runaway is bounded by the advisory lock (one harvest at
    # a time) and lock_timeout (5s, so it never queues behind anything).
    ("statement_timeout", "COVERAGE_STATEMENT_TIMEOUT", "60min"),
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
    since C1 an unresolved
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
        "; dev/fixture is "
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
    ref           varchar(160) NOT NULL,  -- 'node/6863042080' | 'way/…' = item.source_ref format
    letter        char(1)      NOT NULL,  -- B C D F G O P Q (osm-data-architecture.md §5)
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
    # CONCURRENTLY so an absent index never takes a blocking lock mid-harvest on
    # the shared prod cluster (design §3.6); requires autocommit (ensure_schema
    # toggles it). IF NOT EXISTS keeps the bootstrap idempotent.
    "CREATE INDEX CONCURRENTLY IF NOT EXISTS coverage_poi_geom_idx ON coverage_poi USING gist (geom)",
    # Every radius query the app runs casts to geography
    # (ST_DWithin(cp.geom::geography, ...): OsmLinker candidates, /map/coverage/nearby),
    # and a geometry GiST index cannot serve a geography predicate, so the
    # planner fell back to the letter index and measured 375k water POIs per
    # queue card (2.8 s each on /moderate, owner-reported 2026-08-25). A
    # functional index on the cast is what those predicates match: 716 ms -> 2 ms.
    "CREATE INDEX CONCURRENTLY IF NOT EXISTS coverage_poi_geog_idx ON coverage_poi USING gist ((geom::geography))",
    "CREATE INDEX CONCURRENTLY IF NOT EXISTS coverage_poi_letter_idx ON coverage_poi (letter)",
    "CREATE INDEX CONCURRENTLY IF NOT EXISTS coverage_poi_region_id_idx ON coverage_poi (region_id)",
    # country_code arm of /map/coverage/search|nearby|counts (map-and-search.md §4.5):
    # this table is pipeline-owned, so its index lands here, not in web/migrations.
    "CREATE INDEX CONCURRENTLY IF NOT EXISTS coverage_poi_country_code_idx ON coverage_poi (country_code)",
    "CREATE INDEX CONCURRENTLY IF NOT EXISTS coverage_poi_name_trgm_idx ON coverage_poi USING gin (name gin_trgm_ops)",
    # src_region_id is the per-region atomic-swap key (previous-count / DELETE /
    # membership backfills all filter on it), so index it.
    "CREATE INDEX CONCURRENTLY IF NOT EXISTS coverage_poi_src_region_id_idx ON coverage_poi (src_region_id)",
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
    # Per-rule drop counts, non-zero ones only, keyed by a short rule label
    # (`name:P`, `exclude:Q:memorial`, `near_way`): coverage-runs-admin.md §3.
    dropped: dict[str, int] = field(default_factory=dict)


def ensure_schema(conn: psycopg.Connection) -> None:
    """Idempotent bootstrap: pg_trgm guard + coverage_poi table and indexes.

    The privileged CREATE EXTENSION is gated behind COVERAGE_ENSURE_EXTENSION
    (default on; set 0 in prod where devops creates the extension at cluster
    init, so the harvest role needs no superuser). Indexes build CONCURRENTLY —
    an absent index never takes a blocking lock mid-harvest — which requires
    autocommit (CONCURRENTLY cannot run in a txn block). A failed CONCURRENTLY
    build leaves an INVALID index to drop + rebuild; acceptable for a bootstrap
    that only creates indexes on a brand-new cluster (design §3.6)."""
    if os.environ.get("COVERAGE_ENSURE_EXTENSION", "1") != "0":
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
    ensure_tracker_schema(conn)
    # The app keeps per (country, region, letter) counts of this table by
    # trigger (web/migrations/Version20260906180000.php, coverage-provider.md
    # §11). The function lives on the app side and is a no-op until that
    # migration has run, so a pipeline older or newer than the app is fine;
    # once both are there the triggers are installed here, because this is
    # where the table is created and the app cannot know when that happens.
    conn.execute(
        "DO $$ BEGIN IF to_regproc('coverage_count_install') IS NOT NULL THEN "
        "PERFORM coverage_count_install(); END IF; END $$"
    )
    conn.commit()
    # CREATE INDEX CONCURRENTLY cannot run inside a transaction block, so run the
    # index loop in autocommit (each stmt its own txn) and restore after.
    prev_autocommit = conn.autocommit
    conn.autocommit = True
    try:
        for stmt in _INDEX_DDL:
            conn.execute(stmt)
    finally:
        conn.autocommit = prev_autocommit


def _materialize_operational_regions(cur) -> None:
    """Build the per-transaction `region_operational` set every spatial step reads.

    THE RULE is web/src/Catalog/OperationalRegions.php's, restated: a region is
    operational iff its admin_level equals the deepest onboarded level for its
    country. `IS NOT DISTINCT FROM` keeps a single-row country whose admin_level
    is NULL operational, exactly as the PHP predicate does. The two are the same
    rule in two languages and must be changed together.

    **Correctness.** coverage_poi.region_id is read by a client whose region
    registry is itself filtered to operational regions
    (RegionRegistryProvider). Stamping a POI with an infrastructure region — the
    level-2 country outline the 2+4 playbook emits — hands the client an id it
    has never heard of, and the POI then renders under no scope at all. The
    outlines could never WIN a fully subdivided country (they tie at distance 0
    with their own subdivisions and lose the area_km2 tie-break), which is why
    this went unnoticed; a PARTLY onboarded country breaks the tie. Only
    California and Colorado are seeded, so `united-states` claims every US POI
    outside them, and `luxembourg` — operational, being its country's only
    level — correctly stays.

    **Cost.** This is also why the 2026-08-06 rollout made europe/germany,
    europe/france and europe/italy exceed the then-10-minute
    COVERAGE_STATEMENT_TIMEOUT (europe/spain joined them on 08-08, which is why
    the default is now 60min — an unattended production run must not need a
    human to pass a bigger number). The US
    outline spans 358.9 degrees of longitude — Alaska crosses the antimeridian
    and the territories reach into the Pacific — so its bounding box overlaps
    EVERY point on earth and the GiST prefilter these queries depend on stops
    pruning it. Each staged row then ran full-detail ST_DWithin/ST_Contains over
    a 136,302-vertex multipolygon. Measured on one German point: 10.6 ms against
    all 162 regions, 2.0 ms once the two outlines are gone.

    A TEMP table rather than an inline predicate on purpose: these run as
    CORRELATED subqueries, once per staged row, so an inline
    `MAX(admin_level)` lookup would re-derive the whole set per row. A CTE
    measured SLOWER than no filter at all (23 ms) because it forfeits the index.
    """
    cur.execute(
        """
        CREATE TEMP TABLE region_operational ON COMMIT DROP AS
        SELECT r.id, r.geom, r.country_code, r.area_km2
        FROM region r
        WHERE r.geom IS NOT NULL
          AND r.admin_level IS NOT DISTINCT FROM (
              SELECT MAX(r2.admin_level) FROM region r2
              WHERE r2.country_code = r.country_code
          )
        """
    )
    # Without its own index the copy is seq-scanned per row and the fix is
    # undone; without ANALYZE the planner sizes it from defaults, not 120 rows.
    cur.execute("CREATE INDEX ON region_operational USING gist (geom)")
    cur.execute("ANALYZE region_operational")


NEAR_WAY_CELL_DEG = 0.01   # grid cell for choosing which ways to copy in (~0.7-1.1 km)


def _apply_near_ways(cur, within: dict[str, float], lines: Iterable[list[tuple[float, float]]]) -> int:
    """Delete staged points of the given letters with no bike way within their range.

    docs/specs/scenic-views.md: a scenic point stays only along a way a bike may
    ride. Only ways with a vertex within two grid cells of a staged point of
    those letters are copied in, so a country's whole road network never lands
    in the transaction; two cells (at least ~700 m) is far wider than any range.
    """
    letters = list(within)
    cells = {
        (int(lon // NEAR_WAY_CELL_DEG), int(lat // NEAR_WAY_CELL_DEG))
        for lon, lat in cur.execute(
            "SELECT ST_X(geom), ST_Y(geom) FROM coverage_poi_staging WHERE letter = ANY(%s)", (letters,)
        ).fetchall()
    }
    if not cells:
        return 0
    near = {(cx + dx, cy + dy) for cx, cy in cells for dx in range(-2, 3) for dy in range(-2, 3)}
    cur.execute("CREATE TEMP TABLE coverage_near_way (geom geometry(LineString, 4326)) ON COMMIT DROP")
    with cur.copy("COPY coverage_near_way (geom) FROM STDIN") as copy:
        for line in lines:
            if len(line) < 2:
                continue
            if any((int(lon // NEAR_WAY_CELL_DEG), int(lat // NEAR_WAY_CELL_DEG)) in near for lon, lat in line):
                copy.write_row(("SRID=4326;LINESTRING(" + ",".join(f"{lon} {lat}" for lon, lat in line) + ")",))
    cur.execute("CREATE INDEX ON coverage_near_way USING gist (geom)")
    cur.execute("ANALYZE coverage_near_way")
    dropped = 0
    for letter, metres in within.items():
        # The bbox pre-filter uses the widest degrees a metre can be (at 72 deg latitude),
        # so it never excludes a way ST_DWithin would have counted.
        dropped += cur.execute(
            """
            DELETE FROM coverage_poi_staging s
            WHERE s.letter = %(letter)s
              AND NOT EXISTS (
                SELECT 1 FROM coverage_near_way w
                WHERE w.geom && ST_Expand(s.geom, %(deg)s)
                  AND ST_DWithin(w.geom::geography, s.geom::geography, %(m)s)
              )
            """,
            {"letter": letter, "m": metres, "deg": metres / 111320.0 / 0.309},
        ).rowcount
    return dropped


def load_region(
    conn: psycopg.Connection,
    rows: Iterable[PoiRow],
    src_region: str,
    country_code: str | None = None,
    near_ways: tuple[dict[str, float], Iterable[list[tuple[float, float]]]] | None = None,
    name_or_tags: dict[str, list[str]] | None = None,
    exclude_tag_values: dict[str, dict[str, list[str]]] | None = None,
) -> LoadResult:
    """Atomically merge one region's slice of coverage_poi.

    COPY into a same-shape TEMP staging table, drop the staged rows this extract
    does not own (nearest-region-wins), drift-check against the previous run,
    then in the same transaction: UPSERT the staging rows on (ref, letter) with
    an IS DISTINCT FROM guard so an unchanged row is never rewritten, DELETE the
    rows that have vanished from this extract's own slice, and backfill
    region_id via ST_Contains over region polygons (coverage-provider.md §3
    step 4). A DriftAbort (or any error) rolls the whole merge back, keeping the
    last good slice.
    """
    dropped_by_rule: dict[str, int] = {}
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
            _materialize_operational_regions(cur)
            # Rows this source currently owns. Ownership is now decided by geometry,
            # not by which extract ran last (coverage-provider.md §1
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
            # Ownership by geometry, not by write order.
            # Nearest-region-wins
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
                        SELECT r.country_code FROM region_operational r
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
            for letter, keys in (name_or_tags or {}).items():
                # docs/specs/scenic-views.md §2: no name and no photo link says nothing a rider can use.
                bare = cur.execute(
                    "DELETE FROM coverage_poi_staging WHERE letter = %s AND COALESCE(name, '') = '' "
                    "AND NOT jsonb_exists_any(tags, %s)",
                    (letter, keys),
                ).rowcount
                if bare:
                    dropped_by_rule[f"name:{letter}"] = bare
                    print(f"[coverage] {src_region}: {letter} needs a name or one of {keys}: dropped "
                          f"{bare} staged point(s) with neither", file=sys.stderr)
            for letter, rules in (exclude_tag_values or {}).items():
                for key, values in rules.items():
                    # docs/specs/coverage-provider.md §3: a point goes only when
                    # every one of its `;`-separated values is excluded, so a
                    # "plaque;statue" memorial stays for its statue.
                    small = cur.execute(
                        "DELETE FROM coverage_poi_staging s WHERE s.letter = %s "
                        "AND btrim(COALESCE(s.tags ->> %s, '')) <> '' "
                        "AND NOT EXISTS (SELECT 1 FROM unnest(string_to_array(s.tags ->> %s, ';')) v "
                        "WHERE btrim(v) <> ALL(%s))",
                        (letter, key, key, values),
                    ).rowcount
                    if small:
                        dropped_by_rule[f"exclude:{letter}:{key}"] = small
                        print(f"[coverage] {src_region}: {letter} leaves out {key}={values}: dropped "
                              f"{small} staged point(s)", file=sys.stderr)
            if near_ways is not None:
                dropped = _apply_near_ways(cur, *near_ways)
                if dropped:
                    dropped_by_rule["near_way"] = dropped
                    print(f"[coverage] {src_region}: near-way rule dropped {dropped} staged "
                          "point(s) with no bike way in range", file=sys.stderr)
            inserted = cur.execute("SELECT count(*) FROM coverage_poi_staging").fetchone()[0]
            # The guard is for a broken extract (a truncated download, a failed
            # filter), which shrinks every letter. A letter a contract rule
            # filters (nameOrTags, excludeTagValues, nearWay) may shrink as far
            # as the rule takes it, even to nothing, so the guard counts only
            # the other letters (docs/specs/scenic-views.md §2, coverage-provider.md §3).
            ruled = sorted(set(name_or_tags or {}) | set(exclude_tag_values or {})
                           | set((near_ways or ({}, None))[0]))
            guarded_previous = cur.execute(
                "SELECT count(*) FROM coverage_poi WHERE src_region_id = %s AND NOT (letter = ANY(%s))",
                (src_id, ruled),
            ).fetchone()[0]
            guarded_inserted = cur.execute(
                "SELECT count(*) FROM coverage_poi_staging WHERE NOT (letter = ANY(%s))", (ruled,)
            ).fetchone()[0]
            if guarded_previous > 0 and guarded_inserted < guarded_previous * (1 - DRIFT_ABORT_RATIO):
                raise DriftAbort(
                    f"{src_region}: {staged} rows staged, {inserted} after the ownership "
                    f"filter, vs {previous} previously; {guarded_inserted} vs {guarded_previous} "
                    f"outside rule-filtered letters {ruled} (more than {DRIFT_ABORT_RATIO:.0%} "
                    "drop) — aborting swap, keeping last good slice."
                )
            # Diff-merge:
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
            # Capture the ids the upsert touched (design §3.4) so the membership
            # recompute below runs on the delta only, not the whole slice —
            # otherwise the 3 membership UPDATEs would rewrite every region_id each
            # week and undo the diff-merge's WAL savings. Postgres has no
            # RETURNING INTO outside PL/pgSQL, so a data-modifying CTE feeds the
            # ids into a TEMP table dropped with the transaction.
            cur.execute("CREATE TEMP TABLE coverage_touched (id bigint) ON COMMIT DROP")
            cur.execute(
                f"WITH up AS ("
                f"  INSERT INTO coverage_poi ({_STAGING_COLS}, src_region_id) "
                f"  SELECT {_STAGING_COLS}, %s FROM coverage_poi_staging "
                f"  ON CONFLICT (ref, letter) DO UPDATE SET "
                f"  kind = EXCLUDED.kind, name = EXCLUDED.name, geom = EXCLUDED.geom, "
                f"  tags = EXCLUDED.tags, osm_version = EXCLUDED.osm_version, "
                f"  osm_ts = EXCLUDED.osm_ts, src_region_id = EXCLUDED.src_region_id, "
                f"  country_code = EXCLUDED.country_code, region_id = NULL "
                f"  WHERE coverage_poi.geom          IS DISTINCT FROM EXCLUDED.geom "
                f"     OR coverage_poi.name          IS DISTINCT FROM EXCLUDED.name "
                f"     OR coverage_poi.kind          IS DISTINCT FROM EXCLUDED.kind "
                f"     OR coverage_poi.tags          IS DISTINCT FROM EXCLUDED.tags "
                f"     OR coverage_poi.osm_version   IS DISTINCT FROM EXCLUDED.osm_version "
                f"     OR coverage_poi.osm_ts        IS DISTINCT FROM EXCLUDED.osm_ts "
                f"     OR coverage_poi.country_code  IS DISTINCT FROM EXCLUDED.country_code "
                f"     OR coverage_poi.src_region_id IS DISTINCT FROM EXCLUDED.src_region_id "
                f"  RETURNING id"
                f") INSERT INTO coverage_touched (id) SELECT id FROM up",
                (src_id,),
            )
            # Delete-disappeared arm: rows this extract owned but no longer carries.
            # Scoped to THIS src_region's slice only (never a neighbour's), so a
            # non-owning extract can neither delete nor resurrect another's row.
            # geom comparison
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
            # The app stores each open row's OSM-candidate list on the row
            # (item.osm_candidates, catalog-data-model.md §5b) instead of asking
            # this table on every list view. This slice just changed, so every
            # open row of the same country gets its list cleared; the app
            # recomputes on the next read. Answered rows (osm_checked_at set)
            # are left alone. to_regclass: the pipeline's own test schema has no
            # item table, and a harvest must not depend on the app's schema.
            cur.execute(
                "DO $$ BEGIN "
                "IF to_regclass('item') IS NOT NULL THEN "
                "  UPDATE item SET osm_candidates = NULL, osm_candidates_at = NULL "
                "   WHERE osm_checked_at IS NULL AND osm_candidates_at IS NOT NULL "
                "     AND country_code IN (SELECT DISTINCT country_code FROM coverage_poi_staging "
                "                          WHERE country_code IS NOT NULL); "
                "END IF; END $$"
            )
            # Delta-scoped membership (design §3.4): restrict the 3 recompute
            # UPDATEs to rows the upsert touched, so unchanged rows keep last
            # week's region_id/cc (correct while the `region` table is unchanged)
            # and the diff-merge's write savings survive. COVERAGE_FULL_MEMBERSHIP=1
            # falls back to today's whole-slice recompute — run it after a `region`
            # change (new country / new-or-changed subdivisions), where an
            # unchanged row's membership can legitimately shift. In full mode the
            # injected clauses are empty, so the behaviour is byte-identical to the
            # pre-delta code.
            full_membership = os.environ.get("COVERAGE_FULL_MEMBERSHIP") == "1"
            touched_c = "" if full_membership else " AND c.id IN (SELECT id FROM coverage_touched)"
            touched_poi = ("" if full_membership
                           else " AND coverage_poi.id IN (SELECT id FROM coverage_touched)")
            # Drop a stamp that points at a region which is no longer
            # operational, so the two recomputes below can re-derive it. Neither
            # of them can do this on its own: the ST_Contains pass only SETs
            # region_id for rows inside a polygon and never clears a stale one,
            # and the boundary-snap is gated on `region_id IS NULL` — so a row
            # already holding an infrastructure id is skipped by both and keeps
            # it forever. The diff-merge's `region_id = NULL` reset does not
            # cover it either, since an OSM-unchanged row is never rewritten.
            #
            # This is the repair path for the 2026-08-06 rollout, which stamped
            # 250 POIs (JP 89, US 77, AU 52, CH 26, GB 6) with level-2 country
            # outlines via the boundary-snap — coastlines and islands outside
            # every subdivision but within the snap of the country polygon. The
            # client's region registry is itself filtered to operational
            # regions, so those ids resolve to nothing and the POI renders under
            # no scope at all.
            #
            # It is also the general path for a country onboarding a FINER level:
            # its previous operating level demotes to infrastructure the moment
            # the finer rows land (tools/divisions/README.md), and every POI
            # stamped with the old level has to be re-derived. Run with
            # COVERAGE_FULL_MEMBERSHIP=1 after any such change, or only the
            # rows OSM happened to touch get repaired.
            cur.execute(
                f"""
                UPDATE coverage_poi SET region_id = NULL
                WHERE coverage_poi.src_region_id = %s
                  AND coverage_poi.region_id IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM region_operational o
                      WHERE o.id = coverage_poi.region_id
                  ){touched_poi}
                """,
                (src_id,),
            )
            # Smallest-area-wins on overlap (map-and-search.md §4.5): the
            # third membership writer besides RegionResolver and
            # ImportCatalogCommand::recomputeMembership. DISTINCT ON keeps one
            # region per POI, ordered by area then id, so the stamp is
            # deterministic once regions multiply past the Wallonia seed. Only
            # this slice's freshly-inserted rows are candidates; POIs in no
            # region keep the NULL they were inserted with.
            cur.execute(
                f"""
                UPDATE coverage_poi SET region_id = m.region_id
                FROM (
                    SELECT DISTINCT ON (c.id) c.id AS poi_id, r.id AS region_id
                    FROM coverage_poi c
                    JOIN region_operational r ON ST_Contains(r.geom, c.geom)
                    WHERE c.src_region_id = %s{touched_c}
                    ORDER BY c.id, r.area_km2 ASC NULLS LAST, r.id ASC
                ) m
                WHERE coverage_poi.id = m.poi_id
                """,
                (src_id,),
            )
            # Boundary-miss rescue (map-and-search.md §4.5, finding 5): a POI
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
                f"""
                UPDATE coverage_poi SET region_id = m.region_id
                FROM (
                    SELECT DISTINCT ON (c.id) c.id AS poi_id, r.id AS region_id
                    FROM coverage_poi c
                    JOIN region_operational r ON r.country_code = c.country_code
                                 AND ST_DWithin(r.geom, c.geom, %s)
                    WHERE c.src_region_id = %s
                      AND c.region_id IS NULL
                      AND c.country_code IS NOT NULL{touched_c}
                    ORDER BY c.id, ST_Distance(r.geom, c.geom),
                             r.area_km2 ASC NULLS LAST, r.id ASC
                ) m
                WHERE coverage_poi.id = m.poi_id
                """,
                (BOUNDARY_SNAP_DEG, src_id),
            )
            # region ⇒ cc invariant (map-and-search.md §4.5 risk 10, finding
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
                f"""
                UPDATE coverage_poi SET country_code = r.country_code
                FROM region r
                WHERE coverage_poi.region_id = r.id
                  AND r.country_code IS NOT NULL
                  AND coverage_poi.country_code IS DISTINCT FROM r.country_code
                  AND coverage_poi.src_region_id = %s{touched_poi}
                """,
                (src_id,),
            )
    return LoadResult(inserted=inserted, previous=previous, dropped=dropped_by_rule)
