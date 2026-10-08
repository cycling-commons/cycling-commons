<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Edit spec — C · Public toilets

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** C · Public toilets
- **Map depiction:** pin with the drawn toilet (`ItemType::svgPath()`, `TOILET_PATH` in `icons.js`; 🚻 is the text fallback), colour #4E6E8C
- **Editable:** yes · full wizard (add / improve / materialize-on-edit)
- **Lifecycle:** *utility* — never votable; riders confirm with a plain
  "still here?" stance (`ConfirmationStance::Exists`), same as D/E/F/G. See
  [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## The letter — C

This type takes **C** in the letter scheme (practical types A–M, experiential
N–Z, [README](README.md#the-items)). Letters are stable storage identifiers,
never display order: every
surface that lists categories orders this one editorially **right after
B · Water & food** — the contribute hub
card, the map layer rail (`CATALOG` array order in
`web/assets/map/catalog.js`), and any future legend.

## What it is

Public toilets and sanitary stops — the second-most-asked utility after
water on a long ride. OSM's `amenity=toilets` is the reference base
(coverage contract letter `C`); riders add what OSM misses and confirm what
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
| `web` | url | Website (the place's own site) |

## Wiring

- `ItemType::PublicToilets` (`public-toilets`, letter C, 🚻, existence
  confirmations) + `CatalogFormRegistry` field set above.
- Coverage contract letter `C` (`amenity=toilets`) in
  `pipeline/contract/coverage-contract.json`; pipeline `LETTERS` and the
  serving-plane rosters (`CoverageRepository`, `LETTER_KEY`) carry C.
- Map: `toilets` layer (key ↔ letter C), catalog payload key `C`, OSM bulk
  pool `CC_TOILETS_OSM`.
- Contribute hub card (after Water & food), add wizard, materialize-on-edit
  and confirmations all work through the generic per-letter machinery — no
  C-specific code paths.
