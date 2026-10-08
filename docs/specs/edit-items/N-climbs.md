<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Edit spec — N · Climbs

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** N · Climbs
- **Map depiction:** gradient-coloured line + foot pin, icon: a drawn twin-peak mountain (`ItemType::svgPath()`, `MOUNTAIN_PATH` in `icons.js`; ⛰ is the text fallback), colour #6A2C8F
- **Editable:** yes · the `/improve` wizard with the three-point climb editor (add, improve)
- **Lifecycle:** *votable* — verified (≥ X community confirmations) → votable → **best-of** (top-voted); appears in **Best-of** mode once it earns votes. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
A linear feature (foot → summit) with a gradient profile; the layer that closed databases lock down.

**Where a climb is listed besides its own pin.** A recommended route's drawer lists
"Climbs on this route": every served climb the route rides upward for at least 30% of
the climb's `route` line, in order along the route, with its average gradient. The
ridden part may start partway up: a route that joins the climb halfway and rides to
the summit lists it, from where it joins; a route that rides the same part summit
first, downhill, does not, and neither does one that leaves the climb more than
2 km below its summit. A climb near the route that it does not ride is in the
drawer's along list instead, in its climbs group. The rule (40 m tolerance,
share, direction, top part) is in
[route-domain.md](../route-domain.md) §6.4, the drawer rows in
[map-and-search.md](../map-and-search.md) §6.3. Because the test reads `route` foot
first, a climb whose line runs past its summit or starts below its foot changes
which routes list it.

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

**The climb's point is its foot.** `item.geom` is `route[0]` whenever the climb has a
usable line (at least two pairs, the first numeric), kept by the database on every
write ([../catalog-data-model.md §6a](../catalog-data-model.md)). The pin, the region
the climb is filed in, and the duplicate check all sit at the foot; a climb with no
line keeps the point it was given. The edit arm moves no pin of its own for a climb
with a line: redrawing the line moves the point to the new foot when the edit is
approved.

Validation invariants (`App\Contribution\ClimbGeometry`): `route`/`grad` are capped at
`ClimbGeometry::MAX_POINTS` (currently 8000) entries; coordinates must be finite and in
range (lat −90..90, lng −180..180); `steep.pct` must match a gradient shape
(`/^~?\d{1,2}(\.\d{1,2})?%?$/`: `12`, `12.5%`, or an approximation such as `~20%`, which
seeded climbs store for a ramp nobody has surveyed; `~~20%`, `~abc` and `~200%` are
refused). Malformed geometry surfaces as a form error on the `route` field (the
`.wiz-errors` block in `improve.html.twig`), never a silent discard. Pinned by
`ClimbGeometryTest`.

**One shared editor, both arms of one page.** `web/assets/contribute/climb-editor.js`
(`window.Cc.mountClimbEditor`) is mounted by `improve.js` for both the add arm
(`/improve?type=climbs&mode=add`) and the edit arm (`/improve?item=<id>&type=N`); it swaps
the editor in for the generic single-pin Locate step whenever the type is letter N, and a
climb with a stored route always gets the Locate step, whatever the link carried (its line
is the item, and the only way to change where it ends is to drag the summit). Markers are
labelled to make direction unambiguous: foot = green "START · foot", summit = orange "END · summit", steepest = red warning ▲ "STEEPEST · \<pct\>"
(labels come from the `js.climb_marker_*` translation keys via `window.CC_EDITOR_LABELS`).
The editor writes the attributes into hidden form fields on `App\Form\ImproveType`
(`route`/`grad`/`steep`, plus `avg` and `steepPoint`; the add arm also fills `lat`/`lng`
from the foot, and the server pins the new climb at the foot of its `route` whatever
`lat`/`lng` arrive).

