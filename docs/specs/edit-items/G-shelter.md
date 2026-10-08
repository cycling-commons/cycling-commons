<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Edit spec — G · Shelter

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** G · Shelter
- **Map depiction:** the kind's glyph on a disc (`KindIcons`, one per kind); a place with no kind yet keeps the type's drawn icon (`ItemType::svgPath()`; ⛑ is the text fallback), colour #7A6DA5, deep enough that the glyph is white (contrast 4.6:1; owner 2026-10-08: "white on purple")
- **Editable:** yes · the `/improve` wizard (add, improve, materialize-on-edit)
- **Lifecycle:** *utility / coverage* — verified (≥ X community confirmations) then shown; **never votable, never best-of** (value is completeness). Lives in **Everything** mode. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
Refuges, cabanes and emergency shelter on exposed terrain.

## Read view (drawer "current details")
- Type · Use · Where

## Edit form
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Type | select (`type`), alphabetical: Basic hut / Bus shelter / Defibrillator / Dugout / Field shelter / Gazebo / Lean-to / Pavilion / Picnic shelter / Rock shelter / Sun shelter / Weather shelter / Wildlife hide; every harvested OSM `shelter_type` is one of them, Bus shelter and Defibrillator are ours only ([osm-data-architecture.md §5a](../osm-data-architecture.md)) | `[OSM]` |
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
- **Kind:** the Type is a kind, one OSM tag each (`App\Catalog\PlaceKind`), stamped on every OSM point
  and drawn as its own glyph. A bus shelter (`shelter_type=public_transport`) is everywhere and is not
  harvested; a defibrillator (`emergency=defibrillator`) is ours to add
  ([osm-data-architecture.md §5a](../osm-data-architecture.md)).
