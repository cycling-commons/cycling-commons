<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Edit spec — B · Climbs

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** B · Climbs
- **Map depiction:** gradient-coloured line + foot pin, icon ⛰, colour #6A2C8F
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

Validation invariants (`App\Contribution\ClimbGeometry`): `route`/`grad` are capped at
`ClimbGeometry::MAX_POINTS` (currently 2000) entries; coordinates must be finite and in
range (lat −90..90, lng −180..180); `steep.pct` must match a gradient shape
(`12`, `12.5%` — up to two digits, optional decimal, optional `%`). Malformed geometry
surfaces as a form error on the `route` field, never a silent discard.

**One shared editor, both flows.** `web/assets/contribute/climb-editor.js`
(`window.Cc.mountClimbEditor`) is mounted by both the add flow (`add-climb.js`) and the
edit flow (`improve.js`, which swaps it in for the generic single-pin Locate step when
the item is letter B). Markers are labelled to make direction unambiguous — foot = green
"START · foot", summit = orange "END · summit", steepest = red warning ▲ "STEEPEST · \<pct\>"
(labels come from the `js.climb_marker_*` translation keys via `window.CC_EDITOR_LABELS`).
The editor writes the three attributes into hidden form fields (`route`/`grad`/`steep` on
both `App\Form\AddClimbType` and `App\Form\ImproveType`).

**Auto-routing.** Placing or moving foot + summit auto-routes the road between them via
client-side OSRM (`router.project-osrm.org`), producing `route` and the snapped length;
the gradient profile (`grad`) is then derived from the routed track via the client
elevation API (`window.Cc.profileFromRoute` in `climb-elevation.js`, Open-Meteo elevation,
≤ 100 sampled points). Requests time out after `FETCH_TIMEOUT_MS` (`climb-editor.js`,
currently 10 s).

**Steepest: auto-placed, manual-locked.** The steepest marker is auto-placed on the
max-gradient segment of `grad`. Dragging or re-tapping it sets `manual: true`, which
persists with the attribute — from then on it **never auto-moves again**: re-routing
(after a foot/summit change) leaves its position fixed and only refreshes its `pct` from
the new `grad` at that position. While still auto (`manual: false`), each re-route
re-detects and re-places it.

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

`avgGradient`/`maxGradient` are **not** recomputed server-side from `grad`: in the add
flow the average is computed live client-side (gain ÷ length) into the posted field; in
the edit flow both are ordinary registry fields the rider can adjust alongside the shape.

## Adding a climb (`/add-climb`)

Climbs do not use the generic `/improve?mode=add` pin flow — they have a dedicated
5-step gated wizard (`ContributeController::addClimb()`,
`web/templates/contribute/add_climb.html.twig`, `web/assets/contribute/add-climb.js`).
Next is disabled until each step's minimum is met:

| # | Step | Contents | Gate to advance |
|---|---|---|---|
| 1 | **Where** | map hosting the shared three-point editor (foot → summit → auto-routed track + steepest); keyless Photon place search with map-tap fallback; Reset | foot + summit placed, no routing/elevation request in flight |
| 2 | **Profile** | name, length (auto-filled from the routed track, editable — a user-typed value always wins over re-autofill), elevation gain, avg gradient (read-only, = gain ÷ length, live), max gradient, surface, road quality (`sq`), traffic (`tr`) | name + elevation gain > 0 |
| 3 | **Details** | discipline chips (incl. Handbike, with a gradient-ceiling audience hint), rider note, "Already in OSM?" toggle | none (all optional) |
| 4 | **Review** | echoes exactly the entered values ([README.md](README.md) P3) with the provenance line (curator queue; ODbL data / CC BY-SA media) | submit blocked while routing/profiling is pending |
| 5 | **Submitted** | real POST → submission receipt; "you are here" highlight on the journey diagram | — |

The **"journey of a climb" governance diagram** (you draw it → curator review → approved
→ Everything backlog → eligible next round → voted up → best-of, with needs-info and
rejected branches) stays always visible below the wizard (the template's `.journey` block).

**Honesty rule** (carried from the original add-climb design and still binding): never
fabricate a derived value and present it as measured. Length comes from the real routed
geometry, the average gradient is an honest live computation of gain ÷ length, and the
gradient profile exists only when real elevation resolved (see the no-fake-profile rule
above).

Submissions consume the shared per-user `contribution_submit` rate limiter
(`web/config/packages/rate_limiter.yaml`, currently 20/hour sliding window).

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
| Average gradient (%) | input (from DEM) | `[auto]` |
| Max gradient (%) | input | `[auto]` |
| Anything to correct? | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Water on climb? | select(Unknown / Yes / No) | `[tap]` |
| Hairpins (count) | input | `[edit]` |
| Shade / exposure | select(Unknown / Wooded / Exposed) | `[edit]` |

### Report a problem
- Foot/top wrong · Gradient wrong · Surface changed · Duplicate

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Demo:** registry entry per climb in `atlas/demo/edit-items.js` (typed values).
- **Production:** geometry handmade from OSM road geometry (provenance label "OSM roads · geometry handmade"); gradient auto via DEM (SRTM / Copernicus GLO-30); optional snapping via Valhalla.
