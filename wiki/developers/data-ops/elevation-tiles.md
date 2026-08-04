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

<figure class="gis-fig"><svg viewBox="0 0 640 360" role="img" aria-labelledby="dem1-t dem1-d" xmlns="http://www.w3.org/2000/svg"><title id="dem1-t">A smoothly rising road, and the staircase a 90 metre elevation grid returns for it</title><desc id="dem1-d">A cross-section of a two kilometre climb. The accent line is the real road surface, rising steadily from the foot to about 236 metres of gain. Behind it, dotted vertical lines mark the boundaries of a 90 metre digital elevation model grid. The muted stepped line is what that grid actually returns when sampled along the road: one flat value per cell, jumping at each boundary, because the model stores a single elevation for every 90 by 90 metre patch of ground. The steps do not merely look coarse. One tinted step falls below the one before it, so the grid reports the road going downhill on a climb that never descends. Asking the grid a question finer than its cells returns the shape of the grid rather than the shape of the road.</desc><line class="gis-muted" stroke-dasharray="2 4" x1="60.0" y1="60" x2="60.0" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="84.3" y1="60" x2="84.3" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="108.6" y1="60" x2="108.6" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="132.9" y1="60" x2="132.9" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="157.2" y1="60" x2="157.2" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="181.5" y1="60" x2="181.5" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="205.8" y1="60" x2="205.8" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="230.1" y1="60" x2="230.1" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="254.4" y1="60" x2="254.4" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="278.7" y1="60" x2="278.7" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="303.0" y1="60" x2="303.0" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="327.3" y1="60" x2="327.3" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="351.6" y1="60" x2="351.6" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="375.9" y1="60" x2="375.9" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="400.2" y1="60" x2="400.2" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="424.5" y1="60" x2="424.5" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="448.8" y1="60" x2="448.8" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="473.1" y1="60" x2="473.1" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="497.4" y1="60" x2="497.4" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="521.7" y1="60" x2="521.7" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="546.0" y1="60" x2="546.0" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="570.3" y1="60" x2="570.3" y2="280"/><line class="gis-muted" stroke-dasharray="2 4" x1="594.6" y1="60" x2="594.6" y2="280"/><polyline class="gis-muted" fill="none" points="60.0,280.0 84.3,280.0 84.3,269.4 108.6,269.4 108.6,258.9 132.9,258.9 132.9,248.3 157.2,248.3 157.2,237.8 181.5,237.8 181.5,227.2 205.8,227.2 205.8,216.6 230.1,216.6 230.1,206.1 254.4,206.1 254.4,195.5 278.7,195.5 278.7,185.0 303.0,185.0 303.0,174.4 327.3,174.4 327.3,163.8 351.6,163.8 351.6,142.7 375.9,142.7 375.9,132.2 400.2,132.2 400.2,121.6 424.5,121.6 424.5,111.0 448.8,111.0 448.8,111.0 473.1,111.0 473.1,100.5 497.4,100.5 497.4,89.9 521.7,89.9 521.7,89.9 546.0,89.9 546.0,79.4 570.3,79.4 570.3,79.4 594.6,79.4 594.6,68.8 600.0,68.8"/><polyline class="gis-accent" fill="none" points="60.0,280.0 114.0,264.2 168.0,241.3 222.0,216.6 276.0,195.5 330.0,167.4 384.0,135.7 438.0,112.8 492.0,97.0 546.0,82.9 600.0,72.3"/><text class="gis-label-sm" x="60" y="38">Sampling a real climb into a 90 m grid</text><text class="gis-label-sm gis-halo" x="68" y="306">foot</text><text class="gis-label-sm gis-halo" x="592" y="306" text-anchor="end">2 km · summit</text><rect class="gis-ink gis-fill-accent" x="60" y="322" width="22" height="22"/><text class="gis-label-sm" x="90" y="339">the road</text><rect class="gis-ink gis-fill-glacier" x="210" y="322" width="22" height="22"/><text class="gis-label-sm" x="240" y="339">what a 90 m DEM returns</text><rect class="gis-ink gis-fill-clay" fill-opacity=".3" x="440" y="322" width="22" height="22"/><text class="gis-label-sm" x="470" y="339">reads downhill</text></svg><figcaption>The accent line is the road; the stepped line is what a 90&nbsp;metre model returns when you sample along it. The model holds one elevation per 90&nbsp;m cell, so the profile it gives back is flat within each cell and jumps at the boundary — and where two neighbouring cells round in opposite directions, it reports a <em>descent</em> on a climb that never descends. Measured on La Redoute, Copernicus GLO-90 returned 30 distinct values across 99 samples, runs of seven identical readings, and eight samples going downhill. This is why the bin floor exists: ask a grid a question finer than its cells and it answers with its own shape.</figcaption></figure>

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

