# Coverage overview density heatmap — design

**Status:** approved 2026-07-24, not yet implemented.
**Builds on:** [2026-07-24-coverage-no-cluster-design.md](2026-07-24-coverage-no-cluster-design.md)
(individual points from z11, no clustering). This adds the overview layer back —
the right way.
**Related:** [coverage-provider.md](coverage-provider.md) (tile contract §4),
[map-and-search.md](map-and-search.md) (§4/§5 coverage rendering, §11 ride heatmap).

## 1. The problem this fixes

The no-cluster change built coverage tiles at `--minimum-zoom 11`, so **no
coverage shows below z11**. But the scope selector fits a region at ~z7–9, so a
rider lands **2–3 zoom levels below** the coverage floor and sees an empty map;
coverage only appears after zooming in — "too late." (Owner-observed 2026-07-24;
confirmed in a clean session: Gelderland lands at z9.2, coverage starts z11.)

Individual dots can't fill that overview (tens of thousands per region → clutter,
or thinned → the "map looks empty" problem that clustering was added to solve),
and clusters leak (the phantom bug). A **density heatmap** fills it without
either failure: a smooth surface reads well from a thinned sample, and being
built from individual in-scope points it is phantom-free.

## 2. Decision: heatmap at overview, icons when zoomed in

- **z6–~11:** coverage renders as a **density heatmap** — "where the coverage
  is", one smooth surface.
- **z11–14:** the individual **icons** (unchanged from the no-cluster work),
  scope-filtered exactly.
- They **cross-fade at ~z11** (heatmap `maxzoom 11`, icons `minzoom 11`).

`~z11` is the shared handoff, matching the icon floor; tunable.

## 3. Changes

### 3.1 Pipeline — `pipeline/coverage/tiles.py` (`build_pmtiles`)

Revert **`--minimum-zoom 11` → `--minimum-zoom 6`**. Keep everything else from the
no-cluster build (no `--cluster-*`, no `--accumulate-attribute`, `-r1`,
`--drop-densest-as-needed`). This single change gives exactly the two-tier data
the client needs, from one build:

- **z11–14 tiles carry every point** (a z11 tile is small enough that
  `--drop-densest-as-needed` never fires) → the icons stay **complete** and
  exactly scope-filtered, unchanged from the no-cluster work.
- **z6–10 tiles are thinned to fit** (`--drop-densest-as-needed` drops the
  densest overflow where a whole-region tile would exceed the size budget) → a
  density *sample*, which is all a heatmap needs. Relative density is preserved
  (the drop is proportional), so the surface reads correctly even where most
  points were dropped.

`_universal_props` (per-feature `ridtok`/`cctok`) is unchanged — the heatmap
filters on the same per-point tokens as the icons, so both are phantom-free.

### 3.2 Client — `web/assets/map/map.js`

Mirror each coverage icon layer with a heatmap layer. In `addCoverage()`'s
per-`(letter, cc)` loop, alongside the `<letter>-<cc>-cov` icon layer
(`minzoom: 11`), add a `<letter>-<cc>-heat` `type:'heatmap'` layer on the **same**
`<letter>_<cc>` source-layer with **`maxzoom: 11`**. (This restores the
two-layers-per-`(letter,cc)` shape the removed cluster sublayer had — a heatmap
in place of the count bubble.)

- **Scope filter:** the heat layer takes the same scope arm as the icon
  (`covScopeFilter()` → exact per-point `ridtok`/`cctok`). It does **not** need
  the curated-ref dedupe or the stays-accessibility narrow — a density surface is
  not a clickable feature. Give it a dedicated `covHeatFilter()` = scope only.
- **Toggles + mode:** `syncCoverageLayers()` and `updateCoverageScopeFilter()`
  already loop `(letter, cc)` — set the heat layer's visibility (the same `show`
  the icon gets: on/off + Curated/Everything letter set) and re-apply its scope
  filter in those loops, exactly as they did for the old `-cl` layer.
- **Rendering:** all heat layers share one **single-hue** ramp (per-letter colors
  don't blend meaningfully in a heatmap; density from every visible letter sums
  additively across the stacked layers into one combined "coverage density"
  surface). Reuse the `rideheat` `heatmap-weight`/`-intensity`/`-radius`/`-opacity`
  zoom ramps as the starting point, retuned for point density (the ride heatmap
  is line-dense; coverage is point-sparse) and a coverage-appropriate hue
  distinct from the ride heatmap's.
- **`clearSpotlight`-style teardown:** none needed — the heat layers live with the
  coverage source and are toggled, not rebuilt.

### 3.3 Contract / docs

- `coverage-provider.md` §4: tiles carry individual points **z6–14** again — z6–10
  **thinned** (density-sample, no clusters) for the overview heatmap, z11–14
  **complete** for the icons. Still no clustering; per-feature tokens.
- `map-and-search.md` §5: coverage renders as a density heatmap at overview,
  individual icons z11+; both scope-filtered exactly (phantom-free). Cite this
  doc.

## 4. Phantom-free — the same guarantee as the icons

Each heatmap point carries its own single region token; the scope filter admits
only in-scope points, so density is contributed only by points **inside** the
region. A soft feather where the surface meets the region edge is honest (real
coverage sits near the border), not a false marker at a member-centroid the way a
cluster bubble was. No aggregation, no leader, no centroid — nothing to leak.

## 5. What is unchanged

- **Icons z11+** — the whole no-cluster rendering, untouched.
- **Rail `/counts`, `/coverage/poi|search|nearby`** — untouched (the counts stay
  the exact total; the heatmap is a visual, not a count).
- **Confirmed-pin clusters**, **ride heatmap (L layer)** — separate systems,
  untouched. The coverage heatmap is a distinct layer set with its own hue.
- **Scope-filter token format** — unchanged.

## 6. Out of scope

- A **baked low-zoom density grid** (aggregating points into weighted cells for a
  smoother, thinning-independent surface) is a possible v2 refinement; v1 reuses
  the thinned tile sample for free. Note the v1 limitation in the spec's execution
  note if the thinned surface looks patchy at z6–7.
- The **GPX ride-check ∪ coverage** unification — still its own separate spec.

## 7. Testing

- **Pipeline:** update the no-cluster test `test_no_clusters_individual_points_from_z11`
  — it asserts z6 tiles are **absent**; now z6 tiles are **present** but carry
  **no `point_count`** (individual/thinned points, no clusters). Rename to reflect
  "individual points z6–14, no clusters". Keep the z11-no-`point_count` assertion;
  add a z6-present assertion. `make pipeline-test` green.
- **Client:** `make scope-test` unaffected (`scope.js` unchanged). `map.js` is the
  untested serialization layer.
- **Browser (acceptance):** scope to Gelderland/Hesse/Bavaria; at the landing zoom
  (z7–9) a **coverage-density heatmap** shows inside the region (soft edge at the
  border, nothing far outside); zoom past ~z11 and the heatmap fades out as
  individual icons fade in; toggling a letter and switching Curated/Everything
  updates the heat consistently with the icons; a different scope shows the heat
  only within it. 0 console errors.

## 8. Deploy

One coverage **re-tile** (`make coverage-refresh`) to rebuild the PMTiles at
`minzoom 6`. No DB migration, no re-harvest.

## 9. Constraints

- Branch `symfony-base`. Commit per task. **Never push.** No `Co-Authored-By`.
  Explicit pathspecs only.
- `.js` SPDX first line; Python keeps its SPDX header. Section refs doc-qualified.
