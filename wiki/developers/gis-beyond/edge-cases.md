<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Edge cases that bite

Course 1's [`pitfalls.md`](../gis/pitfalls.md) collects several of these as one-line traps: a row in
a table, a sentence of explanation, a link onward. That is the right amount of space for a reference
page. It is not enough space to actually understand *why* each one happens, which is what this
chapter is for. Five ideas, each explained properly, each linking back rather than repeating what
course 1 already said.

Each section states its own kind up front, because they are not all the same kind of gap.

## The antimeridian

**Kind: we do it, and the way we do it is worth reading: named as a risk before it was live, live for a while, fixed once it was.**

[`pitfalls.md`](../gis/pitfalls.md)'s own row on this is accurate and worth re-reading rather than
re-deriving: longitude wraps from +180 back to −180, and a naive minimum/maximum union of longitudes
breaks the instant a shape's coordinates straddle that seam. Here is why that specific piece of
arithmetic breaks, properly.

A bounding box's west/east edges are normally just `min(longitudes)` and `max(longitudes)` — an
ordinary, safe operation on plain numbers, because normally longitude behaves like any other linear
scale: bigger numbers really are further east. That stops being true exactly at the seam. The
**antimeridian**, at ±180° longitude, is a real, physical meridian — half of a great circle running
pole to pole, exactly like the prime meridian [`coordinates.md`](../gis/coordinates.md) introduced,
just on the opposite side of the planet. But +180° and −180° are numerically about as far apart as
two longitudes can possibly be, while the *place* they describe is the same line. A region whose
easternmost point is 179.5°E and westernmost point is 179.7°W (which is the same as −179.7°) is
actually a narrow sliver straddling that seam — a few tenths of a degree wide. Feed those two numbers
into `min`/`max` and you get west = −179.7, east = 179.5: a box that claims to run almost the entire
way around the planet the *wrong* way, covering nearly everything except the sliver it was actually
meant to describe.

The general fixes are variations on the same idea — stop treating longitude as an unbroken line
across that one seam:

- **Split the box.** Represent a seam-crossing extent as two separate boxes, one on each side of
  ±180°, each an ordinary box in its own right. Anything that has to reason about "is this point
  inside" then checks against either piece.
- **Normalise before comparing.** Shift the numbers into a range with no seam where your data lives —
  0–360° instead of −180–180°, for instance, or a range centred on the shape's own rough centre —
  take the min/max in that shifted range, then shift the answer back. This works as long as you know,
  in advance, roughly where the data actually sits, so you can choose a range with the seam somewhere
  the data never touches.

This project has an exact, named, verified answer for where this breaks, because it was looked for
and written down before it happened rather than discovered by accident later. The region-scoping
design's risk register (`docs/specs/Dated/2026-07-19-region-scoping-design.md` §8, risk 11) named
both break points, a naive union on the client and a naive `ST_XMin`/`ST_XMax` on the server, and
recorded the fix as required before any straddling country was seeded. Two were seeded first, so for
a while the register described something live.

The shape chosen is the second of the two general fixes above, and the reason is that it is not
ours: GeoJSON already specifies it. RFC 7946 §5.2 says a bounding box that crosses the antimeridian
is written with its **west value greater than its east value**, and is read the long way round. So
`[166.4, -47.3, -175.8, -34.4]` is a box 17.7° wide sitting over New Zealand, not a 344° box over
everything else. Splitting into two boxes was the alternative; it keeps every comparison trivially
simple, at the cost of changing the shape of the data every consumer reads.

The server emits that shape:

<!-- CODE-FROM web/src/Catalog/RegionRegistryProvider.php -->
```php
                    CASE WHEN ST_XMax(geom) - ST_XMin(geom) > 180
                         THEN CASE WHEN ST_XMin(ST_ShiftLongitude(geom)) > 180
                                   THEN ST_XMin(ST_ShiftLongitude(geom)) - 360
                                   ELSE ST_XMin(ST_ShiftLongitude(geom)) END
                         ELSE ST_XMin(geom) END AS w,
```

