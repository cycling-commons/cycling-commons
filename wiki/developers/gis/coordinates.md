<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# The Earth is awkward

Somewhere in Wallonia, a little east of Spa, there is a drinking-water fountain. It is an ordinary
physical object. You can ride up to it, fill a bottle, and carry on. It sits in exactly one place,
and that place does not move.

Somebody wants to put it on a map. So somebody has to write its position down.

That is where every problem in this series starts. The fountain itself is simple; turning "where it
is" into numbers a computer can store, compare, and draw is not. The moment a position becomes
numbers, the shape of the planet starts leaking into your code, and it keeps leaking for the next
nine chapters. This one covers the two numbers themselves: what they mean, how they behave, and why
they do not behave like the coordinates you already know.

## Latitude and longitude

A position on the Earth's surface is normally written as two angles, measured from the centre of
the planet.

**Latitude** is how far north or south you are. It runs from −90 at the South Pole, through 0 at the
**equator** — the circle exactly halfway between the poles — to +90 at the North Pole. A line
joining every point with the same latitude is called a **parallel**, because those lines never meet:
they are a stack of circles, each one smaller than the one below it, shrinking to nothing at each
pole.

**Longitude** is how far east or west you are. It runs from −180 to +180, with 0 at the **prime
meridian**, an arbitrary line through Greenwich in London that everybody agreed to use. A line
joining every point with the same longitude is called a **meridian**. Unlike parallels, meridians
are not parallel at all. Every one of them is half of a full circle running from the North Pole to
the South Pole, so all 360 of them meet at both ends.

Our fountain is at roughly **50.4894° N, 5.8792° E**. As a pair of signed numbers, that is
`50.4894, 5.8792` — positive latitude means north, positive longitude means east. A fountain in
Chile would have two negative numbers; one in Nairobi would have a small negative latitude and a
positive longitude.

Written out in a sentence, the units are degrees, and a degree divides further. You will still meet
the old sexagesimal notation — `50° 29' 21.8" N`, meaning 50 degrees, 29 minutes, 21.8 seconds — on
signposts, in aviation, and in some camera metadata. Nothing in this codebase uses it. Everything
here is decimal degrees, because that is what GPS receivers, GPX files, OpenStreetMap and GeoJSON
all hand over.

### The trap: which number comes first

This is the single most common bug in beginner GIS code, and it is worth burning in now, because it
will bite you at least once anyway.

**Humans say "lat, long". Most software wants longitude first.**

The reason is boring, which is exactly why it catches people out. Software treats a position as a
point on a plane, and on a plane the horizontal axis `x` conventionally comes before the vertical
axis `y`. Longitude is the east-west one, so longitude is `x`. Latitude is the north-south one, so
latitude is `y`. Hence `(x, y)` = `(longitude, latitude)`.

So:

- **GeoJSON** (chapter 2) is defined by its specification as `[longitude, latitude]`. Always.
- **PostGIS** constructors follow the same rule: `ST_Point(x, y)` means `ST_Point(lng, lat)`.
- **Most maths and geometry libraries** do too, because to them these are just numbers on a plane.

And on the other side:

- **Humans**, road signs, and every "what are your coordinates?" conversation say latitude first.
- **Some map libraries** take `[lat, lng]` — Leaflet is the best-known example. This project does
  not use Leaflet; its map is MapLibre GL JS, which is longitude-first like GeoJSON. But you will
  meet the other convention as soon as you read anybody else's map code.
- **Some of this repository's own data** is written latitude-first, in human order, because a person
  typed it. In `web/src/Catalog/Command/SeedManualCatalogCommand.php` each catalog entry carries
  explicit `'lat'` and `'lng'` keys, and the route paths beside them are arrays of `[lat, lng]`
  pairs.

You can see the flip happen at a real boundary in this codebase. `SpatialResolver` answers "which
region contains this point?" — see `web/src/Contribution/SpatialResolver.php`,
`SpatialResolver::resolve()`. Its PHP signature takes `(float $lat, float $lng)`, in human order,
because that is how the calling code thinks. The SQL it builds a few lines later contains:

