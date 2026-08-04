<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Building elevation tiles

How a climb gets a gradient that is *measured* rather than typed: choosing a
digital elevation model, converting it into the format the routing engine reads,
and — the part that matters most — proving it is good enough before anyone
believes it.

Course 2's [Elevation and terrain](../gis-beyond/elevation.md) explains what a
**DEM (digital elevation model)** is and why two tools disagree about the same
ride. This page is the operational half: the pipeline, and the reasoning behind
each step. The design record is
[climb-elevation.md](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/climb-elevation.md);
the tool reference is
[`tools/elevation/README.md`](https://github.com/cycling-commons/cycling-commons/blob/main/tools/elevation/README.md).

## Why this exists: a number nobody measured

The Côte de la Redoute was published as **"2.0 km · 8.4% avg"**. That string was
typed into a JavaScript literal while the map was a static prototype and carried
into the seed command unchanged — in the same commit whose comment says to *omit
any attribute we cannot verify*.

When three independent sources were finally asked, they said ~9.0%. The
published figure was not a rounding difference or a measurement dispute. Nobody
had ever measured it.

That is the failure this pipeline exists to prevent, and it explains the order
of everything below: **build the measurement first, draw the chart last.** A
beautiful profile chart over unmeasured numbers is worse than no chart, because
it makes a fabrication look like a survey.

## A DEM does not know where the road is

The single most useful thing to understand: **a DEM never produces
coordinates.** It has no idea a road exists.

It is a lookup table. You hand it a latitude and longitude, it returns a height.
The trajectory — where the climb actually goes — comes from a routing engine,
and the two are completely independent:

<!-- CODE-ILLUSTRATIVE the shape of the pipeline, not a source file -->
```
rider taps foot + summit
        ↓  routing engine (this decides WHERE)
a polyline following the road
        ↓  resample to even spacing
points every 20 m
        ↓  DEM lookup (this decides HOW HIGH)
one elevation per point
        ↓
bins → the profile chart
```

That independence is useful: a profile can be recomputed against a better DEM
without re-routing, and a redrawn line gets a new profile without changing
sources.

It also means a DEM cannot rescue a bad line. If the route is wrong, better
elevation data produces a more precise wrong answer.

## Resolution is not accuracy

The obvious question is "how many metres per cell?", and the obvious answer
misleads.

**Copernicus GLO-90** has 90 m cells. Sampled every 25 m along La Redoute it
returned 30 distinct values across 99 samples, with runs of seven identical
readings and eight samples going *downhill* on a climb that never descends.
Binned at 100 m it published a **10% descent through the middle of the climb**.

That is not noise. It is asking a grid a question finer than its cells, and
getting the grid's shape back instead of the road's. The rule that falls out:

> **A bin is never narrower than about four DEM cells.**

At 90 m cells that means bins no narrower than 360 m — which is why GLO-90
cannot draw the 100 m bars we want, at any level of cleverness.

The corollary matters just as much: a *finer* raster does not automatically buy
a better answer. When EU-DEM v1 (1 arc-second) was compared against EU-DEM v1.1
(25 m), they agreed to **0.54 of a percentage point per bin** and both landed
within 1.2 m of the reference gain. The finer grid bought smoothness, not truth.

## The trees are in the data

**EU-DEM and Copernicus DEM are both Digital Surface Models.** EU-DEM's own
readme describes it as "the first surface as illuminated by the sensors".

That means canopy and buildings, **not bare ground**. On a wooded Ardennes
climb, a reading over a tree-lined stretch is partly the trees.

This is not a defect you fix by choosing a different version, because the
alternatives are DSMs too. It is a permanent error term, and it is the most
likely explanation when two good sources disagree locally while agreeing on the
total — exactly the pattern measured here: 1.96 points of per-bin disagreement
between EU-DEM and GLO-30, but gains within 2 m of each other.

A **DTM (digital terrain model)** is the bare-earth counterpart. Where one is
available at useful resolution it is the better input for road gradients.

## Two formats, and a lesson hiding in one of them

Copernicus ships **Cloud Optimized GeoTIFF**. Valhalla's elevation service
reads **`.hgt`**: a raw grid of big-endian `int16` metres, 3601×3601 per 1°
tile, at 1 arc-second in *both* axes. So a conversion is unavoidable.

Inspecting a GLO-30 tile is where it gets interesting:

<!-- CODE-ILLUSTRATIVE gdalinfo output, abridged -->
```
Size is 2400, 3600
Pixel Size = (0.000416666666667,-0.000277777777778)
```

**2400 columns, not 3600.** The tile is decimated in longitude — 1.5 arc-seconds
across, 1 arc-second down.

That is not a defect. Meridians converge toward the poles, so at 50°N one arc-
second of longitude is only about 20 m of ground while one of latitude is about
31 m. By sampling longitude at 1.5″, Copernicus keeps its cells roughly *square*
— about 30 m each way. It is the sensible choice, and `.hgt` cannot express it,
being 1″ by definition.

So the conversion **upsamples longitude onto the uniform grid**. It adds no
information and destroys none, which is why the converted data measures just as
well. But two things follow:

- **The decimation factor changes with latitude band.** A converter that
  hard-codes 2400 columns is correct in Belgium and wrong in Norway. Read each
  tile's actual size; let GDAL resample to the target grid.
- **`.hgt` tiles are a fixed 24.7 MB** (3601 × 3601 × 2 bytes) regardless of how
  much information they carry. Storage is a function of area, not detail.

## Reading a `.hgt` tile

Worth knowing, because it demystifies the format entirely. Tiles are named for
their **south-west corner** — `N50E005.hgt` covers 50–51°N, 5–6°E. Row 0 is the
north edge, column 0 the west edge.

The lookup is arithmetic:

<!-- CODE-FROM tools/elevation/compare-sources.js -->
```js
const r = (latSW + 1 - lat) * 3600, c = (lon - lonSW) * 3600;
const r0 = Math.floor(r), c0 = Math.floor(c), dr = r - r0, dc = c - c0;
```

The integer part of a coordinate picks the tile, the fractional part indexes the
grid. The service then **interpolates between the four surrounding cells** — it
does not snap to the nearest one.

You can see the interpolation from outside: walking 60 m in 2 m steps returns
values that change every ~14 m on a 6.7% slope. The run length tracks the
*gradient*, not the 30 m cell size, which snapping could never produce.

**But the reply is integer metres**, and that is a second, independent reason for
the four-cell bin rule. Rounding is ±0.5 m per reading no matter how good the
raster is. Over a 100 m bin at 9% — 9 m of rise — that is ±0.5 of a point,
tolerable. Over a 20 m bin it would be ±2.5 points, and the bar would be mostly
rounding error.

## The pipeline

<!-- CODE-ILLUSTRATIVE the three steps -->
```bash
./fetch-glo30.sh BENELUX ./data/dem/glo30          # download
./to-hgt.sh ./data/dem/glo30 ./data/dem/hgt        # convert
node compare-sources.js ./old/hgt ./data/dem/hgt   # PROVE IT
```

**Fetching needs no account.** GLO-30 Public is on the AWS Open Data registry —
plain HTTPS against a public bucket, no credentials, no AWS CLI. Registration
with the Copernicus Data Space Ecosystem unlocks the *restricted* instances,
which we do not need. A few countries are withheld from the public set, and
sea-only cells simply do not exist, so "missing tile" is normal and not an error.

**Converting cuts from a mosaic, not tile by tile.** `.hgt` tiles overlap their
neighbours by one row and column — the value at exactly 6°E belongs to both
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

The reader was first checked against the real service on a tile both could see —
179 m of gain and 8.62% against Valhalla's 179 m and 8.61%. Only then were its
numbers used to judge anything.

Three measures, each earning its place:

| measure | why it is there |
|---|---|
| gain and average gradient | the figures riders actually see; disagreement here is user-visible |
| **bins reading downhill** | a climb that descends in its middle is *impossible* — any count above zero condemns the source at that bin width |
| per-bin disagreement | the honest spread between two sources that are both plausible |

The middle one is the sharp instrument. It is not a matter of taste or
tolerance: it is a physical impossibility, so it converts "does this look right?"
into a question with a correct answer.

## What the evaluation concluded

EU-DEM v1 against Copernicus GLO-30, all seven seeded Wallonia climbs:

| | EU-DEM v1 | GLO-30 |
|---|---|---|
| La Redoute, gain | 179 m | 181 m *(reference: 180 m)* |
| bins reading downhill | 2 | **0** |
| mean per-bin disagreement | — | 1.96 points |

GLO-30 is at least as good, and slightly cleaner. Since it is **worldwide**, that
makes a single-source model viable — retiring a three-source chain, the
per-region raster management, and EU-DEM's regulated access terms in one step.

The honest caveat, recorded rather than glossed: this is one tile, one massif,
one latitude band. Benelux is the widening that would confirm it. Which is why
the storage arithmetic matters before committing — 24.7 MB per tile, ~1,500
tiles for Europe (37 GB), 14,000–26,000 for global land (**340–630 GB**). Scope
rasters to onboarded countries, not the globe.

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

One trap, and it is a nasty one: **a Valhalla with no elevation tiles loaded does
not fail.** It returns `0` for every point — a perfectly valid-looking sea-level
profile. Any client must require some minimum share of non-zero samples before
believing a result. A silent zero is worse than an error, because nothing
downstream can detect it.

## Faults no elevation source can fix

While measuring, the comparison tool flagged problems in the *geometry*:

- **Côte de la Redoute** runs 361 m past its summit, and those metres descend.
  Averaged over the stored line the climb is 6.80%; trimmed at the top it is
  8.61%. **Overshooting the summit understates a climb by 1.8 points** — several
  times the gap between the DEM sources we agonised over.
- **Côte de la Roche-aux-Faucons** is stored **backwards**, starting at 242 m and
  ending at 181 m.

Three of seven stored climbs had an endpoint defect. The reversed one is the
dangerous case: "measure to the highest point" puts the summit at index 0, giving
a length of 0 m, a gain of 0 m and 0% — numbers that look unremarkable in a
database column.

The lesson generalises well beyond elevation. **Getting the source right and the
endpoints wrong still publishes a wrong number**, and a validation that fails to
zero rather than to an error is worse than no validation.

## Try it

1. Fetch one tile over a climb you know:
   `./fetch-glo30.sh 50,5,51,6 ./data/dem/glo30`
2. Run `gdalinfo` on it. Find the row count and the column count, and work out
   the latitude band's decimation factor from the ratio.
3. Convert it, then run `compare-sources.js` against it twice — the same
   directory as both arguments. Every number should be zero. If a comparison
   tool cannot report "identical", its other numbers are not trustworthy either.
4. Look up the elevation at a point you can verify — a summit with a signpost,
   or a spot height on a paper map. Note whether the DEM reads high, and whether
   you are standing under trees.
