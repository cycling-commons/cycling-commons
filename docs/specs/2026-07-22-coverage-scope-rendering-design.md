# Coverage scope rendering — per-country clustering + scope dim mask (design)

Status: **executed** (2026-07-22, symfony-base, NOT pushed). Follow-on to
[2026-07-22-country-onboarding-design.md](2026-07-22-country-onboarding-design.md):
adding the Netherlands (a second country bordering Belgium) exposed two map-canvas
issues that the Belgium-only map could not show. Both confirmed in the running app
by instrumenting the MapLibre instance (not just the API).

**Execution record.** Commit range `c9c42cd..d8e701e` (symfony-base, NOT
pushed):

- `c9c42cd` feat(coverage): per-(letter,country) tile layers so clusters never
  cross a border
- `8f0669a` feat(coverage): manifest advertises `country_codes` for the
  per-country client layers
- `bbaf3c3` + `9863fae` feat(map): `/map/scope/boundary` returns the
  `ST_Union` of a scope's regions (+ fix: read query params via `all()` to
  avoid a 400 on array-valued `rids`/`cc`, sort `rids`)
- `a0ab5ce` + `1267ef5` feat(map): render coverage as per-country layers
  driven by manifest `country_codes` (+ cache-key `.v2` shape bump +
  `countryCodes()` tests)
- `d8e701e` feat(map): dim mask for a country scope via `/map/scope/boundary`
  union

Re-harvest (Task 6): BE 23024 / NL 34751 rows loaded into `coverage_poi`;
published artifact `20260722-1418.pmtiles`; manifest
`country_codes: ["BE","NL"]`. Tiles carry per-country source-layers
`<letter>_<cc>` (e.g. `c_be`, `c_nl`, and a `zz` bucket per letter). Border
tile 7/65/42: 0 features mix `BE` and `NL` (of 167 total).

**Browser verification (Task 7), 0 console errors:**

- `country:NL` → `queryRenderedFeatures` on the visible cluster layers: 60
  clusters, ALL NL — 0 MIXED, 0 BE.
- `country:BE` → 133 clusters, ALL BE — 0 MIXED, 0 NL.
- **Dim mask:** `country:NL` greys everything outside the NL union with a gold
  dashed outline; Everywhere → no mask (`region-mask`/`region-line` layers
  gone); a single province (North Holland) → mask outlines just that
  province. My-area keeps its own soft circle (unchanged).

Docs synced per the "Specs to update" list below: coverage-provider.md §4,
map-and-search.md §4.5, and 2026-07-19-region-scoping-design.md §7 all
updated 2026-07-22.

## Problem (observed, verified)

With a country scope selected, the coverage **tile clusters** misbehave at the
border — the server-side rail counts and the `/map/coverage/*` endpoints are
correct; the issue is purely the client-rendered PMTiles cluster bubbles:

1. **Cross-border mixed clusters leak.** At low zoom, tippecanoe merges POIs
   across the BE/NL border into one bubble. The scope token (`cctok`/`ridtok`)
   is the *union* of every member (`--accumulate-attribute=…:concat`), so a
   bubble with even one in-scope member matches the scope — but it is drawn at
   its anchor (often in the *other* country) with a `point_count` dominated by
   out-of-scope POIs. Measured under `country:NL`: 42 pure-NL, **31 mixed**, 0
   pure-BE rendered; the worst mixed bubble near Mechelen (BE) was 650 BE + 170
   NL shown as "820". Mirror case under `country:BE`: a "34" bubble in Leiden
   (deep NL). Pure-country clusters *are* correctly filtered — only the merged
   border blobs leak.

2. **No selected-area effect for a country/multi-region scope.** The dim
   spotlight mask only fires for a single named region or a My-area circle
   (`web/assets/map/map.js` `applyScope`, `setSpotlight(...)` call); a country
   scope (12 provinces) or a multi-region My-area set calls `setSpotlight(null)`,
   so nothing greys and the rider has no visual scope boundary.

