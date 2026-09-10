<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Edit spec — B · Water & food

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** B · Water & food
- **Map depiction:** pin, icon 💧, colour #8FB6A8
- **Edit-item id:** `water-fountain` in `atlas/demo/edit-items.js`, shared by both fountains (Stavelot + Coo) — editing either opens the same edit item
- **Editable:** yes · Frontend demo · 2026-06-18
- **Lifecycle:** *utility / coverage* — verified (≥ X community confirmations) then shown; **never votable, never best-of** (value is completeness). Lives in **Everything** mode. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

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
| Type | select(Public fountain / Drinking tap / Cemetery tap / Public toilet / Café — refill point) | `[OSM]`; Public toilet added 2026-09-05: a tap at a public toilet, the commonest Dutch register case after fountains |
| Potable? | select(Yes (public supply) / Unknown / No / non-potable) | `[tap]`; "Unknown" is a real answer, not a blank: somebody looked and nobody can say. It draws the unfilled drop, the same look a row nobody has spoken about gets. Renamed from "Unsigned — use judgement" 2026-09-10, which described a missing sign rather than the state of our knowledge. A spelling starting with "No" is forbidden here: `ModerationService::stanceFromAnswer()` and `icons.js waterKind()` both prefix-match "No" as non-potable. |
| Seasonal availability | select(Year-round / Summer only / Frost-shut in winter / Unknown) | `[tap]` |
| Availability | select(Unknown / Always / Daytime only / Ask or behind a gate) | `[tap]`, filled by the Dutch register's `type` where it has one; the clock badge reads it |
| Still as mapped? | select(As mapped / Out of order / Closed / Not there anymore) | `[tap]` |
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
