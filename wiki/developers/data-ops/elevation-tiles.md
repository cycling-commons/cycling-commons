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

<figure class="gis-fig"><svg viewBox="0 0 680 476" role="img" aria-labelledby="dem1-t dem1-d" xmlns="http://www.w3.org/2000/svg"><title id="dem1-t">The same climb measured by a reference source and by a 90 metre elevation model</title><desc id="dem1-d">Two elevation profiles of the Cote de la Redoute drawn from real measurements, in one hundred metre bins. The accent line is the reference profile from climbfinder, rising continuously from foot to summit. The stepped line is what Copernicus GLO-90, a ninety metre model, returns for the identical road: it follows the general shape but in flat jumps, and in two bins it goes down instead of up. Those two descending bins are tinted, and the larger reports minus ten percent through the middle of a climb that never descends, bracketed by a twenty-five percent bin and a zero percent bin. The model is not slightly noisy here; it is reporting terrain that is not there.</desc>
<line class="gis-muted" stroke-dasharray="2 5" x1="56.0" y1="116" x2="56.0" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="87.8" y1="116" x2="87.8" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="119.6" y1="116" x2="119.6" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="151.4" y1="116" x2="151.4" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="183.2" y1="116" x2="183.2" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="214.9" y1="116" x2="214.9" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="246.7" y1="116" x2="246.7" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="278.5" y1="116" x2="278.5" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="310.3" y1="116" x2="310.3" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="342.1" y1="116" x2="342.1" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="373.9" y1="116" x2="373.9" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="405.7" y1="116" x2="405.7" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="437.5" y1="116" x2="437.5" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="469.3" y1="116" x2="469.3" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="501.1" y1="116" x2="501.1" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="532.8" y1="116" x2="532.8" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="564.6" y1="116" x2="564.6" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="596.4" y1="116" x2="596.4" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="628.2" y1="116" x2="628.2" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="660.0" y1="116" x2="660.0" y2="312"/><rect class="gis-fill-clay" fill-opacity=".45" x="246.7" y="241.8" width="31.8" height="11.1"/><rect class="gis-fill-clay" fill-opacity=".45" x="628.2" y="116.0" width="31.8" height="4.0"/><polyline class="gis-muted" fill="none" points="56.0,312.0 87.8,312.0 87.8,305.3 119.6,305.3 119.6,296.4 151.4,296.4 151.4,283.0 183.2,283.0 183.2,269.7 214.9,269.7 214.9,241.8 246.7,241.8 246.7,241.8 278.5,241.8 278.5,253.0 310.3,253.0 310.3,235.2 342.1,235.2 342.1,221.8 373.9,221.8 373.9,214.0 405.7,214.0 405.7,201.8 437.5,201.8 437.5,182.8 469.3,182.8 469.3,173.9 501.1,173.9 501.1,160.5 532.8,160.5 532.8,141.6 564.6,141.6 564.6,132.7 596.4,132.7 596.4,124.9 628.2,124.9 628.2,116.0 660.0,116.0 660.0,118.2"/><polyline class="gis-accent" fill="none" points="56.0,312.0 87.8,305.3 119.6,297.5 151.4,286.4 183.2,279.7 214.9,270.8 246.7,261.9 278.5,254.1 310.3,244.1 342.1,232.9 373.9,222.9 405.7,208.4 437.5,190.6 469.3,179.5 501.1,169.5 532.8,155.0 564.6,143.8 596.4,139.4 628.2,132.7 660.0,126.0"/>
<text class="gis-label-sm" x="56" y="40">Same road, two measurements</text>
<line class="gis-clay" x1="262.6" y1="235.8" x2="262.6" y2="94"/>
<text class="gis-label-sm gis-halo" x="262.6" y="88" text-anchor="middle">−10%</text>
<text class="gis-label-sm gis-halo" x="56" y="340">foot</text>
<text class="gis-label-sm gis-halo" x="660" y="340" text-anchor="end">summit</text>
<rect class="gis-ink gis-fill-accent" x="56" y="352" width="20" height="20"/><text class="gis-label-sm" x="86" y="368">the road</text><rect class="gis-ink gis-fill-glacier" x="56" y="382" width="20" height="20"/><text class="gis-label-sm" x="86" y="398">what GLO-90 returns</text><rect class="gis-ink gis-fill-clay" x="56" y="412" width="20" height="20"/><text class="gis-label-sm" x="86" y="428">bins it reports as DOWNHILL</text></svg><figcaption>Both lines measure the same road. The accent line is the reference profile; the stepped line is Copernicus GLO-90, which stores one elevation per 90&nbsp;m cell and so answers in flat jumps. Follow it through the middle: <strong>25%, then 0%, then −10%</strong> — a ten-percent <em>descent</em> on a climb that never descends, with a wall on one side and a flat on the other. None of that is on the road. This is why a bin is never narrower than about four DEM cells: ask a grid a question finer than its cells and it answers with its own shape.</figcaption></figure>

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

