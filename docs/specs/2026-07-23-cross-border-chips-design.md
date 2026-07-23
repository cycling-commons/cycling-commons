<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Cross-border scope chips — design

**Status:** design, approved by the owner 2026-07-23, not executed.
**Audience:** contributors working on the map front end.
**Builds on:** the `scope-chips.js` view model extracted in
`2026-07-23-map-js-phase0-extraction-design.md` (Phase 0). This is the "next change" that spec's
§6 anticipated.

## 1. The problem, measured

Scope chips are ranked and offered **within one country** — `contextualRegions(cc)` is
country-scoped by construction (`byCountry.get(cc)`). So a foreign region can never appear, however
close it is. Ranking all onboarded regions by ground distance from Duisburg (6.76, 51.43):

```
 1.  63 km  NL limburg-nl          <- nearest region of ANY country
 2.  63 km  DE nordrhein-westfalen
 3.  97 km  NL gelderland
 4. 114 km  NL noord-brabant
 ...
```

Six of the eight nearest regions are Dutch, yet a rider scoped to NRW is offered German Hamburg
(309 km) and Thüringen (318 km) while Dutch Limburg — five times closer, just across the river — is
unreachable from the chips. For a cycling platform that is backwards, and in the compass grid it is
visibly wrong: NRW's western neighbours *are* Dutch, so W/NW/SW sit empty while the east is full.

## 2. The decision (owner)

Rank by ground distance across **all onboarded countries**, with **no home-country quota**. This
needs no border-region special-casing: Bavaria's nearest eight are all German anyway, so the
cross-border behaviour **emerges only where geography warrants it**. It composes naturally with the
compass grid (`compassLayout` is already pure geometry, country-agnostic).

## 3. Design

### 3.1 New pure method — `scope.js` `regionsNear(near, opts)`

The one genuinely new unit. Ranks **all** onboarded regions (not `byCountry.get(cc)`) by
ground distance from `near`, nearest-first, capped.

```
regionsNear([lng, lat], { limit = 8 }) -> Region[]
```

Reuses the exact `cos(lat)`-corrected squared-degree distance `contextualRegions({near})` and
`compassLayout` already use — a longitude degree is ~0.65 of a latitude degree at 50°N, and skipping
the correction over-weights east-west separation. Pure: no DOM, no storage, no mutation. Its
country-scoped sibling `contextualRegions(cc)` is **unchanged** — it remains the source for the
"All &lt;country&gt;" rung and stays valid for its existing callers.

### 3.2 `chipModel` (scope-chips.js) — additive changes

Exactly the widening Phase 0 §6 predicted; the input signature does not change.

- **Pool.** Both the compass pool and the linear list draw from `scopeApi.regionsNear(near, …)`
  instead of `scopeApi.contextualRegions(cc, {near})`. Applies wherever we rank by distance — the
  owner chose "everywhere", so country and everywhere scopes get it too (they anchor on their own
  centre, so they stay same-country unless a My-area near a border pulls a foreign neighbour in).
- **Country cue.** Every chip and compass cell gains two fields:
  - `cc` — the region's own `countryCode`
  - `foreign` — `true` when `cc` differs from the active country (`model.country.cc`)
  The shape-contract test uses `k in cell`, so adding keys is structurally safe.
- **`model.more` redefined.** Today `more = contextualRegions(cc, {limit: Infinity}).length >
  shown.length` — the active country's total. That meaning is wrong once the shown set mixes
  countries. It becomes: **more onboarded regions exist than are shown, anywhere** — compared
  against the global registry count (`input.registry.length`, already passed in). With ~32 regions
  and ~9 shown, "More" now nearly always appears, and it opens search, which reaches every region.
  This is honest: "there's more, use search."
- **Unchanged.** The "All &lt;country&gt;" rung stays the **active** scope's country (owner
  decision). The anchor stays as Phase 0 preserved it — compass on `scopeCenter`, linear preferring
  the rider's My-area base. Cross-border widens *which regions are candidates*, never the anchor
  point. The countries-fallback (cold-start, no cc) mode is untouched.

### 3.3 Serializer (map.js `renderScopeChips`)

The model returns the cue as data; map.js formats it, staying a serializer with no ranking logic
(the Phase 0 boundary). A foreign chip renders its label followed by a middot and the uppercase ISO
code; a native chip renders the bare label:

```
native chip:   Bayern
foreign chip:  Limburg · NL
```

The `· NL` is part of the button text, so it flows into the existing compass `aria-label`
(`{dir}: {region}`) — the country is in the accessible name, not conveyed by styling alone. Only the
serializer knows the "· CC" presentation; the model stays semantic (`cc` + `foreign`).

## 4. Return shape (delta from Phase 0)

```js
// linear chip / compass region cell — two new keys:
{ kind, slug, label, dir,   cc, foreign }
//                          ^^^^^^^^^^^^^ added
```

`cc` is always present on a region/center cell and on a linear chip; `foreign` is a boolean. Empty
cells keep `cc: null, foreign: false`. Every other field is exactly as Phase 0 defined it.

## 5. Verification — this is a deliberate behaviour change

Phase 0's pinning tests locked in **country-scoped** pools. Those expectations **change on purpose**
now, and the change is the point. New ground truth is computed from the real region bboxes (the same
method Phase 0 used), not guessed:

| case | Phase 0 (country-scoped) | now (cross-border) |
|---|---|---|
| Flanders (BE) compass | only Brussels + Wallonia | + nearest Dutch regions (Zeeland, Noord-Brabant) |
| NRW / Duisburg | German-only, Hamburg & Thüringen shown | Dutch Limburg/Gelderland surface, ranked by distance |
| Utrecht (deep in NL) | Dutch neighbours | unchanged — its nearest 8 are all Dutch |
| Bavaria (DE interior) | German neighbours | unchanged — its nearest 8 are all German |
| `more` flag | active-country total | global registry total |

New tests to add:
- `regionsNear` ranks across countries (the Duisburg ordering above), and the `cos(lat)` correction
  is exercised (a foreign region east-west of the anchor is not mis-ranked).
- `chipModel` sets `foreign`/`cc` correctly: a Dutch chip under a German scope is `foreign: true`
  with `cc: 'NL'`; the active region's own cell is `foreign: false`.
- `more` reflects the global total.
- Bavaria/Utrecht stay single-country — the regression guard that proves cross-border **emerges from
  geography** rather than always firing.

`make scope-test` green. The serializer's "· CC" formatting is covered by the Phase 0 HTML-parity
approach (a foreign chip's rendered text includes the suffix) plus one live browser check that a
cross-border scope (NRW) shows Dutch chips with the cue and zero console errors.

## 6. Out of scope

- No new letters, no schema, no data change. View model + serializer only, on the tested surface.
  The coverage data it surfaces (Dutch/Luxembourg POIs near a German border) is already live from
  the 2026-07-23 four-country harvest.
- Clicking a foreign chip already works — it sets that region scope; `setRegion` is country-agnostic.
- The "All &lt;country&gt;" rung stays single (the active country). A second "All Netherlands" rung
  when Dutch chips appear is deliberately not added — a rider who wants all-NL clicks a Dutch region
  first.

## 7. Sequencing

This is item 3 of the four queued for the window; items 1 (border-overlap) and 2 (Phase 0
extraction) are done, and the four-country harvest that gives item 3 real cross-border data to show
has run. Item 4 (region-boundary HTTP cache + point-in-polygon click) remains and is independent.
