<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Edit spec — Q · History & culture

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** Q · History & culture
- **Map depiction:** 🏛 pin, colour #6E5849
- **Edit-item id:** `stavelot-abbey` in `atlas/demo/edit-items.js`
- **Editable:** yes · Frontend demo · 2026-06-18
- **Lifecycle:** *votable* — verified (≥ X community confirmations) → votable → **best-of** (top-voted); appears in **Best-of** mode once it earns votes. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
Landmarks, local stories and cycling-heritage sites to ride past. (Split out from the old "Scenic & cultural" — the heritage/POI half.)

## Read view (drawer "current details")
- Type · Founded · Cycling link

## Edit form  (`improve.html?item=stavelot-abbey`)
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Name | input | `[edit]` |
| Type | select(Heritage site / Museum / culture / Monument / Religious site / Architecture) (historic=) | `[OSM]` |
| Bike parking | select(Unknown / Yes / No) | `[OSM]` |
| Still as mapped? | select(As mapped / Closed / Not there anymore) | `[tap]` |
| Anything to add? | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Opening hours | select(Unknown / 24/7 / See website) | `[edit]` |
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