<figure class="gis-fig"><svg viewBox="0 0 700 468" role="img" aria-labelledby="dem2-t dem2-d" xmlns="http://www.w3.org/2000/svg"><title id="dem2-t">Why a Copernicus tile is 2400 columns wide instead of 3600</title><desc id="dem2-d">Two grids compared at fifty degrees north. On the left, a grid sampled one arc-second in both directions: because a degree of longitude is worth only about two thirds of a degree of latitude here, its ground cells come out as tall narrow rectangles, roughly twenty metres wide by thirty-one metres high. On the right, the same ground sampled one and a half arc-seconds in longitude and one in latitude: fewer columns, but cells close to square at about thirty metres each way. That is what Copernicus ships, and it is why a one degree tile at this latitude measures 2400 columns by 3600 rows. The SRTM hgt format is one arc-second in both directions by definition, so converting upsamples longitude back to 3600 columns, adding no information and destroying none.</desc>
<text class="gis-label-sm" x="40" y="56">At 50° N, 1″ is not the same both ways</text>
<text class="gis-label-sm gis-halo" x="40" y="130">1″ × 1″</text><text class="gis-label-sm gis-halo" x="40" y="160">square in ANGLE</text><rect class="gis-muted" fill="none" x="40" y="182" width="26" height="34"/><rect class="gis-muted" fill="none" x="40" y="216" width="26" height="34"/><rect class="gis-muted" fill="none" x="40" y="250" width="26" height="34"/><rect class="gis-muted" fill="none" x="40" y="284" width="26" height="34"/><rect class="gis-muted" fill="none" x="66" y="182" width="26" height="34"/><rect class="gis-muted" fill="none" x="66" y="216" width="26" height="34"/><rect class="gis-muted" fill="none" x="66" y="250" width="26" height="34"/><rect class="gis-muted" fill="none" x="66" y="284" width="26" height="34"/><rect class="gis-muted" fill="none" x="92" y="182" width="26" height="34"/><rect class="gis-muted" fill="none" x="92" y="216" width="26" height="34"/><rect class="gis-muted" fill="none" x="92" y="250" width="26" height="34"/><rect class="gis-muted" fill="none" x="92" y="284" width="26" height="34"/><rect class="gis-muted" fill="none" x="118" y="182" width="26" height="34"/><rect class="gis-muted" fill="none" x="118" y="216" width="26" height="34"/><rect class="gis-muted" fill="none" x="118" y="250" width="26" height="34"/><rect class="gis-muted" fill="none" x="118" y="284" width="26" height="34"/><rect class="gis-muted" fill="none" x="144" y="182" width="26" height="34"/><rect class="gis-muted" fill="none" x="144" y="216" width="26" height="34"/><rect class="gis-muted" fill="none" x="144" y="250" width="26" height="34"/><rect class="gis-muted" fill="none" x="144" y="284" width="26" height="34"/><rect class="gis-muted" fill="none" x="170" y="182" width="26" height="34"/><rect class="gis-muted" fill="none" x="170" y="216" width="26" height="34"/><rect class="gis-muted" fill="none" x="170" y="250" width="26" height="34"/><rect class="gis-muted" fill="none" x="170" y="284" width="26" height="34"/>
<text class="gis-label-sm gis-halo" x="40" y="352">≈20 m × ≈31 m</text>
<text class="gis-label-sm gis-halo" x="40" y="382">oversampled E–W</text>
<text class="gis-label-sm gis-halo" x="348" y="130">1.5″ × 1″</text><text class="gis-label-sm gis-halo" x="348" y="160">square on GROUND</text><rect class="gis-accent" fill="none" x="348" y="182" width="39" height="34"/><rect class="gis-accent" fill="none" x="348" y="216" width="39" height="34"/><rect class="gis-accent" fill="none" x="348" y="250" width="39" height="34"/><rect class="gis-accent" fill="none" x="348" y="284" width="39" height="34"/><rect class="gis-accent" fill="none" x="387" y="182" width="39" height="34"/><rect class="gis-accent" fill="none" x="387" y="216" width="39" height="34"/><rect class="gis-accent" fill="none" x="387" y="250" width="39" height="34"/><rect class="gis-accent" fill="none" x="387" y="284" width="39" height="34"/><rect class="gis-accent" fill="none" x="426" y="182" width="39" height="34"/><rect class="gis-accent" fill="none" x="426" y="216" width="39" height="34"/><rect class="gis-accent" fill="none" x="426" y="250" width="39" height="34"/><rect class="gis-accent" fill="none" x="426" y="284" width="39" height="34"/><rect class="gis-accent" fill="none" x="465" y="182" width="39" height="34"/><rect class="gis-accent" fill="none" x="465" y="216" width="39" height="34"/><rect class="gis-accent" fill="none" x="465" y="250" width="39" height="34"/><rect class="gis-accent" fill="none" x="465" y="284" width="39" height="34"/>
<text class="gis-label-sm gis-halo" x="348" y="352">≈30 m × ≈30 m</text>
<text class="gis-label-sm gis-halo" x="348" y="382">Copernicus: 2400 × 3600</text>
<text class="gis-label-sm" x="40" y="432">Fewer columns is not less detail.</text>
</svg><figcaption>Meridians converge toward the poles, so at 50°&nbsp;N one arc-second of longitude buys about 20&nbsp;m of ground while one of latitude buys about 31&nbsp;m. Sampling both axes at 1″ therefore gives tall, thin cells — <em>oversampled</em> east–west. Copernicus samples longitude at 1.5″ instead, keeping the ground cell roughly square, which is why the tile covering La&nbsp;Redoute measures <code>2400 × 3600</code> rather than <code>3601 × 3601</code>. The <code>.hgt</code> format is 1″ both ways by definition, so conversion upsamples longitude back to 3600 columns: no information added, none destroyed. Two consequences — the decimation factor <strong>changes with latitude band</strong>, so a converter that hard-codes 2400 is right in Belgium and wrong in Norway; and every <code>.hgt</code> tile is 24.7&nbsp;MB whatever it really carries.</figcaption></figure>