<figure class="gis-fig"><svg viewBox="0 0 640 340" role="img" aria-labelledby="dem3-t dem3-d" xmlns="http://www.w3.org/2000/svg"><title id="dem3-t">A surface model follows the treetops, not the road beneath them</title><desc id="dem3-d">A cross-section of a climb running through a belt of woodland. The accent line along the bottom is the road surface, rising gently and steadily. A stand of trees sits over the middle section. The muted line above is what a digital surface model records: it tracks the road where the road is open, then jumps up to follow the canopy through the wooded stretch, and drops back to the road when the trees end. The elevation the model reports through that section is the height of the treetops, tens of metres above the tarmac a rider is actually on. Both EU-DEM and Copernicus DEM are surface models, so this error cannot be removed by choosing between them.</desc>
<path class="gis-muted" fill="none" stroke-dasharray="5 4" d="M 40 250 L 130 244 L 150 164 L 200 150 L 250 166 L 300 154 L 350 146 L 400 236 L 520 220 L 600 206"/>
<path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 150 250 L 164 188 L 178 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 153 222.1 L 164 172.5 L 175 222.1 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 186 250 L 200 176 L 214 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 189 216.7 L 200 157.5 L 211 216.7 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 222 250 L 236 192 L 250 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 225 223.9 L 236 177.5 L 247 223.9 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 258 250 L 272 180 L 286 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 261 218.5 L 272 162.5 L 283 218.5 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 300 250 L 314 184 L 328 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 303 220.3 L 314 167.5 L 325 220.3 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 336 250 L 350 172 L 364 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 339 214.9 L 350 152.5 L 361 214.9 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 372 250 L 386 190 L 400 250 Z"/><path class="gis-spruce gis-fill-spruce" fill-opacity=".35" d="M 375 223.0 L 386 175.0 L 397 223.0 Z"/>
<path class="gis-accent" fill="none" d="M 40 250 L 130 244 L 150 242 L 400 236 L 520 220 L 600 206"/>
<text class="gis-label-sm" x="40" y="34">What the model measures vs what you ride</text>
<text class="gis-label-sm gis-halo" x="200" y="132">the model reads HERE</text>
<text class="gis-label-sm gis-halo" x="200" y="274">you ride HERE</text>
<line class="gis-clay" stroke-dasharray="2 3" x1="270" y1="154" x2="270" y2="236"/>
<text class="gis-label-sm gis-halo" x="278" y="198">canopy height</text>
<rect class="gis-ink gis-fill-accent" x="40" y="294" width="22" height="22"/><text class="gis-label-sm" x="70" y="311">road surface (a DTM would follow this)</text>
<rect class="gis-ink gis-fill-glacier" x="380" y="294" width="22" height="22"/><text class="gis-label-sm" x="410" y="311">what a DSM records</text>
</svg><figcaption>A <strong>DSM</strong> (digital surface model) records "the first surface as illuminated by the sensors" — EU-DEM's own words. Through woodland that surface is the canopy, not the tarmac, so readings over a tree-lined stretch are partly the trees. A <strong>DTM</strong> (terrain model) is the bare-earth counterpart and would follow the road, but no DTM is available worldwide at useful resolution, and <em>both</em> EU-DEM and Copernicus DEM are surface models. So this is not a defect to fix by choosing a different dataset — it is a permanent error term, and the most likely explanation whenever two good sources agree on a climb's total gain and disagree over one stretch of it.</figcaption></figure>

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

