<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Harvesting OSM coverage

This is the runbook for the weekly job that turns raw OpenStreetMap into the two things the map
serves: the `coverage_poi` query index (PostGIS) and one `coverage/<cc>/<stamp>/points.pmtiles`
vector-tile file per country (object storage). If you want the *concepts* behind it, read
[From OpenStreetMap to our database](../gis/osm-to-database.md); this page is how to actually run it.

## What we download, and what we deliberately do not

We download **Geofabrik regional extracts**: one bulk `-latest.osm.pbf` file per country (or per
state or province where a country is onboarded that way), over HTTPS from `download.geofabrik.de`.
We do **not** use the Overpass API.

That is a deliberate choice, and it matters, because the two have opposite rules:

- **Overpass** is a live query API with strict per-IP limits: a small number of concurrent slots, a
  per-query timeout and memory cap, and a soft daily download quota. Its own usage policy says *do
  not bulk-extract with it, use Geofabrik or a planet dump*. A sweep of every onboarded country
  would blow through the quota and violate the terms.
- **Geofabrik** serves static files. No query engine, no per-IP throttle worth worrying about at our
  cadence. The only etiquette is: it rebuilds `-latest` roughly **daily**, so pulling more than once a
  day is wasteful, and it round-robins downloads across mirrors (the cause of the one real gotcha,
  [below](#the-md5-mirror-lag-gotcha)).

The raw files are large; the part we keep is tiny. Four of the onboarded extracts, as cached in the
pipeline volume on 2026-08-30 (rounded):

| Geofabrik file | Downloaded | After `osmium tags-filter` |
|---|---|---|
| `europe/germany-latest.osm.pbf` | ~4.8 GB | ~28 MB |
| `europe/netherlands-latest.osm.pbf` | ~1.4 GB | ~3.5 MB |
| `europe/belgium-latest.osm.pbf` | ~690 MB | ~2.7 MB |
| `europe/luxembourg-latest.osm.pbf` | ~47 MB | ~245 KB |

`osmium tags-filter` keeps only the objects our contract selects (a bike shop, a fountain, a
castle, see [Data catalog](../../data-catalog.md)); everything else in those gigabytes is discarded
before it reaches our database. That ~99 % reduction is the whole point: we hold a narrow *serving
subset*, not a copy of OSM.

## The download is cached: re-download is the exception

The pipeline's scratch directory `/data/work` is a **named Docker volume** (`cc_pipeline_data`). It
persists across runs, restarts, and `docker compose down`; only `docker volume rm` clears it. So the
gigabytes above stay on disk between harvests.

On every run, `fetch_pbf` downloads only the tiny `.md5` companion file (a few bytes), compares it to
the hash of the cached `.pbf`, and if they match it skips the download entirely:

<!-- CODE-FROM pipeline/coverage/run.py -->
```
print(f"[coverage] {region}: PBF unchanged, skipping download")
```

So there is **no "update" flag to pass**: freshness is decided per file, automatically. A re-run
re-downloads a country *only* when Geofabrik's daily rebuild actually changed that country's extract.
Re-running the harvest an hour after a successful one re-downloads nothing.

Two ways to bypass the network entirely:

- `COVERAGE_PBF_PATH=/path/to.osm.pbf` short-circuits `fetch_pbf`, used by the offline fixture run
  and CI, which never touch Geofabrik.
- The fixture run itself:

<!-- CODE-ILLUSTRATIVE offline fixture harvest, no network -->
```bash
make coverage-refresh regions=dev/fixture pbf=tests/fixtures/mini.osm.pbf
```

## Running it

<!-- CODE-ILLUSTRATIVE the full harvest: every onboarded region, the COVERAGE_REGIONS default in developers/docker/compose.yaml -->
```bash
make coverage-refresh regions=$(make -s coverage-regions)
```

!!! warning "Always pass `regions=` explicitly"
    A bare `make coverage-refresh` reads `COVERAGE_REGIONS` from your local
    `developers/docker/.env`, which may pin a **single** country. Since ownership is decided by
    geometry (below), running just one country of a bordering set deletes the border rows that
    country owns and nothing re-creates them until its neighbour runs, a rider watches a POI vanish
    for up to a week.

    That is why the command above passes `regions=` from `make -s coverage-regions`, which reads
    the committed default out of `developers/docker/compose.yaml` and is therefore immune to a local
    override. Use it rather than pasting a list: three pages used to carry their own copy of the
    twenty-two extracts, and a copy is a thing that drifts.

One invocation runs the whole chain, per region then once at the end:

1. `fetch_pbf`: download (or skip, cached) the `-latest.osm.pbf`, md5-verified
2. `osmium tags-filter`: reduce to the contract's selectors
3. pyosmium parse → **atomic per-region merge** into `coverage_poi`
4. per-letter GeoJSONL export → `tippecanoe`, per country → `go-pmtiles` verify → **upload + publish,
   per country**: `coverage/<cc>/<stamp>/points.pmtiles` (the stamp is `YYYYMMDD-HHMMSS`), merged into
   the stable `coverage/manifest.json`, keeping the newest 4 builds per country. A country whose
   export has not changed since the live manifest is skipped rather than rebuilt.

A failed region keeps last week's slice serving and exits non-zero; it never leaves a half-written
slice. The tile rebuild is part of the same command, there is no separate publish step, so a
successful run means the live map is already updated. `make coverage-tiles` runs step 4 on its own
(`--tiles-only`): it rebuilds and publishes the archive from the rows already in PostGIS, per
country, without touching Geofabrik or the database contents.

## Road surface is a separate build

Everything on this page produces **points**. Road surface is **lines**, it never touches PostGIS, and
it has its own chapter: [Building road-surface tiles](surface-tiles.md). Same Geofabrik extracts,
same `osmium tags-filter` step, same tippecanoe, a different shape at the end, and no database.

## Ownership: which country's extract owns a border POI

Geofabrik's extracts **overlap** at borders, so one OSM entity arrives in several countries' files.
Ownership is decided by **geometry, not by which extract ran last**: each staged row is resolved to
the single nearest region within a small snap tolerance, and an extract keeps the row only if that
region's country is its own. This is what makes `src_region_id` deterministic (and what unblocks
<figure class="gis-fig">
<svg viewBox="0 0 640 470" role="img" aria-labelledby="f18-t f18-d" xmlns="http://www.w3.org/2000/svg"><title id="f18-t">Which extract owns a border point: the nearest region wins</title><desc id="f18-d">Two country extracts drawn as overlapping rectangles, one on the left labelled the Belgian extract and one on the right labelled the Dutch extract, with a hatched band down the middle where they overlap. Geofabrik cuts its extracts with an overlap, so one real object arrives in both. Three points sit in the band. For each one a dashed line runs to the nearest region boundary on either side, and the shorter line decides. The left point is nearer a Belgian region, so the Belgian run keeps it and the Dutch run deletes it. The right point is nearer a Dutch region, so the opposite happens. The middle point is nearer a Dutch region by a small margin and goes the same way, which is the case that used to flip week to week when ownership was decided by whichever extract ran last. A note records that the rule is mutually exclusive, so exactly one extract ever stages a given row, and that a point with no region within the snap distance of any onboarded country is dropped by both.</desc><defs><marker id="gis-arrow-f18" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="12" markerHeight="12" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path class="gis-fill-accent" d="M 0 0 L 10 5 L 0 10 Z"/></marker><pattern id="f18hatch" width="10" height="10" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><line class="gis-muted" x1="0" y1="0" x2="0" y2="10"/></pattern></defs><rect class="gis-ink" x="30" y="70" width="330" height="230" rx="6"/><rect class="gis-ink" x="280" y="70" width="330" height="230" rx="6"/><rect x="280" y="70" width="80" height="230" fill="url(#f18hatch)"/><text class="gis-label-sm" x="46" y="58">the Belgian extract</text><text class="gis-label-sm" x="466" y="58">the Dutch extract</text><text class="gis-label-sm" x="252" y="330">they overlap here</text><path class="gis-accent" d="M 190 70 L 190 300"/><text class="gis-label-sm" x="96" y="200">a Belgian region</text><path class="gis-accent" d="M 452 70 L 452 300"/><text class="gis-label-sm" x="472" y="200">a Dutch region</text><circle class="gis-ink gis-fill-ink" cx="300" cy="120" r="5"/><path class="gis-muted" stroke-dasharray="4 4" d="M 300 120 L 190 120"/><path class="gis-muted" stroke-dasharray="4 4" d="M 300 120 L 452 120"/><text class="gis-label-sm" x="300" y="106">BE keeps it</text><circle class="gis-ink gis-fill-ink" cx="336" cy="192" r="5"/><path class="gis-muted" stroke-dasharray="4 4" d="M 336 192 L 190 192"/><path class="gis-muted" stroke-dasharray="4 4" d="M 336 192 L 452 192"/><text class="gis-label-sm" x="252" y="178">NL, by a margin</text><circle class="gis-ink gis-fill-ink" cx="352" cy="262" r="5"/><path class="gis-muted" stroke-dasharray="4 4" d="M 352 262 L 190 262"/><path class="gis-muted" stroke-dasharray="4 4" d="M 352 262 L 452 262"/><text class="gis-label-sm" x="352" y="248">NL keeps it</text><line class="gis-muted" x1="20" y1="356" x2="620" y2="356"/><text class="gis-label-sm" x="20" y="390">The shorter dashed line decides, and the other extract&#8217;s run deletes the row. Exactly one</text><text class="gis-label-sm" x="20" y="416">extract ever stages it, so a border point cannot flip week to week the way it did when</text><text class="gis-label-sm" x="20" y="442">ownership went to whichever run happened to finish last.</text></svg>
<figcaption>The overlap is not a bug in the extracts, it is how Geofabrik cuts them, and it means
every border object arrives more than once. Deciding by distance rather than by run order is what
makes the answer the same every week.</figcaption>
</figure>

partitioning). The full rationale, the measured data, and the nearest-region-wins rule are in
[coverage-provider.md](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/coverage-provider.md)
(§1 for the ownership rule, §3 for the staging step that applies it).

## Safety rails

- **One run at a time.** The run takes a session-level Postgres advisory lock
  (`pg_try_advisory_lock`, key `0xC07E7A6E`) before it touches anything. A second run started while
  one holds it prints `another coverage run holds the advisory lock` and exits with status 2. The
  lock lives with the connection, so a crashed run cannot leave it held.
- **Drift abort.** If a region's new extract has far fewer rows than last time (more than a 40 %
  drop), the merge aborts and keeps the last good slice, rather than publishing a gutted country. An
  abort means "investigate", not "lower the threshold".