This is not a defect you fix by choosing a different version, because the
alternatives are DSMs too. It is a permanent error term, and it is the most
likely explanation when two good sources disagree locally while agreeing on the
total — exactly the pattern measured here: 2.02 points of per-bin disagreement
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

<figure class="gis-fig"><svg viewBox="0 0 660 408" role="img" aria-labelledby="dem3-t dem3-d" xmlns="http://www.w3.org/2000/svg"><title id="dem3-t">A surface model follows the treetops, not the road beneath them</title><desc id="dem3-d">A cross-section of a climb running through woodland. The accent line along the bottom is the road, rising gently and steadily. A stand of trees sits over the middle section. The muted dashed line above is what a digital surface model records: it tracks the road where the road is open, jumps up to follow the canopy through the wooded stretch, then drops back when the trees end. Through that section the elevation it reports is the height of the treetops, tens of metres above the tarmac a rider is actually on. Both EU-DEM and Copernicus DEM are surface models, so this error cannot be removed by choosing between them.</desc>
<path class="gis-muted" fill="none" stroke-dasharray="5 4" d="M 40 250 L 130 244 L 150 164 L 200 150 L 250 166 L 300 154 L 350 146 L 400 236 L 520 220 L 620 206"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 150 250 L 164 188 L 178 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 153 222 L 164 172 L 175 222 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 186 250 L 200 176 L 214 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 189 217 L 200 158 L 211 217 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 222 250 L 236 192 L 250 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 225 224 L 236 178 L 247 224 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 258 250 L 272 180 L 286 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 261 218 L 272 162 L 283 218 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 300 250 L 314 184 L 328 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 303 220 L 314 168 L 325 220 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 336 250 L 350 172 L 364 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 339 215 L 350 152 L 361 215 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 372 250 L 386 190 L 400 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 375 223 L 386 175 L 397 223 Z"/><path class="gis-accent" fill="none" d="M 40 250 L 130 244 L 150 242 L 400 236 L 520 220 L 620 206"/>
<text class="gis-label-sm" x="40" y="46">What the model measures, not what you ride</text>
<text class="gis-label-sm gis-halo" x="196" y="126">model reads HERE</text>
<text class="gis-label-sm gis-halo" x="196" y="284">you ride HERE</text>
<rect class="gis-ink gis-fill-accent" x="40" y="314" width="20" height="20"/><text class="gis-label-sm" x="70" y="330">the road — a DTM follows this</text><rect class="gis-ink gis-fill-glacier" x="40" y="344" width="20" height="20"/><text class="gis-label-sm" x="70" y="360">what a DSM records</text></svg><figcaption>A <strong>DSM</strong> (digital surface model) records "the first surface as illuminated by the sensors" — EU-DEM's own words. Through woodland that surface is the canopy, not the tarmac, so readings over a tree-lined stretch are partly the trees. A <strong>DTM</strong> (terrain model) is the bare-earth counterpart and would follow the road, but none is available worldwide at useful resolution, and <em>both</em> EU-DEM and Copernicus DEM are surface models. So this is not a defect to fix by choosing a different dataset — it is a permanent error term, and the likeliest explanation whenever two good sources agree on a climb's total gain and disagree over one stretch of it.</figcaption></figure>

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
| per-bin disagreement | the honest spread between two sources that are both plausible |
| **bins reading downhill** | useful, but *only* with the caveat below |

