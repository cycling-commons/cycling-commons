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
(the JSON text format geographic data travels in — chapter 2 is largely about it) all hand over.

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
- **PostGIS** — the spatial extension to PostgreSQL, which is what turns this project's ordinary
  database into one that can store and query shapes on the Earth — follows the same rule in its
  constructors: `ST_Point(x, y)` means `ST_Point(lng, lat)`. Every `ST_`-prefixed function in this
  chapter and the rest of the series is PostGIS, not stock PostgreSQL.
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

Here is one such entry, exactly as a person typed it:

<!-- CODE-FROM web/src/Catalog/Command/SeedManualCatalogCommand.php -->
```php
'letter' => 'B', 'name' => 'Côte de la Redoute', 'lat' => 50.49222, 'lng' => 5.69924,
```

and here is that same pin, a few dozen lines later in the same file, on its way into a geometry:

<!-- CODE-FROM web/src/Catalog/Command/SeedManualCatalogCommand.php -->
```php
'geom' => json_encode(['type' => 'Point', 'coordinates' => [$pin['lng'], $pin['lat']]], \JSON_THROW_ON_ERROR),
```

Human order in, longitude-first `coordinates` out — the same flip, inside a single file, that the
next two examples show at the seam of a request and the seam of a map.

You can see the flip happen at a real boundary in this codebase. `SpatialResolver` answers "which
region contains this point?" — see `web/src/Contribution/SpatialResolver.php`,
`SpatialResolver::resolve()`. Its signature takes latitude before longitude, in human order, because
that is how the calling code thinks:

<!-- CODE-FROM web/src/Contribution/SpatialResolver.php -->
```php
public function resolve(float $lat, float $lng): array
```

The SQL it builds a few lines later flips the order:

<!-- CODE-FROM web/src/Contribution/SpatialResolver.php -->
```sql
ST_Contains(geom, ST_SetSRID(ST_Point(:lng, :lat), 4326))
```

`:lng` before `:lat`. The method is the seam where human order becomes machine order, and it is
deliberate. Open the file: the whole class is under forty lines, and the seam is visible at a
glance.

The same flip happens on the front end. In `web/assets/map/spotlight.js`, `setCircleSpotlight()` receives
a `center` in human order and reads the latitude straight out of it:

<!-- CODE-FROM web/assets/map/spotlight.js -->
```js
const n=64, lat=center[0];
```

A few lines later, the same function builds the ring it hands to MapLibre the other way round,
`[lng, lat]`, because that is what GeoJSON requires:

<!-- CODE-FROM web/assets/map/spotlight.js -->
```js
ring.push([center[1]+dLng*Math.cos(a), lat+dLat*Math.sin(a)]);
```

One function, both conventions, a few lines apart.

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

<!-- CODE-ILLUSTRATIVE formula, hand-written -->
```text
one degree of longitude ≈ 111.32 km × cos(latitude)
```

At the equator, `cos(0°) = 1`, so you get the full 111.3 km. At 50° north, `cos(50°) ≈ 0.643`, so
you get about 71 km. At our fountain's latitude of 50.4894°, about 70.8 km. At 70° north, about
38 km. At the pole itself, `cos(90°) = 0`: all 360 degrees of longitude collapse into a single
point, and "one degree east" means standing still.

The figure below shows why. The strip between two meridians is the same number of degrees wide all
the way from pole to pole, but the ground it covers narrows the whole way up.