```sql
ST_Contains(geom, ST_SetSRID(ST_Point(:lng, :lat), 4326))
```

`:lng` before `:lat`. The method is the seam where human order becomes machine order, and it is
deliberate. Open the file: the whole class is under forty lines, and the seam is visible at a
glance.

The same flip happens on the front end. In `web/assets/map/map.js`, `setCircleSpotlight()` receives
a `center` in human order — it reads the latitude out as `center[0]` — and then builds the ring it
hands to MapLibre as `[lng, lat]` pairs, because that is what GeoJSON requires. One function, both
conventions, a few lines apart.

!!! warning "How this bug shows up"
    Swapping the two numbers rarely throws an error, because both are plain floats and both are
    plausible. `50.4894, 5.8792` reversed is `5.8792, 50.4894` — a perfectly valid position in the
    Indian Ocean off the coast of Somalia, about 6,400 km away. Your code runs, your query returns
    zero rows, and nothing tells you why. If a spatial query mysteriously finds nothing, check the
    argument order before you check anything else. Latitude can never exceed 90, so any number above
    90 in the latitude slot is a free giveaway — but only when the longitude is large enough for the
    swap to produce one.

## A degree is not a distance

Here is the second thing that trips people up, and the one that produces wrong answers rather than
empty ones.

A degree of latitude and a degree of longitude are both "one degree", but they do not cover the same
amount of ground, and only one of them is even constant.

**One degree of latitude is about 111 km, everywhere.** Going one degree north always means
travelling along a meridian, every meridian is the same size circle, and equal angles on equal
circles cut equal arcs. The Earth's pole-to-pole circumference is about 40,008 km; divide by 360 and
you get 111.1 km. That number holds in Belgium, in Kenya, and in Antarctica.

**One degree of longitude is about 111 km at the equator, and shrinks from there.** Going one degree
east means travelling along a parallel — and parallels are not all the same size. The equator is a
full-size circle around the planet, about 40,075 km, so one degree of it is 111.3 km. The parallel
at 50° north is a much smaller circle, because it is a slice taken near the top of the sphere. All
360 degrees of longitude still have to fit around that smaller circle, so each degree is shorter:

```text
one degree of longitude ≈ 111.32 km × cos(latitude)
```

At the equator, `cos(0°) = 1`, so you get the full 111.3 km. At 50° north, `cos(50°) ≈ 0.643`, so
you get about 71 km. At our fountain's latitude of 50.4894°, about 70.8 km. At 70° north, about
38 km. At the pole itself, `cos(90°) = 0`: all 360 degrees of longitude collapse into a single
point, and "one degree east" means standing still.

The figure below shows why. The strip between two meridians is the same number of degrees wide all
the way from pole to pole, but the ground it covers narrows the whole way up.

