# Edit spec — B · Climbs

- **Catalog layer:** B · Climbs
- **Map depiction:** gradient-coloured line + foot pin, icon ⛰, colour #6A2C8F
- **Edit-item id:** `cote-de-la-redoute`, `mur-de-huy`, `cote-de-stockeu`, `cote-de-la-roche-aux-faucons` in `atlas/demo/edit-items.js` (one edit item per climb)
- **Editable:** yes · Frontend demo · 2026-06-18

## What it is
A linear feature (foot → summit) with a gradient profile; the layer that closed databases lock down.

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
