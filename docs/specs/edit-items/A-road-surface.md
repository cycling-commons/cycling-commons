<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Edit spec — A · Road surface

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** A · Road surface
- **Map depiction:** line styled by surface class, icon ▰, colour #4E8C84. Rendered **above** ride/climb
  lines so the surface (e.g. a gravel sector along a ride) reads on top.
- **Edit-item id:** `road-surface` in `atlas/demo/edit-items.js`
- **Editable:** yes · Frontend demo · 2026-06-18
- **Lifecycle:** *utility / coverage* — verified (≥ X community confirmations) then shown; **never votable, never best-of** (value is completeness). Lives in **Everything** mode. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
Real road/path segments (RAVeL cycleway, forest gravel, pavé/sett) shown as lines coloured and patterned by surface, so riders can pick the right bike for the terrain.

## Location (add mode)
A surface entry is a **segment**, not a point: the first action is to tap the **start**, then the **end**.
(Contrast: most types drop a single pin; rides need none.)

**The line between them follows the road** (2026-08-12). The two pins are snapped
with the same bicycle router the climb editor uses — `/contribute/route` → our
Valhalla — and the path it returns is what is drawn and what is stored. A
straight chord between two taps crosses fields, houses and the wrong bank of a
river; on a map that reads as a mistake, because it is one. Measured on the
hairpins above Francorchamps, the road wanders **563 m** from the chord.

Stored as `attributes.segment = {a, b, line?}`: `a`/`b` are where the rider
pointed, `line` is the road. `line` is **optional by design** — no route, no
router, or an older client all mean the chord, which is a worse shape but never
a wrong one, and the rider is told the straight line stayed rather than being
shown a road we did not find. The **item** geometry is the `line` when present.
Dragging either pin re-snaps (sequence-guarded, so a slow earlier answer cannot
land after a newer drag).

Server-side `line` is bounded, because it is otherwise a channel for drawing any
shape at all under a name and surface somebody chose: ≤ 3,000 points, every pair
a real coordinate, and **both ends within 1 km of the pin they snapped from**.
A segment may follow an actual ride — the demo includes a **gravel descent traced along the Spa · Sankt
Vith loop** near its end, so a ride's real road type can be recorded.

## Correcting or confirming OSM's answer (built 2026-08-12)

Most A segments a rider sees are not ours: they are the **OSM surface skin**, a
tile layer of every surfaced way OSM knows
([map-and-search.md](../map-and-search.md) §5). Clicking one opens the drawer
with the class, the raw `highway` value and a link to the way on OSM, plus two
actions that are the **same submission** one decision apart:

| Action | What it does |
|---|---|
| ✎ Edit this item | opens `/improve?ref=way/NNN&type=A&sa=…&sb=…` — the add wizard in segment mode |
| ✓ This is correct | the same URL plus `&surface=<tile class>`, so the class arrives already chosen |

There is no separate "agree with OSM" store. Confirming **mints our own A
item** carrying `way/NNN` as `source_ref` (materialize-on-edit,
[osm-data-architecture.md](../osm-data-architecture.md) §6); from then on the
ordinary one-tap item confirmation applies to that item, and the region's
moderators decide the submission exactly as they decide any other. Approved, our
line draws on top of the tile line; the tile stays underneath as OSM's answer.

**The wizard opens knowing where the stretch is.** The clicked way already has
ends, so `sa`/`sb` place both pins on it, draggable — the rider adjusts rather
than re-taps from a blank map. A wrong-but-close start beats an empty one.

**Not every class can be confirmed.** `cycleway` says what a way *is*, not what
it is made of, and `unverified` is the absence of a claim; neither offers the
confirm action. The other five map to a declarable label in
`SurfaceVocabulary::TILE_CLASS` (`paved`→Asphalt, `gravel`→Gravel,
`pave`→Sett — pavé, `dirt`→Dirt, `rock`→Rock — coarser than the dropdown by
design, since one colour stands for a family of OSM values). The map states the
same five in `CONFIRMABLE_CLASSES`; `SurfaceConfirmClassContractTest` fails if
the two lists ever drift.

Segment-located types never resolve through `coverage_poi` — it is the point
index, and surface lines never enter PostGIS. The submission keeps a point (its
start, which is what resolves the region); the **item** gets the LineString.

## Read view (drawer "current details")
- Surface
- Smoothness
- Width
- Traffic

## Edit form  (`improve.html?item=road-surface`)
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Surface | select(Asphalt / Concrete / Paving stones / Sett — pavé / Compacted / Fine gravel / Gravel / Dirt / Rock) | `[OSM]` |
| Smoothness | select(Excellent / Good / Intermediate / Bad / Very bad) | `[OSM]` |
| Width (m) | input | `[OSM]` |
| Traffic | select(Quiet / Moderate / Busy / Car-free (RAVeL)) | `[edit]` |
| Note | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Lit at night? | select(Unknown / Yes / No) | `[edit]` |
| Segregated from cars? | select(Unknown / Yes / No) | `[OSM]` |
| Seasonal closure? | select(None / Winter / Forestry) | `[edit]` |

### Report a problem
- Wrong surface · Surface changed (resurfaced) · Blocked / impassable · Wrong location

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Styling is keyed on `surface=`, not `smoothness=`

The A layer's classification and rendering key **primarily on the OSM `surface=`
tag** (with `highway=` only as a fallback for untagged ways); `smoothness=` is a
displayed/editable attribute row but **never a styling key**. This is deliberate:
keying style on smoothness reproduces CyclOSM's known failure mode where a gravel
way tagged `smoothness=intermediate` renders as if paved — the surface disappears
under the smoothness value. Verified in the harvest classifier
(`tools/wallonia/route_surfaces.py`, `_cls()`: "the actual `surface=` tag wins")
and the map styles (`web/assets/map/map.js`, `SURFACE_STYLE` keyed by surface
class: cycleway teal · paved slate · gravel ochre · pavé slate-grey · dirt brown ·
rock grey · unverified red dashes — "unverified" = OSM has no `surface=` tag,
an invitation to tag it).

Rendering is **one line layer per surface class** (over a single consolidated
GeoJSON source + one shared casing layer) because MapLibre cannot data-drive
`line-dasharray`/`line-cap` per feature within a layer — the dash pattern that
distinguishes gravel/pavé/dirt from solid paved must live at the layer level.
Line width scales from the `width=` tag.

## Implementation
- **Demo:** registry entry `road-surface` in `atlas/demo/edit-items.js` (hand-picked fixture data); segment geometry in `atlas/demo/surface-data.js`.
- **Production:** sourced via the `tools/wallonia` harvest today; region-bbox bulk harvesting is
  superseded going forward ([../catalog-data-model.md](../catalog-data-model.md) §12) and A is
  deliberately excluded from the coverage artifact
  ([../coverage-provider.md](../coverage-provider.md) §4 — corridor line data, its own future
  decision). Rendering: one MapLibre line sub-layer per surface class (solid = paved,
  dashed = gravel, dotted = rough), line width from `width=`.