<figure class="gis-fig"><svg viewBox="0 0 640 380" role="img" aria-labelledby="f1-t f1-d" xmlns="http://www.w3.org/2000/svg"><title id="f1-t">One degree of longitude at the equator and at 50 degrees north</title><desc id="f1-d">A globe seen from the side. Meridians, the lines of constant longitude, are drawn as curves that spread apart at the equator and converge to a single point at each pole. The strip of surface between two neighbouring meridians is tinted from pole to pole; it is at its widest on the equator and narrows steadily toward the top and bottom of the globe. On the equator that strip is marked as about 111 kilometres of ground; on the 50 degrees north parallel the very same strip is marked as about 71 kilometres. Beside the globe, three bars compare the three distances at the same scale: one degree of latitude is 111 kilometres at any latitude; one degree of longitude is 111 kilometres at the equator; one degree of longitude at 50 degrees north is only 71 kilometres, about 36 per cent shorter.</desc><path class="gis-fill-ochre" fill-opacity=".22" d="M 200 65 A 36.23 140 0 0 1 200 345 Z"/><line class="gis-muted" x1="163.77" y1="69.77" x2="236.23" y2="69.77"/><line class="gis-muted" x1="73.12" y1="145.83" x2="326.88" y2="145.83"/><line class="gis-muted" x1="73.12" y1="264.17" x2="326.88" y2="264.17"/><line class="gis-muted" x1="110.01" y1="312.25" x2="289.99" y2="312.25"/><line class="gis-muted" x1="163.77" y1="340.23" x2="236.23" y2="340.23"/><line class="gis-muted" x1="200" y1="65" x2="200" y2="345"/><path class="gis-muted" d="M 200 65 A 36.23 140 0 0 1 200 345"/><path class="gis-muted" d="M 200 65 A 36.23 140 0 0 0 200 345"/><path class="gis-muted" d="M 200 65 A 70 140 0 0 1 200 345"/><path class="gis-muted" d="M 200 65 A 70 140 0 0 0 200 345"/><path class="gis-muted" d="M 200 65 A 98.99 140 0 0 1 200 345"/><path class="gis-muted" d="M 200 65 A 98.99 140 0 0 0 200 345"/><path class="gis-muted" d="M 200 65 A 121.24 140 0 0 1 200 345"/><path class="gis-muted" d="M 200 65 A 121.24 140 0 0 0 200 345"/><path class="gis-muted" d="M 200 65 A 135.23 140 0 0 1 200 345"/><path class="gis-muted" d="M 200 65 A 135.23 140 0 0 0 200 345"/><circle class="gis-ink" cx="200" cy="205" r="140"/><line class="gis-ink" x1="60" y1="205" x2="340" y2="205"/><line class="gis-ink" x1="110.01" y1="97.75" x2="289.99" y2="97.75"/><line class="gis-accent" stroke-width="5" x1="200" y1="205" x2="236.23" y2="205"/><line class="gis-accent" x1="200" y1="198" x2="200" y2="212"/><line class="gis-accent" x1="236.23" y1="198" x2="236.23" y2="212"/><line class="gis-accent" stroke-width="5" x1="200" y1="97.75" x2="223.29" y2="97.75"/><line class="gis-accent" x1="200" y1="90.75" x2="200" y2="104.75"/><line class="gis-accent" x1="223.29" y1="90.75" x2="223.29" y2="104.75"/><text class="gis-label-sm" x="200" y="50" text-anchor="middle">the meridians meet at the poles</text><text class="gis-label-sm" x="54" y="199" text-anchor="end">equator</text><text class="gis-label-sm" x="54" y="213" text-anchor="end">0°</text><text class="gis-label-sm" x="104" y="101" text-anchor="end">50° N</text><text class="gis-halo" x="242" y="201">111 km</text><text class="gis-halo" x="229" y="93">71 km</text><text x="380" y="76">The same one degree, measured</text><text class="gis-label-sm" x="380" y="112">1° of latitude — at any latitude</text><rect class="gis-fill-spruce" x="380" y="118" width="190" height="13"/><text x="578" y="129">111 km</text><text class="gis-label-sm" x="380" y="172">1° of longitude — at the equator</text><rect class="gis-fill-accent" x="380" y="178" width="190" height="13"/><text x="578" y="189">111 km</text><text class="gis-label-sm" x="380" y="232">1° of longitude — at 50° N</text><rect class="gis-fill-accent" x="380" y="238" width="121.5" height="13"/><text x="509.5" y="249">71 km</text><line class="gis-muted" stroke-dasharray="3 4" x1="570" y1="112" x2="570" y2="262"/><text class="gis-label-sm" x="540" y="268" text-anchor="middle">36% shorter</text></svg><figcaption>Meridians are furthest apart on the equator and meet at the poles, so the ground covered by one degree of longitude shrinks as you move away from the equator. The tinted strip is drawn far wider than a single degree so that it is visible at all; the <em>ratio</em> between the two marked spans is exact, and equals cos 50° ≈ 0.64.</figcaption></figure>

The immediate consequence is that a "0.1 degree box" is not a fixed size. Near the equator it is
about 11 km by 11 km. At our fountain it is about 11.1 km tall and 7.1 km wide. In northern Norway,
at 70°, it is about 11.1 km tall and 3.8 km wide — the same box in the code, a third of the ground.

This has a direct effect on code you will be tempted to write. A query like this looks like it asks
for everything within about 5 km:

