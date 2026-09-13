<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Building elevation tiles

The operational half: choosing a source and proving it, converting it into the
format the routing engine reads, installing it, and onboarding a region end to
end. Every step here builds on the previous page, which covers how a DEM lies to
you.

!!! info "Read these in order"

    1. [What a DEM is, and what it gets wrong](elevation-dem-concepts.md):
       resolution against accuracy, the trees, the file format, the four-cell
       floor. Everything below rests on it.
    2. **This page**: the pipeline, proving a source, installing, onboarding a
       region.
    3. [Measuring a climb](measuring-a-climb.md): turning installed tiles into
       a published gradient.

    **Copernicus GLO-30 is the source**, worldwide: 30 m cells, served from
    Valhalla's `/height`. The Europe tiles were built with the pipeline on this
    page (1137 `.hgt` tiles, 28 GB).

The design record is
[climb-elevation.md](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/climb-elevation.md);
the tool reference is
[`tools/elevation/README.md`](https://github.com/cycling-commons/cycling-commons/blob/main/tools/elevation/README.md).

## The pipeline

<!-- CODE-ILLUSTRATIVE the three steps -->
```bash
./fetch-glo30.sh BENELUX ./data/dem/glo30          # download
./to-hgt.sh ./data/dem/glo30 ./data/dem/hgt        # convert
node compare-sources.js ./old/hgt ./data/dem/hgt   # PROVE IT
```

**Fetching needs no account.** GLO-30 Public is on the AWS Open Data registry:
plain HTTPS against a public bucket, no credentials, no AWS CLI. Registration
with the Copernicus Data Space Ecosystem unlocks the *restricted* instances,
which we do not need. A few countries are withheld from the public set, and
sea-only cells simply do not exist, so "missing tile" is normal and not an error.

**Converting cuts from a mosaic, not tile by tile.** `.hgt` tiles overlap their
neighbours by one row and column, the value at exactly 6°E belongs to both
`E005` and `E006`. Convert each GeoTIFF independently and every tile gets a
nodata stripe along its north and east edges. Building a VRT mosaic first and
cutting from that fixes it.

The output is checked to be exactly 25,934,402 bytes. A short file means the
warp silently clipped, and a silently clipped elevation tile is precisely the
kind of fault that surfaces as a mysteriously flat climb months later.

## Proving a source, which is the real work

Downloading data is easy. Knowing whether to trust it is the job.

`compare-sources.js` reads two `.hgt` trees **with the same code**. That detail
carries the whole method: comparing through two different *services* measures
their interpolation as much as their data. Reading the rasters directly with one
reader isolates the thing you are actually asking about.

The reader was first checked against the real service on a tile both could see:
179 m of gain and 8.62% against Valhalla's 179 m and 8.61%. Only then were its
numbers trusted to judge anything.

Three measures, each earning its place:

| measure | why it is there |
|---|---|
| gain and average gradient | the figures riders actually see; disagreement here is user-visible |
| per-bin disagreement | the honest spread between two sources that are both plausible |
| **bins reading downhill** | useful, but *only* with the caveat below |

### The downhill-bin trap

A bin that reads downhill is not proof the source is wrong. Plenty of real
climbs go up, drop, and go up again.

Côte de la Roche-aux-Faucons is exactly that: it climbs to 228 m, descends to
185 m over more than a kilometre, then climbs again to 270 m. Our own 17 km
Hockai route reads downhill in 27 of its bins, and every one of them is a real
descent on a rail-trail that undulates. A rule of "any downhill bin condemns the
source" would have thrown out the correct answer in both cases.

What makes the measure useful is knowing **whether that stretch of road actually
descends**:

- On a climb known to rise monotonically, Mur de Huy, a 1.4 km wall with no
  descent anywhere in it, a downhill bin is unambiguously an artifact. That is
  what condemned GLO-90 on La Redoute: the road never descends, and GLO-90
  published a 10% drop through its middle.
- On any other climb, a downhill bin is a **question, not a verdict**.

The general way to answer that question without a surveyed reference is to
**cross-check two independent sources**. A descent both of them see is terrain.
A descent only one sees is an artifact. That test needs no prior knowledge of
the road, which is what makes it usable on climbs nobody has profiled.

The lesson generalises past elevation: a metric that is decisive on the example
you developed it against can be nonsense one climb over. The fix is not a better
threshold; it is knowing which question the number actually answers.

## What the evaluation concluded

EU-DEM v1 against Copernicus GLO-30, all seven seeded Wallonia climbs:

| | EU-DEM v1 | GLO-30 |
|---|---|---|
| La Redoute, gain | 179 m | 181 m *(reference: 180 m)* |
| mean per-bin disagreement | | 2.02 points |
| downhill bins on Mur de Huy *(never descends)* | 1 | **0** |

The gain match against an independent reference is the load-bearing result. The
Mur de Huy row is the narrow version of the downhill test, that climb genuinely
rises the whole way, so EU-DEM's bin there is an artifact and GLO-30's absence of
one is real. It is one bin on one climb, so it is a tiebreak, not the argument.

**The decision that followed: GLO-30 is the single source, worldwide.** No
chain, no per-coordinate resolution order, no regional fallback.

That is worth dwelling on, because the alternative is a chain: a regional DEM
first, SRTM next, GLO-90 as a worldwide floor. Every mechanism such a ranking
demands (a priority list, a per-continent raster inventory, a provenance field
that varies by where you are standing) exists **only to work around a first
choice that covers one continent**. A source that covers the world needs none
of it.

GLO-90 really is too coarse. But GLO-90 is a 3× downsample of GLO-30, and a
verdict on the coarse product says nothing about the fine one until it is
measured. **The cheapest thing on this page is measuring the option you assumed
was bad.**

Three things are not problems at all with a single worldwide source: EU-DEM's
regulated access terms, a per-region raster inventory, and a provenance field,
which is a constant.

The honest caveat, recorded rather than glossed: the evidence is one tile, one
massif, one latitude band. Benelux is the widening that confirms it, and the
storage arithmetic is why scope still matters, 24.7 MB per tile, 14,000–26,000
tiles for global land (**340–630 GB**). Scope rasters to onboarded countries,
not the globe.

Two Europe figures appear in the record and they are two different products over
two different footprints, not a contradiction. The **EU-DEM** conversion this
decision was sized against is 1,517 tiles and 37 GB (`docs/specs/climb-elevation.md`
§2a-i). The **GLO-30** set actually built and served is 1137 tiles and 28 GB,
land-only inside 35–72 N, 11 W–32 E, because sea-only cells do not exist in the
bucket at all: 1137 of that box's 1591 cells (`tools/elevation/README.md`).
Whenever a Europe tile count is quoted anywhere, check which of the two it means.

Two limits that survive the decision. GLO-30 Public **withholds tiles over a few
countries**, so "worldwide" has holes. And GLO-30 is a **DSM** just as EU-DEM
was, so the trees are still in the data.

## Installing, and a distinction worth money

<!-- CODE-ILLUSTRATIVE valhalla.json key -->
```
additional_data.elevation  ->  the directory holding the .hgt files
```

Copy the tiles there and **restart**. That is all.

**No tile rebuild is needed**, because the elevation service reads that directory
independently of the routing graph. This is worth stating plainly because the
opposite is widely assumed, and a rebuild is hours of work.

A rebuild buys something genuinely different: `build_elevation` bakes grade into
the *routing* tiles so cycling costs can prefer flatter roads. That is a separate
feature, and it is not required for climb profiles.

### Gzip works, and costs more than it saves

**Tiles are stored raw.** Gzip is tempting, and the measurement below is why it
is not used.

Valhalla does read `.hgt.gz` directly: `valhalla_build_elevation` has a
`--decompress` flag precisely so you can decline it. Verified: the same tile
served raw and gzipped returns identical heights, `[316, 313, 289]`, after a
container restart.

The restart is what makes that a real test. Rename a `.hgt` and query again
without restarting and you get the right answer from a file that is not there
any more: the old inode is still mapped.

The disk ratio is genuinely excellent, because a `.hgt` is 26 MB of 16-bit
integers with a lot of local similarity. Measured at `gzip -6`:

| tile | terrain | raw | gzip |
|---|---|---|---|
| N52E004 | Dutch polder | 24.7 MB | 1.8 MB (7%) |
| N70E024 | Arctic Norway | 24.7 MB | 5.8 MB (24%) |
| N49E006 | Luxembourg | 24.7 MB | 9.0 MB (36%) |
| N46E007 | Swiss Alps | 24.7 MB | 15.2 MB (61%) |
| **mixed sample** | | **123.7 MB** | **37.4 MB (30%)** |

Whole continents did better still: 127 GB to 25 GB, 38 GB to 5.8 GB. At that
rate the planet's land is roughly 140 GB instead of 468 GB, that last figure
being every land cell on Earth at 24.7 MB, near the top of the 340–630 GB range
this page sized earlier. Every planet-scale number here is that same arithmetic
over a different footprint, so read the footprint before comparing two of them.

### What the source says, and what the measurement says

Two rounds of measurement and a read of `src/skadi/sample.cc` upstream. The
first round compared two different continents and drew the wrong lesson from
it; this is the controlled version: **one continent, the same 400 cells and
seed, storage the only variable**, and the workload mirrors a real job (route a
hop with `elevation_interval`, then sample heights) rather than `/height` alone.

| | anon | file | wall time |
|---|---|---|---|
| gzipped | **+1260 MB** | +1186 MB | 14.7 s |
| raw | **+23 MB** | +702 MB | **5.7 s** |

The mechanism is in the code, not a guess:

- A raw `.hgt` is **`mmap`ed** (`mem_map<char>`) and read in place. Its pages
  are file-backed, so the kernel reclaims them under pressure; running out means
  slower requests, never a kill.
- A gzipped tile is also `mmap`ed, but then **inflated with `malloc(HGT_BYTES)`**
  into a global `cache_t`. That is anonymous memory: it can be swapped, then
  OOM-killed, but never reclaimed.
- The unpacked cache is **capped at 50 tiles** (`UNPACKED_TILES_COUNT = 50`,
  hard-coded, not configurable). 50 × 25.9 MB ≈ 1.3 GB, which is exactly the
  +1260 MB measured. So the cost is **bounded**, not runaway. An earlier draft
  of this page said skadi showed no sign of evicting; that was wrong.
- Eviction is **pseudo-random**: when the cap is hit it drops the first tile in
  an `unordered_set` whose usage count is zero, and usage counts drop to zero
  between every edge. A tile needed for the very next point is as likely to be
  evicted as any other, so a busy instance **re-inflates the same tiles over and
  over**. That thrash, plus one global `recursive_mutex` around it, is the
  2.6× slowdown.

Upstream knows.
[valhalla/valhalla#6163](https://github.com/valhalla/valhalla/issues/6163)
("Proposal: Optimize compressed HGT file support", opened 1 July 2026, open at
the time of writing) describes precisely this, the maintainer's own words for
the current design are *"the caching mechanism is pretty dumb"*, and it proposes
a per-worker LRU. Until that lands, the behaviour above is what you get.

!!! danger "Store raw unless the instance has a small memory cap AND you accept the slowdown"

    Gzip costs a fixed ~1.3 GB of unreclaimable memory per instance, plus a
    2-3× latency penalty from cache thrash, in exchange for ~70% less disk.

    On an instance with a 4 GB limit that 1.3 GB is a third of everything it
    has. On one with 16 GB it is noise. So the question is not "gzip or not"
    but "what is this instance's cap, and does it serve latency-sensitive
    traffic". Keep anything busy raw. `.lz4` inflates into the same cache and
    changes nothing structural.

One trap, and it is a nasty one: **a Valhalla with no elevation tiles loaded does
not fail.** It returns `0` for every point, a perfectly valid-looking sea-level
profile. Any client must require some minimum share of non-zero samples before
believing a result. A silent zero is worse than an error, because nothing
downstream can detect it.

## The pipeline is not only ours

Everything above is written as though Cycling Commons were the only reader of
that `elevation_data` directory. It is not, and on 2026-08-27 that assumption
was measured and found to be costing another application most of its data.

The Valhalla host serves several applications. Cycling Commons asks it for climb
profiles. **A second application** asks it for route planning, for the climb
metres on every recorded ride, and for a per-region energy figure. They share one DEM directory
per continent, and only one of them has ever put anything in it.

### How to tell whose requirement was used

Compare the installed coverage against the presets in `fetch-glo30.sh`:

| continent | installed box | presets that explain it |
|---|---|---|
| europe | 35-72 / -11-32 | `EUROPE` |
| asia | 24-46 / 122-146 | `JAPAN` |
| africa | -35-0 / 16-33 | `SOUTHAFRICA` ∪ `RWANDA` |
| south-america | -56-13 / -82--66 | `COLOMBIA` ∪ `CHILE` |
| oceania | -48--9 / 112-179 | `AUSTRALIA` ∪ `NEWZEALAND` |
| north-america | 32-63 / -140--56 | `USWEST` ∪ `USROCKY` ∪ `CANADAWEST` ∪ `CANADAEAST` |

Six continents, six exact matches. Not "roughly ours" but **precisely** the union
of our seventeen presets, to the degree. That is the fingerprint of a pipeline
that has only ever had one requester.

That application meanwhile covers 175 countries, whose areas touch
**16,619** one-degree cells. Installed: 3,897, of which 3,439 are in cells it
cares about. So 32 of its 175 countries have elevation and 143 do not. In those
143, `/height` answers `0`, and, per the trap two sections above, nothing
errors. Rides there have been recording zero climb.

!!! warning "The lesson, which is not about elevation"

    A shared resource provisioned from one consumer's list looks completely
    healthy from that consumer's side. Cycling Commons' climbs were correct the
    whole time. The gap was invisible from here precisely *because* our own
    requirement was fully met.

    If you own a pipeline that more than one thing reads, the input is the
    **union of every consumer's requirement**, and each consumer has to be able
    to state its own, mechanically, rather than by someone remembering.

### Consumers declare, this pipeline acts

The split that fixes it:

- **This repository owns the action.** Fetch, convert, validate, install. One
  pipeline, one validator, one write-up. Nobody else should carry a copy of
  `fetch-glo30.sh`, a second copy drifts, and the copy without
  `compare-sources.js` is the one that will be trusted by accident.
- **Every consumer owns its requirement**, and must be able to print it. Cycling
  Commons declares through the presets in `fetch-glo30.sh`. The other consumer
  declares through a console command that reads its own coverage tables:

    <!-- CODE-ILLUSTRATIVE how a consumer prints its own requirement -->
    ```bash
    # what that application needs, against what is installed
    bin/console <its-coverage-command> --installed=/tmp/dem_cells.txt

    # just the missing cell names, ready to feed a fetch
    bin/console <its-coverage-command> --installed=/tmp/dem_cells.txt --missing-only
    ```

- **What gets installed is the union.** Today the presets are the whole input.
  That is the bug, and it is a modelling bug rather than a coding one.

## Onboarding a region, end to end

Here is the whole thing, with the reasoning attached to each step rather than
collected at the bottom.

### 1. Decide the box, and be mean about it

<!-- CODE-ILLUSTRATIVE step 1, choosing the box -->
```bash
./fetch-glo30.sh SLOVENIA ./data/dem/glo30
```

A one-degree cell costs **24.7 MB** as `.hgt` whether it holds the Alps or open
Atlantic, because the format is a fixed 3601 × 3601 grid of 16-bit integers with
no compression and no concept of "empty". That is why the presets are the
onboarded ground rather than the continent: `AUSTRALIA` stops at 44°S, `EUROPE`
stops at 32°E short of the Urals. Widen a box when a country is onboarded, not
in anticipation.

Cells over open sea simply 404 and are skipped, so a slightly generous bbox
costs nothing but a few wasted requests.

### 2. Convert, in a container

<!-- CODE-ILLUSTRATIVE step 2, converting to the format Valhalla reads -->
```bash
./to-hgt.sh ./data/dem/glo30 ./data/dem/hgt
```

GLO-30 ships as Cloud-Optimised GeoTIFF; Valhalla reads SRTMHGT. The conversion
runs inside `ghcr.io/osgeo/gdal` on purpose, the routing host deliberately has
no GDAL, because it is a routing box and not a GIS box, and a tool installed for
one job in 2026 is a dependency nobody can safely remove in 2028.

### 3. Prove it before anyone believes it

<!-- CODE-ILLUSTRATIVE step 3, proving the source -->
```bash
node compare-sources.js <reference_hgt_dir> ./data/dem/hgt
```

This is the step that separates this pipeline from a shell one-liner, and the
reason the whole thing lives here rather than being copied around. Skipping it
is how "2.0 km · 8.4%" got published in the first place.

### 4. Install and restart

<!-- CODE-ILLUSTRATIVE step 4, install and restart -->
```bash
./dem-install.sh europe SLOVENIA        # fetch + convert + install, resumable
docker restart valhalla-europe
```

The restart is unavoidable and it is a real outage for that continent, seconds
to a couple of minutes, but real. That is why this is an operator action and not
a hook on someone's import script.

### 5. Prove it again, from outside

<!-- CODE-ILLUSTRATIVE step 5, proving it from outside -->
```bash
curl -s http://localhost:8002/height -H 'Content-Type: application/json' \
  -d '{"shape":[{"lat":46.05,"lon":14.51},{"lat":46.20,"lon":14.66}]}'
```

**Use more than one point, and require a non-zero.** A single sample that
returns `0` is indistinguishable from a correct sea-level reading, and a
Valhalla with no tiles returns `0` forever without complaint. Sample a spread
and demand that some of it is above sea level.

### 6. Tell the other consumers

New elevation does not backfill itself. Anything already computed against the
old (or absent) DEM keeps its old numbers until it is recomputed:

<!-- CODE-ILLUSTRATIVE step 6, telling the other consumers -->
```bash
# here: the command is all-or-one, --write persists, --id narrows to a single
# climb. There is no --country, so a country-scoped pass is a loop over the
# ids you just onboarded.
bin/console app:climbs:recompute --write

# in every other consumer: anything of theirs that reads /height
bin/console <their-recompute-command>
```

A well-behaved consumer refuses to report success when more than 95% of a
country's results come back with zero climb, the same silent-zero trap, caught
one layer further out.

### 7. Note what a DEM install does *not* fix

This is the distinction from *Installing, and a distinction worth money*, and it
is worth restating with the consequence attached:

| | reads | fixed by installing a DEM? |
|---|---|---|
| climb profiles, ride climb metres, energy priors | `/height`, at request time | **yes**, immediately after restart |
| `use_hills` preferring flatter roads | `weighted_grade`, baked into the tiles | **no**, only a tile rebuild |

So a newly onboarded region measures correctly the moment the DEM lands. What it
does not get is a router that knows to avoid its hills, and that waits for the
next rebuild of that continent.

!!! danger "A rebuild is not free, and right now it is a downgrade"

    The routing tiles currently carry grades **everywhere**, because the
    2026-03-29 build ran with `build_elevation=True` and Valhalla downloaded its
    own SRTM-derived set for the whole graph.

    A rebuild bakes only what is in `elevation_data` at that moment. Any cell
    without a GLO-30 tile comes back **flat**, a downgrade from the SRTM grades
    it has today, and an invisible one.

    So a continent must reach full coverage of everything anyone routes on
    *before* it is rebuilt, not after. Across the 175 countries the other
    consumer covers, that footprint is 16,619 one-degree cells: 401 GB raw, at
    the same 24.7 MB a tile. Store it raw; see "Gzip works, and costs more than
    it saves" above; compression trades that disk for unreclaimable memory.

## Automating it, and the one check that must not be skipped

A whole-planet pass is six continents of fetch, convert, install, build, verify,
compress. Every step is a single command, and none of them is where the time
goes. The time goes into the gaps: during one rebuild the machine sat idle for
hours on two separate occasions, each time because a stage finished and nothing
picked up the next one. The tooling was never the bottleneck. Waiting for a
person to notice was.

So the work is wrapped in a runner. The loop is simple; what the loop is
allowed to believe is not.

### An exit code is not evidence

<!-- CODE-ILLUSTRATIVE the shape of the check, not a source file -->
```
build the tiles          -> exit 0
route a known climb      -> how many distinct grades came back?
                            what was the steepest?
```

A tile build that cannot read its elevation **does not fail**. It writes a flat
`weighted_grade` onto every edge, exits 0, and serves happily forever. `/status`
is byte-identical either way. That is precisely the failure mode this whole page
exists to prevent, and it is invisible to any check that trusts a return value.

So an unattended runner must probe the *output*: route a road that genuinely
climbs, and require a spread of grades, say five or more distinct values with a
maximum above 3%. Anything flatter means the DEM was not read.

And it must **stop the run**, not warn and continue. Halting after one bad
continent is recoverable. Quietly building six is five more rebuilds.

### Distinguish "inconclusive" from "failed"

Two things can go wrong with that probe, and conflating them is dangerous:

- **The route will not snap**: bad coordinates, a gap in the road data. That is
  inconclusive. Pass, and say so.
- **Nothing answers the port**: the container never came up. That is a
  failure.

An early version of the check treated both as inconclusive, so a service that
had died would have sailed straight through the gate reporting success. Pick
probe points on well-mapped roads, and treat silence from the port as a hard
stop.

### One build at a time

If the containers are governed by a single unit or compose project, two
concurrent builds will stop each other's service mid-run and both will look
broken in confusing ways. The runner should wait for any in-flight build before
starting, which also means it can be launched *while* one is already going,
and that is the normal case, because that is exactly when someone thinks of it.

!!! tip "The generalisable bit"

    Automating a pipeline is mostly not about the steps. It is about deciding
    what the automation is permitted to accept as proof that a step worked. If
    the failure mode of your slowest step is *silent and plausible*, the check
    after it has to look at the artefact, not the exit status, and it has to be
    willing to stop the line.

## Try it

!!! tip "Hands-on: prove a source against itself, then prove the install from outside"
    The comparison tool is the one this page's whole evaluation section runs on,
    and the cheapest way to learn to read it is to hand it a case whose answer
    you already know. Point it at the same directory twice:

    <!-- CODE-ILLUSTRATIVE the validation tool, given a case with a known answer -->
    ```bash
    node tools/elevation/compare-sources.js "$DEM_DIR" "$DEM_DIR"
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM any-install; sample output whenever both arguments name the same tiles -->
    ```text
    max |delta|      0 m
    mean |delta|     0 m
    disagreeing cells 0
    ```

    Every column zero, because a source cannot disagree with itself. That is the
    shape of a passing run, and now you can recognise a real one: the evaluation
    above is the same output with numbers in it, and the section on the
    downhill-bin trap is how to tell which of those numbers are terrain and
    which are artifacts.

    Then the check that actually matters, from outside the machine that holds
    the tiles. Ask the routing engine for a summit whose height you know:

    <!-- CODE-ILLUSTRATIVE ask the installed instance for one known summit; Signal de Botrange is about 694 m -->
    ```bash
    curl -s -X POST "$ELEVATION_URL/height" -H 'Content-Type: application/json' \
      -d '{"range":false,"shape":[{"lat":50.5010,"lon":6.0940}]}'
    ```

    A plausible height means the tiles are installed, the instance was restarted,
    and the box is named in `ELEVATION_URLS`. **A `0` means one of those three is
    missing**, and nothing anywhere will tell you which, because a missing tile
    is not an error to this endpoint: it is the number zero. That is the failure
    this page returns to in every section, and the reason the check names a
    summit rather than a random point.