(Also found + handled operationally, not part of this design: `CoverageManifest`
caches the tile URL for 1h — after a dev `coverage-refresh`, clear `cache.app`
or the map serves the previous artifact.)

## A. Per-country clustering (owner decision 2026-07-22: cluster per-country in tiles)

tippecanoe clusters **independently per layer**, so partition each coverage
letter's features by country into separate tile layers.

- **Export** (`pipeline/coverage/tiles.py` `export_geojsonl`): group by
  `(letter, country_code)` instead of `letter` alone, writing one file per pair,
  e.g. `c_be.geojsonl`, `c_nl.geojsonl`. A row with a NULL `country_code` (the
  rare unstamped boundary-miss) goes to a `zz` bucket so no POI is silently
  dropped. Returns `{(letter, cc): Path}`.
- **Tiles** (`build_pmtiles`): one tippecanoe layer per file —
  `-L c_be:<file> -L c_nl:<file> …`. The existing cluster flags
  (`--cluster-distance`, `--cluster-densest-as-needed`, `--cluster-maxzoom`,
  `-r1`, `--accumulate-attribute ridtok:concat`/`cctok:concat`) are unchanged;
  clustering + token-concat now run **within one country**, so a bubble's
  `cctok` is single-country and its `point_count` counts only that country. The
  `ridtok` concat still spans that country's regions, so the region-vs-country
  scope arms keep working.
- **Verify layers** (`tiles.py` `verify_pmtiles`): expect the per-`(letter,cc)`
  layer set instead of per-letter.
- **Manifest** (`pipeline/coverage/publish.py` / `run.py`): the manifest already
  carries `regions`; add `country_codes` (resolved via `COUNTRY_BY_REGION`, e.g.
  `["BE","NL"]`) so the client knows which country-layers to wire without
  probing the tile.
- **Client** (`web/assets/map/map.js` `addCoverage`): iterate coverage letters ×
  the manifest's `country_codes`, adding `<letter>-<cc>-cov` (icons) and
  `<letter>-<cc>-cov-cl` (bubbles) layers, each bound to its `<letter>_<cc>`
  source-layer. `updateCoverageScopeFilter` iterates the same product. The
  `covScopeFilter()`/`coverageTileFilter()` logic is unchanged — it still gates
  on `ridtok`/`cctok`, but a bubble can no longer straddle a border, so the
  filter's result is now clean. `CoverageManifest` must pass `country_codes`
  through to the page (`window.CC_COVERAGE_COUNTRIES`); an older manifest without
  the field falls back to a single unsplit layer set (`<letter>-cov`) so a
  not-yet-rebuilt artifact still renders (degrade, don't blank).
  - **Caveat:** an empty `country_codes` array reads to the client as this
    same pre-split signal (plain `<letter>` layers), not as "every POI landed
    in `zz`." A hypothetical artifact where every row is unstamped (only
    `<letter>_zz` buckets, `country_codes` empty because the manifest build
    excludes `ZZ` from the set) would take the pre-split fallback path and
    look for a `<letter>` source-layer that doesn't exist in a post-split
    tile — nothing would render. This is pathological, not a real rollout
    risk: production always stamps a country via `COUNTRY_BY_REGION` before
    tiling (§A above), so a fully-unstamped artifact shouldn't occur. Noted
    so a future reader doesn't mistake the empty-`country_codes` signal for
    "only-zz."
- **Tile contract** (`docs/specs/coverage-provider.md §4`): source-layer names
  become `<letter>_<cc>`; document the split + the `zz` bucket + the manifest
  `country_codes` field.
- **Rollout**: a full coverage **re-harvest** (both regions) regenerates the
  tiles with the new layer split; no DB change (the split is a tiling concern,
  `coverage_poi` is untouched).
