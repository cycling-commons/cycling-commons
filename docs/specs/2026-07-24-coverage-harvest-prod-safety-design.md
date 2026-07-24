# Coverage harvest — prod-safety hardening (code design)

**Status:** design, 2026-07-24. Supersedes the pre-spec analysis in
`docs/plans/github-issues-backlog.md` #9.
**Scope:** `pipeline/coverage/` only (`load.py`, `run.py`, a small `tiles.py`
touch, new tests). **No app/PHP changes.**
**Companion (infra, owned by devops — NOT this spec):** creating the CC
Postgres cluster, the box's RAM re-slice, `CREATE EXTENSION postgis`, the
systemd timer, Redis `maxmemory`. This spec only makes the harvest *live inside
whatever budget devops sets*.

## 1. Problem

The coverage batch was built for a dev box and is operationally unsafe on the
production database. The nine measured gaps are catalogued in
`docs/plans/github-issues-backlog.md` #9; the load-bearing ones:

- **One multi-minute transaction per country** (Germany ≈ 318k rows: COPY →
  DELETE-all-slice → INSERT-all-slice → 3 full membership UPDATEs, all in one
  `conn.transaction()`). Holds the xmin horizon back and churns the whole slice
  every week even though OSM changed little.
- **No session resource limits** — no `statement_timeout`, no memory caps. On a
  RAM-shared box `maintenance_work_mem × parallel workers` can push the host
  into memory pressure and get the OOM killer to kill a co-tenant backend.
- **No advisory lock** — a staggered timer and a manual `make coverage-refresh`
  can overlap and deadlock on the ownership upserts.
- **DDL every run** — `ensure_schema` would build an absent index
  non-`CONCURRENTLY` mid-harvest.
- **Tile export = up to 28 full-table scans** (letters × country_codes),
  evicting the buffer pool.

## 2. Deployment context (why the code target is "fit a fixed budget")

