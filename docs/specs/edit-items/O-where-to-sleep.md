<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Edit spec — O · Where to sleep

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** O · Where to sleep
- **Map depiction:** pin with the type's drawn icon (`ItemType::svgPath()`; ⛺ is the text fallback), colour #B5532E; no per-kind glyphs (`PlaceKind::LETTERS` leaves O out)
- **Editable:** yes · the `/improve` wizard (add, improve, materialize-on-edit)
- **Lifecycle:** *votable* — verified (≥ X community confirmations) → votable → **best-of** (top-voted); appears in **Best-of** mode once it earns votes. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
Bike-friendly stays riders actually used — gîtes and B&Bs with secure storage and a welcome for muddy kit.

## Read view (drawer "current details")
- Type
- Secure bike storage
- Cyclist label
- Area

## Edit form
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Name | input | `[edit]` |
| Type | select (`type`), alphabetical: Campsite / Chalet / gîte / Guest house / B&B / Holiday rental / Hostel / Hotel / Motel / Mountain hut / Wilderness hut; one OSM `tourism=` tag each, filled when an OSM point is converted ([osm-data-architecture.md §5a](../osm-data-architecture.md)) | `[OSM]` |
| Town / commune | input (`town`) | `[edit]` |
| Website | url (`web`) | `[edit]` |
| Tourist office | url (`officeWeb`): the stay's tourist office, for a stay without a website of its own. Tourisme Wallonie PIVOT fills it with the stay's Maison du Tourisme ([data-provider-hierarchy.md §10](../data-provider-hierarchy.md)); an organisation, never a person | `[provider]` |
| Secure bike storage | select(Yes — locked room / Yes — garage/shed / On request / No) | `[edit]` |
| Drying / washing for kit | select(Unknown / Yes / No) | `[edit]` |
| Cyclist label | multiselect(Bett+Bike / Accueil Vélo / Bike-friendly); nothing ticked means not stated. The drawer shows the OSM labels (`bettundbike`, `bett_and_bike:ref`, `award:accueilvelo`, `bike_friend`) in the same row until a stored value replaces them ([coverage-provider.md §2.1](../coverage-provider.md)) | `[OSM]` |
| Booking link | url | `[edit]` |
| Note for riders | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Pets allowed? | select(Unknown / Yes / No) | `[edit]` |
| Meals / breakfast? | select(Unknown / Yes / No) | `[edit]` |
| Tools to borrow? | select(Unknown / Yes / No) | `[edit]` |
| Accessibility | multiselect(Step-free access / Handbike-friendly / Wheelchair-accessible); nothing ticked means not stated | `[edit]` |

### Report a problem
Not built: per-type reasons are not offered. A rider reports a place through the content report ([../content-reports.md](../content-reports.md)).

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Production:** OSM `tourism=camp_site`, `hostel`, `guest_house`, `chalet`, `wilderness_hut`,
  `alpine_hut`, `motel` and `hotel` harvested (`pipeline/contract/coverage-contract.json`, letter O),
  plus Tourisme Wallonie's PIVOT register (4,152 Walloon stays, `wallonie-pivot`,
  [data-provider-hierarchy.md §10](../data-provider-hierarchy.md)) and community contributions.
- **Kind:** the Type is a kind, one OSM `tourism=` tag each (`App\Catalog\PlaceKind`); Holiday rental
  (`tourism=apartment`) is a kind for our own places only and is not harvested. O has kinds but no
  per-kind map glyphs ([osm-data-architecture.md §5a](../osm-data-architecture.md)).
