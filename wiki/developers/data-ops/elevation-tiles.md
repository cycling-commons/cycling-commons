<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Building elevation tiles

How a climb gets a gradient that is *measured* rather than typed: choosing a
digital elevation model, converting it into the format the routing engine reads,
and — the part that matters most — proving it is good enough before anyone
believes it.

!!! info "What this project runs today"

    **Copernicus GLO-30 is the source**, worldwide — 30 m cells, served from
    Valhalla's `/height`, so the gradients a rider sees come from it. The Europe
    tiles were built with the pipeline on this page (1137 `.hgt` tiles, 28 GB)
    and replaced the earlier EU-DEM set on 2026-08-05.

    Everything below about **GLO-90 describes the source that was replaced.** It
    is kept because it is the clearest lesson available in why resolution is not
    accuracy, and because the failure was measured on our own climbs rather than
    borrowed from a textbook.

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

**Copernicus GLO-90** — *the source used until 2026-08-05, not the one used
now* — has 90 m cells. Sampled every 25 m along La Redoute it
returned 30 distinct values across 99 samples, with runs of seven identical
readings and eight samples going *downhill* on a climb that never descends.
Binned at 100 m it published a **10% descent through the middle of the climb**.

<figure class="gis-fig"><svg viewBox="0 0 680 476" role="img" aria-labelledby="dem1-t dem1-d" xmlns="http://www.w3.org/2000/svg"><title id="dem1-t">The same climb measured by a reference source and by a 90 metre elevation model</title><desc id="dem1-d">Two elevation profiles of the Cote de la Redoute drawn from real measurements, in one hundred metre bins. The accent line is the reference profile from another source, rising continuously from foot to summit. The stepped line is what Copernicus GLO-90, a ninety metre model, returns for the identical road: it follows the general shape but in flat jumps, and in two bins it goes down instead of up. Those two descending bins are tinted, and the larger reports minus ten percent through the middle of a climb that never descends, bracketed by a twenty-five percent bin and a zero percent bin. The model is not slightly noisy here; it is reporting terrain that is not there.</desc>
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
<rect class="gis-ink gis-fill-accent" x="40" y="314" width="20" height="20"/><text class="gis-label-sm" x="70" y="330">the road — a DTM follows this</text><rect class="gis-ink gis-fill-glacier" x="40" y="344" width="20" height="20"/><text class="gis-label-sm" x="70" y="360">what a DSM records</text></svg><figcaption>A <strong>DSM</strong> (digital surface model) records "the first surface as illuminated by the sensors" — EU-DEM's own words. Through woodland that surface is the canopy, not the tarmac, so readings over a tree-lined stretch are partly the trees. A <strong>DTM</strong> (terrain model) is the bare-earth counterpart and would follow the road, but none is available worldwide at useful resolution, and <em>both</em> EU-DEM and Copernicus DEM are surface models. So this is not a defect to fix by choosing a different dataset — it is a permanent error term, and the likeliest explanation whenever two good sources agree on a climb's total gain and disagree over one stretch of it.</figcaption></figure>

This is not a defect you fix by choosing a different version, because the
alternatives are DSMs too. It is a permanent error term, and it is the most
likely explanation when two good sources disagree locally while agreeing on the
total — exactly the pattern measured here: 2.02 points of per-bin disagreement
between EU-DEM and GLO-30, but gains within 2 m of each other.

A **DTM (digital terrain model)** is the bare-earth counterpart. Where one is
available at useful resolution it is the better input for road gradients.

## What the trees cost, measured

The section above is the theory. Here is what it did to a real climb.

Côte de Stockeu is wooded and steep. Our figure for its steepest 100 m, from
Copernicus GLO-30, was **27%**. Another source put the same climb at **19%**. The
*averages* agreed almost exactly — 10.2% against 9.9% — which is the clue: an
error that cancels over a whole climb but not over its worst hundred metres is
not random noise, it is something concentrated in one place.

Wallonia publishes a **50 cm LiDAR terrain model** — bare earth, vegetation and
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
error happened to be worst — the one statistic guaranteed to find the canopy.
Averages let errors cancel; maxima accumulate them by construction. When two
sources agree on a climb's average and disagree on its maximum, suspect the
maximum, not the average.