```sql
-- Wrong, and wrong by a different amount depending on where you run it.
WHERE lat BETWEEN :lat - 0.045 AND :lat + 0.045
  AND lng BETWEEN :lng - 0.045 AND :lng + 0.045
```

It does not. It asks for a rectangle roughly 10 km tall and, at our fountain, about 6.4 km wide —
and if the same query runs for a rider in Norway, that rectangle is about 3.4 km wide instead. The
same numbers, the same code, a different question depending on latitude.

For the same reason, you cannot take two positions in degrees, apply Pythagoras, and call the result
a distance. The two axes are not in the same units as each other, and the longitude axis is not even
in consistent units with itself.

Where this project genuinely does need a rough distance in degrees, it applies the `cos(latitude)`
correction on purpose rather than hoping it does not matter. `setCircleSpotlight()` in
`web/assets/map/map.js` draws the "my area" circle on the map, and to do that it converts a radius
in kilometres into a step in degrees: the latitude step is the radius over 111.32, and the longitude
step is the radius over 111.32 times the cosine of the latitude. It also clamps that cosine to a
small minimum, because near the poles the cosine goes to zero and the longitude step would go to
infinity. Every idea in this section is in those four lines.

Chapter 3 is entirely about how to ask for a real distance instead — the proper way, in the database,
without hand-rolled trigonometry. For now, the thing to carry forward is smaller and simpler:
**degrees are angles, not lengths.**

## Why maps lie

The fountain is on a curved surface. Your screen is flat. Somewhere between the two, something has
to give.

A **projection** is a rule for turning a position on the curved Earth into a position on a flat
plane. Every map you have ever looked at applied one, whether or not it said so.

There is no perfect projection, and that is not a software problem waiting for a better algorithm.
It is a proved fact about geometry: a sphere and a plane have genuinely different curvature, so no
rule can flatten one onto the other without stretching, tearing, or both. The everyday version of
this is peeling an orange and trying to press the peel flat — it splits, or it stretches, and you
get to choose which.

So every projection distorts at least one of four things:

| Property | What it means | Kept by |
|---|---|---|
| **Area** | Two regions that are equally big really look equally big | equal-area projections |
| **Shape** | Angles are locally correct, so small shapes are not skewed | conformal projections |
| **Distance** | Measured lengths match reality | only along particular lines, never everywhere |
| **Direction** | A bearing on the map is a bearing on the ground | some, at a cost elsewhere |

You do not get to keep all four. You pick the lie you can live with, for the job you are doing.

Two of those choices matter here.

**Plotting latitude and longitude straight onto the page**, as if latitude were `y` and longitude
were `x`, is itself a projection. It has a name — plate carrée, or the equirectangular projection —
and it is the one people apply by accident, because it looks like no projection has been applied at
all. It is easy and it is fine for a rough sketch, but it stretches everything east-west as you move
away from the equator, by exactly the `1 / cos(latitude)` factor from the previous section.

**Web Mercator** is the projection tiled web maps use, and it is conformal: it keeps shapes and
angles locally correct, so a town looks like the right shape and a right-angled junction still looks
like a right angle. It pays for that by getting area badly wrong. Away from the equator it stretches
north-south by the *same* factor it stretches east-west, which is why the shapes survive — and why
the areas balloon by that factor squared. Web maps use it anyway, because north is always up, a tile
stays square at every zoom level, and a constant compass bearing is a straight line on the map. For
a slippy map you pan and zoom, that is worth more than honest area.

The usual demonstration is Greenland. On a Web Mercator map it looks about the size of Africa.
Africa is roughly fourteen times larger.

