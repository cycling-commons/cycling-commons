<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Harvesting OSM coverage

This is the runbook for the weekly job that turns raw OpenStreetMap into the two things the map
serves: the `coverage_poi` query index (PostGIS) and the `coverage/<stamp>.pmtiles` vector tiles
(object storage). If you want the *concepts* behind it, read
[From OpenStreetMap to our database](../gis/osm-to-database.md); this page is how to actually run it.

## What we download — and what we deliberately do not

We download **Geofabrik regional extracts** — one bulk `-latest.osm.pbf` file per country, over plain
HTTP. We do **not** use the Overpass API.

That is a deliberate choice, and it matters, because the two have opposite rules:

- **Overpass** is a live query API with strict per-IP limits: a small number of concurrent slots, a
  per-query timeout and memory cap, and a soft daily download quota. Its own usage policy says *do
  not bulk-extract with it — use Geofabrik or a planet dump*. A four-country sweep would blow through
  the quota and violate the terms.
- **Geofabrik** serves static files. No query engine, no per-IP throttle worth worrying about at our
  cadence. The only etiquette is: it rebuilds `-latest` roughly **daily**, so pulling more than once a
  day is wasteful, and it round-robins downloads across mirrors (the cause of the one real gotcha,
  [below](#the-md5-mirror-lag-gotcha)).

The raw files are large; the part we keep is tiny. A single harvest of the four onboarded countries:

| Geofabrik file | Downloaded | After `osmium tags-filter` |
|---|---|---|
| `europe/germany-latest.osm.pbf` | ~4.5 GB | ~22 MB |
| `europe/netherlands-latest.osm.pbf` | ~1.3 GB | ~2.9 MB |
| `europe/belgium-latest.osm.pbf` | ~659 MB | ~2.2 MB |
| `europe/luxembourg-latest.osm.pbf` | ~46 MB | ~217 KB |

`osmium tags-filter` keeps only the objects our contract selects (a bike shop, a fountain, a
castle — see [Data catalog](../../data-catalog.md)); everything else in those gigabytes is discarded
before it reaches our database. That ~99 % reduction is the whole point: we hold a narrow *serving
subset*, not a copy of OSM.

## The download is cached — re-download is the exception

The pipeline's scratch directory `/data/work` is a **named Docker volume** (`cc_pipeline_data`). It
persists across runs, restarts, and `docker compose down` — only `docker volume rm` clears it. So the
gigabytes above stay on disk between harvests.

On every run, `fetch_pbf` downloads only the tiny `.md5` companion file (a few bytes), compares it to
the hash of the cached `.pbf`, and if they match it skips the download entirely:

<!-- CODE-FROM pipeline/coverage/run.py -->
```
print(f"[coverage] {region}: PBF unchanged, skipping download")
```

So there is **no "update" flag to pass** — freshness is decided per file, automatically. A re-run
re-downloads a country *only* when Geofabrik's daily rebuild actually changed that country's extract.
Re-running the harvest an hour after a successful one re-downloads nothing.

Two ways to bypass the network entirely:

- `COVERAGE_PBF_PATH=/path/to.osm.pbf` short-circuits `fetch_pbf` — used by the offline fixture run
  and CI, which never touch Geofabrik.
- The fixture run itself:

<!-- CODE-ILLUSTRATIVE offline fixture harvest, no network -->
```bash
make coverage-refresh regions=dev/fixture pbf=tests/fixtures/mini.osm.pbf
```

## Running it

<!-- CODE-ILLUSTRATIVE the four-country harvest -->
```bash
make coverage-refresh regions=europe/belgium,europe/netherlands,europe/germany,europe/luxembourg
```

!!! warning "Always pass `regions=` explicitly"
    A bare `make coverage-refresh` reads `COVERAGE_REGIONS` from your local
    `developers/docker/.env`, which may pin a **single** country. Since ownership is decided by
    geometry (below), running just one country of a bordering set deletes the border rows that
    country owns and nothing re-creates them until its neighbour runs — a rider watches a POI vanish
    for up to a week. **Keep every onboarded country in the list**, or pass them all on the CLI.

One invocation runs the whole chain, per region then once at the end:

1. `fetch_pbf` — download (or skip, cached) the `-latest.osm.pbf`, md5-verified
2. `osmium tags-filter` — reduce to the contract's selectors
3. pyosmium parse → **atomic per-region swap** into `coverage_poi`
4. per-letter GeoJSONL export → `tippecanoe` → `go-pmtiles` verify → **upload** a versioned
   `coverage/<stamp>.pmtiles` + `manifest.json` → prune (keep the last 4)

A failed region keeps last week's slice serving and exits non-zero; it never leaves a half-written
slice. The tile rebuild is part of the same command — there is no separate publish step, so a
successful run means the live map is already updated.

## Road surface is a separate build

Everything on this page produces **points**. Road surface is **lines**, it never touches PostGIS, and
it has its own chapter: [Building road-surface tiles](surface-tiles.md). Same Geofabrik extracts,
same `osmium tags-filter` step, same tippecanoe — a different shape at the end, and no database.

## Ownership: which country's extract owns a border POI

Geofabrik's extracts **overlap** at borders, so one OSM entity arrives in several countries' files.
Ownership is decided by **geometry, not by which extract ran last**: each staged row is resolved to
the single nearest region within a small snap tolerance, and an extract keeps the row only if that
region's country is its own. This is what makes `src_region_id` deterministic (and what unblocks
partitioning). The full rationale, the measured data, and the nearest-region-wins rule are in
[coverage-provider.md](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/coverage-provider.md)
(§1 for the ownership rule, §3 for the staging step that applies it).

## Safety rails

- **Drift abort.** If a region's new extract has far fewer rows than last time (more than a 40 %
  drop), the swap aborts and keeps the last good slice, rather than publishing a gutted country. An
  abort means "investigate", not "lower the threshold".