<figure class="gis-fig"><svg viewBox="0 0 640 710" role="img" aria-labelledby="f1-t f1-d" xmlns="http://www.w3.org/2000/svg"><title id="f1-t">One degree of longitude at the equator and at 50 degrees north</title><desc id="f1-d">A globe seen from the side. Meridians, the lines of constant longitude, are drawn as curves that spread apart at the equator and converge to a single point at each pole. The strip of surface between two neighbouring meridians is tinted from pole to pole; it is at its widest on the equator and narrows steadily toward the top and bottom of the globe. On the equator that strip is marked as about 111 kilometres of ground for one degree; on the 50 degrees north parallel the very same strip is marked as about 71 kilometres for one degree. Below the globe, three bars compare the three distances at the same scale: one degree of latitude is 111 kilometres at any latitude; one degree of longitude is 111 kilometres at the equator; one degree of longitude at 50 degrees north is only 71 kilometres, and a double-headed arrow marks the missing length as 36 per cent shorter.</desc><defs><marker id="gis-arrow-f1" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs><path class="gis-fill-ochre" fill-opacity=".22" d="M 250 70 A 36.23 140 0 0 1 250 350 Z"/><line class="gis-muted" x1="202.1" y1="78.4" x2="297.9" y2="78.4"/><line class="gis-muted" x1="142.75" y1="120" x2="357.25" y2="120"/><line class="gis-muted" x1="118.4" y1="162.1" x2="381.6" y2="162.1"/><line class="gis-muted" x1="118.4" y1="257.9" x2="381.6" y2="257.9"/><line class="gis-muted" x1="142.75" y1="300" x2="357.25" y2="300"/><line class="gis-muted" x1="202.1" y1="341.6" x2="297.9" y2="341.6"/><line class="gis-muted" x1="250" y1="70" x2="250" y2="350"/><path class="gis-muted" d="M 250 70 A 36.23 140 0 0 1 250 350"/><path class="gis-muted" d="M 250 70 A 36.23 140 0 0 0 250 350"/><path class="gis-muted" d="M 250 70 A 70 140 0 0 1 250 350"/><path class="gis-muted" d="M 250 70 A 70 140 0 0 0 250 350"/><path class="gis-muted" d="M 250 70 A 98.99 140 0 0 1 250 350"/><path class="gis-muted" d="M 250 70 A 98.99 140 0 0 0 250 350"/><path class="gis-muted" d="M 250 70 A 121.24 140 0 0 1 250 350"/><path class="gis-muted" d="M 250 70 A 121.24 140 0 0 0 250 350"/><path class="gis-muted" d="M 250 70 A 135.23 140 0 0 1 250 350"/><path class="gis-muted" d="M 250 70 A 135.23 140 0 0 0 250 350"/><circle class="gis-ink" cx="250" cy="210" r="140"/><line class="gis-ink" x1="110" y1="210" x2="390" y2="210"/><line class="gis-ink" x1="160" y1="102.75" x2="340" y2="102.75"/><line class="gis-accent" stroke-width="6" x1="250" y1="210" x2="286.23" y2="210"/><line class="gis-accent" x1="250" y1="199" x2="250" y2="221"/><line class="gis-accent" x1="286.23" y1="199" x2="286.23" y2="221"/><line class="gis-accent" stroke-width="6" x1="250" y1="102.75" x2="273.29" y2="102.75"/><line class="gis-accent" x1="250" y1="91.75" x2="250" y2="113.75"/><line class="gis-accent" x1="273.29" y1="91.75" x2="273.29" y2="113.75"/><text class="gis-label-sm" x="250" y="44" text-anchor="middle">meridians meet at the poles</text><text class="gis-label-sm" x="104" y="202" text-anchor="end">equator</text><text class="gis-label-sm" x="104" y="232" text-anchor="end">0°</text><text class="gis-label-sm gis-halo" x="146" y="110" text-anchor="end">50° N</text><text class="gis-halo" x="294" y="202">1° ≈ 111 km</text><text class="gis-halo" x="288" y="94">1° ≈ 71 km</text><text x="20" y="400">The same one degree, measured</text><text class="gis-label-sm" x="20" y="448">1° of latitude — at any latitude</text><rect class="gis-fill-spruce" x="20" y="458" width="480" height="26"/><text class="gis-label-sm" x="512" y="479">111 km</text><text class="gis-label-sm" x="20" y="528">1° of longitude — at the equator</text><rect class="gis-fill-accent" x="20" y="538" width="480" height="26"/><text class="gis-label-sm" x="512" y="559">111 km</text><text class="gis-label-sm" x="20" y="608">1° of longitude — at 50° N</text><rect class="gis-fill-accent" x="20" y="618" width="307" height="26"/><text class="gis-label-sm" x="339" y="639">71 km</text><line class="gis-muted" stroke-dasharray="4 5" x1="500" y1="458" x2="500" y2="672"/><line class="gis-muted" stroke-dasharray="4 5" x1="327" y1="644" x2="327" y2="672"/><line class="gis-ink" x1="333" y1="666" x2="494" y2="666" marker-start="url(#gis-arrow-f1)" marker-end="url(#gis-arrow-f1)"/><text class="gis-label-sm" x="413" y="700" text-anchor="middle">36% shorter</text></svg><figcaption>Meridians are furthest apart on the equator and meet at the poles, so the ground covered by one degree of longitude shrinks as you move away from the equator. A degree of latitude does not: the bars are measured against the same scale. That is why a “0.1 degree box” is not a fixed patch of ground — the same two numbers in the code enclose a different amount of the world depending on where you are. The tinted strip is drawn far wider than a single degree so that it is visible at all; the <em>ratio</em> between the two marked spans is exact, and equals cos 50° ≈ 0.64.</figcaption></figure>

