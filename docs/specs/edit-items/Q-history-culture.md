<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Edit spec — Q · History & culture

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** Q · History & culture
- **Map depiction:** the kind's glyph on a disc (`KindIcons`, one per kind); a place with no kind yet keeps the type's drawn icon (`ItemType::svgPath()`; 🏛 is the text fallback), colour #6E5849
- **Editable:** yes · the `/improve` wizard (add, improve, materialize-on-edit)
- **Lifecycle:** *votable* — verified (≥ X community confirmations) → votable → **best-of** (top-voted); appears in **Best-of** mode once it earns votes. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
Landmarks, local stories and cycling-heritage sites to ride past. Viewpoints and natural
features are [P · Scenic views](P-scenic-views.md).

## Read view (drawer "current details")
- Type · Founded · Cycling link

## Edit form
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Name | input | `[edit]` |
| Type | select (`type`, shown alphabetically: Castle / Fort / Ruins / Monument / Memorial / Archaeological site / Manor / Monastery / Museum / Place of worship / Heritage site / Architecture); every OSM tag is one of them, Heritage site and Architecture are ours only ([osm-data-architecture.md §5a](../osm-data-architecture.md)) | `[OSM]` |
| Bike parking | select(Unknown / Yes / No) | `[OSM]` |
| Still as mapped? | select(As mapped / Closed / Not there anymore) | `[tap]` |
| Description | textarea (`note`) | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Opening hours | select(Unknown / 24/7 / See website) | `[edit]` |
| Entry fee? | select(Free / Paid / Unknown) | `[edit]` |
| Cycling story / link | input | `[edit]` |
| Official site | url (`web`) | `[edit]` |
| Other pages about this place | links (`links`) | `[edit]` |

### Report a problem
Not built: per-type reasons are not offered. A rider reports a place through the content report ([../content-reports.md](../content-reports.md)).

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Production:** OSM `historic=` `castle`, `fort`, `ruins`, `monument`, `memorial`,
  `archaeological_site`, `manor` and `monastery` mirrored (no `tourism=museum`), each with a name or an
  `image`, `wikidata` or `wikimedia_commons` tag; memorials of the kinds `bench`, `blue_plaque`,
  `ghost_bike`, `grave`, `plaque`, `stolperstein` and `tomb` are left out
  (`pipeline/contract/coverage-contract.json`, letter Q). The "cycling story" is a community `[edit]`
  overlay.
- **Kind:** the Type is a kind, one OSM tag each, stamped on every OSM point and drawn as its own
  glyph. Museum (`tourism=museum`) and Place of worship (`amenity=place_of_worship`) are kinds for
  our own places only; Heritage site and Architecture have no OSM tag at all
  ([osm-data-architecture.md §5a](../osm-data-architecture.md)).
