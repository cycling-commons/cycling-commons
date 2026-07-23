# Scalable Scope Selector — Design

Status: **executed** (2026-07-23) on `symfony-base` (`d16483c..9cff37d`, NOT pushed). The flat
region-button wall is replaced by search-first scope results, contextual home-country chips, and
click-to-scope on the map. Browser verification is a separate Task 9, not yet run as of this status
update — treat behaviour here as implemented-and-unit-tested, not yet browser-verified.
Supersedes the flat region-button list in the map scope group; does not change
the `CCScope` model, tokens, dim mask, or per-country coverage tiles from
[2026-07-19-region-scoping-design.md](2026-07-19-region-scoping-design.md) and
[2026-07-22-coverage-scope-rendering-design.md](2026-07-22-coverage-scope-rendering-design.md).

**Owner fixes, 2026-07-23 (post-execution, same NOT-pushed branch):**

1. **Cold-start inference now actually reaches the chips.** `_defaultScope`
   (map.js) always carried a countryCode (Wallonia), and the old
   `renderScopeChips` precedence checked `s.countryCode` *before*
   `inferHomeCountry()` — since a region scope always carries a countryCode
   (`scope.js`), the default branch always won and a fresh anonymous visitor's
   timezone/My-area hint (§D) was never consulted. Fixed via a new
   `CCScope.isDefault()` (true only when `init()` resolved neither from the
   URL nor localStorage, false the instant any setter — the single `set()`
   choke point — runs): `renderScopeChips` now checks `inferHomeCountry()`
   first exactly in that window, so a German visitor's first paint is now
   Bavaria/…/All Germany instead of Wallonia/Flanders/Brussels. The active
   scope, viewport, and served data are untouched — only which chips are
   *offered* changes.
2. **8-region cap + overflow.** `contextualRegions(cc)` used to return every
   region of a country unbounded (16 for DE today, 51 for a future US onboard)
   — the flat wall came back, just per-country. `contextualRegions(cc, opts)`
   now takes `{near: [lng, lat], limit}` (default limit 8): with `near` it
   sorts by ground-corrected distance (the same `cos(lat)`-scaled metric as
   `regionOfPoint`) from that point to each region's bbox centre, nearest
   first, then caps; without `near` it keeps the label-sort. `renderScopeChips`
   passes `near` = the rider's My-area base centre (`window.CC_MY_AREA`) when
   set, else the current map centre (`map.getCenter()`) — both are "roughly
   where the rider is looking" with zero extra fetch. When a country has more
   regions than the cap, an overflow chip (`d_scopes_more`, translated in all
   four locales) appears before the `All <country>` rung and focuses the
   sidebar search box, so every onboarded region stays reachable regardless of
   country size. `contextualRegions(cc)` with no `opts` stays valid for every
   existing caller.

## Problem

The map scope selector renders every region as a flat button
(`web/templates/map/index.html.twig`, `{% for r in regions %}`, ordered
`area_km2 DESC, slug` by `RegionRegistryProvider::all()`). With BE + NL + DE it is
already 31 region buttons plus 3 country rungs — ungrouped and interleaved by
size (Wallonia between German states, Brussels after Bremen). It does not scale:
worldwide is ~195 countries, so a flat list of regions **or** countries is a
wall. Each onboarded country (a deliberate harvest, see
[2026-07-22-country-onboarding-design.md](2026-07-22-country-onboarding-design.md))
makes it worse.

## Principle

**No flat list scales.** The two things that do: **search** (O(1) to any target,
indifferent to N) and **geography** (the map itself, plus "what's near me"). So:

- **Search-first** is the "go anywhere" backbone.
- The sidebar shows only a **short contextual block** — what's relevant to where
  the rider is — never the whole world.
- Global browsing stays on the existing `/regions` page, not the map sidebar.

Riders almost never browse the globe from a list; they want *their* area (one
tap) or a named destination (they type it). The selector optimizes for exactly
that. Only **onboarded** countries ever appear (lazy rollout), so the contextual
fallbacks stay bounded regardless.