The immediate consequence is that a "0.1 degree box" is not a fixed size. Near the equator it is
about 11 km by 11 km. At our fountain it is about 11.1 km tall and 7.1 km wide. In northern Norway,
at 70°, it is about 11.1 km tall and 3.8 km wide — the same box in the code, a third of the ground.

This has a direct effect on code you will be tempted to write. A query like this looks like it asks
for everything within about 5 km:

<!-- CODE-ILLUSTRATIVE naive fixed-degree bounding box, not our code -->
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
`web/assets/map/spotlight.js` draws the "my area" circle on the map, and to do that it converts a radius
in kilometres into a step in degrees:

<!-- CODE-FROM web/assets/map/spotlight.js -->
```js
const cosLat=Math.max(0.01, Math.cos(lat*Math.PI/180));
const dLat = rkm/111.32, dLng = rkm/(111.32*cosLat);
```

The latitude step is the radius over 111.32; the longitude step is the radius over 111.32 times the
cosine of the latitude. The line above it clamps that cosine to a small minimum, because near the
poles the cosine goes to zero and the longitude step would go to infinity. Every idea in this
section is in those two lines.

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
a **slippy map** — the pan-and-drag, zoom-with-the-wheel kind every web map is now, as opposed to a
fixed picture — that is worth more than honest area.

The usual demonstration is Greenland. On a Web Mercator map it looks about the size of Africa.
Africa is roughly fourteen times larger.

