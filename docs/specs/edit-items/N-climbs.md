<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Edit spec — N · Climbs

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** N · Climbs
- **Map depiction:** gradient-coloured line + foot pin, icon: a drawn twin-peak mountain (`MOUNTAIN_PATH` in icons.js, like the camera and toilet glyphs; the ⛰ emoji flattened to one plain triangle), colour #6A2C8F
- **Edit-item id:** `cote-de-la-redoute`, `mur-de-huy`, `cote-de-stockeu`, `cote-de-la-roche-aux-faucons` in `atlas/demo/edit-items.js` (one edit item per climb)
- **Editable:** yes · Frontend demo · 2026-06-18
- **Lifecycle:** *votable* — verified (≥ X community confirmations) → votable → **best-of** (top-voted); appears in **Best-of** mode once it earns votes. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
A linear feature (foot → summit) with a gradient profile; the layer that closed databases lock down.

## Geometry — the three-point definition

A climb's shape lives in three jsonb attributes (no dedicated schema; validated at the
submission boundary by `App\Contribution\ClimbGeometry::fromPayload()`):

| Attribute | Shape | Meaning |
|---|---|---|
| `route` | list of `[lat, lng]` pairs | the track, foot → summit (direction-bearing) |
| `grad` | list of numbers | per-segment gradient profile (renders the coloured line + profile bars) |
| `steep` | `{at: [lat, lng], pct: string, manual: bool}` | the steepest ramp marker (the "▲26%" badge) |
| `steepPoint` | `{at: [lat, lng], pct: string, note: string}` | the **rider's** steepest point — see below |
| `lineGrad` | list of numbers | per-position gradients that colour the map line (the bars use `grad`) |
| `length` · `gain` · `binM` · `demSource` | numbers / string | derived and stored ([../climb-elevation.md §4](../climb-elevation.md)) |

Validation invariants (`App\Contribution\ClimbGeometry`): `route`/`grad` are capped at
`ClimbGeometry::MAX_POINTS` (currently 2000) entries; coordinates must be finite and in
range (lat −90..90, lng −180..180); `steep.pct` must match a gradient shape
(`12`, `12.5%` — up to two digits, optional decimal, optional `%`). Malformed geometry
surfaces as a form error on the `route` field, never a silent discard.

