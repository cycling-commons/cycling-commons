<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Edit spec — G · Shelter

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** G · Shelter
- **Map depiction:** ⛑ pin, colour #9A8FB6
- **Editable:** yes · Frontend demo · 2026-06-18
- **Lifecycle:** *utility / coverage* — verified (≥ X community confirmations) then shown; **never votable, never best-of** (value is completeness). Lives in **Everything** mode. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
Refuges, cabanes and emergency shelter on exposed terrain.

## Read view (drawer "current details")
- Type · Use · Where

## Edit form
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Shelter type | select(Refuge / chapel / Bus shelter / Café (seasonal) / Picnic hut) (amenity=shelter) | `[OSM]` |
| Always accessible? | select(Yes — open structure / Daytime only / Seasonal / Unknown) | `[tap]` |
| Water nearby? | select(Unknown / Yes / No) | `[tap]` |
| Still as mapped? | select(As mapped / Closed / Not there anymore) | `[tap]` |
| Note | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Bench / seating? | select(Unknown / Yes / No) | `[edit]` |
| Phone signal? | select(Unknown / Yes / No) | `[edit]` |
| Website | url (`web`) | `[edit]` |

### Report a problem
Not built: per-type reasons are not offered. A rider reports a place through the content report ([../content-reports.md](../content-reports.md)).

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Production:** OSM shelters of the contract's `shelter_type` values (`basic_hut`, `dugout`,
  `field_shelter`, `gazebo`, `lean_to`, `pavilion`, `picnic_shelter`, `rock_shelter`, `sun_shelter`,
  `weather_shelter`, `wildlife_hide`; `pipeline/contract/coverage-contract.json`, letter G) mirrored,
  plus `[tap]` access confirmations.