<figure class="gis-fig"><svg viewBox="0 0 640 640" role="img" aria-labelledby="f2-t f2-d" xmlns="http://www.w3.org/2000/svg"><title id="f2-t">The same two landmasses drawn in EPSG:4326 and in EPSG:3857</title><desc id="f2-d">Two maps of the North Atlantic side by side, covering the same range of longitude and the same range of latitude, drawn at the same horizontal scale. The left map, labelled EPSG:4326, plots degrees straight onto the page: its parallels at 40, 60 and 80 degrees north are evenly spaced, and the whole map is short and wide. Greenland appears roughly eleven times the area of France. The right map, labelled EPSG:3857, uses Web Mercator: the same parallels spread further and further apart toward the top, so the map is nearly three times as tall, and the tinted band between 60 and 80 degrees north is more than three times its height on the left. Greenland is enormously inflated and appears roughly thirty times the area of France. In reality Greenland is about four times the area of France. A key below the maps identifies the tinted band, the pale outlined shape as Greenland, and the small orange shape as France.</desc><text class="gis-label-mono" x="52" y="296">EPSG:4326</text><text class="gis-label-sm" x="52" y="326">degrees plotted flat</text><line class="gis-muted" x1="52" y1="455.4" x2="292" y2="455.4"/><line class="gis-muted" x1="52" y1="428.7" x2="292" y2="428.7"/><line class="gis-muted" x1="52" y1="402" x2="292" y2="402"/><line class="gis-muted" x1="52" y1="375.4" x2="292" y2="375.4"/><line class="gis-muted" x1="52" y1="348.7" x2="292" y2="348.7"/><line class="gis-muted" x1="92" y1="335.4" x2="92" y2="468.7"/><line class="gis-muted" x1="145.3" y1="335.4" x2="145.3" y2="468.7"/><line class="gis-muted" x1="198.7" y1="335.4" x2="198.7" y2="468.7"/><line class="gis-muted" x1="252" y1="335.4" x2="252" y2="468.7"/><rect class="gis-fill-ochre" fill-opacity=".22" x="52" y="348.7" width="240" height="53.3"/><path class="gis-ink gis-fill-paper" d="M 162.7 339.1 L 185.3 342.8 L 200.0 348.2 L 202.7 355.4 L 196.0 363.4 L 192.0 371.4 L 182.7 378.0 L 168.0 383.4 L 150.7 390.0 L 134.9 402.6 L 122.7 399.9 L 116.0 393.5 L 111.2 386.6 L 108.5 380.2 L 104.0 374.0 L 98.7 369.5 L 89.3 364.7 L 74.7 359.4 L 58.7 354.6 L 70.7 349.2 L 94.7 345.2 L 129.3 341.5 Z"/><path class="gis-ink gis-fill-accent" d="M 239.2 433.0 L 248.0 430.0 L 256.3 426.0 L 265.1 429.2 L 272.3 431.6 L 271.2 435.4 L 270.4 439.4 L 270.7 443.9 L 266.7 446.8 L 260.0 447.4 L 260.5 449.0 L 247.2 446.6 L 248.8 440.7 L 246.4 436.7 Z"/><rect class="gis-ink" x="52" y="335.4" width="240" height="133.3"/><text class="gis-label-sm" x="44" y="463.4" text-anchor="end">40°</text><text class="gis-label-sm" x="44" y="410" text-anchor="end">60°</text><text class="gis-label-sm" x="44" y="356.7" text-anchor="end">80°</text><text class="gis-label-mono" x="392" y="50">EPSG:3857</text><text class="gis-label-sm" x="392" y="80">Web Mercator</text><line class="gis-muted" x1="392" y1="451.9" x2="632" y2="451.9"/><line class="gis-muted" x1="392" y1="414" x2="632" y2="414"/><line class="gis-muted" x1="392" y1="367.2" x2="632" y2="367.2"/><line class="gis-muted" x1="392" y1="303.3" x2="632" y2="303.3"/><line class="gis-muted" x1="392" y1="196.2" x2="632" y2="196.2"/><line class="gis-muted" x1="432" y1="90" x2="432" y2="468.7"/><line class="gis-muted" x1="485.3" y1="90" x2="485.3" y2="468.7"/><line class="gis-muted" x1="538.7" y1="90" x2="538.7" y2="468.7"/><line class="gis-muted" x1="592" y1="90" x2="592" y2="468.7"/><rect class="gis-fill-ochre" fill-opacity=".22" x="392" y="196.2" width="240" height="171"/><path class="gis-ink gis-fill-paper" d="M 502.7 127.8 L 525.3 158.1 L 540.0 193.1 L 542.7 230.5 L 536.0 263.7 L 532.0 291.2 L 522.7 310.9 L 508.0 325.2 L 490.7 341.4 L 474.9 368.3 L 462.7 362.9 L 456.0 349.3 L 451.2 333.1 L 448.5 316.8 L 444.0 299.4 L 438.7 285.2 L 429.3 268.6 L 414.7 248.0 L 398.7 226.8 L 410.7 199.3 L 434.7 174.8 L 469.3 147.9 Z"/><path class="gis-ink gis-fill-accent" d="M 579.2 420.6 L 588.0 416.1 L 596.3 409.8 L 605.1 414.8 L 612.3 418.5 L 611.2 424.1 L 610.4 430.0 L 610.7 436.4 L 606.7 440.5 L 600.0 441.2 L 600.5 443.4 L 587.2 440.1 L 588.8 431.9 L 586.4 426.1 Z"/><rect class="gis-ink" x="392" y="90" width="240" height="378.7"/><text class="gis-label-sm" x="384" y="459.9" text-anchor="end">40°</text><text class="gis-label-sm" x="384" y="375.2" text-anchor="end">60°</text><text class="gis-label-sm" x="384" y="204.2" text-anchor="end">80°</text><text class="gis-label-sm" x="172" y="500" text-anchor="middle">Greenland looks</text><text class="gis-label-sm" x="172" y="528" text-anchor="middle">≈ 11× France</text><text class="gis-label-sm" x="512" y="500" text-anchor="middle">Greenland looks</text><text class="gis-label-sm" x="512" y="528" text-anchor="middle">≈ 30× France</text><text x="320" y="572" text-anchor="middle">In reality, Greenland is about 4× France.</text><rect class="gis-ink gis-fill-ochre" fill-opacity=".22" x="20" y="596" width="26" height="26"/><text class="gis-label-sm" x="54" y="616">60°–80° N</text><rect class="gis-ink gis-fill-paper" x="190" y="596" width="26" height="26"/><text class="gis-label-sm" x="224" y="616">Greenland</text><rect class="gis-ink gis-fill-accent" x="360" y="596" width="26" height="26"/><text class="gis-label-sm" x="394" y="616">France</text></svg><figcaption>Both maps cover the same longitudes and the same latitudes, at the same horizontal scale, so every difference you see is what the projection did to the north-south axis. On the left the parallels are evenly spaced; on the right they spread apart the further north they go, which is what stretches Greenland. The tinted band is 60°–80° N in both: on the right it is more than three times as tall. Neither map is “wrong”. They distort different things, and that is exactly why the numbers cannot travel on their own — something has to say which system they are in.</figcaption></figure>

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

<!-- UNANCHORED id=U01 type=general concept="on-the-fly reprojection (ST_Transform)" -->

## What we store

Every geometry column in this project is declared the same way:

<!-- CODE-FROM web/src/Catalog/Doctrine/GeometryType.php -->
```sql
geometry(Geometry, 4326)
```

