# Edit spec — J · History & culture

- **Catalog layer:** J · History & culture
- **Map depiction:** 🏛 pin, colour #6E5849
- **Edit-item id:** `stavelot-abbey` in `atlas/demo/edit-items.js`
- **Editable:** yes · Frontend demo · 2026-06-18

## What it is
Landmarks, local stories and cycling-heritage sites to ride past. (Split out from the old "Scenic & cultural" — the heritage/POI half.)

## Read view (drawer "current details")
- Type · Founded · Cycling link

## Edit form  (`improve.html?item=stavelot-abbey`)
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Name | input | `[edit]` |
| Type | select(Heritage site / Museum / Monument / Religious site) (historic=) | `[OSM]` |
| Bike parking | select(Unknown / Yes / No) | `[OSM]` |
| Anything to add? | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Opening hours | input (opening_hours) | `[OSM]` |
| Entry fee? | select(Free / Paid / Unknown) | `[edit]` |
| Cycling story / link | input | `[edit]` |

### Report a problem
- Wrong details · Wrong location · Duplicate

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Demo:** registry entry `stavelot-abbey` in `atlas/demo/edit-items.js` (hand-picked fixture data).
- **Production:** OSM historic=* / tourism=museum mirrored; the "cycling story" is a community `[edit]` overlay.
