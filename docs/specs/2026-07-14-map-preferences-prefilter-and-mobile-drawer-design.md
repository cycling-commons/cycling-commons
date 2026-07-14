<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Map preferences prefilter & mobile drawer snap sheet (2026-07-14)

## Problem

1. Riders can now save bike types and riding styles (spec 2026-07-14
   unique-display-name-and-rider-preferences), but the map ignores them —
   the promised "prefilter the map for you" does nothing yet. The rail's
   Discipline chips still show the retired 9-value mixed hardware/style
   list and filter nothing.
2. On mobile the detail drawer is a bottom sheet that can only be flicked
   away (dismiss) — riders cannot peek at the map underneath and pull the
   sheet back up. The open question was how sheet-dragging should coexist
   with content scrolling.

## Decisions

- **Prefiltering really filters routes** (decided over preselect-only):
  routes are the only bike-tagged layer, so bike preferences filter the
  routes (experience) layer in BOTH Everything and Curated modes. Riding
  styles cannot filter yet (dataset carries no style tags) — the Discipline
  chips are re-based onto `RidingStyle` and preselected, still visual-only,
  honestly labelled in the template comment.
- **Unknown ≠ unsuitable:** routes with NO declared `bikeTypes` stay
  visible under the prefilter; only routes whose declared suitability does
  not overlap the rider's bikes are hidden.
- **Never silent:** an active prefilter shows a dismissable rail chip
  ("Routes for your bikes"); toggling it off is remembered in
  `localStorage` (key `cc-pref-filter`, values `on`/`off`, default `on`
  when preferences exist).
- **Mobile drawer = snap sheet, Google-Maps-style** (decided over
  handle-only two-state): three snap points; content scrolls only at full;
  below full every drag moves the sheet; scroll↔drag hand-off at the
  content's scroll-top. Desktop right-hand panel unchanged.
- Anonymous visitors: zero behaviour change (no prefs → no filter, chips
  default state).

## Model

### Preferences → page

- `MapController::index()` reads the logged-in user's
  `getBikeTypes()`/`getRidingStyles()` and passes value-lists to the
  template; the template emits them as `window.CC_PREFS =
  {bikes: [...], styles: [...]}` (JSON-encoded, `[]`/`[]` for anonymous).
- No new endpoint: preferences ride the page render, same as `CC_I18N`.

### Route prefilter (map.js)

- Predicate: with `P = CC_PREFS.bikes` non-empty and the filter ON, an
  experience feature is visible iff `!f.bikeTypes || f.bikeTypes.length
  === 0 || f.bikeTypes.some(t => P.includes(t))`. Applied in the same
  render pipeline as the existing curated/surface filters (composes with
  them, never replaces them).
- Rail chip in the Discipline group area: `#prefFilter`, shown only when
  `CC_PREFS.bikes.length > 0`; label `map.pref_filter` ("Routes for your
  bikes"), `.on` class + aria-pressed reflect state; click toggles filter
  + persists to localStorage + re-renders.
- Curated Bike facet (`#boBike`): preselected to the rider's bike iff they
  saved exactly one; otherwise stays `all`. (Facet is single-valued; a
  multi-bike rider keeps the neutral default.)
- Discipline chips: template re-based to the 7 `RidingStyle` values —
  translation keys `map.disc_road`, `map.disc_gravel`, `map.disc_touring`,
  `map.disc_bikepacking`, plus new `map.disc_trail`, `map.disc_leisure`;
  `map.disc_urban` stays; `map.disc_mtb`, `map.disc_ebike`,
  `map.disc_recumbent`, `map.disc_handbike` are removed from all four
  locale files (parity maintained). Chips whose value appears in
  `CC_PREFS.styles` render with `.on` preselected. Still visual-only.

### Mobile snap sheet (≤ 820px)

- Snap points (fraction of viewport height, tuned in CSS custom
  properties): **peek** ≈ `7.5rem` (grab handle + title visible, map fully
  interactive), **half** ≈ `50vh` (default landing state on open),
  **full** = current sheet height (≈ `85vh`).
- New drawer chrome: a grab-handle bar (`.cc-d-grab`) rendered above the
  drawer body — a real `<button>` (aria-label localized,
  `map.drawer_grab`): Enter/Space cycles half ↔ full; Escape closes
  (existing). Pointer-drags on the handle always move the sheet.
- Gesture rules (extends the existing `initDrawerDrag` touch code):
  - Sheet below full → ANY vertical drag on the sheet moves the sheet;
    content scrolling is locked (`overflow: hidden` below full).
  - Sheet at full → content scrolls normally; a downward drag while
    `scrollTop === 0` grabs the sheet instead (the existing hand-off
    check).
  - Release snaps to the nearest point, velocity-weighted (a fast flick
    skips to the next point in its direction); a downward flick from peek
    — or the existing far/fast dismiss thresholds — closes the drawer.
- Scrim: shown only at full (map dimmed); at half/peek the scrim is
  hidden and the map above the sheet is interactive (tapping another item
  re-fills the drawer — accepted behaviour).
- Focus/a11y: drawer keeps `tabindex="-1"` focus-on-open; the grab button
  is the first tab stop inside the sheet; `aria-hidden` unchanged.
- Desktop (> 820px): no grab handle shown, no snap logic engaged — the
  side panel keeps today's behaviour exactly.

## Surfaces

- `MapController` (+ `CC_PREFS` emission in `templates/map/index.html.twig`).
- `assets/map/map.js`: prefilter predicate + chip wiring; snap-sheet
  state machine replacing/extending `initDrawerDrag`.
- `assets/styles/map.css`: grab handle, snap-point custom properties,
  scrim gating, content overflow gating.
- `templates/map/index.html.twig`: discipline chips re-base, pref chip,
  grab-handle markup.
- Translations: `map.pref_filter`, `map.disc_trail`, `map.disc_leisure`,
  `map.drawer_grab` added; 4 obsolete `map.disc_*` keys removed — all four
  locales in the same commit (parity hook).
- Related spec updates in the same change: unique-display-name spec's
  "Future work" section gains an executed-note pointing here; the route
  domain spec is untouched (no route data model change).

## Testing

- PHP: MapController test asserting `CC_PREFS` renders the logged-in
  user's saved values and `[]` for anonymous (functional, WebTestCase).
- JS has no test runner (established): verify via `node --check`, grep of
  the served bundle, and a browser pass (Playwright) covering: filter
  hides a non-matching route / keeps an undeclared one, chip toggle +
  localStorage persistence, chips preselection, and on mobile viewport the
  three snap points, scroll-at-full-only rule, hand-off at scroll-top, and
  flick-to-close.

## Out of scope

- Style-tagging routes (would make Discipline chips filter) — future.
- Preference-driven POI (non-route) filtering — nothing else is
  bike-tagged.
- Any change to the desktop drawer interaction.
