# Edit spec — A · Road surface

- **Catalog layer:** A · Road surface
- **Map depiction:** line styled by surface class, icon ▰, colour #4E8C84. Rendered **above** ride/climb
  lines so the surface (e.g. a gravel sector along a ride) reads on top.
- **Edit-item id:** `road-surface` in `atlas/demo/edit-items.js`
- **Editable:** yes · Frontend demo · 2026-06-18

## What it is
Real road/path segments (RAVeL cycleway, forest gravel, pavé/sett) shown as lines coloured and patterned by surface, so riders can pick the right bike for the terrain.

## Location (add mode)
A surface entry is a **segment**, not a point: the first action is to tap the **start**, then the **end**;
the segment line is drawn between the two taps. (Contrast: most types drop a single pin; rides need none.)
A segment may follow an actual ride — the demo includes a **gravel descent traced along the Spa · Sankt
Vith loop** near its end, so a ride's real road type can be recorded.

## Read view (drawer "current details")
- Surface
- Smoothness
- Width
- Traffic

## Edit form  (`improve.html?item=road-surface`)
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Surface | select(Asphalt / Concrete / Paving stones / Sett — pavé / Compacted / Fine gravel / Gravel / Ground) | `[OSM]` |
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

## Implementation
- **Demo:** registry entry `road-surface` in `atlas/demo/edit-items.js` (hand-picked fixture data); segment geometry in `atlas/demo/surface-data.js`.
- **Production:** Overpass `way[surface]` / `[smoothness]` / `[width]` in the region bbox; render one MapLibre line sub-layer per surface class (solid = paved, dashed = gravel, dotted = rough), line width from `width=`.