<figure class="gis-fig"><svg viewBox="0 0 560 366" role="img" aria-labelledby="f2-t f2-d" xmlns="http://www.w3.org/2000/svg"><title id="f2-t">The same two landmasses drawn in EPSG:4326 and in EPSG:3857</title><desc id="f2-d">Two maps of the North Atlantic side by side, covering the same range of longitude and the same range of latitude, drawn at the same horizontal scale. The left map plots degrees straight onto the page: its parallels at 40, 50, 60, 70 and 80 degrees north are evenly spaced, and the whole map is short and wide. Greenland appears roughly eleven times the area of France. The right map uses Web Mercator: the same parallels spread further and further apart toward the top, so the map is nearly three times as tall, and the band between 60 and 80 degrees north is more than three times its height on the left. Greenland is enormously inflated and appears roughly thirty times the area of France. In reality Greenland is about four times the area of France.</desc><text x="55" y="222">EPSG:4326 — degrees plotted straight</text><line class="gis-muted" x1="55" y1="311" x2="217" y2="311"/><line class="gis-muted" x1="55" y1="293" x2="217" y2="293"/><line class="gis-muted" x1="55" y1="275" x2="217" y2="275"/><line class="gis-muted" x1="55" y1="257" x2="217" y2="257"/><line class="gis-muted" x1="55" y1="239" x2="217" y2="239"/><line class="gis-muted" x1="82" y1="230" x2="82" y2="320"/><line class="gis-muted" x1="118" y1="230" x2="118" y2="320"/><line class="gis-muted" x1="154" y1="230" x2="154" y2="320"/><line class="gis-muted" x1="190" y1="230" x2="190" y2="320"/><path class="gis-ink gis-fill-paper" d="M 129.7 232.52 L 145 235.04 L 154.9 238.64 L 156.7 243.5 L 152.2 248.9 L 149.5 254.3 L 143.2 258.8 L 133.3 262.4 L 121.6 266.9 L 110.98 275.36 L 102.7 273.56 L 98.2 269.24 L 94.96 264.56 L 93.16 260.24 L 90.1 256.1 L 86.5 253.04 L 80.2 249.8 L 70.3 246.2 L 59.5 242.96 L 67.6 239.36 L 83.8 236.66 L 107.2 234.14 Z"/><path class="gis-ink gis-fill-accent" d="M 181.36 295.88 L 187.3 293.9 L 192.88 291.2 L 198.82 293.36 L 203.68 294.98 L 202.96 297.5 L 202.42 300.2 L 202.6 303.26 L 199.9 305.24 L 195.4 305.6 L 195.76 306.68 L 186.76 305.06 L 187.84 301.1 L 186.22 298.4 Z"/><rect class="gis-fill-ochre" fill-opacity=".22" x="55" y="239" width="162" height="36"/><rect class="gis-ink" fill="none" x="55" y="230" width="162" height="90"/><text class="gis-label-sm" x="50" y="314.5" text-anchor="end">40°</text><text class="gis-label-sm" x="50" y="296.5" text-anchor="end">50°</text><text class="gis-label-sm" x="50" y="278.5" text-anchor="end">60°</text><text class="gis-label-sm" x="50" y="260.5" text-anchor="end">70°</text><text class="gis-label-sm" x="50" y="242.5" text-anchor="end">80°</text><text class="gis-halo" x="120" y="257" text-anchor="middle">Greenland</text><text class="gis-label-sm gis-halo" x="213" y="261" text-anchor="end">60°–80° N</text><path class="gis-muted" d="M 204 298 L 224 292"/><text class="gis-label-sm" x="228" y="295">France</text><text class="gis-label-sm" x="136" y="338" text-anchor="middle">Greenland looks ≈ 11× France</text><text x="340" y="56">EPSG:3857 — Web Mercator</text><line class="gis-muted" x1="340" y1="308.65" x2="502" y2="308.65"/><line class="gis-muted" x1="340" y1="283.09" x2="502" y2="283.09"/><line class="gis-muted" x1="340" y1="251.51" x2="502" y2="251.51"/><line class="gis-muted" x1="340" y1="208.35" x2="502" y2="208.35"/><line class="gis-muted" x1="340" y1="136.07" x2="502" y2="136.07"/><line class="gis-muted" x1="367" y1="64.39" x2="367" y2="320"/><line class="gis-muted" x1="403" y1="64.39" x2="403" y2="320"/><line class="gis-muted" x1="439" y1="64.39" x2="439" y2="320"/><line class="gis-muted" x1="475" y1="64.39" x2="475" y2="320"/><path class="gis-ink gis-fill-paper" d="M 414.7 89.89 L 430 110.35 L 439.9 133.98 L 441.7 159.23 L 437.2 181.64 L 434.5 200.16 L 428.2 213.49 L 418.3 223.11 L 406.6 234.07 L 395.98 252.23 L 387.7 248.59 L 383.2 239.38 L 379.96 228.5 L 378.16 217.44 L 375.1 205.69 L 371.5 196.11 L 365.2 184.96 L 355.3 171.03 L 344.5 156.71 L 352.6 138.13 L 368.8 121.65 L 392.2 103.49 Z"/><path class="gis-ink gis-fill-accent" d="M 466.36 287.5 L 472.3 284.49 L 477.88 280.26 L 483.82 283.65 L 488.68 286.14 L 487.96 289.92 L 487.42 293.86 L 487.6 298.2 L 484.9 300.94 L 480.4 301.44 L 480.76 302.91 L 471.76 300.7 L 472.84 295.15 L 471.22 291.25 Z"/><rect class="gis-fill-ochre" fill-opacity=".22" x="340" y="136.07" width="162" height="115.44"/><rect class="gis-ink" fill="none" x="340" y="64.39" width="162" height="255.61"/><text class="gis-label-sm" x="335" y="312.15" text-anchor="end">40°</text><text class="gis-label-sm" x="335" y="286.59" text-anchor="end">50°</text><text class="gis-label-sm" x="335" y="255.01" text-anchor="end">60°</text><text class="gis-label-sm" x="335" y="211.85" text-anchor="end">70°</text><text class="gis-label-sm" x="335" y="139.57" text-anchor="end">80°</text><text class="gis-halo" x="395" y="175" text-anchor="middle">Greenland</text><text class="gis-label-sm gis-halo" x="498" y="197" text-anchor="end">60°–80° N</text><path class="gis-muted" d="M 489 291 L 510 285"/><text class="gis-label-sm" x="514" y="288">France</text><text class="gis-label-sm" x="421" y="338" text-anchor="middle">Greenland looks ≈ 30× France</text><text x="280" y="358" text-anchor="middle">In reality, Greenland is about 4× France.</text></svg><figcaption>Both maps cover the same longitudes and the same latitudes, at the same horizontal scale, so every difference you see is what the projection did to the north-south axis. On the left the parallels are evenly spaced; on the right they spread apart the further north they go, which is what stretches Greenland. The tinted band is 60°–80° N in both: on the right it is more than three times as tall. Neither map is “wrong”. They distort different things, and that is exactly why the numbers cannot travel on their own — something has to say which system they are in.</figcaption></figure>

