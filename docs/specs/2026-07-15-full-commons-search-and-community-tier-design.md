<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Full-Commons search & the community tier (2026-07-15)

## Problem

The map's **Curated best-of / Everything** toggle governs how dense the map is.
But it has leaked into two surfaces where it does not belong — and inconsistently:

- **Town "nearby" card** and **sidebar search** read the full `ITEM_INDEX`
  ([map.js §buildItemIndex](../../web/assets/map/map.js)) with *no* curated
  filter, so they already show everything.
- The **map**, in Curated mode, hides unverified OSM *utility* items
  ([map.js:1121](../../web/assets/map/map.js)) even though utility layers are
  meant to be full-coverage ([map.js:1081](../../web/assets/map/map.js) only
  gates *experiential* layers on `f.cur`).

Result, observed at Mazy: **Black & Bike Spy** (an unverified OSM bike service)
is listed in the town card but has **no marker on the Curated map**, while the
curated **Top Cycle** shows its ⚙ icon. The card promises something the map
won't show.

The user's framing of the tension:

> If search respects the Curated/Everything selection, people miss real
> information. If we show everything by default, it's too much. How do we solve
> this — especially on mobile? (Even OSM makes "show me all the bike shops"
> hard.)

## Reframe (the core decision)

**Curated/Everything is the wrong axis for *search and the town card*.** Those
are **intent-driven** surfaces ("find a bike shop near Mazy") — hiding real
records behind a display toggle is exactly the discoverability frustration the
user described. The toggle governs **ambient map density**; it must not govern
**targeted finding**.

So: **search and the town card always reach the full Commons.** "Too much" is
solved by *ranking and collapsing* (curated first, community records tagged and
capped), never by *hiding*. Approved 2026-07-15.

## Decisions

### A. Search + town card = full Commons, curated-first, community tagged

- Both continue to read the full `ITEM_INDEX`; the toggle never filters them.
- Every index entry carries a **`verified`** flag, derived from the
  curated/confirmed signal each source already has (`f.cur` for CATALOG
  features; the confirmed/`p.c` signal for OSM/PIVOT pools —
  [map.js:648-680](../../web/assets/map/map.js)).
- **Ordering:** within each category, **verified/curated rows first**, then a
  **community** subgroup (unverified records).
- **Town card:** the community subgroup is capped (~3 rows) with a
  **"show all N bike services"** expander — the direct answer to "I can't find
  how to show all bike shops." Verified rows carry the normal type icon;
  community rows use the lighter community marker (see D) and a small
  `community` tag.
- **Search dropdown:** unchanged 30-row cap and grouping; community rows carry a
  dimmed `community` sub-tag so verified vs community is legible at a glance.

### B. Map draws utility items in both modes, tiered by verification

- Change the render gate ([map.js:1081](../../web/assets/map/map.js) /
  [1121](../../web/assets/map/map.js)) so **non-experiential (utility)**
  unverified items **also draw in Curated mode**.
- **Verified/curated** utility items → the full type icon (Top Cycle's ⚙).
- **Unverified** utility items → a **lighter "community" marker**: a
  hollow / reduced-opacity variant of the same type icon, reusing the existing
  marker code with a modifier class (no new icon set).
- This makes the **map and the card consistent** — Black & Bike Spy now shows,
  lightly, on the Curated map.
- **Experiential** layers (climbs, stays, views, routes) are **unchanged** on
  the map: Curated stays best-of-only. Only utilities gain the always-draw
  behaviour, because only utilities are "full coverage."

### C. Picking a non-curated *experiential* item from search in Curated mode

Because utilities now always render (B), this case only affects the four
experiential layers. Behaviour: **drop a single temporary "reveal" pin for that
one item and open its drawer**, without switching the whole map into Everything.
This is more surgical than the current route behaviour
([map.js:1874](../../web/assets/map/map.js)), which force-switches mode; that
call site should adopt the same reveal-pin pattern for consistency. Approved
2026-07-15.

The reveal pin:

- is a one-off marker for the selected feature, styled like the community
  marker with the selection halo;
- is cleared on the next search pick, on drawer close, or on a mode change;
- does **not** alter the toggle or the rest of the Curated render set.

### D. Community marker style

- A single reusable modifier on the existing per-type marker: hollow outline or
  ~55% opacity, same colour/badge as the verified marker so the *type* still
  reads. Used by both the map (B) and the reveal pin (C), and mirrored by the
  card/search `community` tag (A).
- No change to verified markers.

### E. Photon → Wallonia / Belgium only

- The "Search in Wallonia" box currently accepts Photon place hits anywhere in a
  loose bbox that spills over the French border, so "Mazy" returns **Mazy**
  (Namur, BE) *and* **Malzy** (Aisne, FR).
- Filter Photon features to **Belgium** (`properties.countrycode === 'BE'`),
  dropping cross-border near-spellings. Keep the existing bbox as a coarse
  pre-filter; the countrycode check is the precise gate.
- If cross-border coverage is ever wanted, this is one flag to relax — recorded,
  not built (YAGNI).

### F. Mobile

- The town card is already the primary discovery surface on small screens.
- The community subgroup **collapses under "+N more"** by default so the card
  stays compact; one tap expands. Same interaction the desktop "show all"
  expander uses — no separate mobile code path.
- Search dropdown behaviour is unchanged on mobile (already the same component).

## Out of scope

- No change to how items become *verified/curated* (moderation, best-of
  selection). This spec only changes **presentation** of the existing flag.
- No new icon artwork — the community marker is a CSS modifier on existing
  markers.
- No backend/API change: `ITEM_INDEX`, the pools, and the best-of fetch are
  unchanged. This is a client-render + search-presentation change, plus the
  Photon filter.

## Testing

Client behaviour (map.js) is hard to unit-test; verification is end-to-end in
the running app (real search, real town card), per the repo's
reproduce-end-to-end rule:

1. **Curated map** shows Black & Bike Spy as a *community* (light) marker and
   Top Cycle as a *verified* (⚙) marker.
2. **Town card at Mazy**: verified bike service listed first, community bike
   service(s) under a "show all N" expander, both openable.
3. **Search "bike"** near a populous town: verified first, community tagged,
   30-row cap respected.
4. **Pick a non-curated route from search while in Curated mode**: a single
   reveal pin appears + drawer opens; the rest of the Curated set is unchanged;
   the pin clears on the next pick / drawer close / mode change.
5. **Search "Mazy"**: returns Mazy (BE) only; **Malzy (FR) is gone**.
6. **Mobile**: town card community tier collapsed by default, expands on tap.

Any pure helper extracted along the way (e.g. a `verified`-flag deriver, or the
Photon `countrycode` filter) gets a focused unit test.

## Relationship to other work

Part E shares the search code touched by the 2026-07-14 town-search work
([memory: town-search + ride-check](../../)). Parts A/B/D touch the same
`ITEM_INDEX` / render loop as the registry-driven drawer work. No conflict with
the same-session opening-hours dropdown change (different surface).
