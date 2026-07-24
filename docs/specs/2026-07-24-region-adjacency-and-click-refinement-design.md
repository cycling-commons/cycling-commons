# Region adjacency & click refinement — design (item 4)

**Status:** **EXECUTED 2026-07-24** (see §7 execution notes; HTTP-verified registry + JS/PHP gates; visual Playwright checks deferred).
**Supersedes:** the edge-distance ranking approach deferred in
[2026-07-23-cross-border-chips-design.md §9](2026-07-23-cross-border-chips-design.md).
**Related:** [2026-07-22-scope-selector-scale-design.md](2026-07-22-scope-selector-scale-design.md)
(compass grid, `regionOfPoint`, `contextualRegions`),
[region-scoping-design.md](region-scoping-design.md) (region registry, boundary endpoints).

Item 4 is the last unbuilt piece of the 2026-07 map-scope window. It has two
independent parts that share a theme (region boundary geometry) but touch
different code and can ship separately:

- **Part A — adjacency-gated cross-border chips.** Fix which regions a rider is
  *offered* near a border. No client geometry.
- **Part B — point-in-polygon click refinement.** Fix which region a rider
  *lands in* when they click the map directly.

---

## 1. The problem

### 1.1 Ranking (Part A)

The shipped cross-border chips
([2026-07-23-cross-border-chips-design.md](2026-07-23-cross-border-chips-design.md))
rank candidate regions by distance from an anchor to each region's **bbox
centroid** (`rankByGroundDistance` in `web/assets/map/scope.js`). Centroid
distance misjudges cross-border neighbours in both directions:

| active region | actually borders | offered today | why centroid fails |
|---|---|---|---|
| Groningen | Lower Saxony | only `Bremen` (8th) | Lower Saxony's centroid is far east (near Hannover); compact Bremen's centroid wins |
| Drenthe | Lower Saxony | only `Bremen` | same |
| Overijssel | Lower Saxony, NRW | **nothing German** | both German centroids are far; 8 nearer Dutch centroids fill every slot |
| Bremen | (enclave, only borders Lower Saxony) | Friesland/Groningen/Drenthe (NL) | its surrounder Lower Saxony has a far centroid, so "nearest" reaches the Dutch coast |

An **edge-distance** metric (anchor → nearest polygon edge) was considered and
rejected: it fixes the four cases above but over-reaches. Utrecht's edges are
26 km from Nordrhein-Westfalen and 41 km from Flanders — nearer than Friesland
(53 km) — so edge distance pulls two foreign chips into Utrecht's compass even
though **Gelderland sits between Utrecht and Germany**. Offering a region you can
only reach by passing through one you are already offered is noise. Owner
verdict (2026-07-24): Utrecht must stay all-Dutch.

The metric that satisfies every case is **adjacency**: a foreign region is
offered only when it **shares a border** with the active region. Verified
against the live 32-region DB (`ST_Intersects` on full geometry, 660 ms for the
whole table):

| active region | border-neighbours (`*` = foreign) |
|---|---|
| Groningen | Drenthe, Friesland, Lower Saxony\* |
| Drenthe | Overijssel, Groningen, Lower Saxony\*, Friesland |
| Overijssel | Gelderland, Drenthe, Lower Saxony\*, Flevoland, Friesland, NRW\* |
| Bremen | Lower Saxony (only — enclave) |
| **Utrecht** | **Gelderland, Noord-Holland, Zuid-Holland, Flevoland (all Dutch)** |
| Bayern | Baden-Württemberg, Thüringen, Hessen, Sachsen (all German) |

Every owner complaint is corrected, and Utrecht/Bayern stay single-country by
construction, not by tuning.

### 1.2 Click (Part B)

`regionOfPoint(lng, lat)` (`web/assets/map/scope.js`) resolves a map click to a
region by (1) collecting every region whose **bbox** contains the point, then
(2) on 2+ candidates, picking the one whose bbox **centre** is nearest. Step 2
is a guess. A bbox is a rectangle around a wiggly polygon, so neighbouring
regions' bboxes overlap even when the polygons do not touch (verified for
`gelderland`↔`noord-holland` and `groningen`↔`overijssel`: bboxes intersect,
polygons do not). In that overlap wedge a click sits inside exactly one
polygon, but nearest-centre can pick the other — wrong region, wrong POIs. The
classic shape is a concave region like Flevoland whose bbox swallows water and
overlaps three neighbours.

