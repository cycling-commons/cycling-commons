# Edit spec — F · Hazards & conditions

- **Catalog layer:** F · Hazards & conditions
- **Map depiction:** ⚠ pin, colour #C8923A
- **Edit-item id:** `exposed-crosswind-hautes-fagnes` in `atlas/demo/edit-items.js`, dynamic — needs freshness
- **Editable:** yes · Frontend demo · 2026-06-18

## What it is
Persistent, real hazards — crosswind and fog exposure, ice, loose surface — kept fresh by rider confirmations so stale warnings age out.

## Read view (drawer "current details")
- Type
- Severity
- Seasonal

## Edit form  (`improve.html?item=exposed-crosswind-hautes-fagnes`)
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Hazard type | select(Crosswind / fog / Ice / frost / Loose surface / gravel / Flooding / Roadworks / Other) | `[edit]` |
| Severity | select(Low / Moderate / High) | `[edit]` |
| When is it worst? | select(Autumn / winter / Year-round / After rain / Windy days) | `[edit]` |
| Still present? | select(Yes — confirmed today / Reduced / Gone — clear now) | `[tap]` |
| What did you see? | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Alternative / detour | input | `[edit]` |
| Time of day | select(Any / Morning / Afternoon / Evening) | `[edit]` |

### Report a problem
- Resolved / gone · Wrong location · Duplicate

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Demo:** registry entry `exposed-crosswind-hautes-fagnes` in `atlas/demo/edit-items.js` (hand-picked fixture data).
- **Production:** community report + freshness decay (confirmations age out); safety-tagged, never auto from OSM.