<figure class="gis-fig"><svg viewBox="0 0 640 420" role="img" aria-labelledby="dem2-t dem2-d" xmlns="http://www.w3.org/2000/svg"><title id="dem2-t">Why a Copernicus tile is 2400 columns wide instead of 3600</title><desc id="dem2-d">Two panels comparing grids at 50 degrees north. On the left, a grid sampled one arc-second in both directions: because a degree of longitude is worth only about two thirds of a degree of latitude at this latitude, its ground cells come out as tall narrow rectangles, roughly 20 metres wide by 31 metres high. On the right, the same area sampled one and a half arc-seconds in longitude and one in latitude: fewer columns, but the ground cells are close to square at about 30 metres each way. That is what Copernicus does, and it is why a one degree tile at this latitude is 2400 columns by 3600 rows rather than 3600 by 3600. The SRTM hgt format is one arc-second in both directions by definition, so converting to it upsamples the longitude axis back to 3600 columns, adding no information and destroying none.</desc>
<text class="gis-label-sm" x="20" y="34">At 50° N, one arc-second is not the same distance both ways</text>
<text class="gis-label-sm gis-halo" x="20" y="66">1″ × 1″ — square in ANGLE</text>
<g><rect class="gis-muted" fill="none" x="20" y="86" width="22" height="34"/><rect class="gis-muted" fill="none" x="20" y="120" width="22" height="34"/><rect class="gis-muted" fill="none" x="20" y="154" width="22" height="34"/><rect class="gis-muted" fill="none" x="20" y="188" width="22" height="34"/><rect class="gis-muted" fill="none" x="20" y="222" width="22" height="34"/><rect class="gis-muted" fill="none" x="42" y="86" width="22" height="34"/><rect class="gis-muted" fill="none" x="42" y="120" width="22" height="34"/><rect class="gis-muted" fill="none" x="42" y="154" width="22" height="34"/><rect class="gis-muted" fill="none" x="42" y="188" width="22" height="34"/><rect class="gis-muted" fill="none" x="42" y="222" width="22" height="34"/><rect class="gis-muted" fill="none" x="64" y="86" width="22" height="34"/><rect class="gis-muted" fill="none" x="64" y="120" width="22" height="34"/><rect class="gis-muted" fill="none" x="64" y="154" width="22" height="34"/><rect class="gis-muted" fill="none" x="64" y="188" width="22" height="34"/><rect class="gis-muted" fill="none" x="64" y="222" width="22" height="34"/><rect class="gis-muted" fill="none" x="86" y="86" width="22" height="34"/><rect class="gis-muted" fill="none" x="86" y="120" width="22" height="34"/><rect class="gis-muted" fill="none" x="86" y="154" width="22" height="34"/><rect class="gis-muted" fill="none" x="86" y="188" width="22" height="34"/><rect class="gis-muted" fill="none" x="86" y="222" width="22" height="34"/><rect class="gis-muted" fill="none" x="108" y="86" width="22" height="34"/><rect class="gis-muted" fill="none" x="108" y="120" width="22" height="34"/><rect class="gis-muted" fill="none" x="108" y="154" width="22" height="34"/><rect class="gis-muted" fill="none" x="108" y="188" width="22" height="34"/><rect class="gis-muted" fill="none" x="108" y="222" width="22" height="34"/><rect class="gis-muted" fill="none" x="130" y="86" width="22" height="34"/><rect class="gis-muted" fill="none" x="130" y="120" width="22" height="34"/><rect class="gis-muted" fill="none" x="130" y="154" width="22" height="34"/><rect class="gis-muted" fill="none" x="130" y="188" width="22" height="34"/><rect class="gis-muted" fill="none" x="130" y="222" width="22" height="34"/></g>
<text class="gis-label-sm gis-halo" x="20" y="286">≈20 m wide · ≈31 m tall</text>
<text class="gis-label-sm gis-halo" x="20" y="308">tall, thin — oversampled east-west</text>
<text class="gis-label-sm gis-halo" x="352" y="66">1.5″ × 1″ — square on the GROUND</text>
<g><rect class="gis-accent" fill="none" x="352" y="86" width="33" height="34"/><rect class="gis-accent" fill="none" x="352" y="120" width="33" height="34"/><rect class="gis-accent" fill="none" x="352" y="154" width="33" height="34"/><rect class="gis-accent" fill="none" x="352" y="188" width="33" height="34"/><rect class="gis-accent" fill="none" x="352" y="222" width="33" height="34"/><rect class="gis-accent" fill="none" x="385" y="86" width="33" height="34"/><rect class="gis-accent" fill="none" x="385" y="120" width="33" height="34"/><rect class="gis-accent" fill="none" x="385" y="154" width="33" height="34"/><rect class="gis-accent" fill="none" x="385" y="188" width="33" height="34"/><rect class="gis-accent" fill="none" x="385" y="222" width="33" height="34"/><rect class="gis-accent" fill="none" x="418" y="86" width="33" height="34"/><rect class="gis-accent" fill="none" x="418" y="120" width="33" height="34"/><rect class="gis-accent" fill="none" x="418" y="154" width="33" height="34"/><rect class="gis-accent" fill="none" x="418" y="188" width="33" height="34"/><rect class="gis-accent" fill="none" x="418" y="222" width="33" height="34"/><rect class="gis-accent" fill="none" x="451" y="86" width="33" height="34"/><rect class="gis-accent" fill="none" x="451" y="120" width="33" height="34"/><rect class="gis-accent" fill="none" x="451" y="154" width="33" height="34"/><rect class="gis-accent" fill="none" x="451" y="188" width="33" height="34"/><rect class="gis-accent" fill="none" x="451" y="222" width="33" height="34"/></g>
<text class="gis-label-sm gis-halo" x="352" y="286">≈30 m × ≈30 m</text>
<text class="gis-label-sm gis-halo" x="352" y="308">what Copernicus ships: 2400 × 3600</text>
<line class="gis-ink" stroke-dasharray="3 3" x1="320" y1="80" x2="320" y2="300"/>
<text class="gis-label-sm" x="20" y="352">Same ground, same rows. Fewer columns is not less detail — it is the</text>
<text class="gis-label-sm" x="20" y="374">same detail, sampled where the ground actually needs it.</text>
</svg><figcaption>Meridians converge toward the poles, so at 50°&nbsp;N one arc-second of longitude buys about 20&nbsp;m of ground while one of latitude buys about 31&nbsp;m. Sampling both axes at 1″ therefore produces tall, thin cells — <em>oversampled</em> east–west. Copernicus samples longitude at 1.5″ instead, keeping the ground cell roughly square, which is why the tile covering La&nbsp;Redoute measures <code>2400 × 3600</code> rather than <code>3601 × 3601</code>. The <code>.hgt</code> format is 1″ in both directions by definition, so the conversion upsamples longitude back to 3600 columns: it adds no information and destroys none. Two things follow — the decimation factor <strong>changes with latitude band</strong>, so a converter that hard-codes 2400 is right in Belgium and wrong in Norway; and every <code>.hgt</code> tile is 24.7&nbsp;MB regardless of how much information it really carries.</figcaption></figure>

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