**It pointed at the wrong road, not just the wrong number.** The LiDAR's steepest
stretch is at 550 m; GLO-30's was at 850 m. A profile can be wrong about *where*
as well as *how much*, and a marker on a map makes that visible in a way a table
of numbers does not.

**You can check this without downloading anything.** The Wallonia model is served
as an ArcGIS `MapServer` with query enabled, so a single HTTP request returns the
elevation at one coordinate — ninety-three of them profiled the whole climb in
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

**2400 columns, not 3600.** The tile is decimated in longitude — 1.5 arc-seconds
across, 1 arc-second down.

That is not a defect. Meridians converge toward the poles, so at 50°N one arc-
second of longitude is only about 20 m of ground while one of latitude is about
31 m. By sampling longitude at 1.5″, Copernicus keeps its cells roughly *square*
— about 30 m each way. It is the sensible choice, and `.hgt` cannot express it,
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
</svg><figcaption>Meridians converge toward the poles, so at 50°&nbsp;N one arc-second of longitude buys about 20&nbsp;m of ground while one of latitude buys about 31&nbsp;m. Sampling both axes at 1″ therefore gives tall, thin cells — <em>oversampled</em> east–west. Copernicus samples longitude at 1.5″ instead, keeping the ground cell roughly square, which is why the tile covering La&nbsp;Redoute measures <code>2400 × 3600</code> rather than <code>3601 × 3601</code>. The <code>.hgt</code> format is 1″ both ways by definition, so conversion upsamples longitude back to 3600 columns: no information added, none destroyed. Two consequences — the decimation factor <strong>changes with latitude band</strong>, so a converter that hard-codes 2400 is right in Belgium and wrong in Norway; and every <code>.hgt</code> tile is 24.7&nbsp;MB whatever it really carries.</figcaption></figure>

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

### Gzip works, and costs more than it saves

**This section previously recommended storing tiles gzipped. That advice was
wrong, and the measurement that overturned it is below.**

Valhalla does read `.hgt.gz` directly — `valhalla_build_elevation` has a
`--decompress` flag precisely so you can decline it. Verified on 2026-08-28: the
same tile served raw and gzipped returned identical heights, `[316, 313, 289]`,
after a container restart.

The restart is what makes that a real test. Rename a `.hgt` and query again
without restarting and you get the right answer from a file that is no longer
there — the old inode is still mapped.

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
rate the planet's land is roughly 140 GB instead of 468 GB.

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
  hard-coded, not configurable). 50 × 25.9 MB ≈ 1.3 GB — which is exactly the
  +1260 MB measured. So the cost is **bounded**, not runaway. An earlier draft
  of this page said skadi showed no sign of evicting; that was wrong.
- Eviction is **pseudo-random**: when the cap is hit it drops the first tile in
  an `unordered_set` whose usage count is zero, and usage counts drop to zero
  between every edge. A tile needed for the very next point is as likely to be
  evicted as any other, so a busy instance **re-inflates the same tiles over and
  over**. That thrash, plus one global `recursive_mutex` around it, is the
  2.6× slowdown.

Upstream knows. Issue **#6163** (open, July 2026, "Optimize compressed HGT file
support") describes precisely this — the maintainer's own words for the current
design are *"the caching mechanism is pretty dumb"* — and proposes a per-worker
LRU. Until that lands, the behaviour above is what you get.

!!! danger "Store raw unless the instance has a small memory cap AND you accept the slowdown"

    Gzip costs a fixed ~1.3 GB of unreclaimable memory per instance, plus a
    2-3× latency penalty from cache thrash, in exchange for ~70% less disk.

    On an instance with a 4 GB limit that 1.3 GB is a third of everything it
    has. On one with 16 GB it is noise. So the question is not "gzip or not"
    but "what is this instance's cap, and does it serve latency-sensitive
    traffic". Keep anything busy raw. `.lz4` inflates into the same cache and
    changes nothing structural.

One trap, and it is a nasty one: **a Valhalla with no elevation tiles loaded does
not fail.** It returns `0` for every point — a perfectly valid-looking sea-level
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

