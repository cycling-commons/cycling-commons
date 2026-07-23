<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# map.js Phase 0 — extract the scope-chip view model — design

**Status:** executed 2026-07-23 (f42c6dd, b986541); live browser pass outstanding — see §8.
**Audience:** contributors working on the map front end.
**Scope:** a strictly behaviour-preserving refactor. No user-visible change.

## 1. Why now

`renderScopeChips()` (`web/assets/map/map.js:244-398`, ~155 lines) decides *which*
scope chips to offer and then *renders* them, in one function, inside a 4,077-line
classic script that has no test harness of any kind.

The decision half is pure: given a scope, a region registry and an anchor point, the
chip set is a deterministic function of its inputs. The rendering half is not: it is
`innerHTML` string concatenation plus DOM event binding.

Two rounds of owner-reported bugs landed in the decision half in a single day
(2026-07-23), and **both escaped the existing 69-test suite because they lived in
map.js rather than in `scope.js`**:

- the overflow "More regions…" chip was **dead code** — `renderScopeChips` computed its
  total with a bare `contextualRegions(cc)`, which applies the same default cap of 8, so
  `total` was never greater than `shown`. Germany showed 8 of 16 regions with no way to
  reach the other 8. Found only by opening a browser.
- the compass ranking anchored on `map.getCenter()`, which at call time still holds the
  *outgoing* scope, so Utrecht's chips were ranked against Germany's centroid. The
  ranking maths in `scope.js` was correct and independently verified; the caller in
  map.js passed it the wrong origin.

Both are caller-side defects in untested code calling tested code. The
cross-border chips change (owner observation 2026-07-23: a rider in Duisburg is offered
Hamburg and Thüringen while Dutch Limburg, five times closer, is hidden) rewrites
exactly this decision logic and will add a country-cue rule on top. Extracting first
means that change lands as a small diff on a tested surface.

## 2. What moves and what stays

Line references in §1-§2 are to the pre-refactor file (`f8de3a7`); see §8 for what
actually landed and where it lives now.

**Moves** into the new module — every decision, none of them DOM-aware:

| concern | today |
|---|---|
| which country's regions to offer (`isDefault` precedence over the active scope) | map.js:256-258 |
| anchor resolution: My-area base → incoming scope centre → map centre | map.js:274-277 |
| the uncapped total, and therefore the `more` flag | map.js:282, 350, 363 |
| compass vs linear vs countries mode selection | map.js:289-290, 369 |
| the 9-nearest pool, minus the active region, capped at 8 | map.js:296-297 |
| compass row assembly and whole-empty-row dropping | map.js:310-317 |
| the `All <country>` rung's label fallback | map.js:353, 366 |
| the cold-start countries list and its pinned-`en` label sort | map.js:375-379 |

**Stays** in `renderScopeChips()` — everything that touches the DOM or the catalogue:

- `escPend` / `tpl` interpolation and the `innerHTML` build
- the `dir` → `D.compassNw` translation lookup (the model returns semantic
  direction keys, never translated strings)
- `host.classList.toggle('cc-grid', gridMode)`
- `button[data-scope]` binding, the `#scopeMoreBtn` → `focusSearchBox` binding, and
  active-chip marking via `scopeToken`

`renderScopeChips()` was predicted to drop to roughly 45 lines; it actually landed at 69
(§8) — the estimate undercounted the DOM-binding and active-chip-marking code that stays,
which this table's bullet list names but does not each count. It contains no decisions
either way.

## 3. The module

New file `web/assets/map/scope-chips.js`. It is a browser IIFE with the same dual-export
guard `scope.js` uses (`window.CCScopeChips` + `module.exports` when present), so
`node --test` can require it directly.

It is **not** merged into `scope.js`. `scope.js` is the scope *state* model — it owns
persistence, the URL token, the `cc:scopechange` event and the pure geometry. The chip
set is a *view* model derived from that state. Keeping them apart also stops `scope.js`
(678 lines) from growing past ~800 as the cross-border country cue arrives.

### Signature

```js
chipModel({
  scope,            // {kind, regionIds, countryCode} — the ACTIVE scope
  isDefault,        // CCScope.isDefault()
  activeRegions,    // CCScope.regions() — regions of the active scope
  registry,         // window.CC_REGIONS, for the countries fallback
  inferredCountry,  // CCScope.inferHomeCountry() | null
  scopeCenter,      // CCScope.scopeCenter() — [lng,lat] | null
  mapCenter,        // [lng,lat] — Everywhere fallback only
  myArea,           // window.CC_MY_AREA — {lat,lng} | null
}, scopeApi)
```

`scopeApi` supplies `contextualRegions` and `compassLayout`. Injecting them rather than
reaching for `window.CCScope` keeps `chipModel` deterministic and lets a test pass the
real `CCScope` (required from `scope.js`) for integration realism, or a stub to force an
edge case that no real registry produces.

### Return shape

```js
{
  mode: 'compass' | 'linear' | 'countries',
  country:   {cc, label} | null,   // the "All <country>" rung; null in countries mode
  rows:      [[cell, cell, cell], ...],   // compass only; empty rows ALREADY dropped
  chips:     [{slug, label}],      // linear mode
  overflow:  [{slug, label}],      // regions compassLayout could not place
  more:      bool,                 // render the "More regions…" chip
  countries: [{cc, label}],        // countries mode, label-sorted
}
```

A `cell` is `{kind: 'center'|'region'|'empty', slug, label, dir}`. On a `region` cell
`dir` is one of `n ne e se s sw w nw`; on `center` and `empty` cells `dir` is `null`, and
on an `empty` cell `slug` and `label` are `null` too. Row-major order is the visual order,
which is also the document and tab order — the accessibility property the current code
establishes and this refactor must not lose.