**One shared editor, both arms of one page.** `web/assets/contribute/climb-editor.js`
(`window.Cc.mountClimbEditor`) is mounted by `improve.js` for both the add arm
(`/improve?type=climbs&mode=add`) and the edit arm (`/improve?item=<id>&type=N`); it swaps
the editor in for the generic single-pin Locate step whenever the type is letter N. (Until
2026-08-25 a separate `add-climb.js` mounted the same editor for the retired `/add-climb`
wizard; see the dated note below.) Markers are labelled to make direction unambiguous — foot = green
"START · foot", summit = orange "END · summit", steepest = red warning ▲ "STEEPEST · \<pct\>"
(labels come from the `js.climb_marker_*` translation keys via `window.CC_EDITOR_LABELS`).
The editor writes the attributes into hidden form fields on `App\Form\ImproveType`
(`route`/`grad`/`steep`, plus `avg` and `steepPoint`; the add arm also fills `lat`/`lng`
from the foot, because a climb's pin is its foot).

**Auto-routing.** Placing or moving foot + summit auto-routes the road between them via
client-side OSRM (`router.project-osrm.org`), producing `route` and the snapped length;
the gradient profile (`grad`) is then derived from the routed track by
`window.Cc.profileFromRoute` in `climb-elevation.js`, which posts up to 200 sampled points
to **our own** `POST /contribute/elevation` (`App\Elevation\ElevationClient` → Valhalla
`/height`). It called Open-Meteo from the browser until 2026-08-04; moving it server-side
put the dataset behind a setting instead of a third party's choice, and the sample count
behind our own limit rather than theirs
([../climb-elevation.md §2b-i](../climb-elevation.md)). Requests time out after `FETCH_TIMEOUT_MS` (`climb-editor.js`,
currently 10 s).

**Two steepest markers, and they are not the same thing.** `steep` is ours: the
steepest sustained 100 m the elevation model can see, derived on every redraw
and never edited, which is what makes it comparable between climbs.
`steepPoint` is the rider's — where the wall actually is, placed by hand with a
distinct amber icon, optionally carrying a percentage and a short note. It
exists because the model *cannot* answer that question: a hairpin smaller than
one DEM cell is invisible to it at any window width, so nothing recovers Mur de
Huy's ~26% by computing harder. Placement is a mode (`markSteepestPoint()`),
not a fourth tap, because it is optional and repeatable. See
[../climb-elevation.md §5a](../climb-elevation.md).

**Steepest: found, not placed.** The marker is derived from the steepest sustained
~100 m window, and an automatic one is **re-derived on every route change** — the line is
what determines where the steepest ramp is. Dragging or re-tapping it sets `manual: true`,
which persists with the attribute: a hand-placed marker keeps its position through a
redraw and only has its `pct` re-read, and is re-derived solely when the route no longer
passes it, since a marker stranded beside a road that is no longer part of the climb is
wrong however it got there. See [../climb-elevation.md §5](../climb-elevation.md).

**No fake profile (honesty rule).** If routing fails, the straight foot→summit line stays
with **no** `grad`, the steepest is settable only manually, and the failure is surfaced to
the contributor ("could not snap to the road network, showing a straight line"). If the
elevation API fails, the track still saves and the profile is simply absent ("gradient
profile unavailable"). A derived profile is never fabricated, and the wizard's gating
blocks advancing/submitting while a routing or elevation request is still in flight — a
mid-flight two-point placeholder is never submitted.

**Edit prefill and untraced climbs.** The edit flow prefills foot = `route[0]`,
summit = `route[last]`, steepest = `steep.at` (with its `manual` flag). A climb with no
`route` (point-only import) starts empty — the edit flow is the user-facing path to give
it a real track. Geometry edits are ordinary Edit submissions through the normal
moderation + per-field change-history pipeline
([../moderation-and-contribution.md](../moderation-and-contribution.md)) — no special path.

**Both gradients are derived from the drawn line, in both flows** (2026-08-04). Neither
is a box anyone types in: `CatalogField::derivedText` keeps them out of the edit form,
and `CLIMB_FIELDS` carries no `fAvg`/`fMax`.

- `avgGradient` — the editor's **ascent-only** average over ~100 m bins, posted in the
  hidden `avg` field beside `route`/`grad`/`steep`, and moved onto the attribute by
  `CatalogContributionService::applyDerivedAverage()`. Definition and the reasoning for
  ascent-only: [../climb-elevation.md §4b](../climb-elevation.md).
- `maxGradient` — read off the steepest-ramp marker (`deriveMaxGradient()`), which is the
  steepest sustained ~100 m window - the distance climb databases report, so our
  figure is comparable with theirs.

This replaces two earlier behaviours that produced stale numbers. The add flow used to
compute the average as **gain ÷ length** from a *typed* gain — a different definition from
the edit flow, so the same road got different figures depending on which door you came
through. And the edit flow offered both as ordinary registry fields, so a redraw left them
untouched: rebuilding Roche-aux-Faucons from 1.75 km to 4.35 km kept the 9% somebody typed
in June (owner-reported 2026-08-04). One definition, measured, recomputed on every redraw.

## Adding a climb (`/improve?type=climbs&mode=add`)

Since 2026-08-25 a climb is added on the same registry-driven page that edits one. There
is **one contribution form per item type** ([README.md](README.md)): `/improve?type=climbs&mode=add`
is the `mode=add` arm of `ContributeController::improve()` (`addPlace()`, `ImproveType` with
`add_mode: true`, `web/templates/contribute/improve.html.twig`,
`web/assets/contribute/improve.js`), and `/improve?item=<id>&type=N` is the edit arm of the
same page. Next is disabled until each step's minimum is met:

| # | Step | Contents | Gate to advance |
|---|---|---|---|
| 1 | **Where** | map hosting the shared three-point editor (foot → summit → auto-routed track + steepest); an on-map **hint pill** at the top of the map (`#wz-mapHint`) that says the next tap (foot, then summit) and hides once both are set; the four-line how-to; keyless Photon place search with map-tap fallback; Undo + Reset; a read-only **measured** block under the map (`#wz-measured`: length, height gain, average gradient, steepest) filled live from the elevation profile as the line is drawn | foot + summit placed, and no routing or elevation-profile request in flight (`WZ.locPending`) |
| 2 | **Details** | the registry fields for `ItemType::Climbs` (`CatalogFormRegistry`): **name** (required, injected by add mode), surface, road quality (`sq`), traffic (`tr`), effort, "anything to correct" (`correction`); and the add-missing extras water on climb, hairpins, shade / exposure, famous for, approach. Ascent, average gradient and steepest sustained are `CatalogField::derivedText`: displayed, never typed | name filled |
| 3 | **Photos + links** | up to 6 photos through the shared uploader (consent modal on the first drop, quarantine scan, ids in the hidden `mediaIds` field, claimed by the submission at intake; [../photo-uploads.md](../photo-uploads.md) §4); links through the shared links editor | none (all optional); Next is held while a photo is uploading or checking (`cc:media-busy`) |
| 4 | **Review** | echoes exactly the entered values ([README.md](README.md) P3); the foot/summit line is followed by the **measured** numbers (length, gain, average, steepest) so the review repeats what step 1 showed; the provenance line (curator queue; ODbL data / CC BY-SA media) | submit blocked while routing/profiling is pending |
| 5 | **Submitted** | real POST → submission receipt; the page's own lifecycle/funnel line says what happens next | none |

**Intake.** The form posts as `CatalogContributionService::submit('add', …)` →
`submitAdd()`, the same path as every other type: `type: climbs`, `details` (name, surface,
`sq`, `tr`, effort, correction), `extras`, `lat`/`lng`, the hidden `route`/`grad`/`steep`/
`avg`/`steepPoint`, and `mediaIds`. For climbs `submitAdd()` merges
`ClimbGeometry::fromPayload()` into the attributes and runs `deriveClimbProfile()`, so
length, gain, average and maximum gradient and the profile bars are measured from the
DEM ([../climb-elevation.md §4](../climb-elevation.md)). If the wizard sent no `lat`/`lng`,
the foot of the drawn route becomes the pin. The result is a NewItem submission in the
curator queue ([../moderation-and-contribution.md §3.3](../moderation-and-contribution.md)),
and photos ride along via `mediaIds` exactly as for an improve.

**Gate rules, in one place:** foot and summit placed before Next on step 1, and nothing
in flight (a mid-flight two-point placeholder is never submitted); name required on step 2;
a photo still uploading or checking holds Next on step 3; submit waits for routing and the
profile.

**Honesty rule** (carried from the original climb wizard design and still binding): never
fabricate a derived value and present it as measured, and **never let a rider type one**.
Length comes from the real routed geometry, the height gain and the average gradient are
measured from the DEM profile of that geometry (ascent-only average), the maximum is read
off the steepest-ramp marker, and the gradient profile exists only when real elevation
resolved (see the no-fake-profile rule above). The numbers are shown in the rider's unit
(`ccKm`/`ccElev`, [../account-and-auth.md](../account-and-auth.md)) but stored metric; there
is no typed field to convert back.

**Arriving from the map (camera hint).** The map's "Add a climb here" rail block links to
`/improve?type=climbs&mode=add&lat=&lng=&z=`; `panels.js` `initAddClimbHere()` rewrites the
href on every map move, keeping the page's own query and adding the camera. Old
`/add-climb?lat=&lng=&z=` links still work: `ContributeController::addClimb()` answers with a
**301** to the same target, validating the hint on the way (`lat`/`lng` must be numeric and
in range or both are dropped; `z` is clamped to 3..18). `improve.js` reads `?lat=&lng=&z=`
(clamping `z` to 3..18 again) and opens the map at that view, otherwise at its own default
centre. Only the camera travels; the foot pin is never seeded from it
([../map-and-search.md §8.1](../map-and-search.md)).

Submissions consume the shared per-user `contribution_submit` rate limiter
(`web/config/packages/rate_limiter.yaml`, currently 20/hour sliding window).

### `/add-climb` retired (2026-08-25)

The dedicated 5-step wizard (`add_climb.html.twig`, `add-climb.js`, `App\Form\AddClimbType`,
`AddClimbTest`, `CatalogContributionService::submit('climb', …)` / `submitClimb()`) was
deleted on 2026-08-25 (owner decision). Why: two forms existed for one thing; the
registry-driven form already had more fields (effort, correction, famous for, approach,
links) than the wizard; and every fix was landing in the wrong one, so the two drifted
apart with each round. `/add-climb` remains only as the 301 described above.

Carried over into `/improve` for climbs, add and edit alike: the on-map hint pill and the
read-only measured block (above), the review step's echo of the measured numbers, and the
gating on in-flight routing/profile and on photo uploads.

Dropped on purpose: the wizard's "journey of a climb" governance diagram (the `/improve`
page already carries its lifecycle/funnel line); the static "gradient guidance"
pseudo-field (it looked like a field and nobody could fill it in); the "Already in OSM?"
toggle (OSM linking is the linker's job, [../catalog-data-model.md §5b](../catalog-data-model.md));
and the typed length and elevation-gain fields, which the DEM overwrote on submit anyway.

Translations: the whole `add_climb.*` block and the `meta.add_climb_*` keys were removed
from all five locales; new keys are `improve.step1.measured_length`,
`improve.step1.measured_gain`, `improve.step1.measured_avg`, `improve.step1.measured_max`,
`improve.review.measured_avg` and `improve.review.measured_max`. `map.add_climb_*` stays
(the map rail still says "Add a climb here") and so does `js.climb_*` (shared editor copy).
`/add-climb` was removed from `SitemapController`.

The three dated subsections below describe the shared editor and the retired wizard as
they were in August 2026. They are kept as history; wherever they say "add-climb" or "the
wizard", the behaviour now lives in `/improve`.

### The "it sends me back to the first page" bug (2026-08-03)

Editing almost any seeded climb was impossible, and said nothing about why.

`ClimbGeometry` validated `steep.pct` against `/^\d{1,2}(\.\d{1,2})?%?$/` — it
had to start with a digit. But five of the six seeded climbs store their
steepest pitch as an approximation: `~20%`, `~11%`, `~13%`. That is honest for a
ramp nobody has surveyed, and `render.js` prints it verbatim on the steepest
marker.

So: the editor loads the stored marker, carries its `pct` into the hidden
`steep` field, and **any** submission that touched the geometry came back
`invalid_geometry`. The rider was returned to step 1 with no message, because
the wizard rendered no form errors at all (fixed the same day — see
moderation-and-contribution.md and the `.wiz-errors` block in
`improve.html.twig`). Two faults compounding: a validator that rejected the
application's own data, and a form that would not say so.

The rule now allows one optional leading `~` and nothing else
(`/^~?\d{1,2}(\.\d{1,2})?%?$/`); `~~20%`, `~abc` and `~200%` stay refused.

**Why this went unnoticed.** Every automated probe submitted successfully, and
the first explanation written here — "a headless browser never populates the
hidden geometry fields, MapLibre does not initialise" — was **wrong**. MapLibre
initialises fine; the canvas renders and the markers place. The real reason is
below: those probes opened `/improve?item=…` with no `lat`/`lng`, which until
2026-08-03 dropped the Locate step entirely, so there was no editor to write
`route`/`grad`/`steep` and the validator was never reached. With the step
restored, the geometry path IS reachable from a probe.

`ClimbGeometryTest` still covers the rule directly, which is the right place
for it.

### A climb always gets its map (2026-08-03)

`LOCATE` was gated on `ADD || hasCoords || RELOCATE`, and `hasCoords` depends on
the CALLER putting `lat`/`lng` in the URL. The map drawer's edit link does; the
contributions list's (`?item=&type=`) does not. So a rider following the link
from their own contributions reached a wizard with **no map at all** — stepper
straight to Details.

That is wrong for any item and fatal for a climb: its line is not a location it
happens to sit at, it IS the item, and the only way to change where the climb
ends is to drag the summit. A rider answering a curator's question about their
proposed ending arrived at a form that could not show the thing being asked
about (owner-reported 2026-08-03).

Letter N now always gets the Locate step when the item has a stored route.
`CC_ITEM` already carries `route`/`grad`/`steep` regardless of the step, so the
editor mounts on the real geometry with nothing extra sent.

### Elevation, gradients and the profile live in their own spec

How a climb's length, height gain, average and maximum gradient and profile bars
are MEASURED — the elevation source chain, the binning rules, the steepest-ramp
window, and the chart that displays them — is owned by
[climb-elevation.md](../climb-elevation.md).

The short version, because it changes what this page may offer: **nobody types a
gradient.** A rider marks the foot and the summit; everything else is derived.
`maxGradient` is already `CatalogField::$derived` for that reason, and the rest
follows when that spec is built.

**Still latent:** the editor requests OSRM with `overview=full`, and
`ClimbGeometry::MAX_POINTS` is 2000. A long enough climb could exceed it and be
refused as `invalid_geometry` — now at least visibly. Not yet measured against a
real long climb.

### The wizard caught up with `/improve` (2026-08-03, history)

*(Describes the retired `/add-climb` wizard; since 2026-08-25 only `/improve` exists and
everything below is simply its own behaviour.)*

Both flows mounted the same three-point editor, but only `/improve` had been given
that editor's supporting treatment. `/add-climb` was given all of it:

- **Its JS is translated.** 23 hardcoded English strings — the readouts, the
  search notes, the review-card labels, the nav — moved into a
  `window.CC_ADD_CLIMB_I18N` bag, serialised with all four `JSON_HEX_*` flags
  and read through a `t()` that resolves a missing key to empty rather than to
  its own name. Same rules as `improve.js`
  ([docs/plans/2026-08-01-improve-js-i18n.md](../../plans/2026-08-01-improve-js-i18n.md)):
  **strings crossing into JS are text; markup stays in Twig.**
- **Nothing with a value in it is built with `innerHTML` any more.** The review
  card and the Photon results list — where rider-entered text and catalogue
  strings meet in one node, and a curator reads it back — are built with
  `createElement` + `textContent` through `assets/contribute/review-card.js`,
  the module `improve.js` already uses. `escHtml()` is gone from the file, so
  there is no longer a call anyone can forget. The search rows also carry their
  coordinates in a closure instead of `data-` attributes, which removes the last
  string-into-attribute path there.
- **The four-line how-to** (tap foot → summit, tap again for the steepest ramp,
  drag to correct, Undo takes back the last thing) now appears above the map,
  not only on `/improve`. The third tap is undiscoverable without it.
- **Undo is wired.** `mountClimbEditor` always exposed `undo()`/`canUndo()` and
  an `onHistory` callback; the add-climb wizard simply never used them. The control is
  revealed only once there is history to take back.

**Where that copy lives.** The how-to lines and the Undo label describe the
*shared editor*, not either wizard, so they moved from `improve.step1.*` into
`js.*` beside the editor's marker labels (`js.climb_how_1..4`, `js.climb_undo`).
Two wizards mounting one control must not be able to explain it two different
ways. That split outlived the second wizard: `js.climb_*` is still the editor's
copy, and the page copy (readouts, search notes, review labels) is `improve.*`;
the `add_climb.*` keys are gone.

## Read view (drawer "current details")
- Length
- Average gradient
- Max gradient
- Surface
- Traffic

## Edit form  (`improve.html?item=cote-de-la-redoute`)
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Name | input | `[edit]` |
| Surface | select(Smooth asphalt / Asphalt / Worn asphalt / Cobbles / Gravel) | `[OSM]` |
| Average gradient (%) | read-only (measured from the DEM) | `[auto]` |
| Max gradient (%) | read-only (off the steepest marker) | `[auto]` |
| Anything to correct? | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Water on climb? | select(Unknown / Yes / No) | `[tap]` |
| Hairpins (count) | input | `[edit]` |
| Shade / exposure | select(Unknown / Wooded / Partly shaded / Exposed) | `[edit]` |

### Report a problem
- Foot/top wrong · Gradient wrong · Surface changed · Duplicate

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Demo (`main` branch):** registry entry per climb in `atlas/demo/edit-items.js` (typed values).
- **Production:** add on `/improve?type=climbs&mode=add`, edit on `/improve?item=<id>&type=N`,
  both `ImproveType` + `improve.js` mounting `climb-editor.js`; intake
  `CatalogContributionService::submitAdd()` (add) and the edit path, both validating the
  geometry through `ClimbGeometry::fromPayload()` and measuring length, gain, gradients and
  profile bars with `deriveClimbProfile()` from the DEM (Copernicus GLO-30 via Valhalla
  `/height`, [../climb-elevation.md](../climb-elevation.md)); road snapping via our own
  Valhalla behind `POST /contribute/route`. Seeded climbs carry the provenance label
  "OSM roads · geometry handmade". The dedicated `/add-climb` wizard, `add-climb.js` and
  `AddClimbType` were deleted on 2026-08-25; the route is a 301.
