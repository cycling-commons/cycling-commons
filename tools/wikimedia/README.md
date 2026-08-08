<!-- SPDX-License-Identifier: Apache-2.0 -->
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

**It gives you the col, never the foot.** Where a climb begins is a judgement
about which junction the pass road leaves the valley at, and getting it wrong is
the defect already logged in docs/TODO.md against the climbs we have. Treat this
as research for a human building a seed list, not as an importer.