Every key above is always present. Keys that do not apply to the returned `mode` are
empty (`[]` / `null`), never `undefined`, so a caller never branches on existence.

## 4. Verification

The refactor is behaviour-preserving by construction, so the tests are written **first**,
against `chipModel`, pinning today's output. The cases come from behaviour already
browser-verified and recorded in the run ledger, so each one is a known-good expectation
rather than a fresh guess:

| case | pins |
|---|---|
| Utrecht scope | neighbours offered; Groningen/Drenthe/Friesland absent (the anchor bug) |
| Groningen, Limburg, Bavaria scopes | anchor tracks the scope, not one lucky centre |
| Flanders scope | whole N row dropped — Brussels and Wallonia both lie south |
| Germany, 16 regions | `more: true`, 8 shown (the dead-overflow bug) |
| Belgium, 3 regions | `more: false` |
| Bavaria | `overflow` non-empty — a corner region cannot fill the far cells |
| no country resolvable | `mode: 'countries'`, label-sorted, pinned `en` collator |
| `isDefault` + inferred home | inferred country wins over the startup default's cc |
| explicit scope | the scope's own country wins over the inferred home |
| My-area present | `myArea` beats `scopeCenter` as the anchor |
| Everywhere scope | `scopeCenter` null → `mapCenter` fallback, `mode: 'linear'` |

Then map.js is rewired to consume the model, and one browser pass confirms nothing moved:
Germany's grid, Belgium's linear list, the More chip focusing `INPUT#search`, and zero
console errors.

Gate: `make scope-test` (`node --test web/tests/js/*.test.cjs`) green, existing 69 tests
untouched and still passing.

## 5. Wiring

`web/templates/map/index.html.twig` gains one `<script>` tag for `map/scope-chips.js`,
placed after `map/scope.js` and before `map/catalog-load.js` (which injects map.js).

The only real ordering constraint is **before `map/catalog-load.js`**. `scope-chips.js`
has no load-time dependency on `scope.js`: at load it does nothing but assign
`window.CCScopeChips`, and `window.CCScope` is looked up later, at call time, by
`renderScopeChips()` in map.js — which is what passes it in as `scopeApi`. It sits next
to `scope.js` for readability, not because the order is load-bearing.

## 6. Out of scope

- Any change to the chip set a rider actually sees. Cross-border ranking is the **next**
  step, designed separately, and deliberately not folded in here — a refactor and a
  behaviour change in one diff are indistinguishable in review and in the browser check.
- Any further map.js decomposition. This extraction is justified by the two escaped bugs
  and the incoming cross-border work; a general map.js split is not part of it.
- `scope.js`'s public API, which does not change.

`chipModel`'s interface does not need to break for the cross-border chips work: country
scoping belongs in the implementation (`scopeApi.contextualRegions`), not the signature,
and `input.registry` already carries the worldwide list. The return shape is expected to
widen ADDITIVELY when that work lands — the next author should not read today's contract
as frozen. Three things will change: `chipOf` (`scope-chips.js:30`) and the region/center
cells must start carrying `countryCode`/`countryLabel` (that IS the country cue);
`model.country` should stay a single home rung (two tests use exact-object `deepEqual`, so
keep the cue on chips, not on `country`); and `model.more`'s meaning must be redefined once
foreign regions enter the shown set. None of these fields are added now — that would put
speculative surface into a strictly behaviour-preserving commit.

## 7. Sequencing

This is item 2 of four queued for the current window:

1. **Border-overlap ownership** (`2026-07-23-border-overlap-ownership-design.md`) — must
   land before the pending tag-trim re-harvest, or that is two full harvests instead of
   one. A hard external constraint; nothing else competes for the slot.
2. **This extraction** — independent of 1, lands the tested surface 3 needs.
3. **Cross-border chips** — depends on 2.
4. **Region-boundary HTTP cache + point-in-polygon click** — independent of all three;
   last because its ray-casting function wants the same pure-and-tested shape this
   extraction establishes.

Items 2 and 4 do not depend on 1, so they can run either side of the harvest window.
Only 2 → 3 is a strict ordering.

## 8. Execution notes

- **2026-07-23, Task 1 (module + tests):** `web/assets/map/scope-chips.js` landed with
  the 15 tests from section 4 above (commit `f42c6dd`). Green against real `scope.js`.
- **2026-07-23, Task 2 (wiring):** `renderScopeChips()` in `web/assets/map/map.js`
  rewritten to call `CCScopeChips.chipModel()` and serialize the result; the script tag
  for `map/scope-chips.js` added to `web/templates/map/index.html.twig` per section 5.
  155 lines dropped to 69; no decisions remain in the function. `make scope-test`
  unaffected (100 pass / 0 fail, before and after — the module's own 15 plus the rest of
  the suite). Commit `b986541` on `symfony-base` (not pushed).
  The section 4 browser pass (Germany's grid, Belgium's linear list, the More chip
  focusing `INPUT#search`, zero console errors) could **not** be run this session: the
  shared Playwright browser profile was held by a concurrent agent session for the
  session's full duration, confirmed by live `chrome`/`claude` processes rather than a
  stale lock file. The parity harness referenced in the plan's pre-verification
  (38 scope combinations, 0 HTML mismatches) still stands as evidence the serializer is
  byte-identical to the prior implementation, but the DOM-binding/focus/`.on`-marking
  behaviour this browser pass exists to catch remains unverified in a live browser as of
  this commit. Follow-up: re-run the section 4 table once the browser is free.