---

## 2. Part A — adjacency-gated cross-border chips

### 2.1 Server: compute and persist adjacency

Adjacency is a **derived, symmetric** property of region geometry, recomputed
every import exactly like `region_id` membership — never authored. It is
computed across **all** onboarded countries (that is what makes it
cross-border).

- **Storage:** a new nullable `region.adj integer[]` column — the ids of every
  region sharing a border with this one. A migration adds the column (the
  `region` table IS Doctrine-migration-managed, unlike `coverage_poi`).
- **Compute:** a new step in `ImportCatalogCommand::execute()`, inside the
  existing transaction, **after** `importRegions()` (needs every region present)
  and independent of `recomputeMembership()`. One statement:

  ```sql
  UPDATE region a SET adj = COALESCE((
      SELECT array_agg(b.id ORDER BY b.id)
      FROM region b
      WHERE b.id <> a.id AND ST_Intersects(a.geom, b.geom)
  ), '{}');
  ```

  `ST_Intersects` uses the existing `idx_region_geom` GiST index (bbox prefilter
  → exact only on truly-touching pairs), so cost stays sub-second at the current
  scale and scales with border count, not region count². Full geometry, not
  simplified — a simplification gap must never drop a real border (Part A is
  about correctness, and the vertices are already in the DB).

  A same-country digitization sliver (two admin polygons that overlap by a hair)
  registers as adjacency — harmless: same-country neighbours are eligible
  regardless, so a spurious same-country edge changes nothing. Only
  **cross-country** edges gate anything, and those are real shared borders.

### 2.2 Registry: expose `adj` to the client

`RegionRegistryProvider::all()` gains `adj` in its `SELECT` and output row, so
each `window.CC_REGIONS` entry becomes
`{ id, slug, countryCode, bbox, adj: [ids] }`. Inlined into the map page like
the rest of the registry (`web/templates/map/index.html.twig`). Cost: ~3–6 ints
per region (a region borders a bounded number of others no matter how large the
world grows), so this scales worldwide. The return-type docblock and the
`@return list<array{...}>` annotation are updated.

### 2.3 Client: gate foreign chips by adjacency

`chipModel` (`web/assets/map/scope-chips.js`) currently draws its pool from
`scopeApi.regionsNear(...)` (all regions, centroid-ranked). Change:

- **Eligibility gate.** A region is eligible for the pool iff it is
  (a) the **same country** as the active region, OR
  (b) in the active region's **`adj`** set (a true border-neighbour, any
  country). A foreign region NOT in `adj` is excluded.
- **Compass ordering — adj-first, then domestic fill.** Eligibility alone is not
  enough for the compass: under pure centroid rank among eligible regions, a
  border-neighbour whose centroid is far (Overijssel → Lower Saxony / NRW) is
  crowded out by eight nearer same-country centroids and never appears — which
  defeats §1.1. Compass therefore builds the pool in two slices, each still
  centroid-ordered internally via `regionsNear`:
  1. **all** `adj` neighbours of the active region (any country), then
  2. same-country regions that are **not** already in `adj`,
  then `slice(0, 8)` and bearing-place with `compassLayout`. Domestic
  non-neighbours never displace an adj entry; foreign non-neighbours are never
  eligible. The `cc` / `foreign` tagging and the `· CC` serializer cue
  ([2026-07-23-cross-border-chips-design.md §3.2–3.3](2026-07-23-cross-border-chips-design.md))
  are untouched — a foreign chip that passes the gate still renders `Limburg · NL`.
- **Linear ordering — centroid among eligible (unchanged metric).** Linear mode
  keeps a single centroid-ranked pass filtered by the eligibility gate, so an
  Everywhere / country anchor near Duisburg or Amsterdam still surfaces the
  nearest region first. Adj-first is compass-only.
- **Anchor unchanged.** Compass anchors on the active region; linear prefers the
  rider's My-area base. Cross-border still widens *which regions are candidates*,
  never the geographic anchor point.

