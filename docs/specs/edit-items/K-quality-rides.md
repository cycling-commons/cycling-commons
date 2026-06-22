# Edit spec — K · Quality rides

- **Catalog layer:** K · Quality rides
- **Map depiction:** line, icon ★, colour #FF5A1F (brand orange)
- **Edit-item id:** `ride` in `atlas/demo/edit-items.js` — **shared by all six contributed GPX loops** (one ride edit flow, not six)
- **Editable:** yes · Frontend demo · 2026-06-18

## What it is
Contributed GPX loops that carry the wiki's cyclist-experience attributes — quietness, scenery, friendliness, suitability, accessibility, and best direction to ride. "Quality rides" is the rider-facing name for these cyclist-experience attributes, surfacing how a loop actually feels to ride rather than just its geometry.

## Location & search
A ride needs **no pin** — the GPX/FIT track sets the whole route. Each ride also carries a list of
**start / through towns** (a demo lookup of the loop's cities), shown in the drawer as **"Starts at"** and
**"Towns on route"**, so riders can find loops by start location. In production these are reverse-geocoded
from the track; in the demo they're a hand lookup keyed by ride name.

## Privacy — trim the ends
The **first and last ~350–750 m of every ride are dropped** before it's shown/published, so a loop never
reveals exactly where the rider started or finished (home/start fingerprints). The drawer states this
("First & last ~N m trimmed", `[k-anon]`). The demo trims the displayed path on load (deterministic per
ride); production trims at ingest, alongside map-matching and k-anonymity (see the wiki heatmap design).

## Read view (drawer "current details")
- **Difficulty** — a 1–5 scale (1 Easy · 2 Moderate · 3 Challenging · 4 Hard · 5 Very hard), shown as five
  numbered circles in the **climb-purple** ramp (light→dark); the ride's level is the enlarged, bold,
  filled circle, and hovering any circle reveals its label.
- Distance · **Starts at** · **Towns on route** (searchable) · Season · Source (Contributed GPX — GPS track only)
- Quietness / traffic level  `[auto][tap]`
- Scenic rating  `[tap]`
- Overall cycling-friendliness  `[tap]`
- Suitability by bike type — road / gravel / MTB / e-bike  `[edit]`
- Accessibility — adapted-bike / handbike friendly, gradient-limited  `[edit]`
- Best direction to ride the loop  `[edit]`

## Edit form  (`improve.html?item=ride`)
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Ride name | input | `[edit]` |
| Difficulty | select(Gentle/Moderate/Hard/Very hard) | `[edit]` |
| Best season | select(Spring/Summer/Autumn/Winter/Any) | `[edit]` |
| Dominant surface | select(Asphalt/Mixed/Gravel) | `[edit]` |
| Note for riders | textarea | `[edit]` |

### Add missing  (cyclist-experience attributes)
| Field | Control | Provenance |
|---|---|---|
| Quietness rating (1–5) | select | `[auto][tap]` |
| Scenic rating (1–5) | select | `[tap]` |
| Cycling-friendliness (1–5) | select | `[tap]` |
| Suitable bike types | multi-select(Road/Gravel/MTB/E-bike) | `[edit]` |
| Handbike-friendly? | select(Unknown/Yes/No) | `[edit]` |
| Gradient-limited? | select(No/≤6%/≤9%) | `[edit]` |
| Best direction | select(Clockwise/Counter-clockwise/Either) | `[edit]` |

### Report a problem
- Wrong / broken track · Trim a private start/end · Duplicate ride · Not actually rideable.

### Add a photo
Available on this type (CC BY-SA 4.0). **Rides additionally** get a **Replace track** tab to upload a `.gpx` / `.fit` recording (ODbL; personal start/end trimmable before it goes public).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Demo:** shared registry entry `ride` in `atlas/demo/edit-items.js`; the six loops live in `atlas/demo/routes-data.js`; the experience attributes are demo values shown in the map drawer.
- **Production:** GPS track only (k-anonymised, map-matched). Quietness is `[auto]` from a traffic model then `[tap]`-confirmed; scenic/friendliness are community `[tap]` ratings; suitability/accessibility/direction are `[edit]`.
