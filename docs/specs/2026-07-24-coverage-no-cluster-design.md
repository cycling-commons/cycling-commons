# Coverage rendering without clustering — design

**Status:** approved 2026-07-24, executed 2026-07-24 (§9 execution notes).
**Supersedes:** the low-zoom coverage clustering of
[2026-07-22-coverage-scope-rendering-design.md](2026-07-22-coverage-scope-rendering-design.md)
§7 and the `minzoom 6` overview-coverage decision in `pipeline/coverage/tiles.py`.
**Related:** [coverage-provider.md](coverage-provider.md) (tile contract §4),
[map-and-search.md](map-and-search.md) (§4/§5 coverage rendering),
[region-scoping-design.md](region-scoping-design.md) (§6 scope filter, §7 clustering).

## 1. The problem this removes

Coverage POIs are pre-clustered in the PMTiles at overview zooms (z6–11) into
count bubbles, and the client filters those bubbles by scope
(`ridtok`/`cctok` membership tokens). This produces **phantom bubbles**: a
cluster straddling a region border is rendered at the *centroid of its members*,
which can sit **outside** the scoped region, while its properties come from one
*leader* member. Investigation (2026-07-24) proved the position is decoupled from
every per-feature region stamp — a shelter bubble rendered in Thuringia carried a
single Hesse `ridtok`. **No tile attribute can gate a cluster's rendered position**
(union `ridtok`, leader `ridtok`, tighter `cluster-distance`, and larger tile
budgets were all tested; none reached zero phantoms).

The clustering also does not scale worldwide. "Ship a region's points to the
client and cluster there" is bounded for European states (Bavaria 52k) but breaks
for a US state or Chinese province onboarded whole (California ≈200–400k,
Guangdong 500k–2M+).

**Both problems are artifacts of clustering.** Individual coverage points do not
have them: a single point carries exactly one `ridtok` token = its own region, so
the scope filter is precise at any scale, straight from the static tiles.

## 2. Decision: don't cluster coverage

Render coverage as **individual points only, from a local zoom (~z11) upward**;
show **no coverage at overview zoom** and rely on the rail's exact per-scope
counts for the "how much". One uniform rule for **every** scope — no dual path,
no per-scope special-casing, no client- or server-side clustering.

- **z11–14:** individual coverage icons, scope-filtered on the per-feature
  `ridtok`/`cctok` tokens (exact for a single point → **no phantom at any zoom,
  any country**). A z11 viewport holds a renderable number of symbols, all
  genuinely in scope.
- **below z11 (overview):** no coverage layer renders. The rider sees the region
  spotlight and the rail counts (`Water 485/485`, …); zooming in reveals the
  actual dots. This is what the map did before clustering was added to fill the
  overview — and that overview fill is exactly what leaked.

`~z11` is the initial threshold (roughly the pre-clustering individual-dot zoom).
It is tunable: verify z11 tile sizes on the densest areas during implementation
and raise to z12 if a z11 tile is too heavy.

## 3. Changes

### 3.1 Pipeline — `pipeline/coverage/tiles.py` (`build_pmtiles`)

Drop the entire clustering apparatus from the tippecanoe command:

- Remove `--cluster-distance`, `--cluster-maxzoom`, `--cluster-densest-as-needed`.
- Remove both `--accumulate-attribute ridtok:concat` / `cctok:concat` (the concat
  existed **only** to union member tokens across a cluster; with no clusters each
  feature keeps its own single token).
- Change `--minimum-zoom 6` → `--minimum-zoom 11` (no tiles below the individual
  zoom → nothing to render at overview; also avoids huge all-point low-zoom
  tiles). Keep `--maximum-zoom 14`.
- Keep `-r1` (retain every point at the built zooms — no rate dropping). Add
  `--drop-densest-as-needed` **only** as a tile-size safety valve for
  pathologically dense z11 tiles; note in a comment that any drop there recovers
  at z12+.

`_universal_props` still emits `ridtok`/`cctok` **per feature** (single token) —
they remain the scope-filter keys; only the cross-cluster accumulation is gone.

### 3.2 Client — `web/assets/map/map.js`

- **Remove the cluster sublayer** `<letter>-<cc>-cov-cl` and its builder in
  `addCoverage()`; remove `covClusterFilter()` and the cluster arm of
  `updateCoverageScopeFilter()`. Coverage becomes a single icon layer per
  `(letter, cc)` (`<letter>-<cc>-cov`).
- The icon layer keeps `covIconFilter()` (scope + curated-ref dedupe + the stays
  accessibility narrow) — **exact** on individual points. Rename to `covFilter()`
  if it reads cleaner now that there is only one coverage sublayer.
- Coverage icon layers get `minzoom: 11` (belt-and-braces with the tile minzoom),
  so nothing paints below the threshold even if a stale wider-zoom tile exists.
- No cluster bubble rendering, no `point_count` handling for coverage.

### 3.3 Contract / docs

