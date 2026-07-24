# Region adjacency & click refinement — design (item 4)

**Status:** approved 2026-07-24, not yet implemented.
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
- **Ordering unchanged.** Eligible regions are still centroid-ranked
  (`regionsNear` / `rankByGroundDistance`) and bearing-placed
  (`compassLayout`), capped at 8, with the existing overflow row. The `cc` /
  `foreign` tagging and the `· CC` serializer cue
  ([2026-07-23-cross-border-chips-design.md §3.2–3.3](2026-07-23-cross-border-chips-design.md))
  are untouched — a foreign chip that passes the gate still renders `Limburg · NL`.
- **Anchor unchanged.** Compass anchors on the active region; linear prefers the
  rider's My-area base. Cross-border still widens *which regions are candidates*,
  never the anchor.

**Linear mode** (Everywhere / country scope) anchors on a point, not a region,
so it has no `adj` of its own. It resolves the anchor to a region with the
existing **synchronous** bbox `regionOfPoint`, then applies **that** region's
`adj` gate. The bbox resolver (not Part B's polygon-confirmed one) keeps chip
rendering synchronous; a rider's base is rarely exactly on a border, and
adjacent regions have near-identical `adj` sets, so an occasional bbox
mis-resolution changes at most one edge chip. If the anchor resolves to no
region (an Everywhere scope with the map centred over open sea), the pool falls
back to same-country-less — i.e. `regionsNear` with no gate, today's behaviour.

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
- No change to the distance metric for **ordering** — adjacency is a *filter*
  layered on the existing centroid ranking, not a replacement for it.
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
