<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Edit spec — P · Scenic views

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** P · Scenic views
- **Map depiction:** pin with a drawn camera (`ItemType::svgPath()`; the 📷 glyph from `ItemType::icon()` is only the text fallback), colour #2C5440
- **Editable:** yes · Frontend demo · 2026-06-18
- **Lifecycle:** *votable* — verified (≥ X community confirmations) → votable → **best-of** (top-voted); appears in **Best-of** mode once it earns votes. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
Viewpoints, panoramas — the photo spot worth stopping for. (Split from the old "Scenic & cultural": this is the viewpoint/panorama half; heritage is now Q.)

## Read view (drawer "current details")
- Type · Elevation · The 700 m step · Tower · Setting · What you see · Climate · Watershed · For cyclists
- Worked example (Signal de Botrange): the 694 m high point, the Butte Baltia stone stair (1923) that
  reaches exactly 700 m, the Baltia tower (1934), the Hautes Fagnes setting, the climate (coldest/wettest
  in Belgium — record −25.6 °C, ~1,450 mm rain, 35+ snow days), and the watershed / language border.
- The photo credits the author (links to their Wikimedia user profile) and links **both** the licence
  deed and the image source (the Wikimedia Commons file page).

**Reference-link labels (convention):** use the *subject/topic* as link text — better for SEO and
accessibility than a generic "Wikipedia" (e.g. **Hautes Fagnes** for the High Fens article, official
site names for venues). Reserve the literal "Wikipedia" label only for a link to the item's *own*
article, where repeating the panel title would be redundant.

## Edit form
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Name | input | `[edit]` |
| Type | select(Viewpoint / high point / Natural feature) (tourism=viewpoint) | `[OSM]` |
| Access for bikes | select(Roadside / Short walk / Path only) | `[edit]` |
| What can you see? | input | `[edit]` |
| Still as mapped? | select(As mapped / Closed / Not there anymore) | `[tap]` |
| Description | textarea (`note`) | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Best light / time | select(Any / Morning / Golden hour / Sunset) | `[edit]` |
| Bench? | select(Unknown / Yes / No) | `[edit]` |
| Official site | url (`web`) | `[edit]` |
| Other pages about this place | links (`links`) | `[edit]` |

A new view more than 250 m from a bike way needs the rider to confirm it can be reached by bike
([../scenic-views.md](../scenic-views.md) §5).

### Report a problem
Not built: per-type reasons are not offered. A rider reports a place through the content report ([../content-reports.md](../content-reports.md)).

### Add photos
Available on this type (CC BY-SA 4.0) — **several at once**.
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Production:** OSM `tourism=viewpoint` and `waterway=waterfall` along a bike way, with a name or photo link, plus community edits ([scenic-views.md §2](../scenic-views.md)).
- **Map icon: a drawn camera** (owner 2026-08-14), not the 📷 emoji — the
  pins render glyphs as flat silhouettes, and a camera emoji's silhouette is
  a blank rounded box. The path is `ItemType::svgPath()`, served as
  `window.CC_TYPE_ICONS`; `CAMERA_PATH` in `web/assets/map/icons.js` reads it and feeds both
  the DOM pins (inline SVG) and the canvas discs (Path2D, evenodd lens hole).