### The downhill-bin trap

It is tempting to treat a bin that reads downhill as proof the source is wrong —
a climb that descends in its middle sounds impossible. **It is not.** Plenty of
real climbs go up, drop, and go up again.

Côte de la Roche-aux-Faucons is exactly that: it climbs to 228 m, descends to
185 m over more than a kilometre, then climbs again to 270 m. Our own 17 km
Hockai route reads downhill in 27 of its bins, and every one of them is a real
descent on a rail-trail that undulates. A rule of "any downhill bin condemns the
source" would have thrown out the correct answer in both cases.

What makes the measure useful is knowing **whether that stretch of road actually
descends**:

- On a climb known to rise monotonically — Mur de Huy, a 1.4 km wall with no
  descent anywhere in it — a downhill bin is unambiguously an artifact. That is
  what condemned GLO-90 on La Redoute: the road never descends, and GLO-90
  published a 10% drop through its middle.
- On any other climb, a downhill bin is a **question, not a verdict**.

The general way to answer that question without a surveyed reference is to
**cross-check two independent sources**. A descent both of them see is terrain.
A descent only one sees is an artifact. That test needs no prior knowledge of
the road, which is what makes it usable on climbs nobody has profiled.

The lesson generalises past elevation: a metric that is decisive on the example
you developed it against can be nonsense one climb over. The fix is not a better
threshold — it is knowing which question the number actually answers.

## What the evaluation concluded

EU-DEM v1 against Copernicus GLO-30, all seven seeded Wallonia climbs:

| | EU-DEM v1 | GLO-30 |
|---|---|---|
| La Redoute, gain | 179 m | 181 m *(reference: 180 m)* |
| mean per-bin disagreement | — | 2.02 points |
| downhill bins on Mur de Huy *(never descends)* | 1 | **0** |

The gain match against an independent reference is the load-bearing result. The
Mur de Huy row is the narrow version of the downhill test — that climb genuinely
rises the whole way, so EU-DEM's bin there is an artifact and GLO-30's absence of
one is real. It is one bin on one climb, so it is a tiebreak, not the argument.

**The decision that followed: GLO-30 is the single source, worldwide.** No
chain, no per-coordinate resolution order, no regional fallback.

That is worth dwelling on, because the thing it removed was never a feature. The
earlier design ranked three sources — EU-DEM in Europe, SRTM, then GLO-90 as a
worldwide floor — and every mechanism that ranking demanded (a priority list, a
per-continent raster inventory, a provenance field that varies by where you are
standing) existed **only to work around a first choice that covered one
continent**. Choosing a source that covers the world deleted all of it at once.

GLO-90 really was too coarse. But GLO-90 is a 3× downsample of GLO-30, and that
verdict got applied to GLO-30 by association — which left the better dataset
untested for the entire design. **The cheapest thing on this page is measuring
the option you assumed was bad.**

Three things stop being problems rather than getting solved: EU-DEM's regulated
access terms, the per-region raster inventory, and the provenance field, which
becomes a constant.

The honest caveat, recorded rather than glossed: the evidence is one tile, one
massif, one latitude band. Benelux is the widening that confirms it, and the
storage arithmetic is why scope still matters — 24.7 MB per tile, ~1,500 tiles
for Europe (37 GB), 14,000–26,000 for global land (**340–630 GB**). Scope
rasters to onboarded countries, not the globe.

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
