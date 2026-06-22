# Add-spot location wizard for `improve.html`

**Date:** 2026-06-21
**Status:** Approved design — ready for implementation plan
**Scope:** `atlas/demo/improve.html` add mode only (`?item=<id>&mode=add`)

## Problem

`improve.html` is the generic edit page for most item types (driven by the
`CC_EDIT` registry in `atlas/demo/edit-items.js`, parameterized by `?item=…&mode=add`).
When creating a new spot, the user must set the location on a map. Today that map
is a 200px-tall element crammed into a narrow 340px context column
(`#loc`, `improve.html:52`, `improve.html:289`), and there is **no place search** —
the user has to pinch and pan from a default center to find their spot. On mobile
this is the worst case: the two-column grid collapses, but the map stays tiny and
search is still absent.

The dedicated climb wizard `atlas/demo/add-climb.html` already solves exactly this for
one type: a stepped flow (`Where → Profile → Details → Review → Submitted`) whose
first step is a Photon place-search box plus a large map
(`#cmap`, `clamp(400px,60vh,640px)`). The fix is to generalize that proven,
in-repo pattern to `improve.html`'s add flow.

## Goals

- Make new-spot creation **mobile-first**: one task per screen, large picking surface.
- Add **place search** to the location step (keyless Photon, same as add-climb).
- Reuse the add-climb stepper/navigation/search machinery — no new dependencies.

## Non-goals

- **Fix / Report mode is untouched.** Editing an existing item keeps today's
  tabbed two-column layout; its map is a reference thumbnail, not a picker. No
  search is added there for now.
- No change to what is submitted, to the curator/review queue, or to the
  consent/donation/licensing copy. This is purely input UX.
- No real geocoding backend — Photon is called client-side, exactly as add-climb
  does today.
- No changes to `edit-items.js` data (the registry already exposes everything the
  wizard needs: `center`, `icon`, `tags`, `fields`, `addFields`, `upload`).

## Approach

In add mode, render `improve.html` as a **responsive single-column wizard**
(same layout on desktop and mobile) instead of the context+form two-column grid.
Non-add mode keeps the existing markup, branched on the existing `ADD` flag.

Reuse from `add-climb.html`:

- `.stepper` CSS + `<ol class="stepper">` progress markup.
- `step(n)` navigation: toggles `.pane.on`, marks stepper `done`/`on`, manages
  Back/Next buttons, scrolls to top, runs per-step enter hooks.
- Per-step gating (`refreshGate`) — **Next** is disabled until the step's
  requirement is met.
- Photon search: `geocode(q)`, `renderResults(list)`, the `.csearch` /
  `.csearch-results` CSS, 320ms debounce, "search unavailable → click the map"
  fallback.
- Large map sizing.

Keep from current `improve.html`:

- The `CC_EDIT` registry lookup and the `LOCATE = point | segment | none` derivation
  (`improve.html:276`).
- Pin placement, segment `drawSeg` line drawing, and the coordinate readout that
  updates `#e-co`.
- The media consent/donation flow: `openConsent`, `acceptConsent`, `queueAdd`,
  `linkMedia`, `KNOWN_SOURCES`, the upload queue chips.
- Submit → thanks view transition.

Implementation stays **self-contained in `improve.html`** (inline JS/CSS), matching
the codebase convention of standalone pages. Sharing the search helper across
add-climb and improve via a small module is a possible future cleanup, explicitly
out of scope here (YAGNI).

## Step structure

Four steps, registry-driven so the first step and the field set adapt per type.
The stepper labels for step 1 change with `LOCATE`; steps 2–4 are constant.

| Step | Point types¹ | Segment (`road-surface`) | Ride (`ride`) |
|---|---|---|---|
| **1 — Locate / Track** | search + big map; tap to drop pin, drag to nudge | tap **start** then **end** (`drawSeg`) | **Add track**: GPX/FIT upload; map hidden, route sets location |
| **2 — Details** | the type's `fields` (`fieldHTML` render) | same | same |
| **3 — Photos & video** | photo + video drop zones + URL link + consent/queue | same | same |
| **4 — Review & submit** | location + entered details + queued media summary → submit | same | same |