**Auto-routing.** Placing or moving foot + summit auto-routes the road between them via
`POST /contribute/route` (`App\Elevation\RouteSnapper`, our own Valhalla, `bicycle`
costing), producing `route` as the full road shape and the snapped length. The map draws
`route` vertex for vertex, so the stored line is never thinned
([../climb-elevation.md §7d](../climb-elevation.md));
the gradient profile (`grad`) is then derived from the routed track by
`window.Cc.profileFromRoute` in `climb-elevation.js`, which posts up to 200 sampled points
to **our own** `POST /contribute/elevation` (`App\Elevation\ElevationClient` → Valhalla
`/height`), so the dataset is a setting rather than a third party's choice, and the sample
count is our own limit ([../climb-elevation.md §2b-i](../climb-elevation.md)). Requests time out after `FETCH_TIMEOUT_MS` (`climb-editor.js`,
currently 10 s).

**Two steepest markers, and they are not the same thing.** `steep` is ours: the
steepest sustained 250 m the elevation model can see (`ClimbProfiler::MAX_WINDOW_M`,
the 95th percentile of sliding windows), derived on every redraw
and never edited, which is what makes it comparable between climbs.
`steepPoint` is the rider's — where the wall actually is, placed by hand with a
distinct amber icon, optionally carrying a percentage and a short note. It
exists because the model *cannot* answer that question: a hairpin smaller than
one DEM cell is invisible to it at any window width, so nothing recovers Mur de
Huy's ~26% by computing harder. Placement is a mode (`markSteepestPoint()`),
not a fourth tap, because it is optional and repeatable. See
[../climb-elevation.md §5a](../climb-elevation.md).

**Steepest: found, not placed.** The marker is derived from the steepest sustained
250 m window, and an automatic one is **re-derived on every route change**: the line is
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

**Both gradients are derived from the drawn line, add and edit alike** (owner
2026-08-04). Neither is a box anyone types in: `CatalogField::derivedText` keeps them out
of the edit form, and every redraw measures them again.

- `avgGradient`: the **ascent-only** average over 100 m bins, measured on the server by
  `App\Elevation\ClimbProfiler` inside `CatalogContributionService::deriveClimbProfile()`.
  The editor's hidden `avg` field is a transport key and is never stored. Definition and
  the reasoning for ascent-only: [../climb-elevation.md §4b](../climb-elevation.md).
- `maxGradient`: the steepest sustained 250 m window (95th percentile), measured in the
  same `ClimbProfiler` pass that places the `steep` marker; the width travels with the
  figure as `steepWindowM` ([../climb-elevation.md §2a](../climb-elevation.md)).

## Adding a climb (`/improve?type=climbs&mode=add`)

A climb is added on the same registry-driven page that edits one. There
is **one contribution form per item type** ([README.md](README.md)): `/improve?type=climbs&mode=add`
is the `mode=add` arm of `ContributeController::improve()` (`addPlace()`, `ImproveType` with
`add_mode: true`, `web/templates/contribute/improve.html.twig`,
`web/assets/contribute/improve.js`), and `/improve?item=<id>&type=N` is the edit arm of the
same page. Next is disabled until each step's minimum is met:

| # | Step | Contents | Gate to advance |
|---|---|---|---|
| 1 | **Where** | map hosting the shared three-point editor (foot → summit → auto-routed track + steepest); an on-map **hint pill** at the top of the map (`#wz-mapHint`) that says the next tap (foot, then summit) and hides once both are set; the four-line how-to; keyless Photon place search with map-tap fallback; Undo + Reset; the optional **+ Steepest point** control for the rider's own steepest point (`web/assets/contribute/rider-steep.js`); the similar-places list under the map ([README.md](README.md), Locate step); a read-only **measured** block under the map (`#wz-measured`: length, height gain, average gradient, steepest) filled live from the elevation profile as the line is drawn; on an edit it starts from the stored `gain` and `avgGradient` (passed in `window.CC_ITEM`) until a redraw re-measures them | foot + summit placed, and no routing or elevation-profile request in flight (`WZ.locPending`) |
| 2 | **Details** | the registry fields for `ItemType::Climbs` (`CatalogFormRegistry`): **name** (required, injected by add mode), surface, road quality (`sq`), traffic (`tr`), effort, "anything to correct" (`correction`); and the add-missing extras water on climb, hairpins, shade / exposure, famous for, approach. Ascent, average gradient and steepest sustained are `CatalogField::derivedText`: displayed, never typed | name filled |
| 3 | **Photos + links** | up to 6 photos through the shared uploader (consent modal on the first drop, quarantine scan, ids in the hidden `mediaIds` field, claimed by the submission at intake; [../photo-uploads.md](../photo-uploads.md) §4); links through the shared links editor | none (all optional); Next is held while a photo is uploading or checking (`cc:media-busy`) |
| 4 | **Review** | echoes exactly the entered values ([README.md](README.md) P3); the foot/summit line is followed by the **measured** numbers (length, gain, average, steepest) so the review repeats what step 1 showed, then the rider's steepest point when one is placed ("⬗ steepest point 18 %", `improve.review.rider_point`/`rider_point_pct`); the provenance line (curator queue; ODbL data / CC BY-SA media) | submit blocked while routing/profiling is pending |
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

