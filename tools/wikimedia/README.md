<!-- SPDX-License-Identifier: AGPL-3.0-only -->
# Wikimedia lookups

## `commons_photo.py` — a licence-verified photo for a place

Finds a Wikimedia Commons photo for a named place and **proves it is free to
use** before handing it over. The search is the easy part; the licence check is
the reason this exists.

```bash
python3 tools/wikimedia/commons_photo.py "Furka Pass" "Grimsel Pass"
python3 tools/wikimedia/commons_photo.py --candidates "Furka Pass"   # whole Commons category
python3 tools/wikimedia/commons_photo.py --file "Am Sustenpass.jpg"  # check one file
```

It resolves the name to a Wikidata item, takes **P18 only**, then asks the
Commons API for that exact file's licence and author and accepts nothing
outside the free-licence list (which is kept inside `ccUrl()`'s table in
`web/assets/map/util.js`, so the drawer's licence link always resolves). A file
whose author Commons does not state is **skipped**, not credited to the
filename — a CC BY-SA photo you cannot attribute is a photo you cannot use.

`wc_args` in the JSON output is exactly the argument list
`SeedManualCatalogCommand::wc()` takes, in order.

### Two things the script cannot decide for you

1. **Whether the photo shows the thing.** P18 for a mountain pass is sometimes
   a road sign, a wildflower, or the construction site of a nearby dam. Look at
   the image.
2. **Whether there are people in it.** A free licence says nothing about the
   people in frame, and the Commons category for a famous climb is mostly race
   photography — spectators' faces, close up, in focus. Those are exactly the
   photos this project refuses to publish (`docs/specs/photo-uploads.md` §6).
   Every candidate for the Côte de Stockeu and the Côte de la Roche-aux-Faucons
   was rejected on this ground in the 2026-08-08 pass, despite clean licences.

## `climb_candidates.py` — well-known climbs, and a check on ours

```bash
python3 tools/wikimedia/climb_candidates.py --country ES      # candidates
python3 tools/wikimedia/climb_candidates.py --verify          # check what we store
```

`--verify` is the valuable half. It compares our stored `summitEle` against
Wikidata's published elevation for the same pass, which is an **independent**
check on the DEM, the sampling, and above all on where the drawn line stops — a
climb whose line ends short of the col reads low here and nowhere else.

2026-08-08: Furka +1, Grimsel +1, Susten +3, Nufenen −2, Klausen +14,
**Gotthard −11**. Six agreements inside 14 m, and the old Susten defect (2260 m
against a published 2224) is gone. Gotthard is the one worth redrawing.

`--country` answers "can we get 10 well-known climbs here". Availability is
uneven and tracks whether a country's famous climbs are *mountain passes*:
CH/IT/FR/ES have 10+ each, BE gets its Flemish bergs correctly, GB about eight,
DE/JP/AU are thin, and NL has none — there are no mountain passes in the
Netherlands.

Since 2026-09-06 the finder also takes `--class hill|climb|steep|mountain`
(Wikidata classes beside mountain pass: the Dutch and Luxembourg climbs are
hills, the Flemish walls are "hillclimbing", the Cauberg and Ventoux are
mountains) and `--source osm` (named `mountain_pass=yes` nodes via Overpass,
for a country Wikidata barely knows: Colombia has one pass there and 106 in
OpenStreetMap). `climb_sides.py` takes the same two flags, plus `--min-km`
and `--min-pct` so a 600 m Dutch berg is not thrown away by Alpine floors
(climb-elevation.md §7c).

**It gives you the col, never the foot.** Where a climb begins is a judgement
about which junction the pass road leaves the valley at, and getting it wrong is
the defect already logged in docs/TODO.md against the climbs we have. Treat this
as research for a human building a seed list, not as an importer.

## `country_places.py` — scenic + historical places per country

```bash
python3 tools/wikimedia/country_places.py --country ES --per-layer 4
python3 tools/wikimedia/country_places.py --all
```

Harvests notable viewpoints (layer **I**) and heritage sites (layer **J**) from
Wikidata, verifies every photo against the same licence bar `commons_photo.py`
sets, and writes a **reviewable** `out/places-<cc>.json`. The Symfony side
(`app:catalog:seed-wikidata <dir>`) imports that artifact, so what ships is a
list a human read, not whatever the API returned the minute the seed ran.

Selection is by sitelink count, which measures **fame, not quality and not
whether a road goes there**. Review the file. A place whose photo has no stated
author is dropped rather than shipped uncredited.

## `climb_foot.py` — where a climb starts, from its summit and its length

```bash
python3 tools/wikimedia/climb_foot.py --summit 50.3726,5.9437 --length-km 2.3 --both
```

Closes the gap that stopped `climb_candidates.py` being an importer. A foot is
not unknown, it is *implied*: follow the road down from the col for the
published length and that is where the climb begins. Overpass supplies the
roads, the walk keeps to the straightest descending branch at junctions, and
Valhalla gives the drop so a wrong answer is visible.

Validated against Côte de Stockeu, whose foot we already know: derived
50.39116,5.93722 against a true 50.39147,5.93248 — **338 m apart on a 2.3 km
climb**, from geometry alone.

`--both` reports every side of the pass with its drop and average gradient,
because **which side is still a human's call** — Furka from Gletsch and Furka
from Realp share a summit and are different climbs. The reported average
gradient is the check: if it does not match the published figure, the walk went
down the wrong side.

Needs Valhalla for the elevation half (`make up-routing`); without it the walk
still works and honestly reports "no elevation" rather than guessing.