¹ Point types: water point, repair station, shelter, viewpoint, hazard, transport
link, place to sleep, heritage site (everything where `LOCATE === 'point'`).

Notes:

- **Details (step 2)** renders the type's primary attributes via the existing
  `fieldHTML` over `E.fields`. If a type has no fields, fall back to the existing
  "Note for riders" textarea (current `p-add` fallback).
- **Photos & video (step 3)** is its own step (per requirement) and combines what
  are currently the separate Photo and Video tabs. The existing first-time
  consent/donation modal and upload-queue behavior are preserved unchanged.
  - **Strip location metadata (privacy requirement).** Media can carry embedded
    metadata (EXIF) such as a capture location; when real uploads go live, the
    server strips location metadata from uploaded media before it is stored or
    published — the Commons maps places, not riders. (Also stated in the public
    privacy notice.)
- For **rides**, the GPX/FIT track is step 1 (it sets the route/location), so it is
  *not* duplicated in step 3; step 3 remains optional photos/video.
- Climbs are unaffected — they have their own `add-climb.html` wizard and are not
  routed through `improve.html` add mode.

## Gating (when Next enables)

- **Step 1:** location is set — pin placed (`point`), both start+end placed
  (`segment`), or a track is present (`ride`, demo: file/selection registered).
- **Step 2:** light — if the type has a clear primary field (e.g. a name), require
  it; otherwise no gate. Keep friction low; default to ungated when unsure.
- **Step 3:** never gated — photos/video are optional.
- **Step 4:** Next becomes **Submit for review →**; always enabled.

## Mobile specifics

- **Step 1 map fills the screen:** `height: clamp(360px, 68vh, 640px)`, with the
  search box pinned directly above it.
- **Sticky bottom action bar** (`Back` / `Next →`) so the confirm control is always
  reachable beneath a tall map without scrolling.
- Search-first acquisition: type a town/street → results dropdown → map flies there
  (`flyTo`) → tap to place. Removes the pinch-pan-from-default-center problem.
- Single column, one step per screen, large tap targets.

## Data flow & state

A small JS state object (mirroring add-climb's `S`) holds: location (pin
lng/lat, or start+end, or track-present flag), the entered field values, and the
media queue. Steps render from / write to this object so navigating Back and
forward preserves input. Step 4 reads the object to render the review summary;
Submit runs the existing `submitImprove()` → thanks transition.

## Edge cases

- **Search unavailable / offline:** dropdown shows "Search unavailable — click the
  map instead" (existing add-climb fallback); map tap still works.
- **Ride type:** step 1 shows the track uploader, not a map; gate on track present.
- **Back navigation:** location and field values persist (held in the state object
  and the live DOM controls).
- **Re-tapping the map:** existing behavior — re-placing clears prior pins
  (`improve.html:305`).
- **Non-add mode:** completely unchanged — verify the tabbed layout still renders.

## Verification

Manual, in the dev stack (served page):

1. `improve.html?item=road-surface&mode=add` — 4 steps render; search flies the
   map; tapping sets start then end; Next gates on 2 points; details, photos&video,
   review, then Submit → thanks.
2. A point type (`water-fountain`) — single-pin locate, drag to nudge, full flow.
3. `ride` — step 1 is the track uploader (map hidden), rest of the flow works.
4. Mobile viewport (≤520px) — step-1 map is tall, sticky Back/Next reachable,
   search dropdown usable.
5. Non-add: `improve.html?item=repair-station-malmedy` (no `mode`) still shows the
   original tabbed two-column layout.

## Files

- `atlas/demo/improve.html` — add-mode wizard markup, stepper/search/map CSS, and the
  add-mode JS branch. Fix/report markup and the media/consent JS are retained.
- No changes expected to `atlas/demo/edit-items.js`.
