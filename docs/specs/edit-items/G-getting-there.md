# Edit spec — G · Getting there

- **Catalog layer:** G · Getting there
- **Map depiction:** 🚆 pin, colour #3E7D8C
- **Edit-item id:** `aywaille-station` in `atlas/demo/edit-items.js`
- **Editable:** yes · Frontend demo · 2026-06-18

## What it is
Multimodal access points — stations with bikes-on-train, the gateway to the climbs.

## Read view (drawer "current details")
- Type · Line · Bikes on train

## Edit form  (`improve.html?item=aywaille-station`)
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Bikes on board | select(Allowed with supplement / Allowed, free / Restricted at peak / Not allowed) | `[OSM]` |
| Step-free access | select(Unknown / Yes / No) (wheelchair=) | `[OSM]` |
| Bike parking at station | select(Unknown / Covered racks / Open racks / None) | `[OSM]` |
| Note | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Lift / ramp? | select(Unknown / Yes / No) | `[OSM]` |
| Bike ticket needed? | select(Unknown / Yes / No) | `[edit]` |

### Report a problem
- Wrong details · Closed · Duplicate

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Demo:** registry entry `aywaille-station` in `atlas/demo/edit-items.js` (hand-picked fixture data).
- **Production:** OSM railway=station + operator (SNCB) info + community edits.