- **Unseeded-country guard.** Harvesting a country whose regions are not seeded yet fails loudly (the
  ownership filter would otherwise silently drop every row). Onboard the country first; see
  [Onboarding a new country](onboarding-a-country.md).

<a id="the-md5-mirror-lag-gotcha"></a>

### The md5 mirror-lag gotcha

Geofabrik round-robins across mirrors and rebuilds `-latest` daily, so the `.md5` you fetch from one
mirror can disagree with the `.pbf` a different mirror just served you, a *valid* multi-GB download
then fails its check. `fetch_pbf` handles this by verifying the bytes it received against the `.md5`
from the **same resolved mirror URL**, and only falling back to the round-robin `.md5` on a retry. If
you ever see a genuine md5 mismatch after retries, the download is actually corrupt: re-run.

## The database side of a run

The loader is deliberate about what it does to the shared Postgres host, because the harvest runs
unattended next to the live app.

**A per-session resource budget.** `apply_session_budget` runs `SET SESSION` on the harvest
connection, never `ALTER SYSTEM`, so nothing leaks into the cluster's configuration. Every value is
an environment knob with a conservative default:

| Setting | Environment variable | Default |
|---|---|---|
| `statement_timeout` | `COVERAGE_STATEMENT_TIMEOUT` | `60min` |
| `lock_timeout` | `COVERAGE_LOCK_TIMEOUT` | `5s` |
| `idle_in_transaction_session_timeout` | `COVERAGE_IDLE_TXN_TIMEOUT` | `30s` |
| `synchronous_commit` | `COVERAGE_SYNCHRONOUS_COMMIT` | `off` |
| `maintenance_work_mem` | `COVERAGE_MAINTENANCE_WORK_MEM` | `256MB` |
| `work_mem` | `COVERAGE_WORK_MEM` | `32MB` |
| `max_parallel_workers_per_gather` | `COVERAGE_MAX_PARALLEL` | `0` |

