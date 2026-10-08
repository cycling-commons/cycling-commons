<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Edit spec — B · Water & food

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** B · Water & food
- **Map depiction:** the water drop by what is known (`KindIcons`: a filled drop, a crossed drop when not potable, an unfilled drop when unknown, a fork and knife for food); the type's drawn icon is `ItemType::svgPath()` with 💧 as the text fallback; colour #8FB6A8
- **Editable:** yes · the `/improve` wizard (add, improve, materialize-on-edit)
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
| Type | select(Public fountain / Drinking tap / Cemetery tap / Café - refill point) | `[OSM]`; a provider may default it (RIVM: Drinking tap, data-provider-hierarchy.md §5.2) |
| Potable? | select(Yes (public supply) / Unknown / No / non-potable) | `[tap]`; "Unknown" is a real answer, not a blank: somebody looked and nobody can say. It draws the unfilled drop, the same look a row nobody has spoken about gets (owner 2026-09-10). A spelling starting with "No" is forbidden here: `ModerationService::stanceFromAnswer()` and `icons.js waterKind()` both prefix-match "No" as non-potable. |
| Seasonal availability | select(Year-round / Summer only / Frost-shut in winter / Unknown) | `[tap]` |
| Availability | select(Unknown / Always / Daytime only / Ask or behind a gate) | `[tap]`, filled by the Dutch register's `type` where it has one; the clock badge reads it |
| Still as mapped? | select(As mapped / Out of order / Closed / Not there anymore) | `[tap]`; never defaulted, by the form or by a provider. Empty shows as the first option "Not checked yet" (catalog-data-model.md §7) |
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
