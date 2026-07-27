# Region ranking by polygon-edge distance — design (item 4, part 3)

**Status:** **EXECUTED 2026-07-27** (see §6). Branch `symfony-base`, not pushed.
**Completes:** [2026-07-24-region-adjacency-and-click-refinement-design.md](2026-07-24-region-adjacency-and-click-refinement-design.md)
(item 4 parts A + B shipped 07-24; this is the third and last piece).
**Supersedes:** that document's §5 line "Edge-distance ranking remains rejected
(§1.1)" — see §2 below for why the rejection no longer applies.
**Related:** [2026-07-23-cross-border-chips-design.md](2026-07-23-cross-border-chips-design.md),
[2026-07-22-scope-selector-scale-design.md](2026-07-22-scope-selector-scale-design.md)
(compass grid), [region-scoping-design.md](region-scoping-design.md).

---

## 1. The problem

`rankByGroundDistance` (`web/assets/map/scope.js`) ordered candidate regions by
the distance from an anchor to each region's **bbox centre**. A bbox centre is a
poor stand-in for "how far away is this region" whenever a region is large or
oddly shaped, and the error is not small. Measured on the live 32-region
registry, from Groningen's own centre:

| region | edge distance | bbox-centre distance |
|---|---|---|
| **Niedersachsen** — the region Groningen actually borders | **26 km** | far (centroid sits out by Hannover) |
| **Bremen** — does not border Groningen at all | **119 km** | nearer, because it is compact |

So the metric ranked a region 119 km away above the one whose border Groningen
touches. Every consumer inherited that: the compass pool's `slice(0, 8)` cut,
the linear-mode chip order, and `contextualRegions(cc, {near})`.

The most visible symptom needed no geometry at all to see: with a `country: DE`
scope anchored in **Duisburg**, the first chip offered was Dutch Limburg. The
rider was standing *inside* Nordrhein-Westfalen, whose centroid is 80 km east.

## 2. Why this is not the metric that was rejected

[2026-07-24-region-adjacency-and-click-refinement-design.md §1.1](2026-07-24-region-adjacency-and-click-refinement-design.md)
tried edge distance and rejected it, because Utrecht's edges are 26 km from
Nordrhein-Westfalen and 41 km from Flanders — nearer than Dutch Friesland — so
edge distance pulled two foreign chips into Utrecht's compass even though
Gelderland lies in between. Owner verdict: Utrecht must stay all-Dutch.

That rejection was of edge distance as an **eligibility** rule, and it still
stands. Eligibility is now decided by **adjacency** (part A, shipped): a foreign
region is offered only when it shares a border with the active region. Utrecht
borders no foreign region, so NRW and Flanders are excluded *before* ranking
runs, no matter what they measure.

Edge distance now only **orders a pool adjacency has already chosen**. The two
mechanisms are orthogonal and the Utrecht case is pinned as a test
(`compass: Utrecht offers its true neighbours` asserts every offered chip is
`countryCode === 'NL'`), so the over-reach cannot come back through the ranking
door.

The **bbox-extent shortcut** — distance to the region's rectangle rather than to
its polygon — was prototyped and declined by the owner. This design uses real
polygon geometry. Do not re-propose the rectangle.

## 3. Design

Ranking must stay **synchronous** — `chipModel` renders chips on every scope
change and cannot go async (that constraint is why part A's linear anchor uses
the synchronous `regionOfPoint`). So the geometry has to be on the client
already, which makes payload the governing constraint.

### 3.1 A simplified ranking outline, shipped in the registry

New nullable `region.outline jsonb`, derived and recomputed every catalog import
beside `adj`, never authored. Per region: a list of rings, each a **flat**
`[lng, lat, lng, lat, …]` array.

It is deliberately a **ranking metric, not a geometry source** — the real
boundaries still come from `RegionBoundaryProvider` / `/map/region/{slug}/boundary`
— so every knob is set for bytes:

| choice | value | why |
|---|---|---|
| parts kept | area ≥ max(1% of the region, 5 km²) | drops Wadden islets and Zeeland slivers; the largest part always survives whatever its size, so a small region can never end up with no outline |
| rings | **exterior only** | a hole cannot change which region is nearest |
| simplification | `ST_SimplifyPreserveTopology` at **0.05°** (~5.5 km) | the distinctions this metric makes are tens of km; the errors it replaces were hundreds |
| coordinates | 3 decimals (~110 m) | rounding must never dominate simplification |
| encoding | flat pairs | one array per ring, no per-point array allocation on the client |

Measured on the 32 onboarded regions: **40 rings, 1,142 points, 17.7 kB** of
JSON. The map page goes 42.6 → 58.1 kB raw, **12.7 → 18.5 kB gzipped**.

**Scale caveat, recorded honestly.** This is inlined per page load, so it grows
linearly with the number of onboarded regions — as `bbox`, `adj` and the labels
in `CC_REGIONS` already do. At ~1,000 regions the registry is a problem with or
without outlines; the fix then is to serve `CC_REGIONS` as a cacheable endpoint
or scope it to the viewport, which is a change to the registry as a whole, not
to this column.

### 3.2 The client metric

`groundDistance2(region, lng, lat, kx)` in `scope.js` returns the squared
distance from the anchor to the nearest point on the outline, in **corrected
degrees squared** — the same unit the centre metric used, which is what lets the
fallback below stay directly comparable:

- **inside** any ring → **0** (ray-cast containment on the flat array). Standing
  in a region means it is the nearest one, which is exactly what the Duisburg
  case needed.
