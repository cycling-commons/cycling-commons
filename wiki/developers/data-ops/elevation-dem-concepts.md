<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Elevation: what a DEM is, and what it gets wrong

Before any pipeline, the ground truth: what a digital elevation model actually
holds, why a finer grid is not a more accurate one, and why the trees are in the
data. Nothing here is operational. It is the reasoning every decision on the
next two pages rests on, and it is the half worth reading even if you never run
a build.

!!! info "What this project runs today"

    **Copernicus GLO-30 is the source**, worldwide: 30 m cells, served from
    Valhalla's `/height`, so the gradients a rider sees come from it.

    The **GLO-90** measurements below are of a coarser product this project does
    not serve. They are kept because they are the clearest lesson available in
    why resolution is not accuracy, and because the failure was measured on our
    own climbs rather than borrowed from a textbook.

Course 2's [Elevation and terrain](../gis-beyond/elevation.md) covers the same
subject for a reader who wants the concept and not the operation. This page goes
one level deeper, because an operator has to know exactly how the data lies to
them. The design record is
[climb-elevation.md](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/climb-elevation.md);
the tool reference is
[`tools/elevation/README.md`](https://github.com/cycling-commons/cycling-commons/blob/main/tools/elevation/README.md).

Three pages cover elevation, in order:

1. **This one**: what a DEM is, resolution against accuracy, the trees, the file
   format.
2. [Building elevation tiles](elevation-tiles.md): the pipeline, proving a
   source, installing it, and onboarding a region end to end.
3. [Measuring a climb](measuring-a-climb.md): turning installed tiles into a
   published gradient, and the faults no source can fix.

## Why this exists: a number nobody measured

The Côte de la Redoute was published as **"2.0 km · 8.4% avg"**. That string was
typed into a JavaScript literal while the map was a static prototype and carried
into the seed command unchanged, in the same commit whose comment says to *omit
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
The trajectory, where the climb actually goes, comes from a routing engine,
and the two are completely independent:

<!-- CODE-ILLUSTRATIVE the shape of the pipeline, not a source file -->
```
rider taps foot + summit
        ↓  routing engine (this decides WHERE)
a polyline following the road
        ↓  resample to a fixed count
200 points, evenly spaced along the line
        ↓  DEM lookup (this decides HOW HIGH)
one elevation per point
        ↓
bins → the profile chart
```

The count is fixed, not the spacing (`ClimbProfiler::SAMPLES`), so a longer
climb is sampled more coarsely: 200 points over 26 km is one every 130 m. That
detail looks like an obvious suspect later in this page, and it turns out to be
innocent.

That independence is useful: a profile can be recomputed against a better DEM
without re-routing, and a redrawn line gets a new profile without changing
sources.

It also means a DEM cannot rescue a bad line. If the route is wrong, better
elevation data produces a more precise wrong answer.

## Resolution is not accuracy

The obvious question is "how many metres per cell?", and the obvious answer
misleads.

**Copernicus GLO-90**, the 90 m product, is the demonstration. Sampled every
25 m along La Redoute it returned 30 distinct values across 99 samples, with runs
of seven identical readings and eight samples going *downhill* on a climb that never descends.
Binned at 100 m it published a **10% descent through the middle of the climb**.

<figure class="gis-fig"><svg viewBox="0 0 680 476" role="img" aria-labelledby="dem1-t dem1-d" xmlns="http://www.w3.org/2000/svg"><title id="dem1-t">The same climb measured by a reference source and by a 90 metre elevation model</title><desc id="dem1-d">Two elevation profiles of the Cote de la Redoute drawn from real measurements, in one hundred metre bins. The accent line is the reference profile from another source, rising continuously from foot to summit. The stepped line is what Copernicus GLO-90, a ninety metre model, returns for the identical road: it follows the general shape but in flat jumps, and in two bins it goes down instead of up. Those two descending bins are tinted, and the larger reports minus ten percent through the middle of a climb that never descends, bracketed by a twenty-five percent bin and a zero percent bin. The model is not slightly noisy here; it is reporting terrain that is not there.</desc>
<line class="gis-muted" stroke-dasharray="2 5" x1="56.0" y1="116" x2="56.0" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="87.8" y1="116" x2="87.8" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="119.6" y1="116" x2="119.6" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="151.4" y1="116" x2="151.4" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="183.2" y1="116" x2="183.2" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="214.9" y1="116" x2="214.9" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="246.7" y1="116" x2="246.7" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="278.5" y1="116" x2="278.5" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="310.3" y1="116" x2="310.3" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="342.1" y1="116" x2="342.1" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="373.9" y1="116" x2="373.9" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="405.7" y1="116" x2="405.7" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="437.5" y1="116" x2="437.5" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="469.3" y1="116" x2="469.3" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="501.1" y1="116" x2="501.1" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="532.8" y1="116" x2="532.8" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="564.6" y1="116" x2="564.6" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="596.4" y1="116" x2="596.4" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="628.2" y1="116" x2="628.2" y2="312"/><line class="gis-muted" stroke-dasharray="2 5" x1="660.0" y1="116" x2="660.0" y2="312"/><rect class="gis-fill-clay" fill-opacity=".45" x="246.7" y="241.8" width="31.8" height="11.1"/><rect class="gis-fill-clay" fill-opacity=".45" x="628.2" y="116.0" width="31.8" height="4.0"/><polyline class="gis-muted" fill="none" points="56.0,312.0 87.8,312.0 87.8,305.3 119.6,305.3 119.6,296.4 151.4,296.4 151.4,283.0 183.2,283.0 183.2,269.7 214.9,269.7 214.9,241.8 246.7,241.8 246.7,241.8 278.5,241.8 278.5,253.0 310.3,253.0 310.3,235.2 342.1,235.2 342.1,221.8 373.9,221.8 373.9,214.0 405.7,214.0 405.7,201.8 437.5,201.8 437.5,182.8 469.3,182.8 469.3,173.9 501.1,173.9 501.1,160.5 532.8,160.5 532.8,141.6 564.6,141.6 564.6,132.7 596.4,132.7 596.4,124.9 628.2,124.9 628.2,116.0 660.0,116.0 660.0,118.2"/><polyline class="gis-accent" fill="none" points="56.0,312.0 87.8,305.3 119.6,297.5 151.4,286.4 183.2,279.7 214.9,270.8 246.7,261.9 278.5,254.1 310.3,244.1 342.1,232.9 373.9,222.9 405.7,208.4 437.5,190.6 469.3,179.5 501.1,169.5 532.8,155.0 564.6,143.8 596.4,139.4 628.2,132.7 660.0,126.0"/>
<text class="gis-label-sm" x="56" y="40">Same road, two measurements</text>
<line class="gis-clay" x1="262.6" y1="235.8" x2="262.6" y2="94"/>
<text class="gis-label-sm gis-halo" x="262.6" y="88" text-anchor="middle">−10%</text>
<text class="gis-label-sm gis-halo" x="56" y="340">foot</text>
<text class="gis-label-sm gis-halo" x="660" y="340" text-anchor="end">summit</text>
<rect class="gis-ink gis-fill-accent" x="56" y="352" width="20" height="20"/><text class="gis-label-sm" x="86" y="368">the road</text><rect class="gis-ink gis-fill-glacier" x="56" y="382" width="20" height="20"/><text class="gis-label-sm" x="86" y="398">what GLO-90 returns</text><rect class="gis-ink gis-fill-clay" x="56" y="412" width="20" height="20"/><text class="gis-label-sm" x="86" y="428">bins it reports as DOWNHILL</text></svg><figcaption>Both lines measure the same road. The accent line is the reference profile; the stepped line is Copernicus GLO-90, which stores one elevation per 90&nbsp;m cell and so answers in flat jumps. Follow it through the middle: <strong>25%, then 0%, then −10%</strong>, a ten-percent <em>descent</em> on a climb that never descends, with a wall on one side and a flat on the other. None of that is on the road. This is why a bin is never narrower than about four DEM cells: ask a grid a question finer than its cells and it answers with its own shape.</figcaption></figure>

That is not noise. It is asking a grid a question finer than its cells, and
getting the grid's shape back instead of the road's. The rule that falls out:

> **A bin is never narrower than about four DEM cells.**

At 90 m cells that means bins no narrower than 360 m, which is why GLO-90
cannot draw the 100 m bars we want, at any level of cleverness. **GLO-30's 30 m
cells clear the same rule at 120 m**, which is what makes the profile actually
published honest.

The corollary matters just as much: a *finer* raster does not automatically buy
a better answer. When EU-DEM v1 (1 arc-second) was compared against EU-DEM v1.1
(25 m), they agreed to **0.54 of a percentage point per bin** and both landed
within 1.2 m of the reference gain. The finer grid bought smoothness, not truth.

## The trees are in the data

**EU-DEM and Copernicus DEM are both Digital Surface Models.** EU-DEM's own
readme describes it as "the first surface as illuminated by the sensors".

That means canopy and buildings, **not bare ground**. On a wooded Ardennes
climb, a reading over a tree-lined stretch is partly the trees.

<figure class="gis-fig"><svg viewBox="0 0 660 408" role="img" aria-labelledby="dem2-t dem2-d" xmlns="http://www.w3.org/2000/svg"><title id="dem2-t">A surface model follows the treetops, not the road beneath them</title><desc id="dem2-d">A cross-section of a climb running through woodland. The accent line along the bottom is the road, rising gently and steadily. A stand of trees sits over the middle section. The muted dashed line above is what a digital surface model records: it tracks the road where the road is open, jumps up to follow the canopy through the wooded stretch, then drops back when the trees end. Through that section the elevation it reports is the height of the treetops, tens of metres above the tarmac a rider is actually on. Both EU-DEM and Copernicus DEM are surface models, so this error cannot be removed by choosing between them.</desc>
<path class="gis-muted" fill="none" stroke-dasharray="5 4" d="M 40 250 L 130 244 L 150 164 L 200 150 L 250 166 L 300 154 L 350 146 L 400 236 L 520 220 L 620 206"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 150 250 L 164 188 L 178 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 153 222 L 164 172 L 175 222 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 186 250 L 200 176 L 214 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 189 217 L 200 158 L 211 217 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 222 250 L 236 192 L 250 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 225 224 L 236 178 L 247 224 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 258 250 L 272 180 L 286 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 261 218 L 272 162 L 283 218 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 300 250 L 314 184 L 328 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 303 220 L 314 168 L 325 220 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 336 250 L 350 172 L 364 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 339 215 L 350 152 L 361 215 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 372 250 L 386 190 L 400 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 375 223 L 386 175 L 397 223 Z"/><path class="gis-accent" fill="none" d="M 40 250 L 130 244 L 150 242 L 400 236 L 520 220 L 620 206"/>
<text class="gis-label-sm" x="40" y="46">What the model measures, not what you ride</text>
<text class="gis-label-sm gis-halo" x="196" y="126">model reads HERE</text>
<text class="gis-label-sm gis-halo" x="196" y="284">you ride HERE</text>
<rect class="gis-ink gis-fill-accent" x="40" y="314" width="20" height="20"/><text class="gis-label-sm" x="70" y="330">the road, a DTM follows this</text><rect class="gis-ink gis-fill-glacier" x="40" y="344" width="20" height="20"/><text class="gis-label-sm" x="70" y="360">what a DSM records</text></svg><figcaption>A <strong>DSM</strong> (digital surface model) records "the first surface as illuminated by the sensors", EU-DEM's own words. Through woodland that surface is the canopy, not the tarmac, so readings over a tree-lined stretch are partly the trees. A <strong>DTM</strong> (terrain model) is the bare-earth counterpart and would follow the road, but none is available worldwide at useful resolution, and <em>both</em> EU-DEM and Copernicus DEM are surface models. So this is not a defect to fix by choosing a different dataset; it is a permanent error term, and the likeliest explanation whenever two good sources agree on a climb's total gain and disagree over one stretch of it.</figcaption></figure>

This is not a defect you fix by choosing a different version, because the
alternatives are DSMs too. It is a permanent error term, and it is the most
likely explanation when two good sources disagree locally while agreeing on the
total, exactly the pattern measured here: 2.02 points of per-bin disagreement
between EU-DEM and GLO-30, but gains within 2 m of each other.

A **DTM (digital terrain model)** is the bare-earth counterpart. Where one is
available at useful resolution it is the better input for road gradients.

## What the trees cost, measured

The section above is the theory. Here is what it did to a real climb.

Côte de Stockeu is wooded and steep. Our figure for its steepest 100 m, from
Copernicus GLO-30, was **27%**. Another source put the same climb at **19%**. The
*averages* agreed almost exactly, 10.2% against 9.9%, which is the clue: an
error that cancels over a whole climb but not over its worst hundred metres is
not random noise, it is something concentrated in one place.

Wallonia publishes a **50 cm LiDAR terrain model**, bare earth, vegetation and
buildings removed. Sampled along the same road:

| | average | steepest 100 m |
|---|---|---|
| Copernicus GLO-30 (surface model) | 10.2% | **27%** |
| Wallonia 50 cm LiDAR (terrain model) | 9.8% | **16.7%** |
| another source | 9.9% | 19% |

And on the exact stretch GLO-30 called 27%, the bare earth is unremarkable:

<!-- CODE-ILLUSTRATIVE sampled elevations, metres -->
```
780m 381.07   840m 388.88   900m 395.15   960m 401.88
800m 384.19   860m 390.67   920m 398.02   980m 403.79
```

That is a steady ~11%. GLO-30 read a **27 m rise across 100 m** where the ground
rises 9 m. The difference is the tree canopy: a surface model measures the top of
the forest, and where the canopy thickens going up a hill it manufactures a
gradient that no rider will ever feel.

Two things worth taking from this.

**The maximum is far more fragile than the average.** A sliding maximum searches
every position and keeps the largest reading, so it *selects for* wherever the
error happened to be worst, the one statistic guaranteed to find the canopy.
Averages let errors cancel; maxima accumulate them by construction. When two
sources agree on a climb's average and disagree on its maximum, suspect the
maximum, not the average.

**It pointed at the wrong road, not just the wrong number.** The LiDAR's steepest
stretch is at 550 m; GLO-30's was at 850 m. A profile can be wrong about *where*
as well as *how much*, and a marker on a map makes that visible in a way a table
of numbers does not.

**You can check this without downloading anything.** The Wallonia model is served
as an ArcGIS `MapServer` with query enabled, so a single HTTP request returns the
elevation at one coordinate, ninety-three of them profiled the whole climb in
about thirty seconds. That is enough to *test* a hypothesis about a source, which
is a different job from serving a catalogue and needs none of the storage.

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

**2400 columns, not 3600.** The tile is decimated in longitude, 1.5 arc-seconds
across, 1 arc-second down.

That is not a defect. Meridians converge toward the poles, so at 50°N one arc-
second of longitude is only about 20 m of ground while one of latitude is about
31 m. By sampling longitude at 1.5″, Copernicus keeps its cells roughly *square*,
about 30 m each way. It is the sensible choice, and `.hgt` cannot express it,
being 1″ by definition.

<figure class="gis-fig"><svg viewBox="0 0 700 468" role="img" aria-labelledby="dem3-t dem3-d" xmlns="http://www.w3.org/2000/svg"><title id="dem3-t">Why a Copernicus tile is 2400 columns wide instead of 3600</title><desc id="dem3-d">Two grids compared at fifty degrees north. On the left, a grid sampled one arc-second in both directions: because a degree of longitude is worth only about two thirds of a degree of latitude here, its ground cells come out as tall narrow rectangles, roughly twenty metres wide by thirty-one metres high. On the right, the same ground sampled one and a half arc-seconds in longitude and one in latitude: fewer columns, but cells close to square at about thirty metres each way. That is what Copernicus ships, and it is why a one degree tile at this latitude measures 2400 columns by 3600 rows. The SRTM hgt format is one arc-second in both directions by definition, so converting upsamples longitude back to 3600 columns, adding no information and destroying none.</desc>
<text class="gis-label-sm" x="40" y="56">At 50° N, 1″ is not the same both ways</text>
<text class="gis-label-sm gis-halo" x="40" y="130">1″ × 1″</text><text class="gis-label-sm gis-halo" x="40" y="160">square in ANGLE</text><rect class="gis-muted" fill="none" x="40" y="182" width="26" height="34"/><rect class="gis-muted" fill="none" x="40" y="216" width="26" height="34"/><rect class="gis-muted" fill="none" x="40" y="250" width="26" height="34"/><rect class="gis-muted" fill="none" x="40" y="284" width="26" height="34"/><rect class="gis-muted" fill="none" x="66" y="182" width="26" height="34"/><rect class="gis-muted" fill="none" x="66" y="216" width="26" height="34"/><rect class="gis-muted" fill="none" x="66" y="250" width="26" height="34"/><rect class="gis-muted" fill="none" x="66" y="284" width="26" height="34"/><rect class="gis-muted" fill="none" x="92" y="182" width="26" height="34"/><rect class="gis-muted" fill="none" x="92" y="216" width="26" height="34"/><rect class="gis-muted" fill="none" x="92" y="250" width="26" height="34"/><rect class="gis-muted" fill="none" x="92" y="284" width="26" height="34"/><rect class="gis-muted" fill="none" x="118" y="182" width="26" height="34"/><rect class="gis-muted" fill="none" x="118" y="216" width="26" height="34"/><rect class="gis-muted" fill="none" x="118" y="250" width="26" height="34"/><rect class="gis-muted" fill="none" x="118" y="284" width="26" height="34"/><rect class="gis-muted" fill="none" x="144" y="182" width="26" height="34"/><rect class="gis-muted" fill="none" x="144" y="216" width="26" height="34"/><rect class="gis-muted" fill="none" x="144" y="250" width="26" height="34"/><rect class="gis-muted" fill="none" x="144" y="284" width="26" height="34"/><rect class="gis-muted" fill="none" x="170" y="182" width="26" height="34"/><rect class="gis-muted" fill="none" x="170" y="216" width="26" height="34"/><rect class="gis-muted" fill="none" x="170" y="250" width="26" height="34"/><rect class="gis-muted" fill="none" x="170" y="284" width="26" height="34"/>
<text class="gis-label-sm gis-halo" x="40" y="352">≈20 m × ≈31 m</text>
<text class="gis-label-sm gis-halo" x="40" y="382">oversampled E–W</text>
<text class="gis-label-sm gis-halo" x="348" y="130">1.5″ × 1″</text><text class="gis-label-sm gis-halo" x="348" y="160">square on GROUND</text><rect class="gis-accent" fill="none" x="348" y="182" width="39" height="34"/><rect class="gis-accent" fill="none" x="348" y="216" width="39" height="34"/><rect class="gis-accent" fill="none" x="348" y="250" width="39" height="34"/><rect class="gis-accent" fill="none" x="348" y="284" width="39" height="34"/><rect class="gis-accent" fill="none" x="387" y="182" width="39" height="34"/><rect class="gis-accent" fill="none" x="387" y="216" width="39" height="34"/><rect class="gis-accent" fill="none" x="387" y="250" width="39" height="34"/><rect class="gis-accent" fill="none" x="387" y="284" width="39" height="34"/><rect class="gis-accent" fill="none" x="426" y="182" width="39" height="34"/><rect class="gis-accent" fill="none" x="426" y="216" width="39" height="34"/><rect class="gis-accent" fill="none" x="426" y="250" width="39" height="34"/><rect class="gis-accent" fill="none" x="426" y="284" width="39" height="34"/><rect class="gis-accent" fill="none" x="465" y="182" width="39" height="34"/><rect class="gis-accent" fill="none" x="465" y="216" width="39" height="34"/><rect class="gis-accent" fill="none" x="465" y="250" width="39" height="34"/><rect class="gis-accent" fill="none" x="465" y="284" width="39" height="34"/>
<text class="gis-label-sm gis-halo" x="348" y="352">≈30 m × ≈30 m</text>
<text class="gis-label-sm gis-halo" x="348" y="382">Copernicus: 2400 × 3600</text>
<text class="gis-label-sm" x="40" y="432">Fewer columns is not less detail.</text>
</svg><figcaption>Meridians converge toward the poles, so at 50°&nbsp;N one arc-second of longitude buys about 20&nbsp;m of ground while one of latitude buys about 31&nbsp;m. Sampling both axes at 1″ therefore gives tall, thin cells, <em>oversampled</em> east–west. Copernicus samples longitude at 1.5″ instead, keeping the ground cell roughly square, which is why the tile covering La&nbsp;Redoute measures <code>2400 × 3600</code> rather than <code>3601 × 3601</code>. The <code>.hgt</code> format is 1″ both ways by definition, so conversion upsamples longitude back to 3600 columns: no information added, none destroyed. Two consequences: the decimation factor <strong>changes with latitude band</strong>, so a converter that hard-codes 2400 is right in Belgium and wrong in Norway; and every <code>.hgt</code> tile is 24.7&nbsp;MB whatever it really carries.</figcaption></figure>

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
their **south-west corner**, `N50E005.hgt` covers 50–51°N, 5–6°E. Row 0 is the
north edge, column 0 the west edge.

The lookup is arithmetic:

<!-- CODE-FROM tools/elevation/compare-sources.js -->
```js
const r = (latSW + 1 - lat) * 3600, c = (lon - lonSW) * 3600;
const r0 = Math.floor(r), c0 = Math.floor(c), dr = r - r0, dc = c - c0;
```

The integer part of a coordinate picks the tile, the fractional part indexes the
grid. The service then **interpolates between the four surrounding cells**: it
does not snap to the nearest one.

You can see the interpolation from outside: walking 60 m in 2 m steps returns
values that change every ~14 m on a 6.7% slope. The run length tracks the
*gradient*, not the 30 m cell size, which snapping could never produce.

**But the reply is integer metres**, and that is a second, independent reason for
the four-cell bin rule. Rounding is ±0.5 m per reading no matter how good the
raster is. Over a 100 m bin at 9%, 9 m of rise, that is ±0.5 of a point,
tolerable. Over a 20 m bin it would be ±2.5 points, and the bar would be mostly
rounding error.

## Try it

!!! tip "Hands-on: open one tile and read the two numbers this page turns on"
    A `.hgt` file has no header. Its size *is* its resolution, which is the whole
    reason the arithmetic above works, and you can confirm that with `ls` before
    you confirm anything else.

    <!-- CODE-ILLUSTRATIVE inspect one installed tile; any N/S/E/W name works -->
    ```bash
    ls -l "$DEM_DIR"/N46E008.hgt
    python3 -c "import os,math; b=os.path.getsize('N46E008.hgt'); n=int(math.isqrt(b//2)); print(b,'bytes ->',n,'x',n,'samples')"
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM any-install; sample output for any 1 arc-second tile, because the size is fixed by the format -->
    ```text
    25934402 bytes -> 3601 x 3601 samples
    ```

    3601 rather than 3600 because the edges are shared with the neighbouring
    tile, and 25,934,402 bytes because every sample is two bytes. That number is
    on the [numbers page](../numbers.md), derived rather than typed, and every
    storage estimate on the next page is that one figure multiplied.

    Now the point this page exists to make. Divide the tile's own cell size into
    the window a gradient is measured over:

    <!-- CODE-ILLUSTRATIVE the four-cell floor, computed rather than asserted -->
    ```text
    one degree of latitude    ~111,320 m
    3600 cells across         ~30.9 m per cell
    four-cell floor           ~124 m
    ```

    That floor is why the published gradient window is 250 m and not the
    conventional 100 m. A 100 m window over a 30 m grid asks for a figure across
    barely three cells, which is a question finer than the data can answer, and
    the third page in this series is the story of what happens when you ask it
    anyway.
