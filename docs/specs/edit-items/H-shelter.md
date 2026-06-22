# Edit spec — H · Shelter & emergency

- **Catalog layer:** H · Shelter & emergency
- **Map depiction:** ⛑ pin, colour #9A8FB6
- **Edit-item id:** `shelter-baraque-michel` in `atlas/demo/edit-items.js`
- **Editable:** yes · Frontend demo · 2026-06-18

## What it is
Refuges, cabanes and emergency shelter on exposed terrain.

## Read view (drawer "current details")
- Type · Use · Where

## Edit form  (`improve.html?item=shelter-baraque-michel`)
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Shelter type | select(Refuge / chapel / Bus shelter / Café (seasonal) / Picnic hut) (amenity=shelter) | `[OSM]` |
| Always accessible? | select(Yes — open structure / Daytime only / Seasonal / Unknown) | `[tap]` |
| Water nearby? | select(Unknown / Yes / No) | `[tap]` |
| Note | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Bench / seating? | select(Unknown / Yes / No) | `[edit]` |
| Phone signal? | select(Unknown / Yes / No) | `[edit]` |

### Report a problem
- Gone · Wrong location · Duplicate

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Demo:** registry entry `shelter-baraque-michel` in `atlas/demo/edit-items.js` (hand-picked fixture data).
- **Production:** OSM amenity=shelter mirrored + `[tap]` access confirmations.