Every migration's copy of that string is generated from one place, so no table can quietly get a
different one. See `web/src/Catalog/Doctrine/GeometryType.php`,
`GeometryType::getSQLDeclaration()` — a Doctrine custom type, registered as the DBAL type
`geometry` in `web/config/packages/doctrine.yaml`, whose entire job is to say what a geometry column
looks like in SQL. Every entity that stores a shape declares `#[ORM\Column(type: 'geometry')]` —
some add `nullable: true`, but none of them names a type or an SRID of its own — so the six geometry
columns that exist today — `region.geom`, `item.geom`,
`recommended_route.geom`, `heat_point.geom`, `submission.geom` and `users.base_point` — all got
their declaration from this one method. You will find the same literal string written out in each
of the migrations that created them; those are copies of this method's output, frozen at the moment
the migration was generated, not six independent decisions.

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
build — and even there, we do not do the projecting ourselves. `build_pmtiles()` in
`pipeline/coverage/tiles.py` shells out to **tippecanoe**, the tile cutter, handing it our
geometries in 4326:

<!-- CODE-FROM pipeline/coverage/tiles.py -->
```python
for (letter, cc) in sorted(layer_files):
    cmd += ["-L", f"{letter.lower()}_{cc.lower()}:{layer_files[(letter, cc)]}"]
subprocess.run(cmd, check=True)
```

tippecanoe is what projects them to Web Mercator and slices the result into tiles. That is the
moment this project's data leaves 4326, and it happens inside a third-party tool, on the way out,
to a copy.

The Web Mercator formula is written out in our own code exactly once, a few functions further down
the same file:

<!-- CODE-FROM pipeline/coverage/tiles.py -->
```python
def _tile_y(lat: float, n: int) -> int:
    lat = max(min(lat, 85.05112878), -85.05112878)
    return min(n - 1, max(0, int((1 - math.asinh(math.tan(math.radians(lat))) / math.pi) / 2 * n)))
```

`_tile_y()` clamps a latitude to that same ±85.05112878 limit and returns which tile row it falls
in. It is a private helper with a single caller, `verify_pmtiles()` — the
sanity gate that runs *after* the archive is built, works out which tile ought to contain the
data's bounding box, fetches it, and checks it decodes. So `_tile_y()` is not the project
projecting anything; it is the project checking tippecanoe's homework, and it is the only place a
reader will find the maths spelled out. The database itself never holds a projected coordinate.
Chapter 7 covers the tile pyramid all of this belongs to.

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

## Try it

!!! tip "Hands-on — watch the swap go quiet"
    Ask the dev catalog for everything within 5 km of the fountain, the right way round and then the
    wrong way round, and watch the second answer disappear without an error. This runs against the
    live `item` table (chapter 5, [`making-it-fast.md`](making-it-fast.md#how-to-tell), shows how to
    open a `psql` session against it).

    First, longitude before latitude — `ST_Point(lng, lat)`, exactly as PostGIS wants it:

    <!-- CODE-ILLUSTRATIVE psql query against the dev catalog, correct lng-first argument order -->
    ```sql
    SELECT count(*) AS nearby
    FROM item
    WHERE ST_DWithin(geom::geography,
                      ST_SetSRID(ST_Point(5.8792, 50.4894), 4326)::geography, 5000);
    ```

    <!-- CODE-ILLUSTRATIVE sample output on a stack seeded by `make course-data`; the count grows as the catalog does, being non-zero is the point -->
    ```text
     nearby
    --------
         76
    (1 row)
    ```

    76 is what a clone seeded by `make course-data` holds around Spa; import more data and it only
    goes up. The number is not the lesson — a non-zero one is.

    Now swap the two numbers into `ST_Point`, as if you had typed them in the order a human says them
    out loud — latitude first:

    <!-- CODE-ILLUSTRATIVE psql query against the dev catalog, the human lat-first order fed straight into ST_Point -->
    ```sql
    SELECT count(*) AS nearby
    FROM item
    WHERE ST_DWithin(geom::geography,
                      ST_SetSRID(ST_Point(50.4894, 5.8792), 4326)::geography, 5000);
    ```

    <!-- CODE-ILLUSTRATIVE sample output; stable regardless of catalog growth, the swapped point has nothing near it on Earth's dry land -->
    ```text
     nearby
    --------
          0
    (1 row)
    ```

    Same predicate, same radius, same fountain — the only change is which number went into which
    argument slot. `ST_Point(50.4894, 5.8792)` is a real, valid point, about 6,400 km from here, in
    the Indian Ocean off the coast of Somalia — the exact place the warning above named. Nothing
    threw an error, because both numbers are plausible floats in range. The query simply came back
    empty, and empty is precisely what a longitude-first bug looks like from the outside.