- `coverage-provider.md` §4 (tile artifact contract): rewrite the clustering
  paragraph — the tiles carry **individual points at z11–14, no clusters**; the
  scope filter is exact per point; `ridtok`/`cctok` are per-feature (no union).
- `map-and-search.md` §4.5 / §5: replace the "coverage clusters at overview"
  description with the no-cluster rule and the z11 threshold; note the phantom
  fix and that overview coverage is conveyed by the rail counts.
- Update the `pipeline/coverage/tiles.py` header comment (it currently documents
  the clustering rationale).

## 4. What is unchanged

- **Rail `/counts`** — server-exact per-scope totals, the overview "how much".
  Untouched; now the sole overview coverage signal.
- **`/coverage/poi`, `/coverage/search`, `/coverage/nearby`** — untouched.
- **The rest of the tile pipeline** (extract → parse → load → per-`(letter,cc)`
  GeoJSONL export → publish) — untouched; only the tippecanoe flags change.
- **Confirmed-pin clusters** (`setupConfClusters`, DOM markers for
  validated/simulated served items) — a **separate** system, rebuilt per-scope
  from the in-scope set, already phantom-free. Not touched.
- **Ride heatmap (L layer)** — ride density, not coverage POIs. Not touched.
- **Country / Everywhere scopes** — same uniform rule; they were already
  phantom-free (per-`cc` tiles) and simply stop showing overview bubbles too.

## 5. Out of scope

- The **GPX ride-check ∪ coverage** unification (extending `RideCheckService` to
  corridor-search `coverage_poi`) is a separate system (exact `ST_Intersects`
  corridor over the DB, no tiles, no clustering) and gets its own spec. It is
  unaffected by this change and does not share the phantom problem.
- No change to `coverage_poi` schema, the harvest, or the scope-filter token
  format. (Simplifying `ridtok`/`cctok` from `"|id|"` tokens to scalar
  `region_id`/`country_code` is now possible — the token format existed to support
  the substring-union test — but is deliberately deferred as optional cleanup to
  keep this change minimal and the scope filter's tests stable.)

## 6. Testing

- **Pipeline:** a tile-build assertion that z6–10 tiles are **absent** and z11–14
  tiles carry features **without** `point_count` (no clusters). `make
  pipeline-test` stays green (no contract-prop change).
- **Client:** `make scope-test` unaffected (the scope filter builder in `scope.js`
  is unchanged — it still tests `ridtok`/`cctok` tokens, which now only ever
  appear on individual points).
- **Browser (the acceptance test):** scope to Hesse and to Bavaria; at overview
  (z7–8) **no** coverage dots appear anywhere (only the spotlight + rail counts);
  zoom past ~z11 and individual dots appear **only inside the region** — zero
  bubbles outside it at any zoom. Repeat for a country scope (dots appear z11+
  countrywide) and Everywhere. Confirm the stays-accessibility narrow and the
  curated-ref dedupe still bite on the individual icons.

## 7. Deploy

- Requires a coverage **re-tile** (`make coverage-refresh`) to rebuild the PMTiles
  without clustering and at `minzoom 11`. No DB migration, no re-harvest — the
  `coverage_poi` rows are unchanged; only the tile artifact is rebuilt and
  re-published.

## 8. Constraints

- Branch `symfony-base`. Commit per task. **Never push.** No `Co-Authored-By`
  trailers. Explicit pathspecs only.
- First lines: `.py` SPDX header as in the file; `.js` SPDX first line.
- Section refs doc-qualified (never bare `§N`).

## 9. Execution notes

- **Pipeline:** `build_pmtiles` now runs with `--minimum-zoom 11`, no cluster
  or accumulate-attribute flags, `-r1` + `--drop-densest-as-needed`.
  `pipeline/tests/test_tiles.py` 13 passed; full pipeline suite 83 passed.
  (Commit 5588a67.)
- **Client:** the coverage `-cl` cluster sublayer and `covClusterFilter`/
  `covClusterIcon` are removed; the icon layer is `minzoom: 11`.
  `make scope-test` 119/119 (unchanged — `scope.js` untouched). (Commit
  2555460.)
- **Re-tile:** `make coverage-refresh` published `20260724-1409.pmtiles`
  (app serves it). Tile-level verification: `pmtiles show` reports
  `min zoom: 11`; a z11 German shelter tile carries 21 individual features
  with **0** `point_count`; the z7 tile is absent; bounds reach lng 15 / lat
  55 (Germany present).
- **Browser (0 console errors):** Hesse overview z8 shows **zero** coverage
  dots anywhere (phantom class eliminated; rail counts carry it); Hesse z13
  inside the region shows individual coverage icons with no bubbles; Bavaria
  overview z7.5 shows zero dots (no spill into neighbours) — the second
  phantom case, also clean.
- **Prod deploy:** re-tile via `make coverage-refresh`; no DB migration, no
  re-harvest.