- **Scaling**: layers = coverage-letters × onboarded-countries (7×2 today).
  Grows with onboarding; acceptable for the incremental rollout, revisit only at
  many-dozens of countries (e.g. a per-country PMTiles or a merged low-zoom
  layer) — flagged, not solved here.

## B. Scope dim mask for country / multi-region / My-area

Extend the single-region spotlight to any region-set scope.

- **Server** — new `GET /map/scope/boundary` (`MapController`, modelled on
  `regionBoundary`): takes the same scope params the coverage endpoints use
  (`rids` csv and/or `cc`), returns a single GeoJSON Feature =
  `ST_Union` of the matching `region.geom`, `ST_Simplify`-reduced for transfer,
  wrapped like `RegionBoundaryProvider::featureJson`. ETag + `max-age=3600`
  (boundaries change only on a versioned re-import). A new
  `ScopeBoundaryProvider` (or a method on `RegionBoundaryProvider`) owns the
  union SQL. Empty scope (no rids, no cc → Everywhere / empty My-area) → 204/empty,
  the client draws no mask.
- **Client** (`web/assets/map/map.js`): in `applyScope`, for `country`,
  multi-region, and `myArea` (derived-set) scopes, fetch `/map/scope/boundary`
  with the active `coverageParams()` and draw the existing world-minus-shape
  dark mask + dashed outline (the same `region-mask` / `region-line` layers the
  single-region and circle spotlights already build). Single-region keeps its
  current path (or routes through the same endpoint with one rid). Everywhere →
  `setSpotlight(null)`, no mask. My-area with an empty derived set → no mask
  (nothing in scope), consistent with the leak-safe-hide rule.
  - **Caveat:** in practice the country/multi-region union mask only ever
    fires for a `country`-kind scope. A multi-region scope today only exists
    as `myArea` (a derived region set), and `myArea` keeps its own soft-circle
    mask rather than routing through this union path; `scope.js` also
    collapses a multi-region scope's region-tokens down to their first region
    before the mask decision is made. So there is no exercised path today
    where a hand-built, non-`myArea` multi-region region-scope reaches the
    union mask — the multi-region branch of this design is currently
    unexercised for that case, kept for when a non-`myArea` multi-region
    scope is introduced.

## Testing

- **Pipeline**: `export_geojsonl` groups by `(letter, cc)` — fixture PBF assert
  the file/layer set includes `_be`/`_nl` (extend `pipeline/tests/test_tiles.py`);
  a NULL-cc row lands in `zz`. `verify_pmtiles` expects the split layers.
  Manifest carries `country_codes` (`pipeline/tests/test_run.py`).
- **Server**: `/map/scope/boundary` returns the union for `cc=NL` (one polygon
  spanning the 12 provinces), for a single `rids`, and 204/empty for Everywhere;
  ETag + cache headers; unknown/empty scope safe. New controller test.
- **Client**: extend `web/tests/js/scope.test.cjs` if the country-layer product
  or boundary-scope selection has pure logic worth pinning; otherwise browser
  verification (below) covers the rendering.
- **Browser (this time, the tile canvas)**: under `country:NL`, `country:BE`, a
  single province, and My-area — assert queried rendered cluster features are
  single-country and no bubble is anchored across the border (the Mechelen "820"
  and Leiden "34" cases resolve), and the dim mask outlines the scoped country.

## Specs to update

- `docs/specs/coverage-provider.md §4` — per-`(letter,cc)` source-layers, `zz`
  bucket, manifest `country_codes`.
- `docs/specs/map-and-search.md` — the country/multi-region dim mask behaviour.
- `docs/specs/2026-07-19-region-scoping-design.md` — note the border-cluster
  resolution + country greying under the coverage-scoping section.
- This doc records the execution once done.

## Out of scope

- Reworking the token filter (`ridtok`/`cctok`) — still correct; per-country
  layers make its result clean.
- Per-country PMTiles / worldwide layer-count optimisation (flagged in A).
- Count-relabeling of mixed clusters (superseded by per-country clustering).