The statement timeout is sized for the largest country anyone onboards unattended, not for a
typical one; `make coverage-refresh timeout=90min` sets it for a single run. `maintenance_work_mem`
times the parallel workers is the term that can starve a co-tenant, which is why parallelism is off.
A runaway query is bounded by the advisory lock (one harvest at a time) and the 5 s lock timeout (it
never queues behind anything).

**Diff-merge, not delete-and-reload.** Each region's rows are staged, filtered for ownership and
checked for drift, then merged into `coverage_poi` as a delta. An upsert on `(ref, letter)` with an
`IS DISTINCT FROM` guard skips every unchanged same-owner row (no heap write, no WAL), updates
changed rows and ownership takeovers (resetting `region_id` so membership is re-derived for those
rows only), and a delete scoped to this extract's own slice removes the rows that have disappeared
from it. Week-over-week churn is a few hundred to a few thousand rows per country, so the
per-country transaction is sub-second and there is no weekly WAL burst.

**Indexes the app relies on.** `ensure_schema` creates the table's indexes `CONCURRENTLY` on every
run (in autocommit, one statement per transaction), so a fresh database gets them without a
migration. Among them is `coverage_poi_geog_idx`, a GiST index on the expression
`(geom::geography)`: every radius query the app runs is a geography predicate
(`ST_DWithin(geom::geography, ...)` from the OSM linker and `/map/coverage/nearby`), and a plain
geometry GiST index cannot serve it.