- **Unseeded-country guard.** Harvesting a country whose regions are not seeded yet fails loudly (the
  ownership filter would otherwise silently drop every row). Onboard the country first — see
  [Onboarding a new country](onboarding-a-country.md).

<a id="the-md5-mirror-lag-gotcha"></a>

### The md5 mirror-lag gotcha

Geofabrik round-robins across mirrors and rebuilds `-latest` daily, so the `.md5` you fetch from one
mirror can disagree with the `.pbf` a different mirror just served you — a *valid* multi-GB download
then fails its check. `fetch_pbf` handles this by verifying the bytes it received against the `.md5`
from the **same resolved mirror URL**, and only falling back to the round-robin `.md5` on a retry. If
you ever see a genuine md5 mismatch after retries, the download is actually corrupt — re-run.

## Verify a harvest landed

After a run, three read-only checks confirm ownership and stamping are healthy:

<!-- CODE-ILLUSTRATIVE post-harvest acceptance queries -->
```sql
-- No row owned by an extract of the wrong country. MUST be empty.
SELECT s.slug, p.country_code, count(*)
FROM coverage_poi p JOIN coverage_source s ON s.id = p.src_region_id
WHERE p.country_code <> upper(split_part(s.slug, '/', 2))  -- extract country vs row country
GROUP BY 1, 2;

-- Every row is region-stamped. MUST be 0.
SELECT count(*) FROM coverage_poi WHERE region_id IS NULL;

-- Per-source row counts, sanity.
SELECT s.slug, count(*) FROM coverage_poi p
JOIN coverage_source s ON s.id = p.src_region_id GROUP BY 1 ORDER BY 2 DESC;
```

The published artifact and its region list:

<!-- CODE-ILLUSTRATIVE read the published manifest -->
```bash
curl -s http://localhost:9100/cc-maps/coverage/manifest.json | jq '{regions, url}'
```

## Where to go deeper

- [coverage-provider.md](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/coverage-provider.md) — the serving cache, the tag
  whitelist, tile properties, prod scheduling.
- [osm-data-architecture.md](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/osm-data-architecture.md) — why the cache is a
  narrow subset, the item catalogue, the storage principles.
- [coverage-batch.md](https://github.com/cycling-commons/cycling-commons/blob/main/developers/coverage-batch.md) — the systemd timer, environment
  variables, prod bucket/CORS setup.