## SRIDs — naming the system

`50.4894, 5.8792` is not a place. It is two numbers. It only becomes a place once you also know
which coordinate system it belongs to — which model of the Earth's shape, which starting lines,
which units.

An **SRID** — Spatial Reference IDentifier — is an integer that names that system. PostGIS stores an
SRID alongside every geometry it holds, so a value in the database always carries its own answer to
"what do these numbers mean?".

Most SRIDs are simply the identifiers from the **EPSG registry**, a long-running public catalogue of
coordinate systems, each with its own number. When you see `EPSG:4326` written in documentation and
`4326` written in SQL, they are the same thing.

Two of them come up constantly.

**EPSG:4326 — WGS84, in degrees.** Latitude and longitude as described at the top of this page,
measured against WGS84, a specific agreed model of the Earth's shape. This is what a GPS receiver
gives you, what a GPX file from a bike computer contains, what OpenStreetMap stores, and what
GeoJSON is defined to use. The units are degrees.

**EPSG:3857 — Web Mercator, in metres.** The projected plane that tiled web maps are drawn on. The
units are called metres, and near the equator they behave like metres, but they are not ground
distances. Web Mercator stretches by `1 / cos(latitude)`, so at 60° north one projected metre
corresponds to roughly half a metre of actual ground. Measuring in EPSG:3857 and reporting the
answer in metres is a real and popular bug.