Six continents, six exact matches. Not "roughly ours" — **precisely** the union
of our seventeen presets, to the degree. That is the fingerprint of a pipeline
that has only ever had one requester.

That application meanwhile covers 175 countries, whose areas touch
**16,619** one-degree cells. Installed: 3,897, of which 3,439 are in cells it
cares about. So 32 of its 175 countries have elevation and 143 do not. In those
143, `/height` answers `0`, and — per the trap two sections above — nothing
errors. Rides there have been recording zero climb.

!!! warning "The lesson, which is not about elevation"

    A shared resource provisioned from one consumer's list looks completely
    healthy from that consumer's side. Cycling Commons' climbs were correct the
    whole time. The gap was invisible from here precisely *because* our own
    requirement was fully met.

    If you own a pipeline that more than one thing reads, the input is the
    **union of every consumer's requirement** — and each consumer has to be able
    to state its own, mechanically, rather than by someone remembering.

### Consumers declare, this pipeline acts

The split that fixes it:

- **This repository owns the action.** Fetch, convert, validate, install. One
  pipeline, one validator, one write-up. Nobody else should carry a copy of
  `fetch-glo30.sh` — a second copy drifts, and the copy without
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
runs inside `ghcr.io/osgeo/gdal` on purpose — the routing host deliberately has
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

The restart is unavoidable and it is a real outage for that continent — seconds
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
# here
bin/console app:climbs:recompute --country=SI

# in every other consumer — anything of theirs that reads /height
bin/console <their-recompute-command> --country=SI
```

A well-behaved consumer refuses to report success when more than 95% of a
country's results come back with zero climb — the same silent-zero trap, caught
one layer further out.

### 7. Note what a DEM install does *not* fix

This is the distinction from *Installing, and a distinction worth money*, and it
is worth restating with the consequence attached:

| | reads | fixed by installing a DEM? |
|---|---|---|
| climb profiles, ride climb metres, energy priors | `/height`, at request time | **yes**, immediately after restart |
| `use_hills` preferring flatter roads | `weighted_grade`, baked into the tiles | **no** — only a tile rebuild |

So a newly onboarded region measures correctly the moment the DEM lands. What it
does not get is a router that knows to avoid its hills, and that waits for the
next rebuild of that continent.

!!! danger "A rebuild is not free, and right now it is a downgrade"

    The routing tiles currently carry grades **everywhere**, because the
    2026-03-29 build ran with `build_elevation=True` and Valhalla downloaded its
    own SRTM-derived set for the whole graph.

    A rebuild bakes only what is in `elevation_data` at that moment. Any cell
    without a GLO-30 tile comes back **flat** — a downgrade from the SRTM grades
    it has today, and an invisible one.

    So a continent must reach full coverage of everything anyone routes on
    *before* it is rebuilt, not after. For the whole platform that is 16,619
    cells: 401 GB raw. Store it raw — see "Gzip works, and costs more than it
    saves" above; compression trades that disk for unreclaimable memory.

## Automating it, and the one check that must not be skipped

A whole-planet pass is six continents of fetch, convert, install, build, verify,
compress. Every step is a single command, and none of them is where the time
goes. The time goes into the gaps: during one rebuild the machine sat idle for
hours on two separate occasions, each time because a stage finished and nothing
picked up the next one. The tooling was never the bottleneck. Waiting for a
person to notice was.

So the work is worth wrapping in a runner. The interesting part is not the loop
— it is what the loop is allowed to believe.

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
climbs, and require a spread of grades — say five or more distinct values with a
maximum above 3%. Anything flatter means the DEM was not read.

And it must **stop the run**, not warn and continue. Halting after one bad
continent is recoverable. Quietly building six is five more rebuilds.

### Distinguish "inconclusive" from "failed"

Two things can go wrong with that probe, and conflating them is dangerous:

- **The route will not snap** — bad coordinates, a gap in the road data. That is
  inconclusive. Pass, and say so.
- **Nothing answers the port** — the container never came up. That is a
  failure.

An early version of the check treated both as inconclusive, so a service that
had died would have sailed straight through the gate reporting success. Pick
probe points on well-mapped roads, and treat silence from the port as a hard
stop.

### One build at a time

If the containers are governed by a single unit or compose project, two
concurrent builds will stop each other's service mid-run and both will look
broken in confusing ways. The runner should wait for any in-flight build before
starting — which also means it can be launched *while* one is already going,
and that is the normal case, because that is exactly when someone thinks of it.

!!! tip "The generalisable bit"

    Automating a pipeline is mostly not about the steps. It is about deciding
    what the automation is permitted to accept as proof that a step worked. If
    the failure mode of your slowest step is *silent and plausible*, the check
    after it has to look at the artefact, not the exit status — and it has to be
    willing to stop the line.

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

## Measuring a climb, end to end

Everything above is about the *source*. This is the method built on top of it,
as it finally stands — and the order in which it went wrong is more instructive
than the finished shape.

A climb becomes numbers in six steps:

<!-- CODE-ILLUSTRATIVE the measurement pipeline, not a source file -->
```
foot + summit
      ↓  routing engine          WHERE the road goes