`ST_ShiftLongitude` is the normalisation described above, into a 0–360° range where the seam is not
a discontinuity. The test for whether to apply it is a **raw span wider than 180°**, and the reason
it is not the more obvious "did shifting make the span narrower?" is worth knowing: shifting adds
360 to a negative longitude, and the result cannot hold the original number's mantissa, so every
region in the western hemisphere comes back roughly 1e-14 narrower. Asturias and Madrid answer yes
to that question. Only a box reaching from one edge of the seam to the other is wider than 180°.

The client half matters more than it looks, because **no single region we ship crosses the seam**.
New Zealand's country scope is the union of seventeen regions running from Southland at 166.4°E to
the Chatham Islands at 175.8°W, and taking the minimum west and maximum east of those built the
world-wrapping box out of seventeen perfectly ordinary ones. `CCScope`'s union now compares the
width each way and keeps the shorter, and every longitude question goes through helpers that
understand the wrapped shape rather than through `b[0] <= lng && lng <= b[2]`, which is false almost
everywhere a crossing region actually is. `docs/specs/map-and-search.md` §4.5a is the contract.

Two consumers need naming. **Photon is not handed a crossing box at all**: it reads
`minLon,minLat,maxLon,maxLat` and has no notion of going round the back, so a wrapping scope sends
its country code and no bbox. And **the camera was never affected**, for an unrelated reason:
`RegionRegistryProvider` frames on the largest outline ring rather than the true bbox, because
distant islands would otherwise frame empty ocean.

## The poles

**Kind: we do not need it: general knowledge, no counterpart required.**

[`coordinates.md`](../gis/coordinates.md) already explains why Web Mercator cannot represent the
poles at all, and why the cutoff sits at exactly ±85.05112878° — the latitude where the projected
height of the whole world equals its projected width, making the projected world a perfect square.
That is not repeated here. Two things worth adding that coordinates.md does not cover.

**The cutoff is not distortion getting worse — it is a hard edge where the coordinate system stops
existing at all.** Everywhere Web Mercator *does* work, it is honest about being distorted: shapes
stretch, areas balloon, and the further you go the worse it gets, but there is still a well-defined
projected point for every latitude short of the cutoff. Past ±85.05112878°, there is no projected
point any more — the formula's output goes to infinity, not to a large-but-valid number. A tile
pyramid built on Web Mercator ([`tiles.md`](../gis/tiles.md) covers the pyramid itself) inherits this
exactly: no `z/x/y` tuple, at any zoom level, ever addresses a point past that latitude. It is not
that the tile covering the pole renders badly or looks stretched — there is no such tile. The two
polar caps are a permanent, structural gap in the addressing scheme, not a quality problem that a
better projection could fix while keeping the same square-world tiling trick.

**Longitude itself stops meaning anything, exactly at a pole.** Every meridian — every line of
constant longitude — meets at both poles, by definition; [`coordinates.md`](../gis/coordinates.md)'s
"parallels never meet, meridians all meet at both ends" already says as much. Push that one step
further: standing exactly on the North Pole, "which way is east" has no answer, because every
direction you could face is south. A longitude value at that exact point is not wrong, wasted, or
in need of rounding — it is a question without a fact to be an answer to. Any code that leans on
longitude to reliably tell two nearby points apart — sorting by it, differencing it, the antimeridian
section's min/max trick — degrades continuously as you approach a pole, well before you actually
reach one, because the real ground distance one degree of longitude represents keeps shrinking by
`cos(latitude)` the whole way there ([`coordinates.md`](../gis/coordinates.md) covers this shrinkage
in full) and hits exactly zero only at the pole itself.

This project's data has not reached the point where either of these matters, and the margin is
worth stating with the real numbers. The northernmost onboarded region is Canada, whose outline
reaches about 83°N at the top of Ellesmere Island; the United States follows at about 71°N, on
Alaska's north coast, and the United Kingdom at about 61°N, in Shetland. Canada's tip sits some 2° of
latitude short of Web Mercator's cutoff at 85.05°: close enough that the tile pyramid's hard edge is
a real property of that country's own extent, but every point of it still has a projected position,
and none of it is anywhere near an actual pole, where longitude stops meaning anything. This is why
it is the "we do not need it" kind, cleanly: not a live defect with a named trigger, the way the
antimeridian is, just real GIS knowledge this system's own footprint stops short of.