**Count triggers.** The app keeps per `(country, region, letter)` totals of this table in
`coverage_count`, maintained by the triggers `coverage_count_poi_ins`, `coverage_count_poi_upd`
and `coverage_count_poi_del`. The installer function `coverage_count_install()` ships with the
app's migrations (`Version20260906180000`); the pipeline calls it after creating the table whenever
the function exists, so a pipeline older or newer than the app is fine and the triggers are there as
soon as both are.

## Try it

!!! tip "Hands-on: verify a harvest landed, and read the one number that must be zero"
    Three read-only checks, none of which changes anything, and one of them is a pass/fail rather
    than a judgement call. Run them after any harvest.

    <!-- CODE-ILLUSTRATIVE post-harvest acceptance queries -->
    ```sql
    -- Per-extract country stamps. Each extract resolves to one country in the
    -- pipeline's COUNTRY_BY_REGION map, so one row per extract is the expected
    -- shape; a second country with a handful of rows is a border case to look
    -- at, not a failure of the run.
    SELECT s.slug, p.country_code, count(*)
    FROM coverage_poi p JOIN coverage_source s ON s.id = p.src_region_id
    GROUP BY 1, 2 ORDER BY 1, 2;

    -- Every row is region-stamped. MUST be 0.
    SELECT count(*) FROM coverage_poi WHERE region_id IS NULL;

    -- Per-source row counts, sanity.
    SELECT s.slug, count(*) FROM coverage_poi p
    JOIN coverage_source s ON s.id = p.src_region_id GROUP BY 1 ORDER BY 2 DESC;
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM author-install; sample output on a machine holding all nineteen countries, 2026-09-10; the counts are that machine's, the zero is not -->
    ```text
     unstamped
    -----------
             0

          slug      | count
    ----------------+--------
     europe/germany | 397082
     europe/france  | 307725
     europe/italy   | 269373
     europe/spain   | 236354
    ```

    The middle query is the one to read first, and the only one with a right answer: **it must be
    zero**. A non-zero count means rows landed outside every region polygon, which is either a
    country that was never onboarded or a boundary that has moved, and either way those rows are
    invisible to every scoped query on the site. The other two are shape checks: one row per extract
    in the first, and per-source counts that should look like the countries they name.

    Then read the artifact those rows were published into:

    <!-- CODE-ILLUSTRATIVE read the published manifest -->
    ```bash
    curl -s http://localhost:9100/cc-maps/coverage/manifest.json | jq '{version, countries: (.countries|keys)}'
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM author-install; sample output, abbreviated: the real list runs to 19 country codes -->
    ```json
    {
      "version": 2,
      "countries": ["be", "lu", "nl", "..."]
    }
    ```

    `countries` is the sorted list of countries the artifact was built for, one file per country. If
    a country you just harvested is missing from that list, the rows landed but its tiles were not
    rebuilt, and the map will not show them until they are. Read one country's own entry to see what
    it published:

    <!-- CODE-ILLUSTRATIVE read one country's manifest entry -->
    ```bash
    curl -s http://localhost:9100/cc-maps/coverage/manifest.json | jq '.countries.lu'
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM author-install; real output, a `--tiles-only` republish -->
    ```json
    {
      "stamp": "20260924-1021",
      "built_at": "2026-09-24T10:21:24+00:00",
      "inputs": "d5744ffc321f9426",
      "bounds": [5.744702, 49.456939, 6.507648, 50.180646],
      "counts": {"B": 450, "C": 347, "D": 124, "F": 83, "G": 138, "O": 512, "P": 78, "Q": 1141},
      "tiles": {"points": "http://localhost:9100/cc-maps/coverage/lu/20260924-1021/points.pmtiles"}
    }
    ```

    `inputs` is the fingerprint a rebuild compares against; unchanged inputs mean the next run skips
    this country rather than rebuilding it.

## Where to go deeper

- [coverage-provider.md](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/coverage-provider.md): the serving cache, the tag
  whitelist, tile properties, prod scheduling.
- [osm-data-architecture.md](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/osm-data-architecture.md): why the cache is a
  narrow subset, the item catalogue, the storage principles.
- [coverage-batch.md](https://github.com/cycling-commons/cycling-commons/blob/main/developers/coverage-batch.md): the systemd timer, environment
  variables, prod bucket/CORS setup.
