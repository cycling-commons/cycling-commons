<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Edit spec — Q · History & culture

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** Q · History & culture
- **Map depiction:** 🏛 pin, colour #6E5849
- **Editable:** yes · Frontend demo · 2026-06-18
- **Lifecycle:** *votable* — verified (≥ X community confirmations) → votable → **best-of** (top-voted); appears in **Best-of** mode once it earns votes. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
Landmarks, local stories and cycling-heritage sites to ride past. (Split out from the old "Scenic & cultural" — the heritage/POI half.)

## Read view (drawer "current details")
- Type · Founded · Cycling link

## Edit form
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Name | input | `[edit]` |
| Type | select(Heritage site / Museum / culture / Monument / Religious site / Architecture) (historic=) | `[OSM]` |
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
