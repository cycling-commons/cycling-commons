<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Edit spec — M · Public toilets

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** M · Public toilets
- **Map depiction:** 🚻 pin, colour #4E6E8C
- **Editable:** yes · full wizard (add / improve / materialize-on-edit)
- **Lifecycle:** *utility* — never votable; riders confirm with a plain
  "still here?" stance (`ConfirmationStance::Exists`), same as D/F/G/H. See
  [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## The letter — M, deliberately not L

Letter **L is reserved** for the derived, anonymized ride heatmap (see
`ItemType`'s class doc), so this type takes **M**. Letters are stable storage
identifiers, never display order: every surface that lists categories orders
this one editorially **right after C · Water & food** — the contribute hub
card, the map layer rail (`CATALOG` array order in
`web/assets/map/catalog.js`), and any future legend.

## What it is

Public toilets and sanitary stops — the second-most-asked utility after
water on a long ride. OSM's `amenity=toilets` is the reference base
(coverage contract letter `M`); riders add what OSM misses and confirm what
exists.

## Data sources (Netherlands note)

Start with **OSM** (`amenity=toilets`) — the Dutch commercial app HogeNood
(hogenood.nl) has the richest NL dataset but **no open data**; treat it as a
potential partner, never a scrape target. Same discipline as the stays
sourcing rule (Warmshowers/WTMG precedent).

## Edit form

### Fix details
| field | kind | choices |
|---|---|---|
| `fee` | select | Free · Paid |
| `wheelchair` | select | Unknown · Yes · No |
| `openingHours` | text | free text ("24/7", "Apr–Oct daylight") |
| `condition` | select | As mapped · Out of order · Closed · Not there anymore |
| `note` | textarea | rider guidance ("behind the beach pavilion; code at the counter") |

### Add missing
| field | kind | choices |
|---|---|---|
| `changingTable` | select | Unknown · Yes · No |
| `shower` | select | Unknown · Yes · No |

## Wiring

- `ItemType::PublicToilets` (`public-toilets`, letter M, 🚻, existence
  confirmations) + `CatalogFormRegistry` field set above.
- Coverage contract letter `M` (`amenity=toilets`) in
  `pipeline/contract/coverage-contract.json`; pipeline `LETTERS` and the
  serving-plane rosters (`CoverageRepository`, `LETTER_KEY`) carry M. Tiles
  and `coverage_poi` rows appear after the next harvest run; every reader
  tolerates their absence until then.
- Map: `toilets` layer (key ↔ letter M), catalog payload key `M`, OSM bulk
  pool `CC_TOILETS_OSM`.
- Contribute hub card (after Water & food), add wizard, materialize-on-edit
  and confirmations all work through the generic per-letter machinery — no
  M-specific code paths.