**Linear mode** (Everywhere / country scope) anchors on a point, not a region,
so it has no `adj` of its own. It resolves the anchor to a region with the
existing **synchronous** bbox `regionOfPoint`, then applies **that** region's
`adj` gate (eligibility only — see ordering above). The bbox resolver (not
Part B's polygon-confirmed one) keeps chip rendering synchronous; a rider's
base is rarely exactly on a border, and adjacent regions have near-identical
`adj` sets, so an occasional bbox mis-resolution changes at most one edge chip.
If the anchor resolves to no region (an Everywhere scope with the map centred
over open sea), the pool falls back to same-country-less — i.e. `regionsNear`
with no gate, today's behaviour.

### 2.4 What changes, concretely

- Groningen: **+ Lower Saxony** (adjacent, centroid missed it), **− Bremen**
  (not adjacent, was a centroid artifact).
- Overijssel: **+ Lower Saxony, + NRW** (both adjacent).
- Bremen: **− the Dutch coast regions** (not adjacent); shows Lower Saxony +
  nearest German fill.
- Utrecht, Bayern: **unchanged** (no foreign region borders them).

The `web/tests/js/scope-chips.test.cjs` ground truth is re-derived **once** to
these adjacency results (this is a deliberate behaviour change, expected to move
the pinning tests, not a regression). `web/tests/js/scope.test.cjs` gains
adjacency-gate unit coverage. The Luxembourg fixture row and the four-country
harvest already present in those tests carry over.

---

## 3. Part B — point-in-polygon click refinement

### 3.1 Pure point-in-polygon primitive

Add `pointInPolygon(point, rings)` to `web/assets/map/scope.js` — a pure
ray-casting test over a GeoJSON polygon's ring array (outer ring + holes),
returning boolean. Its own `node:test` unit tests cover winding direction,
holes (a point in a hole is outside), and on-edge points. No dependency, no
mutation.

### 3.2 A new async resolver; the synchronous one keeps its name

Two methods with a clear split, so chip rendering never goes async:

- **`regionOfPoint(lng, lat)`** — **unchanged, synchronous**, bbox +
  nearest-centre. Retained verbatim for the Part A linear anchor (§2.3) and any
  non-click caller. Its existing pinning tests are untouched.
- **`regionOfPointPrecise(lng, lat)`** — **new, async**. Runs the same bbox pass
  as its **candidate filter**, then refines:
  - **0 candidates** → `null` (no-op click).
  - **1 candidate** → that region. Nothing is fetched; the common case (a click
    deep inside one region) stays instant and offline — the promise resolves
    synchronously-fast with no network.
  - **2+ candidates** → the click is in an overlap wedge. Fetch each candidate's
    boundary polygon and run `pointInPolygon`; the containing polygon wins. If
    the click is inside **none** of them (a simplification gap or open water),
    fall back to **nearest-centre among the candidates** — identical to today's
    `regionOfPoint`, so never a regression.

Only the single live map-click caller (`web/assets/map/map.js`, one site)
switches to `await regionOfPointPrecise(...)`; every other caller stays on the
synchronous `regionOfPoint`.

### 3.3 Polygon source: the existing boundary endpoint

Reuse `/map/region/{slug}/boundary`
(`MapController::regionBoundary` → `RegionBoundaryProvider::featureJson`) as-is:
`ST_SimplifyPreserveTopology` at 0.001° (~70–110 m), `Cache-Control: public,
max-age=3600`, ETag. It is already immutably-ish cached and warmed by the region
spotlight, so click refinement rides the **same cache entry** — no new endpoint,
no new variant. The client caches fetched polygons in memory for the session
(a small `Map<slug, rings>`), so a second ambiguous click in the same overlap
zone fetches nothing.

**Precision caveat (accepted):** at 0.001° a click within ~100 m of a border may
still resolve either way. Acceptable — both answers are defensible that close to
the line, and the shared-cache win outweighs vertex-exactness for a scope click.

---

## 4. Testing

- **Part A server:** an `ImportCatalogCommand` / DB test asserting `region.adj`
  is populated symmetrically and cross-country (e.g. Groningen's `adj` contains
  Lower Saxony's id and vice versa) after import.
- **Part A client:** `scope.test.cjs` gains adjacency-gate cases (a foreign
  non-neighbour is excluded; a foreign neighbour is included; same-country
  regions are never gated). `scope-chips.test.cjs` pinning ground truth
  re-derived to §2.4 (Groningen +LS −Bremen, Overijssel +LS +NRW, Utrecht/Bayern
  unchanged), plus the empty-anchor fallback.
- **Part B:** `pointInPolygon` unit tests (§3.1). A `regionOfPointPrecise` test
  for the overlap wedge (2+ bbox candidates, one containing polygon) and the
  outside-all nearest-centre fallback; the synchronous `regionOfPoint` tests are
  untouched. Async caller path covered by the existing map wiring.
- **Gate:** `make scope-test` (JS), the PHP catalog-import suite, `make
  pipeline-test` unaffected (no pipeline change).
- **Browser (Part A + B):** scope to Groningen → Lower Saxony · DE appears,
  Bremen gone; Overijssel → both German neighbours cued; Utrecht → all Dutch
  (matches the approved layout); click the Gelderland shore under Flevoland's
  bbox → scope lands in Gelderland. Screenshots to `.playwright-mcp/` or
  scratchpad, never the repo root.

---

## 5. Out of scope

- No change to the coverage pipeline, tiles, or `coverage_poi`.
- No replacement of centroid distance as the *within-slice* ranking metric —
  compass uses adjacency to **prioritise** the neighbour slice (§2.3), then
  centroid within each slice; linear uses adjacency only as a filter on a
  single centroid-ranked list. Edge-distance ranking remains rejected (§1.1).
- No prefetch of boundaries (fetch strictly on the ambiguous click path).
- No new public API; both parts are site-internal map endpoints.

---

## 6. Constraints

- Branch `symfony-base`. Commit per task. **Never push.** No `Co-Authored-By`
  trailers. Explicit pathspecs only (a parallel session shares the tree).
- First line of every `.js` file: `// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0`.
- Section refs doc-qualified (never bare `§N`).
- The `region.adj` migration must run in prod before the next catalog import
  relies on it (add to the go-live migration checklist).

## 7. Execution notes

- **2026-07-24, Tasks 1–8 (item 4):** Implemented on `symfony-base` (not pushed). Migration
  `Version20260724120000` adds nullable `region.adj integer[]`. `ImportCatalogCommand` runs
  `recomputeAdjacency()` after `importRegions()` inside the same transaction (`ST_Intersects`
  full-geometry UPDATE → empty array, never NULL, for a region touching nothing).
  `RegionRegistryProvider::all()` ships `adj` as `list<int>` in `CC_REGIONS`. `chipModel`
  gates foreign chips by active/anchor `adj`; compass pool is **adj-first then
  domestic centroid fill** (§2.3 — so Overijssel still offers Lower Saxony + NRW);
  linear keeps centroid order among eligible. `pointInPolygon` + async `regionOfPointPrecise`
  refine ambiguous map clicks; `map.js` click handler awaits the precise resolver. Final
  `make scope-test`: 119 pass / 0 fail. PHPUnit
  `ImportCatalogCommandTest` + `RegionRegistryProviderTest`: exit 0 (both suites
  green under `APP_ENV=test` / `cyclingcommons_test`). HTTP substitute on `:8001/map`:
  Groningen adj len=3 values=[26, 28, 46]. Visual browser checks (Groningen +LS −Bremen chips, Overijssel +both German
  neighbours, Utrecht/Bayern single-country, Flevoland/Gelderland overlap click) were **not**
  run — Playwright profile locked by a parallel session. **Prod:** run migration
  `Version20260724120000` before the next catalog import that relies on `adj` (design §6).

- **2026-07-24 (spec amend):** §2.3 / §5 updated to document compass **adj-first**
  ordering as intentional (was implemented during Task 4 when pure
  eligibility+centroid failed the Overijssel +both-German pinning). Linear mode
  unchanged. No code change in this amend.

---

## 8. Part C — three-tier adjacency spotlight (follow-up, 2026-07-24)

**Goal:** on the map, the active region's border-neighbours (the same regions
offered as scope chips, i.e. its `adj` set) render in a **middle** dim tone —
lighter than the fully-outside world, darker than the clear active region — so a
rider sees where the neighbouring regions they can jump to actually lie.

**Why it needs a new layer, not a tweak.** The spotlight
(`drawSpotlightMask`, `web/assets/map/map.js`) is one dark polygon: the whole
world with the active region punched out as a hole, filled `#101E16` at
`fill-opacity 0.22`, plus a dashed gold outline. A fill can only *add* darkness,
so a neighbour cannot be made *lighter* than the surrounding 0.22 by painting
over it. The neighbours must instead be **excluded from the dark mask** and then
given their own thinner dim.

**Data source.** The active region's neighbour ids are already in
`CC_REGIONS[activeRegion].adj` (Part A). Their union geometry comes from the
**existing** `/map/scope/boundary?rids=<adj ids>` endpoint (the same
`ST_Union` + `ST_SimplifyPreserveTopology`, `Cache-Control: public,
max-age=3600` the country spotlight already uses — `MapController::scopeBoundary`
→ `RegionBoundaryProvider::unionFeatureJson`). No new endpoint, no schema change.
One extra fetch per region spotlight, race-guarded by the existing `_spotReq`
token and session-cacheable by the browser.

**Layering (three tiers).** For a single-region scope with a non-empty `adj`:
1. **Dark mask** `#101E16 @ 0.22` — world with **active ∪ adjacent** punched out
   as holes → the outside world stays fully dim; active *and* adjacent are clear
   of it.
2. **Light mask** `#101E16 @ ~0.10` (tunable live) — a fill over the **adjacent
   union only** → neighbours land at the middle tone. The active region is not
   in the adjacent union, so it stays fully clear.
3. **Active outline** — the existing dashed gold `region-line`, unchanged, so the
   active region keeps the only hard edge ("you are here").

Adjacent regions get **tone only, no outline** — the single dashed border stays
unambiguous. When `adj` is empty (a region bordering nothing) or the union fetch
fails, tiers 2 collapses and the spotlight is exactly today's two-tone — a clean
degrade.

**Scope coverage.** Applies to **single-region scopes only** (the compass case,
the only scope with one active region whose neighbours are defined). Country,
Everywhere, and My-area spotlights are unchanged — they have no single active
region whose `adj` to lighten.

**Interaction.** No new click handling: a click on a lightly-dimmed neighbour is
an ordinary map click, already resolved by `regionOfPointPrecise` (Part B) to
that region and scoped to it — so the neighbour becomes active and the three-tier
spotlight re-centres on it. Consistent with clicking a chip.

**Implementation surface.** `web/assets/map/map.js` only:
- `drawSpotlightMask(g, adjUnion)` gains an optional adjacent-union geometry: when
  present, its rings join the dark mask's holes and a new `region-adj-mask`
  fill layer is added below `region-line`.
- `setSpotlight(slug)` looks up the region's `adj` ids from `CC_REGIONS`, fetches
  `/map/scope/boundary?rids=…` alongside the active boundary, and passes the
  union through — all under one `_spotReq` guard.
- `clearSpotlight()` also removes `region-adj-mask` (source + layer).
- `setCountrySpotlight` / `setCircleSpotlight` pass no adjacent union (two-tone
  unchanged).

No server, test-fixture, or `scope*.js` change — this is the untested
map-serialization layer (like Part B Task 7), browser-verified.

**Status: EXECUTED 2026-07-24** (commits on `symfony-base`, not pushed).
`drawSpotlightMask(g, adjUnion, fullUnion)` punches the **dissolved**
`ST_Union(active + neighbours)` (`fullUnion`) out of the dark mask and adds a
`region-adj-mask` fill at `fill-opacity 0.10` below `region-line`; `setSpotlight`
fetches two unions off `/map/scope/boundary` — `rids=<adj>` (light fill) and
`rids=<active,adj>` (dark holes) — alongside the active boundary under one
`_spotReq` guard (both degrade to null → clean two-tone on any failure); the
light fill is gated on `fullUnion` being present so it never double-darkens;
`clearSpotlight` drops `region-adj-mask`.

**Critical fix (same day):** the first cut punched the active region and each
neighbour into the dark mask as **separate** rings. Adjacent regions share
borders, so those holes touched, and MapLibre's earcut tessellator emitted
triangular artifacts to the world-ring corners (dark sea spike for Groningen,
diagonal wedges for Utrecht). Fixed by punching the single dissolved `fullUnion`
blob instead of touching rings.

Browser-verified on `:8001/map`: Gelderland (7 neighbours lightened, all three
`/boundary` requests fired), Overijssel (**Lower Saxony · DE** + NRW lightened
across the national border), and the two artifact cases (Groningen, Utrecht) now
clean; region switch re-centres with no stale layers; 0 console errors.
Middle-tone opacity `0.10` is a live-tunable default.