## A. Unified search (one box, sectioned results)

The existing feature search box ("Search a climb, town, viewpoint…") absorbs
scope search. As the rider types, results group into two sections, each rendered
only when it has hits, with a single keyboard up/down/enter path across both:

- **Scopes** — matching countries (`All Germany`) and regions (`Bavaria`,
  `Wallonia`). Selecting one **sets the scope** (`country:<cc>` / `region:<slug>`
  via `CCScope.set`) and fits the map to it. Matched **client-side** against the
  region registry already injected in the page — no endpoint, no round-trip.
- **Places** — towns / climbs / viewpoints. Unchanged: the existing feature
  search endpoint and behaviour (fly to it + open the drawer).

Scope matching is over each region's localized `label` and its `slug`, plus each
onboarded country's `All <country>` label. Ranking: exact/prefix label match
first, then substring; country rungs sort above their regions on a country-name
hit.

## B. Contextual scope block (replaces the flat wall)

Below the search, a minimal ordered list — the flat `{% for regions %}` wall is
removed:

1. **My area** — shown only when a My-area source exists (logged-in base
   location or anon circle). Unchanged (`scope.js` myArea).
2. **Your country + its regions** — the default region group, and the **only**
   one shown by default. Country/regions resolve from the **home country** (§D).
   E.g. home BE → `Wallonia · Flanders · Brussels · All Belgium`.
3. **Everywhere.**

There is **no automatic viewport following**. The rider changes scope by exactly
three deliberate acts: **search** (§A), **click the map** (§C), or **the chips**
(this block). Panning/zooming the map is passive — it never reshuffles the
sidebar or re-serves data.

**Cold-start fallback:** when no home country resolves (§D) — e.g. an anon
visitor whose timezone maps to a not-yet-onboarded country — the block collapses
to just the onboarded **country rungs** (`All Belgium / Netherlands / Germany`) +
Everywhere. Short, bounded, no wall.

## C. Click-to-scope (map as a spatial selector)

A **left-click on empty map** (not on a POI, cluster, or route line — those keep
their current select/zoom behaviour) is the explicit "focus here" gesture:

A click **always resolves to a region**, never a whole country — scoping all of
the USA / France / China from one click would be far too coarse. Onboarded
countries are fully tessellated, so a click inside one lands in a region.

1. Resolve the **region** under the clicked point — client-side point-in-bbox
   test over the registry, refined to point-in-polygon via the existing
   scope-boundary data. A rare polygon-simplification gap (point inside a country
   but between region polygons) **snaps to the nearest region of that country**,
   not to country scope. A click outside every onboarded region resolves to
   nothing and is a no-op.
2. On a hit, **set the scope** to that region (`region:<slug>`), which **serves
   the items** (coverage + features re-filter to the scope, counts update) **and
   repopulates the region chips** in the filter menu to that region's country
   siblings — the clicked region active — so the rider can immediately refine to
   a neighbour, widen to `All <country>`, or click elsewhere.

Nothing runs until the click — no `moveend` work, no speculative serving. The
feature-vs-empty distinction is the one careful edge: the empty-map handler must
fire only when `queryRenderedFeatures` at the point returns no
selectable/coverage/route feature, so it never collides with feature selection.

## D. Home-country resolution (client-side, transient)

`scope.js` resolves the contextual home country in priority order, all
client-side:

1. **My-area base country** — the logged-in rider's `base_country_codes`
   (`window.CC_MY_AREA`), when present.
2. **Anonymous circle country** — the `cc-my-area` localStorage circle's derived
   country, when present.
3. **Timezone-inferred country** — `Intl.DateTimeFormat().resolvedOptions().timeZone`
   (e.g. `Europe/Berlin` → `DE`) via a maintained IANA-zone→ISO-country table.
   Computed in-browser, **stored nowhere**.
4. **None** → cold-start fallback (§B).

The resolved home only **preselects** the contextual block; it is a suggestion,
never auto-applied to anything irreversible and never persisted for an anon
visitor.

## E. Plumbing (no new server endpoints)