**Honesty rule:** never
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

### Elevation, gradients and the profile live in their own spec

How a climb's length, height gain, average and maximum gradient and profile bars
are MEASURED (the elevation source chain, the binning rules, the steepest-ramp
window, and the chart that displays them) is owned by
[climb-elevation.md](../climb-elevation.md). The short version, because it
changes what this page may offer: **nobody types a gradient.** A rider marks the
foot and the summit; everything else is derived.

**Point cap.** The editor routes through our own Valhalla behind
`POST /contribute/route` and keeps the full road shape, and
`ClimbGeometry::MAX_POINTS` is 8000. A climb whose shape exceeds it is refused
as `invalid_geometry`, visibly.

**Where the editor's copy lives.** The how-to lines, the Undo label and the
marker labels describe the *shared editor*, so they are `js.climb_*` keys
(`js.climb_how_1..4`, `js.climb_undo`, `js.climb_marker_*`); the page copy
(readouts, measured block, search notes, review labels) is `improve.*`. Strings
crossing into JS are text; markup stays in Twig. The review card and the Photon
results list are built with `createElement` + `textContent`
(`assets/contribute/review-card.js`), never `innerHTML`.

## Read view (drawer "current details")
- Length
- Average gradient
- Max gradient
- Surface
- Traffic

## Edit form
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Name | input | `[edit]` |
| Surface | select(Smooth asphalt / Asphalt / Worn asphalt / Cobbles / Gravel) | `[OSM]` |
| Road quality (`sq`) | select(Smooth / Good / Worn / Rough / Broken / loose) | `[edit]` |
| Traffic (`tr`) | select(Traffic-free / Quiet / Moderate / Busy) | `[edit]` |
| Ascent | read-only (measured from the DEM) | `[auto]` |
| Average gradient (%) | read-only (measured from the DEM) | `[auto]` |
| Steepest sustained (%) | read-only (the steepest 250 m window) | `[auto]` |
| Effort | select(Steady / Challenging / Tough / Very steep) | `[edit]` |
| Anything to correct? | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Water on climb? | select(Unknown / Yes / No) | `[tap]` |
| Hairpins (count) | input | `[edit]` |
| Shade / exposure | select(Unknown / Wooded / Partly shaded / Exposed) | `[edit]` |
| Famous for | input | `[edit]` |
| Approach | input | `[edit]` |

### Report a problem
Not built: per-type reasons are not offered. A rider reports a place through the content report ([../content-reports.md](../content-reports.md)).

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Production:** add on `/improve?type=climbs&mode=add`, edit on `/improve?item=<id>&type=N`,
  both `ImproveType` + `improve.js` mounting `climb-editor.js`; intake
  `CatalogContributionService::submitAdd()` (add) and the edit path, both validating the
  geometry through `ClimbGeometry::fromPayload()` and measuring length, gain, gradients and
  profile bars with `deriveClimbProfile()` from the DEM (Copernicus GLO-30 via Valhalla
  `/height`, [../climb-elevation.md](../climb-elevation.md)); road snapping via our own
  Valhalla behind `POST /contribute/route`. Seeded climbs are `source = 'manual'` rows
  (`SeedManualCatalogCommand`). `/add-climb` is a 301 to the add arm.