Web Mercator also cannot represent the poles at all: the formula sends the projected `y` value off
to infinity as latitude approaches 90. The convention is to cut the world off at ±85.05112878°,
which is not an arbitrary number — it is precisely the latitude at which the projected height equals
the projected width, making the whole world a square. That squareness is what lets the tile scheme
in chapter 7 divide the world into four, then four again, forever.

Two more things are worth knowing before you write any SQL.

First, PostGIS refuses to compare geometries with different SRIDs. That looks like an obstacle the
first time you hit it, and it is actually the system saving you from a silently wrong answer.

Second, and easy to confuse: `ST_SetSRID` and `ST_Transform` are not the same operation.
`ST_SetSRID` only *labels* a geometry — it changes the stated system and leaves every number
untouched. `ST_Transform` genuinely *converts*, recomputing the numbers from one system into
another. Using the first where you needed the second gives you a geometry that claims to be
somewhere it is not, with no error anywhere. Nothing in this repository calls `ST_Transform`,
because nothing here ever needs to leave 4326 — which is the subject of the last section.

## What we store

Every geometry column in this project is declared the same way:

```sql
geometry(Geometry, 4326)
```

That text comes from one place, so no table can quietly get a different one. See
`web/src/Catalog/Doctrine/GeometryType.php`, `GeometryType::getSQLDeclaration()` — a Doctrine custom
type whose entire job is to say what a geometry column looks like in SQL. Every migration that has
ever created a geometry column here carries that identical string, `region.geom` and `item.geom` and
`recommended_route.geom` and the rest.

The declaration has two halves:

- **`Geometry`** is the shape type: which kind of shape the column may hold. `Geometry` is the
  permissive option, meaning any of them — a point, a line, an outline. Chapter 2 covers what those
  shapes are, and which of them each table actually holds.
- **`4326`** is the SRID. Degrees of latitude and longitude, in WGS84.

**Why 4326 and not something else.** Everything that feeds this system already speaks it. GPS
devices produce it, the GPX files riders upload contain it, OpenStreetMap publishes it, and GeoJSON
— the format geometries travel in on the way to and from PHP — is defined in terms of it. Storing
anything else would mean converting on the way in *and* converting back on the way out, on every
single row, with a fresh chance to get it wrong in each direction. Storing what the world hands you
means the conversion count is zero.

The same class shows the boundary in action. `GeometryType::convertToDatabaseValueSQL()` wraps every
write in `ST_SetSRID(ST_GeomFromGeoJSON(…), 4326)`, and `GeometryType::convertToPHPValueSQL()` wraps
every read in `ST_AsGeoJSON(…)`. Note which function that is on the write side: `ST_SetSRID`, not
`ST_Transform`. There is nothing to convert, because GeoJSON is already defined to be in this
system; the call is there to stamp the label explicitly rather than rely on a default. Chapter 2
picks that round-trip up and explains the GeoJSON half of it.

**So where does 3857 appear?** Only at the very end, on the way out to the browser, in the tile
build. `pipeline/coverage/tiles.py` contains the Web Mercator maths — `_tile_y()`, which clamps a
latitude to that same ±85.05112878 limit and works out which tile row the point falls in. That is
the one place this project leaves 4326, and even there it is a calculation about tile addressing,
not a change to anything stored. The database never holds a projected coordinate. Chapter 7 covers
the tile pyramid that maths belongs to.

## What to carry into chapter 2

- Latitude is north-south, −90 to 90. Longitude is east-west, −180 to 180.
- Humans say latitude first. GeoJSON, PostGIS and most libraries want longitude first.
- A degree of latitude is about 111 km anywhere. A degree of longitude is about 111 km at the
  equator and shrinks by `cos(latitude)` from there.
- Degrees are angles. They are not a unit of distance, and you cannot do arithmetic on them as if
  they were.
- Flattening the Earth always costs you something. Every map has chosen which error to accept.
- Numbers without an SRID are meaningless. In this codebase the SRID is always 4326, everywhere it
  is stored.

The fountain now has a position and a coordinate system to interpret it in. Next it needs a shape —
because the same column type that holds this single point also has to hold a rider's whole route and
the outline of Wallonia.

<!-- EXERCISE-SLOT ch=1 — hands-on box goes here (spec D5); do not remove -->
