# Edit spec — D · Bike services

- **Catalog layer:** D · Bike services
- **Map depiction:** ⚙ pin, colour #6b6f5e
- **Edit-item id:** `repair-station-malmedy` in `atlas/demo/edit-items.js`, also the default/fallback edit item when `improve.html` gets no `?item=`
- **Editable:** yes · Frontend demo · 2026-06-18

## What it is
Shops, public repair stations, pumps and e-bike charging — the places riders reach for when something breaks or runs flat.

## Read view (drawer "current details")
- Type
- Pump
- Tools
- Hours

## Edit form  (`improve.html?item=repair-station-malmedy`)
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Name | input | `[edit]` |
| Pump valve | select(Presta + Schrader / Presta only / Schrader only / No pump) | `[OSM]` |
| Opening hours | input (opening_hours) | `[OSM]` |
| Tools available | input | `[edit]` |
| Anything to correct? | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Work stand? | select(Unknown / Yes / No) | `[edit]` |
| Chain tool? | select(Unknown / Yes / No) | `[edit]` |
| E-bike charging? | select(Unknown / Yes / No) | `[edit]` |

### Report a problem
- Gone / closed · Wrong location · Wrong details · Duplicate

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Demo:** registry entry `repair-station-malmedy` in `atlas/demo/edit-items.js` (hand-picked fixture data).
- **Production:** OSM `amenity=bicycle_repair_station` / `shop=bicycle` mirrored + community edits.