## Ring winding order, properly

**Kind: we do not need it: general knowledge, no counterpart required.**

[`shapes.md`](../gis/shapes.md) and [`pitfalls.md`](../gis/pitfalls.md) both already cover the
practical shape of this trap: the direction a polygon's ring is walked — clockwise or
counter-clockwise — is called its **winding**, PostGIS itself is forgiving about which direction you
use, and some other tools use winding as their *only* signal for telling a fill from a hole, with no
error if you get it backwards, just the wrong picture. Here is where that convention actually comes
from, and how you would check it yourself.

**How you compute it.** Winding direction is not something you eyeball reliably from a coordinate
list; you compute it with the **shoelace formula** (also called the surveyor's formula), the same
calculation that gives you a polygon's signed area:

<!-- CODE-ILLUSTRATIVE the shoelace/surveyor's formula, hand-written, not a repo quote -->
```text
signed_area = 0.5 * sum( x[i] * y[i+1] - x[i+1] * y[i] )   for each edge, wrapping the last point to the first
```

Treat longitude as `x` and latitude as `y`, matching the plain equirectangular plot
[`coordinates.md`](../gis/coordinates.md) already introduced as "plate carrée" — plotting degrees
straight onto a page as if they were an ordinary flat `(x, y)` plane. Sum that formula around a ring
and the sign of the result tells you the winding: positive means the ring was walked
counter-clockwise, negative means clockwise, on that same ordinary plane where east is right and
north is up.

**The actual rule, and where it comes from.** GeoJSON's own specification — RFC 7946, §3.1.6 —
recommends a specific convention often called the **right-hand rule**: an exterior ring should be
wound counter-clockwise, and a hole's interior ring the opposite way, clockwise. Picture curling your
right hand's fingers in the direction the ring is walked; your thumb points toward what that ring
considers "inside." Two exterior rings both walked counter-clockwise both point their thumbs
outward, away from empty space and into the shape; a hole walked clockwise points its thumb the other
way, into the emptiness it is cutting out — which is exactly the signal a renderer that trusts
winding uses to tell "this ring adds area" from "this ring removes it," with nothing else to go on.

Two things are worth being precise about, because they explain why "PostGIS is forgiving" and "some
tools are not" can both be true of the same specification. RFC 7946 itself says a GeoJSON parser
**should not** reject a geometry purely for having the wrong winding — it is a recommendation for
producers to follow, not a validity requirement consumers must enforce — and it explicitly allows a
consumer to use winding as a hint rather than a hard rule. PostGIS takes the permissive reading:
winding is not required, and its own functions do not depend on it. Some other renderers and
libraries take the stricter reading in practice, using winding as their only cue rather than
inspecting each ring's actual position — a design choice that is entirely within what the specification
allows, but leaves no room for error the moment a producer did not follow the recommendation.

Nothing in this project currently depends on winding either way — the point [`pitfalls.md`](../gis/pitfalls.md)
already makes and this chapter is not repeating. The reason to know the actual rule anyway is the day
this project's geometry is handed to something that is not PostGIS and does not treat winding as
optional.

## Antipodal points and great-circle surprises

**Kind: we do not need it: a real GIS concept, and a structural (not incidental) non-issue here.**

Two points are **antipodal** if they sit exactly opposite each other on the globe — as far apart as
two points on a sphere can possibly be. Antipodal, or nearly-antipodal, pairs are where flat-plane
shortcuts stop merely being imprecise and start being actively misleading. [`metres-vs-degrees.md`](../gis/metres-vs-degrees.md)
already warns against doing Pythagoras on raw degrees; near an antipodal pair, that kind of
shortcut does not just get the *distance* wrong, it can point you in a bearing that bears no
relation to the real shortest path at all — because near the antipode, the shortest path could
legitimately run through almost any direction, and a small numerical wobble in a hand-rolled formula
is enough to flip which one a naive calculation prefers.

This project's own distance answers are not at risk from this, and it is worth being precise about
*why* rather than just asserting it: nothing in this codebase computes great-circle trigonometry by
hand. Every real distance this project asks PostGIS for goes through a `geography` cast —
[`metres-vs-degrees.md`](../gis/metres-vs-degrees.md) covers `BaseAreaResolver::resolve()`'s
`ST_DWithin(r.geom::geography, …, :m)` as the worked example — and PostGIS's own geodesic routines
already handle the sphere (strictly, the WGS84 ellipsoid) correctly, antipodal pairs included. The
risk described in this section is real GIS knowledge worth having; it simply describes a mistake
this project has no code path capable of making, because it never reimplements the maths that
`geography` already gets right.

The related idea is the **great circle**: the shortest path between two points on a sphere is an arc
of the circle you get by slicing the sphere through both points and its centre — not the straight
line you would draw connecting them on a flat plot. A straight line from Amsterdam to Tokyo, drawn on
an ordinary flat map, looks like it cuts through the Middle East; the real shortest flight path arcs
much further north, closer to the Arctic, because a flat map's "straight" is not the sphere's
"straight." This matters for anything that infers what lies *between* two far-apart points from
just the two endpoints — routing engines, and any tool that draws a straight connecting line across
a genuinely large distance and treats it as meaningful.

It does not matter here, and the reason is structural rather than lucky. [`routes.md`](../gis/routes.md)
covers how a rider's GPX file becomes a stored LineString: every route this project stores is a dense
chain of GPS points recorded a few metres apart along an actual road or path, never two distant
endpoints with the path between them left for something else to infer. Over a few metres, a straight
chord and a great-circle arc are indistinguishable to well beyond any precision this project's data
already carries. The great-circle-versus-straight-line gap only shows up at scales orders of
magnitude larger than a bike ride, and nothing in this project ever draws a chord between two
endpoints that far apart and treats it as a path. It is a real trap in general GIS work; it simply
has no scale at which to bite here.

## Floating-point comparison of coordinates

**Kind: we already handle this correctly: general knowledge, with real anchors in this codebase.**

Every coordinate in this system — every latitude, every longitude, at every layer from a GPS
receiver to a rendered pixel — is a floating-point number: an approximation, to a fixed number of
binary digits, of a real value. The general trap is old and applies far beyond GIS: **never compare
two floats with exact equality (`==`)** and expect it to mean "the same value," because ordinary
arithmetic — sums, averages, any multi-step calculation — accumulates rounding error in the number's
last few bits. Two values that are mathematically identical, and would print identically to a few
decimal places, can differ in a bit position too small to see and still fail an exact-equality test,
silently, forever.

This project's own coverage pipeline produces exactly the kind of arithmetic that triggers this.
`pipeline/coverage/parse.py`'s `way()` handler reduces a way to a single point by averaging its
member nodes' coordinates — `sum(lons) / len(lons)` — and that division is not guaranteed to land on
a value you could predict by doing the same sum on paper and rounding. The pipeline's own test suite
is written with this in mind, and it is worth reading as the reference case for how to test
floating-point results honestly:

<!-- CODE-FROM pipeline/tests/test_parse.py -->
```python
    assert way.lon == pytest.approx((4.0000 + 4.0300 + 4.0300) / 3, abs=1e-6)
    assert way.lat == pytest.approx((50.0000 + 50.0000 + 50.0300) / 3, abs=1e-6)
```

`pytest.approx(…, abs=1e-6)` asks "is this within a tenth of a millimetre of the expected value" —
`1e-6` degrees of latitude is roughly a tenth of a millimetre of real ground — rather than "is this
bit-for-bit identical to it." That is the correct question for a value produced by arithmetic, and
the test suite asks it deliberately rather than by accident.

The same idea shows up on the browser side for a different reason: not testing a computed result,
but using a coordinate as part of an identity key without letting floating-point noise turn two
renders of the same point into two different keys. `web/assets/map/osm-pools.js` tracks already-rendered
map markers across re-renders by building a string key from each feature's coordinates:

<!-- CODE-FROM web/assets/map/osm-pools.js -->
```js
const key = p.cluster ? 'c'+p.cluster_id : 'l'+co[0].toFixed(5)+','+co[1].toFixed(5);
```

`toFixed(5)` rounds to five decimal places — about a metre of precision — *before* the coordinates
become part of a key two different code paths need to agree on. Rounding first sidesteps the exact
question this section opened with: two renders of what is conceptually the same point might disagree
in their seventeenth significant bit for reasons that have nothing to do with the point having
actually moved, and a key built from the raw floats would treat them as two different markers. A key
built from the rounded value does not.

Neither of these is this project reaching for a special trick. Both are the same, ordinary
engineering habit — round before you use a float as an identity, and compare floats with a tolerance
rather than `==` — applied in two different places for two different reasons, and both already
present in this codebase before this chapter ever pointed at them.

## Try it

!!! tip "Hands-on: run the naive union CCScope.bbox() produces, on the seam"
    Two onboarded countries, New Zealand and the United States, straddle ±180°, so on a stack that
    has seeded them the live `region` rows trigger this defect. `make course-data` seeds neither, so
    start with the arithmetic itself, which needs no onboarded region at all, only the same two
    example longitudes this chapter's own antimeridian section already uses: a sliver running from
    179.5°E to 179.7°W. Run `CCScope.bbox()`'s own `Math.min`/`Math.max` union directly on those two
    numbers:

    <!-- CODE-ILLUSTRATIVE psql query — the same Math.min/Math.max union CCScope.bbox() performs, run on the two example longitudes this chapter's antimeridian section names -->
    ```sql
    WITH lons(lon) AS (VALUES (179.5), (-179.7))
    SELECT min(lon) AS naive_west, max(lon) AS naive_east, max(lon) - min(lon) AS naive_box_width_degrees
    FROM lons;
    ```

    <!-- CODE-ILLUSTRATIVE sample output -->
    ```text
     naive_west | naive_east | naive_box_width_degrees
    ------------+------------+-------------------------
          -179.7 |      179.5 |                    359.2
    ```

    `359.2` degrees wide — a box claiming to cover all but eight-tenths of a degree of the entire
    planet's longitude, to describe a sliver that is actually eight-tenths of a degree wide. Now shift
    both numbers into the 0–360° range this chapter's own fix section named, take the same min/max,
    and read the width back off:

    <!-- CODE-ILLUSTRATIVE psql query — a longitude-normalised union, the fix this chapter's own text names -->
    ```sql
    WITH lons(lon) AS (VALUES (179.5), (-179.7)),
         shifted AS (SELECT CASE WHEN lon < 0 THEN lon + 360 ELSE lon END AS lon FROM lons)
    SELECT min(lon) AS shifted_west, max(lon) AS shifted_east, max(lon) - min(lon) AS true_box_width_degrees
    FROM shifted;
    ```

    <!-- CODE-ILLUSTRATIVE sample output -->
    ```text
     shifted_west | shifted_east | true_box_width_degrees
    --------------+--------------+------------------------
            179.5 |        180.3 |                     0.8
    ```

    `0.8` degrees — the real width, recovered by refusing to treat ±180° as an ordinary number line
    for exactly the length of one comparison. This is the precise failure the region-scoping risk
    register names as **required** to fix before any seam-straddling country is seeded, and the query
    above is exactly `CCScope.bbox()`'s own `Math.min`/`Math.max` shape, run on two literal numbers
    so it works on any install. On a stack that has seeded the two countries, the same arithmetic on
    the real rows shows the defect live:

    <!-- CODE-ILLUSTRATIVE psql query against the region table; only returns rows on a stack that has seeded New Zealand and the United States -->
    ```sql
    SELECT slug, round(ST_XMin(geom)::numeric, 2) AS w, round(ST_XMax(geom)::numeric, 2) AS e,
           round((ST_XMax(geom) - ST_XMin(geom))::numeric, 1) AS naive_width_degrees
    FROM region WHERE slug IN ('new-zealand', 'united-states');
    ```

    <!-- CODE-ILLUSTRATIVE sample output at the time of writing; the outlines come from Overture, so the decimals may move with a re-import, the shape of the failure does not -->
    ```text
         slug      |    w    |   e    | naive_width_degrees
    ---------------+---------+--------+---------------------
     new-zealand   | -178.57 | 179.03 |               357.6
     united-states | -179.15 | 179.77 |               358.9
    ```

    Those are the `w` and `e` values `RegionRegistryProvider` publishes as each country's `bbox`,
    and the width `CCScope.bbox()` inherits: a box that wraps the world to describe a country.
