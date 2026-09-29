<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Edit spec — B · Water & food

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** B · Water & food
- **Map depiction:** pin, icon 💧, colour #8FB6A8
- **Editable:** yes · Frontend demo · 2026-06-18
- **Lifecycle:** *utility / coverage* — verified (≥ X community confirmations) then shown; **never votable, never best-of** (value is completeness). Lives in **Everything** mode. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
Ride-critical drinking water / refill points (fountains, taps, cemetery taps, cafés).

## Read view (drawer "current details")
- Type
- Potable
- Seasonal

## Edit form
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Type | select(Public fountain / Drinking tap / Cemetery tap / Café — refill point) | `[OSM]` |
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
| Website | url (`web`) | `[edit]` |

### Report a problem
Not built: per-type reasons are not offered. A rider reports a place through the content report ([../content-reports.md](../content-reports.md)).

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Production:** OSM `amenity=drinking_water`, `drinking_water=yes`, `amenity=water_point`,
  `man_made=water_tap` and `shop=bakery` mirrored (`pipeline/contract/coverage-contract.json`, letter B),
  plus `[tap]` seasonal / potable confirmations.