- **Region registry payload** (`RegionRegistryProvider::all()` → the injected
  registry) gains `label` (localized, from the messages domain) and
  `countryLabel` per row, so JS can search and render scope results/chips without
  re-fetching. Today it carries only `id/slug/countryCode/bbox`.
- **IANA timezone → ISO country table** — a small maintained map (starts with the
  onboarded countries' zones, grows per onboarding alongside the country config).
  Unknown zone → cold-start fallback.
- **Point → region resolver** (client-side): bbox test over the registry, used by
  §C. No new endpoint; `/map/scope/boundary` already exists for the union
  geometry if a polygon-precise test is later wanted.
- The cold-start country rungs are derived client-side, NOT server-injected:
  `renderScopeChips()` (`map.js`) walks the already-injected `window.CC_REGIONS`
  registry, dedupes by `countryCode`, and label-sorts the result (a
  `scope_countries` server variable was planned here but removed as dead code,
  `a1bcf14` — the registry alone is sufficient).

## F. Privacy & consent posture (banner-free by construction)

This feature adds **no consent surface**. Rules, binding on this and future
changes:

1. **Geo/timezone inference is compute-only** — never written to any storage.
   Reading `Intl…timeZone` writes nothing to the device, so it is outside the
   ePrivacy "storage/access" trigger.
2. **`localStorage` is written only on an *explicit* user scope selection**
   (`persist:true`). Inferred defaults — timezone cold-start, any suggested scope
   — are set `persist:false` (shown, not stored). An anon visitor who only looks
   and leaves persists **nothing**. Auto-persisting an inferred scope is
   **forbidden** (it would break this rule and create a consent surface).
3. **No analytics / tracking / third-party / fingerprinting storage** anywhere in
   the feature. All storage is first-party and functional (remembering a scope
   the rider *chose* is the same strictly-necessary/functional class as
   remembering a language choice — exempt from the consent banner).

**Analytics** is a dedicated self-hosted **Umami** server (site-wide infra, out
of scope for this feature): cookieless, no PII, no cross-site tracking, no raw-IP
retention → aggregate, legitimate-interest, **no consent banner**. Its dashboard
is intended to be **public**, matching the open-Commons ethos.

**Deliverable:** a **wiki page** (CC-BY-SA-4.0 SPDX header, per the wiki gate)
stating how Cycling Commons handles location & storage without a consent banner —
timezone inference stores nothing, only explicit selections persist as
first-party functional prefs, analytics is cookieless Umami with a public
dashboard. This page is the user-facing **notice** that stands in for a banner.

## G. Removed / unchanged

- **Removed:** the flat `{% for r in regions %}` wall of 31+ region buttons.
- **Unchanged:** `CCScope` scope model + serialized tokens, the country dim mask
  (`/map/scope/boundary`), per-`(letter,country)` coverage tiles, the coverage
  serving path, and the `/regions` browse page (the home for global browsing).

## H. Testing

- **`scope.js` units** (harness exists, `web/tests/js/*.test.cjs`): scope-search
  match by label and by slug (incl. country-rung `All <cc>`); IANA-zone→country
  resolution (hit + unknown→fallback); home-country priority order
  (myArea → anon → tz → none); contextual-block composition for each home state
  and the cold-start fallback; point→region resolution (inside vs outside all
  regions).
- **Browser verify:** type-to-scope (a region and an `All <country>`); the home
  block for a logged-in rider and an anon tz cold-start; click empty map →
  scope + chips repopulate; click on a feature still selects it (no collision);
  reaching a non-home `All <country>` via search; the flat wall is gone.

## I. Out of scope / non-goals

- Umami integration/wiring (site-wide infra, separate task).
- IP-based or Geolocation-API cold-start (deliberately not used — timezone only,
  to stay prompt-free and banner-free; revisit only if accuracy demands it).
- Automatic viewport-following scope (rejected — click-to-scope instead).
- Global browse in the map sidebar (stays on `/regions`).
- Continent-tier hierarchy (unneeded while search + contextual + `/regions`
  cover it; revisit only if a flat country rung list itself grows unwieldy).