Production is the upstream platform Hetzner cluster: LB, 2× web, **1× DB/Redis
host**, 1× messenger/valhalla worker. Per the accepted infra decision, CC moves
to its **own Postgres cluster on that same DB host** (a second `postgres`
process — separate port, data dir, `shared_buffers`, WAL, autovacuum — *not* a
second database in Upstream's instance). Upstream keeps the original cluster.

Consequence for this code:

- **Logical isolation is free** (separate xmin horizon, buffers, WAL) — CC's
  harvest can no longer stall Upstream's autovacuum or evict Upstream's buffers.
- **Physical RAM is shared** and the Linux OOM killer ignores cluster
  boundaries. So bounding the harvest's memory footprint is what stops CC's
  harvest from OOM-killing Upstream. **This is the reframing of issue #9: keep
  the harvest inside a fixed memory budget.**
- `coverage_poi` and `region` are co-located on CC's cluster, so every spatial
  join in `load_region` stays local and unchanged; the app keeps its single
  `DATABASE_URL` (now CC's cluster). The pipeline points `DATABASE_DSN` at the
  same cluster — already env-driven, no code change.

## 3. Design

Every resource limit is an **env-driven knob the pipeline applies at session
start**, never a hardcoded number and never a global cluster change — devops
picks the numbers, the code enforces the ceiling.

### 3.1 Session resource budget — the spine (gaps: timeouts, OOM safety)

A new `apply_session_budget(conn)` in `load.py`, called by `run.main()`
immediately after `psycopg.connect()` and before `ensure_schema`. It issues
`SET SESSION` statements (this connection only — never `ALTER SYSTEM`/global):

| Setting | Env knob | Default | Purpose |
|---|---|---|---|
| `statement_timeout` | `COVERAGE_STATEMENT_TIMEOUT` | `10min` | no runaway query |
| `lock_timeout` | `COVERAGE_LOCK_TIMEOUT` | `5s` | fail fast on lock waits |
| `idle_in_transaction_session_timeout` | `COVERAGE_IDLE_TXN_TIMEOUT` | `30s` | no held-open txn |
| `synchronous_commit` | `COVERAGE_SYNCHRONOUS_COMMIT` | `off` | safe: rebuildable cache |
| `maintenance_work_mem` | `COVERAGE_MAINTENANCE_WORK_MEM` | `256MB` | bounds index/maintenance RAM |
| `work_mem` | `COVERAGE_WORK_MEM` | `32MB` | bounds per-sort/hash RAM |
| `max_parallel_workers_per_gather` | `COVERAGE_MAX_PARALLEL` | `0` | bounds `× workers` RAM term |

`maintenance_work_mem × (1 + max_parallel_workers_per_gather)` is the memory
term that can OOM a co-tenant; capping it here is load-bearing, not tuning.
Defaults are conservative; devops overrides via the coverage env file. Values
pass through verbatim as PostgreSQL settings (validated by Postgres on `SET`).

### 3.2 Advisory lock — single-run guard (gap: concurrent runs)

`run.main()` takes a **session-level** `pg_try_advisory_lock(COVERAGE_LOCK_KEY)`
(a fixed documented bigint constant) right after applying the session budget. If
it fails, log "another coverage run holds the lock — exiting" and return a
distinct non-zero exit (not a failure that pages: it's a benign overlap). Held
for the whole run, auto-released on disconnect. This makes a staggered per-region
timer and a manual refresh mutually exclusive.

### 3.3 Diff-merge load — write only the delta (gaps: long txn, WAL, onboarding)

Rewrite `load_region`'s swap from **DELETE-all + INSERT-all** to **upsert-changed
+ delete-disappeared**, preserving every existing semantic (atomic swap,
drift-abort, nearest-region ownership, region⇒cc invariant). The staging COPY,
ownership filter, and drift check are **unchanged**. `LoadResult.inserted`
keeps its current meaning — *the resulting slice size* (staging rows after the
ownership filter), not the number of rows physically written — so all existing
assertions hold.

In the (now tiny) transaction, replace the `DELETE … WHERE src_region_id` +
`INSERT … ON CONFLICT` pair with:

1. **Upsert arm** (new + changed + ownership-takeover), capturing touched ids:
   ```sql
   INSERT INTO coverage_poi (<cols>, src_region_id)
   SELECT <cols>, :src_id FROM coverage_poi_staging
   ON CONFLICT (ref, letter) DO UPDATE SET
       kind = EXCLUDED.kind, name = EXCLUDED.name, geom = EXCLUDED.geom,
       tags = EXCLUDED.tags, osm_version = EXCLUDED.osm_version,
       osm_ts = EXCLUDED.osm_ts, country_code = EXCLUDED.country_code,
       src_region_id = EXCLUDED.src_region_id, region_id = NULL
   WHERE coverage_poi.geom          IS DISTINCT FROM EXCLUDED.geom
      OR coverage_poi.name          IS DISTINCT FROM EXCLUDED.name
      OR coverage_poi.kind          IS DISTINCT FROM EXCLUDED.kind
      OR coverage_poi.tags          IS DISTINCT FROM EXCLUDED.tags
      OR coverage_poi.osm_version   IS DISTINCT FROM EXCLUDED.osm_version
      OR coverage_poi.osm_ts        IS DISTINCT FROM EXCLUDED.osm_ts
      OR coverage_poi.country_code  IS DISTINCT FROM EXCLUDED.country_code
      OR coverage_poi.src_region_id IS DISTINCT FROM EXCLUDED.src_region_id
   RETURNING id;
   ```
   Unchanged, same-owner rows fail the `WHERE` → **no heap write, no WAL**. New
   rows insert; changed rows and ownership-takeovers update and reset
   `region_id = NULL` (so the membership recompute re-runs for them — this is
   exactly what `test_load_region_reclaimed_boundary_miss_reevaluates_region`
   pins). The touched ids are captured into a TEMP `coverage_touched (id bigint)
   ON COMMIT DROP` table in the **same statement** via a data-modifying CTE
   (Postgres has no `RETURNING INTO` outside PL/pgSQL):
   ```sql
   WITH up AS ( <the INSERT … ON CONFLICT … RETURNING id above> )
   INSERT INTO coverage_touched (id) SELECT id FROM up;
   ```

   *Kept from today:* an intra-batch duplicate `(ref, letter)` still raises
   `CardinalityViolation` here (ON CONFLICT cannot affect a row twice in one
   command), and any error rolls the whole swap back — the behaviour
   `test_load_region_generic_error_rolls_back_whole_swap` asserts.

2. **Delete-disappeared arm** (scoped to this extract's own slice — never a
   neighbour's, so `test_owner_dropping_the_entity_removes_it` is preserved):
   ```sql
   DELETE FROM coverage_poi c
   WHERE c.src_region_id = :src_id
     AND NOT EXISTS (SELECT 1 FROM coverage_poi_staging s
                     WHERE s.ref = c.ref AND s.letter = c.letter);
   ```

Because OSM week-over-week churn is a few hundred–few thousand rows out of
~318k, the transaction goes sub-second, the WAL burst collapses, autovacuum
bloat stops accumulating (**no `VACUUM FULL` ever needed** — retires that
question; `pg_repack` remains the answer if a file shrink is ever wanted), and
the onboarding worst-case (a country's *first* harvest) is the only large txn,
still bounded by §3.1.

### 3.4 Delta-scoped membership — required, not optional

The three membership writers (region_id via `ST_Contains`, boundary-snap rescue,
country_code from region) currently rewrite **every** row in the slice. Left
alone they would re-write all ~318k `region_id`s each week and undo §3.3's
savings. Scope all three to the delta by **joining `coverage_touched`** (and
keeping their existing `src_region_id = :src_id` guard). Only inserted/changed
rows recompute membership; unchanged rows keep last week's `region_id`/cc, which
is correct **while the `region` table is unchanged**.

**Invariant (documented + enforced):** delta membership assumes a stable
`region` table between weekly runs. A `region` change (new country, new/changed
subdivisions) requires a full membership recompute of `coverage_poi`. Provide an
escape hatch: `COVERAGE_FULL_MEMBERSHIP=1` (default off) makes `load_region`
recompute membership over the **whole** slice (today's behaviour) instead of the
delta — run it after region changes / onboarding, or force it via a full
re-harvest. `run.py` logs which mode each run used.

### 3.5 Tile export footprint (gap: 28 full-table scans)

`export_geojsonl` loops `letters × country_codes`, one full-table COPY each
(~28 scans). Collapse to **one COPY per letter**, ordered by `country_code`, and
split into per-cc files client-side while streaming — ~28 scans → ~7. Runs under
the §3.1 `work_mem` cap. Behaviour and output files are identical (same
`{(LETTER, CC): Path}` map, same per-cc files, same tippecanoe inputs); this is
purely fewer scans. *Second-priority; isolated to `tiles.py` and its test.*

### 3.6 `ensure_schema` / DDL hygiene (gap: DDL every run)

- Index creation → `CREATE INDEX CONCURRENTLY IF NOT EXISTS` so an absent index
  never takes a blocking lock mid-harvest. **Correctness detail:** `CONCURRENTLY`
  cannot run inside a transaction block, but today's `ensure_schema` wraps all
  statements in one implicit transaction and commits once at the end — so the
  index loop must run in **autocommit** (set `conn.autocommit = True` around the
  index creations, or run them on a short-lived autocommit connection, then
  restore). Caveat to document: a failed `CONCURRENTLY` build leaves an INVALID
  index that must be dropped and rebuilt — acceptable for a bootstrap that only
  creates indexes on a brand-new cluster. The table/source DDL and the
  `pg_trgm`/extension step stay in the normal committed path.
- `CREATE EXTENSION pg_trgm` gated behind `COVERAGE_ENSURE_EXTENSION` (default
  **off in prod**: devops creates `pg_trgm`/`postgis` at cluster init, so the
  harvest role needs no superuser). Default **on** for dev/CI/fixture so the
  existing `make coverage-refresh` and the test `db` fixture keep working.

## 4. What is deliberately unchanged

Drift-abort ratio and semantics; nearest-region ownership filter (C1); the
unseeded-region guard; per-region failure isolation in `run.py`; the
`dev/fixture` unfiltered path; the manifest `counts` (whole-table) vs `regions`
(this run) split; the PMTiles build/verify gate. No schema/column changes — the
table shape is identical.

## 5. Testing

Extend `pipeline/tests/test_load.py` and `test_run.py` (real PostGIS via the
`db` fixture; the whole existing suite must stay green — it is the regression
net for §3.3/§3.4):

- **Diff-merge correctness:** insert-only, change-only (a single field flips →
  row rewritten, siblings untouched), delete-only, no-op (identical reload
  writes zero rows — assert via `xmax`/`ctid` stability or a row-version probe),
  ownership-takeover (src_region flip re-derives region_id + cc).
- **Delta membership:** an unchanged neighbour row keeps its `region_id` across
  a reload; `COVERAGE_FULL_MEMBERSHIP=1` recomputes the whole slice.
- **Regression:** every existing `test_load.py` case passes unmodified —
  especially drift-abort, generic-error rollback (`CardinalityViolation`),
  reclaimed-boundary-miss re-snap, owner-dropping removal, ownership
  independence of load order.
- **Advisory lock:** a second `run.main()` while the lock is held exits with the
  benign non-zero code and touches nothing.
- **Session budget:** after `apply_session_budget`, `SHOW <setting>` returns the
  configured (or default) value; an env override is honoured.
- **Tile export:** `export_geojsonl` output is byte-identical before/after the
  scan collapse for the fixture (same files, same feature counts).

`make pipeline-test` is the gate. `make coverage-refresh regions=dev/fixture`
must still produce the 10-row fixture index end-to-end.

## 6. Rollout

Code ships behind conservative defaults, safe on the current dev/shared DB
before the CC cluster exists. When devops stands up the CC cluster: point
`DATABASE_DSN`/`DATABASE_URL` at it, set the coverage env budget, run the
**transition re-harvest of all onboarded regions together** (one
`make coverage-refresh`, not per-timer — folds in the pending tag-trim +
ferries/bakeries/water contract additions so it is one harvest, not two) with
`COVERAGE_FULL_MEMBERSHIP=1` (first load on the new cluster), then enable the
weekly timer. Repointing to standalone hardware later is a one-line DSN change.

## 7. Open items

- Exact RAM slice numbers depend on Redis `maxmemory` on the DB host —
  devops confirms (`free -h`, `redis-cli config get maxmemory`) and sets the
  `COVERAGE_*_WORK_MEM` knobs accordingly. The code default budget is
  conservative and does not block that.
- `COVERAGE_LOCK_KEY` constant value — pick and document once (any fixed bigint
  not colliding with other advisory-lock users on the CC cluster; CC is the only
  advisory-lock user there today).
