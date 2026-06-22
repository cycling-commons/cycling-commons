# Edit spec — C · Water & food

- **Catalog layer:** C · Water & food
- **Map depiction:** pin, icon 💧, colour #8FB6A8
- **Edit-item id:** `water-fountain` in `atlas/demo/edit-items.js`, shared by both fountains (Stavelot + Coo) — editing either opens the same edit item
- **Editable:** yes · Frontend demo · 2026-06-18

## What it is
Ride-critical drinking water / refill points (fountains, taps, cemetery taps, cafés).

## Read view (drawer "current details")
- Type
- Potable
- Seasonal

## Edit form  (`improve.html?item=water-fountain`)
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Type | select(Public fountain / Drinking tap / Cemetery tap / Café — refill point) | `[OSM]` |
| Potable? | select(Yes — public supply / Unsigned — use judgement / No / non-potable) | `[tap]` |
| Seasonal availability | select(Year-round / Summer only / Frost-shut in winter / Unknown) | `[tap]` |
| Note | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Bottle-fill friendly? | select(Unknown / Yes / No) | `[edit]` |
| Cost | select(Free / Customers only) | `[edit]` |

### Report a problem
- Gone / dry · Wrong location · Not potable · Duplicate

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Demo:** shared registry entry `water-fountain` in `atlas/demo/edit-items.js`; any number of fountains can point at one edit item.
- **Production:** OSM `amenity=drinking_water` mirrored, plus `[tap]` seasonal / potable confirmations.