- otherwise the minimum over every ring segment of a point-to-segment distance,
  with the anchor translated to the origin and longitudes scaled by `cos(lat)` —
  the same correction `regionOfPoint` and `compassLayout` already apply.
- **no usable outline → the bbox centre**, unchanged. A region imported before
  the column existed, a hand-edited row, or a test fixture with bboxes only all
  keep ranking sanely rather than sorting as `NaN`.

Exported as `CCScope.edgeDistanceKm(near, region)` (kilometres) for callers that
want the number, and as the sort key inside `rankByGroundDistance`.

### 3.3 What deliberately does NOT change

- **`compassLayout` still places by bearing to the bbox centre.** Which *cell* a
  region lands in is a separate owner decision
  ([2026-07-22-scope-selector-scale-design.md §B](2026-07-22-scope-selector-scale-design.md));
  this change is scoped to the ranking metric.
- **`regionOfPoint` / `regionOfPointPrecise`** keep their own nearest-centre
  tiebreak. Click resolution already has a real point-in-polygon path (part B).
- **The adjacency gate and the adj-first compass ordering** are untouched.

## 4. Testing

- `web/tests/js/scope.test.cjs` — synthetic unit coverage of the new metric:
  edge beats centre when the two disagree, an inside anchor scores 0, a region
  with no outline still ranks by bbox centre, the `cos(lat)` correction survives,
  `edgeDistanceKm` in km, and a degenerate/missing ring returns a finite fallback
  rather than throwing.
- `web/tests/js/scope-chips.test.cjs` — real outlines stamped onto the existing
  32-region fixture from `web/tests/js/fixtures/region-outlines.cjs`, generated
  from the live DB by **the same statement the import runs**, and verified byte
  for byte against it, so what the tests rank is what the browser ranks.
- `web/tests/Catalog/RegionRegistryProviderTest.php` — the registry ships
  `outline`, and it is **flat** pairs. A nested `[[lng,lat],…]` would make the
  client read every region as "no outline" and silently fall back to bbox
  centres with nothing failing, so the flatness is asserted explicitly.
- Gate: `make scope-test`, the Catalog PHPUnit suite, `web/tools/check-spdx.sh`.

## 5. Constraints

- Branch `symfony-base`. Commit per task. **Never push.** No `Co-Authored-By`.
- First line of every `.js` file: `// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0`.
- Section refs doc-qualified (never bare `§N`).
- **Prod:** `Version20260727120000` must run before the next catalog import.
  It backfills existing regions itself (by referencing
  `ImportCatalogCommand::OUTLINE_SQL` rather than copying it, so the two cannot
  drift), so the ranking improves without waiting for a re-import.

## 6. Execution notes

**2026-07-27, executed on `symfony-base` (not pushed).**

- `Version20260727120000` adds nullable `region.outline jsonb` and backfills.
  `ImportCatalogCommand::recomputeOutlines()` runs after `recomputeAdjacency()`
  inside the same transaction; the statement lives in the public
  `OUTLINE_SQL` constant the migration reuses.
- `RegionRegistryProvider::all()` ships `outline` as `list<list<float>>`;
  a malformed row decodes to `[]` rather than 500ing the map page.
- `scope.js` gains `segDist2`, `pointInFlatRing`, `groundDistance2` and the
  `edgeDistanceKm` export; `rankByGroundDistance` sorts on `groundDistance2`.
- **`make scope-test`: 125 pass / 0 fail.** Catalog PHPUnit: 174/174 (8 skipped)
  on a freshly reset test DB.
- **Live browser check** (`/map`, 0 console errors): 32/32 regions ship an
  outline; from Groningen's centre Niedersachsen measures **26 km** and Bremen
  **119 km**, and `regionsNear` returns
  `[groningen, drenthe, niedersachsen, friesland, overijssel]`. Utrecht's five
  nearest are all Dutch.

**Four pinning tests moved, each re-derived deliberately** (this is a behaviour
change, expected to move them — the note the 07-24 design made about its own
adjacency change applies again):

1. *Duisburg linear* — first chip is now `nordrhein-westfalen` (the region the
   anchor is inside, distance 0) instead of `limburg-nl`. Limburg keeps second
   place, so the cross-border reach the test exists for is unchanged.
2. *Utrecht compass* — the four adjacent regions and their cells are unchanged;
   the four **domestic fill** slots re-sort. Friesland's southern shore is 80 km
   from Utrecht's centre and Zeeland's nearest land 82 km — the reverse of what
   their centroids said — so Friesland takes the eighth slot and fills the
   previously empty NW cell, and Zeeland drops out. Groningen and Drenthe, the
   regions the old anchor bug really did surface over adjacent neighbours, stay
   out at 93 km+. The test now also asserts every offered chip is Dutch.
3. *Bayern overflow* — third entry is `niedersachsen`, not
   `nordrhein-westfalen`: Lower Saxony's southern edge reaches nearer to Bavaria
   than NRW's does, which its far-north-west centroid hid.
4. *empty-cell contract* — moved from NRW to Bayern, because NRW now places all
   eight of its pool and has no empty cell left.

**One trap worth recording.** The Catalog suite failed with three unrelated-
looking assertion diffs until the test DB was reset: running
`app:catalog:import` by hand against `cyclingcommons_test` while debugging left
`test-square` rows behind, and the adjacency/membership tests count regions.
`make test-db-reset` is the fix, and the failures are not a signal about the
change.
