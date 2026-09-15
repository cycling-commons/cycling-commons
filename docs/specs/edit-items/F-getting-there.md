<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Edit spec — F · Getting there

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** F · Getting there
- **Map depiction:** 🚆 pin, colour #3E7D8C
- **Edit-item id:** `aywaille-station` in `atlas/demo/edit-items.js`
- **Editable:** yes · Frontend demo · 2026-06-18
- **Lifecycle:** *utility / coverage* — verified (≥ X community confirmations) then shown; **never votable, never best-of** (value is completeness). Lives in **Everything** mode. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
Multimodal access points — stations with bikes-on-train, the gateway to the climbs.

## Read view (drawer "current details")
- Type · Line · Bikes on train
- **Bikes on board from OSM.** An uncurated F point shows a Bikes on board row
  when OSM tags `bicycle` on it, in plain words: `yes`, `designated` or
  `permissive` read "Allowed", `no` reads "Not allowed", `dismount` reads "Walk
  your bike", and `bicycle:fee=yes` adds "with a fee" where a bike may come
  aboard. Any other value, or no `bicycle` tag, gives no row
  ([coverage-provider.md §5](../coverage-provider.md)). A stored Bikes on board
  value on a curated item takes the row's place. OSM carries the tag on ferries,
  not stations: in the Netherlands extract 368 of 580 ferry routes and 28 of 884
  ferry terminals have it, and no station does. Step-free access, bike parking,
  lift or ramp and bike ticket have no OSM source in the harvest and stay
  community fields.

## Edit form  (`improve.html?item=aywaille-station`)
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Bikes on board | select(Allowed with supplement / Allowed, free / Restricted at peak / Not allowed) | `[OSM]` |
| Step-free access | select(Unknown / Yes / No) (wheelchair=) | `[OSM]` |
| Bike parking at station | select(Unknown / Covered racks / Open racks / None) | `[OSM]` |
| Still as mapped? | select(As mapped / Closed / Not there anymore) | `[tap]` |
| Note | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Lift / ramp? | select(Unknown / Yes / No) | `[OSM]` |
| Bike ticket needed? | select(Unknown / Yes / No) | `[edit]` |

### Report a problem
- Wrong details · Closed · Duplicate

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Demo:** registry entry `aywaille-station` in `atlas/demo/edit-items.js` (hand-picked fixture data).
- **Production:** OSM `railway=station`, `railway=halt`, `amenity=ferry_terminal`
  and `route=ferry` with their `bicycle` and `bicycle:fee` tags, plus community
  edits. Heritage railways (`usage=tourism` or `usage=leisure`, such as the
  Museumstoomtram Hoorn-Medemblik) are left out: they are a day out, not a way
  to get somewhere. So is a ferry or terminal tagged `bicycle=no`: it takes no
  bikes ([coverage-provider.md §7](../coverage-provider.md)).
- **A dock without a `bicycle` tag inherits the answer of the ferry routes that
  end at it** (the route's first or last node is the dock). Allowed wins over
  walk your bike, which wins over no; a dock whose every tagged route says no
  is left out. The answer is stored apart from the dock's own tags
  (`cc:bicycle_from_route`, `cc:bicycle:fee_from_route`, `cc:ferry_route`), and
  the drawer says so: "Bikes on board: Allowed · from the ferry Enkhuizen -
  Stavoren" ([coverage-provider.md §3, §5](../coverage-provider.md)).
- **A ferry route shows its crossing facts** when OSM has them: crossing time
  (`duration`), season (`seasonal`), service hours (`opening_hours`), fare
  (`toll` or `fee`) and website. Every dock links the ferry routes that end at
  it, including a dock with its own `bicycle` tag (whose tag still answers Bikes
  on board), and a dock with exactly one route shows that route's facts with a
  "Ferry" badge.