a polyline
      ↓  trim at the highest point   the line must END at the summit
the climb
      ↓  resample to 200 points
      ↓  DEM lookup              HOW HIGH each point is
elevations
      ↓
length · gain · average · steepest stretch
```

The first four steps were never the hard part. The last one was, and only one of
the two published figures was ever in trouble.

### One of these two figures is robust and the other is not

**The average is safe.** It is a sum over hundreds of samples, and a Digital
Surface Model's errors — canopy, cuttings, roofs — are scattered, so they cancel.
Measured against published figures for six Swiss passes, ours agreed to within a
few tenths: Nufenen 13.27 km at 8.5% against a published 13.4 km at 8.5%.

**The steepest stretch is fragile**, for a reason worth internalising: **it is an
extreme-value statistic, and on a surface model the extreme is almost always the
artifact.** An average asks "what is this road typically like", which noise
cancels out of. A maximum asks "what is the single worst thing in this data",
which is a question about the noise.

That produced a published 20% on the Furka, a climb that is about 10%.

### The tell: it got worse as the data got better

The obvious suspect was sampling. The profiler takes a fixed 200 samples
whatever the length, so a 26 km climb samples every 130 m and a 100 m window
spans less than one interval. Plausible, and **wrong**: resampling the Furka at
10 m, 20 m, 30 m and 50 m moved the answer by less than half a point.

The real tell was the opposite of what a bug usually does. **The raw maximum got
worse as sampling improved.** On the Grimsel it went from 35% to 77% when
spacing tightened from 50 m to 20 m. Nothing that reads the road behaves like
that — finer sampling was finding more *spikes*, not more road. That single
observation is what identified the fault: if refining your input degrades your
answer, you are measuring your noise.

Two causes came out of it.

**The window was finer than the data could answer.** The rule earlier on this
page — a bin is never narrower than about four DEM cells — puts GLO-30's floor
at 120 m. The window was 100 m. It had been below the source's resolution from
the first day; short, unroofed Ardennes climbs simply never exposed it.

**And a clamp was hiding the damage.** The code capped the published figure at
35%. Grimsel, Susten and Klausen all published exactly 35% — which looks like
three steep passes and is really one ceiling that three artifacts hit. *Any time
several independent things report the identical value, suspect that you are
reading a limit rather than a measurement.*

### What is published now

**The 95th percentile of sliding 250 m windows.** Wider than the source's
resolution floor, and a statistic that a single bad cell cannot move.

<figure class="gis-fig"><svg viewBox="0 0 660 350" role="img" aria-labelledby="pct-t pct-d" xmlns="http://www.w3.org/2000/svg"><title id="pct-t">Every sliding window on one climb, ordered by gradient</title><desc id="pct-d">A curve showing the gradient of every two hundred and fifty metre sliding window along the Furka, ordered from gentlest on the left to steepest on the right. For the first ninety five percent of windows the curve rises slowly and smoothly from about three percent to about ten percent, which is the road. In the last few percent it turns sharply upward and shoots to twenty four percent at the extreme right. That tail is tinted and labelled as artifacts: cuttings, rock faces and roofs. A vertical marker at the ninety fifth percentile shows the published figure of ten percent, sitting at the top of the smooth part of the curve, while the maximum at the far right is more than twice it.</desc>
<line class="gis-muted" x1="70" y1="278" x2="628" y2="278"/>
<line class="gis-muted" x1="70" y1="278" x2="70" y2="60"/>
<line class="gis-muted" stroke-dasharray="2 5" x1="70" y1="194" x2="628" y2="194"/>
<line class="gis-muted" stroke-dasharray="2 5" x1="70" y1="110" x2="628" y2="110"/>
<text class="gis-label-sm" x="62" y="282" text-anchor="end">0%</text>
<text class="gis-label-sm" x="62" y="198" text-anchor="end">10%</text>
<text class="gis-label-sm" x="62" y="114" text-anchor="end">20%</text>
<path class="gis-fill-clay" stroke="none" fill-opacity=".35" d="M 592 278 L 592 191 L 604 177 L 612 144 L 617 110 L 620 76 L 620 278 Z"/>
<polyline class="gis-accent" fill="none" points="70,253 180,232 290,219 400,209 510,201 565,196 592,191 604,177 612,144 617,110 620,76"/>
<line class="gis-clay" x1="592" y1="191" x2="592" y2="300"/>
<text class="gis-label-sm gis-halo" x="600" y="308" text-anchor="end">95th percentile</text>
<text class="gis-label-sm gis-halo" x="600" y="326" text-anchor="end">published 10%</text>
<circle class="gis-clay gis-fill-clay" cx="620" cy="76" r="4"/>
<text class="gis-label-sm gis-halo" x="608" y="64" text-anchor="end">maximum 24%</text>
<text class="gis-label-sm gis-halo" x="500" y="128" text-anchor="middle">the tail is artifacts</text>
<text class="gis-label-sm" x="70" y="40">Every 250 m window, ordered by gradient</text>
<text class="gis-label-sm gis-halo" x="200" y="262">the road</text>
<text class="gis-label-sm" x="70" y="302">gentlest &#8594; steepest</text></svg><figcaption>Every sliding window on one climb, gentlest to steepest. For 95% of them the curve is <strong>smooth and slow</strong> — that is the road. Then it turns almost vertical. <strong>A maximum reads the very last point of that tail</strong>, which is a cutting, a rock face or a roof; the 95th percentile reads the top of the smooth part. This is why the average was always trustworthy and the steepest figure never was: an average is a question the noise cancels out of, a maximum is a question <em>about</em> the noise.</figcaption></figure>

It was checked against the only two independent truths available, and it hits
both: Wallonia's 50 cm LiDAR puts the Côte de Stockeu's steepest at **16.7%**
and we read **16.7%**; the Furka is about **10%** and we read **10.3%**. Two
points, two countries, two kinds of terrain — enough to adopt, not enough to
stop testing.

### Tunnels, or: ask the road, don't guess from the profile

A percentile removes scattered noise. It does not remove a systematic error, and
alpine roads have a large one.

<figure class="gis-fig"><svg viewBox="0 0 660 340" role="img" aria-labelledby="gal-t gal-d" xmlns="http://www.w3.org/2000/svg"><title id="gal-t">A surface model follows the roof of an avalanche gallery, not the road inside it</title><desc id="gal-d">A cross-section of a mountain road climbing gently from left to right. Over the middle third a solid roof slab sits above the road, forming an avalanche gallery, with the road running straight through underneath it unchanged. The accent line is the road itself, rising steadily and unbroken the whole way. The dashed line is what a digital surface model records: it lies on the road across the open approach, then jumps almost vertically at the gallery entrance up onto the top of the roof, runs dead flat along it, and drops back down onto the road where the gallery ends. The jump is annotated as fifty four metres gained in one hundred and forty metres, which reads as a thirty seven percent ramp, and the flat stretch immediately after it is annotated as the giveaway, because a real ramp does not stop dead.</desc>
<rect class="gis-fill-spruce" stroke="none" fill-opacity=".38" x="200" y="178" width="240" height="14"/>
<line class="gis-muted" x1="201" y1="190" x2="201" y2="261"/>
<line class="gis-muted" x1="439" y1="190" x2="439" y2="233"/>
<path class="gis-accent" fill="none" d="M 40 286 L 200 262 L 440 232 L 620 206"/>
<path class="gis-muted" fill="none" stroke-dasharray="7 4" style="stroke-width:2.6" d="M 40 286 L 197 263 L 203 174 L 437 172 L 443 233 L 620 206"/>
<line class="gis-clay" x1="197" y1="260" x2="197" y2="132"/>
<text class="gis-label-sm gis-halo" x="205" y="126">+54 m in 140 m &#8594; reads as 37%</text>
<text class="gis-label-sm gis-halo" x="320" y="164" text-anchor="middle">dead flat &#8212; the giveaway</text>
<text class="gis-label-sm gis-halo" x="320" y="222" text-anchor="middle">gallery</text>
<text class="gis-label-sm" x="40" y="44">What the model reads where the road is roofed</text>
<rect class="gis-ink gis-fill-accent" x="40" y="306" width="18" height="14"/><text class="gis-label-sm" x="66" y="318">the road you ride</text>
<rect class="gis-ink gis-fill-glacier" x="290" y="306" width="18" height="14"/><text class="gis-label-sm" x="316" y="318">what the surface model records</text></svg><figcaption>Where a road runs under an avalanche gallery or through a tunnel, a <strong>surface model reads the mountain on top of it</strong>. On the Grimsel the profile climbs <strong>768&nbsp;m to 822&nbsp;m in 140&nbsp;m and then goes flat</strong> — a 54&nbsp;m step that is the roof, not tarmac. The flat afterwards is the signature: real ramps do not stop dead. The road underneath never changes gradient at all.</figcaption></figure>

The tempting fix is to detect that signature — find the step-then-flat pattern
and discard it. Resist it. **The road network already knows.** Valhalla's
`/trace_attributes` map-matches a shape onto real edges and reports OpenStreetMap's
`tunnel` flag for each one, so the covered stretches are a *lookup*, not an
inference about what a shape in a profile probably means. A heuristic would also
have to be right about steep-but-real ramps, and this never has to guess.

The Grimsel turns out to carry **2,082 m under cover across 9 spans** — 8% of the
climb. Windows overlapping those spans are simply not candidates for the steepest
stretch.

| | before | tunnel-aware | independent figure |
|---|---:|---:|---:|
| Grimsel | 14% | **12%** | ~11% |
| Susten | 12% | **11%** | — |
| Klausen | 15% | **14%** | — |
| Furka, Gotthard, Nufenen, Stockeu, Redoute, Huy | — | **unchanged** | — |

**The last row is the row that matters.** Every climb with no cover measured
identically. A change that only moves what it claims to move is a change you can
believe; one that shifts everything slightly is one you cannot.

Three implementation details carry more weight than they look like they should:

- **Covered stretches are recorded as fractions of the line, not metres.**
  Map-matching snaps to the carriageway, so the matched geometry is *not* the
  shape you sent and its length differs. A proportion survives that. A metre
  offset drifts quietly along the climb — and quietly wrong is the failure mode
  this whole page exists to avoid.
- **One lookup, on the same 200 samples.** Matching follows the *road* between
  your samples, so a 33 m tunnel is still found from points 130 m apart. Checked
  on three passes, the 200-point shape returns identical spans to the full
  690-point route.
- **It fails soft.** If the routing service is unreachable, no spans come back
  and the climb measures exactly as it did before cover was considered. The
  elevation read has already succeeded by then; a second outage should cost
  accuracy on roofed roads, never a missing profile.

### What is still wrong

Stating this plainly is the point of the page.

- **The Grimsel still reads 12% against a real ~11%.** It has the most galleries
  of the six, and a residue survives.
- **Cover is excluded from the steepest search only.** Those readings are still
  in the gain, in the chart bars and in the line colouring, where they are
  diluted enough that nothing has shown up as wrong. That is a reason to leave
  them until someone measures a case where they *are* wrong — not evidence that
  they are right.
- **A percentile is not a maximum**, and the label has to say so. The width the
  figure was averaged over is stored beside the figure and the caption is built
  from it, so the copy cannot drift back to claiming 100 m. It had already done
  exactly that, in four translation catalogues at once.

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
