<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Asking spatial questions

The fountain now has a position (chapter 1), a shape (chapter 2), and a way to ask about real
ground distance instead of degrees (chapter 3,
[`metres-vs-degrees.md`](metres-vs-degrees.md)). None of that, on its own, answers anything anyone
building this project actually needed to know. A row in a table is just a row. The moment it matters
is when a real question gets asked of it:

- A rider submits a new fountain. *Which region does it belong to?*
- Another rider sets a base location in their settings. *What is near them?*
- A third rider uploads a GPX from their ride. *What did they pass, and in what order did they pass
  it?*

Every one of these is a **spatial question**, a question whose answer depends on where things are,
not just what they are. PostGIS answers each with a specific function, and almost all of them are
named starting `ST_`, for "spatial type." This chapter covers seven of them. Some answer yes or no,
those are properly called **predicates**. Others hand back a number, or even a whole new shape. All
seven are the vocabulary this project's own code already speaks; the rest of this chapter is
learning to read it.

## Is it inside? ST_Contains

The plainest spatial question there is: *is this point inside that shape?* `ST_Contains(a, b)`
answers it, true if `b` lies entirely within `a`, false otherwise. For a region polygon and a
single submitted point, "entirely within" just means "inside the outline."

Notice what this predicate does *not* need. No units, because it never measures a distance, it
only asks about containment, the same kind of topology question chapter 3 called out as the one case
that never needs a `::geography` cast. No cast, for the same reason: whether the polygon's degrees
happen to be worth 111 km or 71 km apiece makes no difference to whether a point sits inside its
outline.

This is exactly the question `SpatialResolver` answers, the class chapter 1 already introduced for
its longitude-first argument order. Here is its actual method signature, in
`web/src/Contribution/SpatialResolver.php`, notice the parameters are `$lat` before `$lng`, the
human order a submitted form uses, not the `x, y` (longitude, latitude) order PostGIS itself expects:

<!-- CODE-FROM web/src/Contribution/SpatialResolver.php -->
```php
public function resolve(float $lat, float $lng): array
```

Inside, `resolve()` swaps that human order back to PostGIS's own before it ever reaches the
database, `ST_Point(:lng, :lat)`, longitude first, and asks:

<!-- CODE-FROM web/src/Contribution/SpatialResolver.php -->
```sql
SELECT id, country_code FROM region
 WHERE ST_Contains(geom, ST_SetSRID(ST_Point(:lng, :lat), 4326))
 ORDER BY area_km2 ASC NULLS LAST, id ASC LIMIT 1
```

Read literally: for every region polygon, does it contain this point, and of those that do, take
the smallest. The `ORDER BY` earns its keep because more than one row *can* say yes: a country's
whole-country outline sits in the same table as its subdivisions, so a point in Bavaria is inside
both `bayern` and `germany`. Smallest-area-wins makes the
subdivision take it deterministically, the same tie-break `RegionResolver` uses for routes.

You have actually already seen this predicate at work, without a name attached to it. Chapter 3
quoted `Service/BaseAreaResolver.php`, `BaseAreaResolver::resolve()` sorting its result by
`ST_Contains(r.geom, ST_SetSRID(ST_Point(:lng, :lat), 4326)) DESC` before anything else, so that "the
region that actually contains you" always outranks a neighbour that merely happens to be close. Now
you know what that line is actually asking: the exact same yes/no containment test as
`SpatialResolver`, just used to sort a list instead of to look one row up.

## Do they touch? ST_Intersects

`ST_Intersects(a, b)` is broader than `ST_Contains`: it is true whenever the two shapes share *any*
point at all, one fully inside the other, one just brushing the other's edge, or two lines
genuinely crossing. It does not care which shape is bigger, or which one "contains" which. It only
asks: is there at least one point the two of them have in common?

That looseness is exactly what makes it useful between shapes of completely different kinds. A point
and a polygon, a line and a polygon, two polygons, `ST_Intersects` asks the same question of all of
them, which is why it turns up constantly once a query starts mixing shape types. It is the workhorse
predicate of this whole chapter, and, worth remembering for chapter 5, it is also the one a spatial
index can serve directly. Nothing else here gets that for free.

`Catalog/RideCheckService.php`, `RideCheckService::corridorGroups()` uses it twice, once for catalog
items and once (in the sibling method `followedRoutes()`) for recommended routes:

<!-- CODE-FROM web/src/Catalog/RideCheckService.php -->
```sql
AND ST_Intersects(i.geom, (SELECT b FROM corridor))
```

`corridor` here is a polygon, the buffered ride you will meet properly a few sections down. This
line is the whole filter: keep an item only if its point shares at least one point with that corridor
shape. It does not matter whether the item sits deep inside the corridor or right on its edge;
`ST_Intersects` answers true either way.

## Is it near? ST_DWithin

`ST_DWithin(a, b, d)` is true if the two shapes are within distance `d` of each other, which
includes touching or overlapping, since a distance of zero is certainly "within" any positive `d`.
It is the predicate behind every "near me" question this project asks.

Chapter 3's whole lesson applies directly here: `d`'s units depend entirely on whether you cast to
`geography`. Compare it against `geometry`, and `d` means degrees on the raw stored value, the wrong
shape and the wrong size, exactly as chapter 3 demonstrated. Cast both sides to `geography`, and `d`
means real metres on the ground, in every direction, at any latitude.

`BaseAreaResolver::resolve()`, chapter 3's own example, casts both sides:

<!-- CODE-FROM web/src/Service/BaseAreaResolver.php -->
```sql
ST_DWithin(r.geom::geography, ST_SetSRID(ST_Point(:lng, :lat), 4326)::geography, :m)
```

`:m` arrives already converted from the kilometres a rider typed into their settings form. That is
the whole predicate: does any part of this region's outline lie within `:m` metres of the rider's
base point? Everything chapter 3 said about casting *both* arguments, not just one, is this exact
line.

`ST_DWithin` is not free of the cost/servability question either, and this is the one place this
chapter has to point forward rather than answer it: casting to `geography` gets you the right
answer, but it is also the shape of query that chapter 5 shows going wrong at scale, and the rewrite
that fixes it.

## How far, and where? ST_Distance and ST_ClosestPoint

Sometimes true-or-false is not enough. Sometimes the question is *how far*, or *exactly where*.

`ST_Distance(a, b)` returns a number: the shortest distance between the two shapes. Same casting
rule as everything else in this chapter, `geometry` hands back degrees, `geography` hands back
metres, because `ST_Distance` is measuring the same underlying shapes chapter 3 already covered, it
just returns the measurement itself instead of comparing it to a threshold.

`ST_ClosestPoint(a, b)` answers a different question: not *how far*, but *where*. It returns an
actual point, the specific point on geometry `a` that is nearest to geometry `b`. Order matters
here in a way it does not for `ST_Distance`: the point that comes back always lies *on `a`*, never on
`b`. If `a` is a long, winding line and `b` is off to one side of it, `ST_ClosestPoint(a, b)` is the
one point on that line you'd have to walk to first before you could get any closer to `b`. But if `a`
is a Point, there is only ever one point on it, itself, so `ST_ClosestPoint` returns `a` unchanged,
no matter where `b` is. A Point geometry has no "nearer" or "further" location to offer; asking for
the closest point on a single point is trivially answered before the second argument is even looked
at.

`RideCheckService::corridorGroups()` uses both `ST_Distance` and `ST_ClosestPoint` in the same query,
but not quite on the same footing:

<!-- CODE-FROM web/src/Catalog/RideCheckService.php -->
```sql
ST_Distance(i.geom::geography, (SELECT g FROM track)::geography) AS dist_m,
ST_LineLocatePoint((SELECT g FROM track), ST_ClosestPoint(i.geom, (SELECT g FROM track))) AS frac
```

`ST_Distance` is the straightforward one: how far, in real metres, does the fountain sit from the
rider's track? That becomes `distM` in the response the rider actually sees, "80 m off the track."
`ST_ClosestPoint(i.geom, (SELECT g FROM track))` puts the item first, so per the rule just above, the
point it returns lies on `i.geom`, the fountain's own geometry, not on the track. Catalog items are
usually, but not necessarily, Points (`item.geom` is declared `geometry(Geometry, 4326)`,
`web/migrations/Version20260703153611.php`, and the road-surface layer A already stores LineStrings
in it). For the ordinary case, a Point fountain, that means `ST_ClosestPoint` here just hands back the
fountain's own coordinates, unchanged, the same trivial case called out above. It is easy to misread
this line as "the point on the track nearest the fountain"; it is the opposite argument order from
that. The query still works, and works correctly, but not because `ST_ClosestPoint` is projecting
anything onto the track, that projection is `ST_LineLocatePoint`'s own job, two sections from here.
What `ST_ClosestPoint` contributes here, for a Point item, is nothing more than passing the fountain's
location through unchanged into the function that actually does the work. `BaseAreaResolver` uses
`ST_Distance` too, the same way chapter 3 already quoted it: sorting candidate regions by real
distance, in metres, once the containment check above has already picked a winner.

<figure class="gis-fig">
<svg viewBox="0 0 640 710" role="img" aria-labelledby="f6-t f6-d" xmlns="http://www.w3.org/2000/svg">
  <title id="f6-t">Four spatial questions asked of one region, two points and one track</title>
  <desc id="f6-d">A single drawing holding one region polygon, two points and one track, with four questions asked of them. The region is an irregular filled outline covering the left half of the panel. Point A sits well inside it; point B sits just outside its lower-left edge, about thirty units beyond the boundary. A dashed circle of five kilometres' radius is drawn around A, and B falls inside that circle, about four kilometres from A, so the near test passes. A short measured segment with tick marks at both ends runs from A down to the nearest point on the region's boundary, labelled 2.8 kilometres. A track enters from the right-hand edge of the panel, outside the region, and crosses the region's boundary exactly once before its arrowhead comes to rest inside; the single crossing point is marked with a dot. Below the drawing, five result lines: ST_Contains of region and A is true, ST_Contains of region and B is false, ST_Intersects of region and track is true, ST_DWithin of A and B at five kilometres is true, and ST_Distance of A and the edge is 2.8 kilometres. Only that last line answers with a number; the four above it answer yes or no.</desc>
  <defs><marker id="gis-arrow-f6" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs>
  <text class="gis-label-sm" x="320" y="44" text-anchor="middle">Four questions, the same few shapes</text>
  <path class="gis-ink gis-fill-paper" d="M 35 300 L 70 150 L 150 75 L 270 95 L 340 215 L 300 340 L 170 385 Z"/>
  <circle class="gis-muted" stroke-width="1.8" stroke-dasharray="7 6" cx="200" cy="290" r="145"/>
  <line class="gis-muted" stroke-width="1.8" stroke-dasharray="6 5" x1="200" y1="290" x2="97.5" y2="187.5"/>
  <path class="gis-accent" stroke-width="3" marker-end="url(#gis-arrow-f6)" d="M 615 110 L 420 95 L 215 120"/>
  <line class="gis-clay" x1="200" y1="290" x2="226.2" y2="365.6"/>
  <line class="gis-clay" x1="192.4" y1="292.6" x2="207.6" y2="287.4"/>
  <line class="gis-clay" x1="218.6" y1="368.2" x2="233.8" y2="363"/>
  <circle class="gis-fill-ink" cx="279.5" cy="112.2" r="5"/>
  <circle class="gis-ink gis-fill-accent" cx="200" cy="290" r="9"/>
  <circle class="gis-ink gis-fill-ochre" cx="140" cy="390" r="9"/>
  <text class="gis-halo" x="200" y="266" text-anchor="middle">A</text>
  <text class="gis-halo" x="126" y="398" text-anchor="end">B</text>
  <text class="gis-label-sm gis-halo" x="108" y="136">region</text>
  <text class="gis-label-sm gis-halo" x="152" y="230">5 km</text>
  <text class="gis-label-sm gis-halo" x="190" y="340" text-anchor="end">2.8 km</text>
  <text class="gis-label-sm gis-halo" x="500" y="88" text-anchor="middle">track</text>
  <text class="gis-label-sm gis-halo" x="296" y="152">crosses once</text>
  <text class="gis-label-mono" x="30" y="492">ST_Contains(region, A): true</text>
  <text class="gis-label-mono" x="30" y="528">ST_Contains(region, B): false</text>
  <text class="gis-label-mono" x="30" y="564">ST_Intersects(region, track): true</text>
  <text class="gis-label-mono" x="30" y="600">ST_DWithin(A, B, 5 km): true</text>
  <text class="gis-label-mono" x="30" y="636">ST_Distance(A, edge): 2.8 km</text>
  <text class="gis-label-sm" x="30" y="678">Only the last one answers with a number.</text>
</svg>
<figcaption>Four questions, one region and one point apiece: <code>ST_Contains</code> and <code>ST_Intersects</code> answer yes or no; <code>ST_DWithin</code> answers yes or no about a distance; only <code>ST_Distance</code> hands back a number instead of a verdict. Same shapes, four different kinds of answer.</figcaption>
</figure>

## Grow a shape: ST_Buffer

Every predicate so far compares two shapes that already exist. `ST_Buffer(g, d)` makes a new one: it
returns every point within distance `d` of `g`. A point buffered becomes a circle. A line buffered
becomes a corridor, a stadium-shaped strip running the whole length of the line, `d` wide on each
side. A polygon buffered becomes a slightly larger polygon, its outline pushed outward everywhere.

One caveat before you trust the shape: a buffer's curves are not curves. PostGIS approximates each
one with a fixed number of straight segments, so a buffered point is a many-sided polygon rather
than a circle. It is close enough for every use here and worth knowing before you compare a buffer's
area against the circle you pictured.

That second case is exactly how "within 100 m of my ride" stops being a sentence and becomes an
actual shape you can test other shapes against. `RideCheckService::corridorGroups()` builds its
corridor this way:

<!-- CODE-FROM web/src/Catalog/RideCheckService.php -->
```sql
corridor AS MATERIALIZED (SELECT ST_Buffer((SELECT g FROM track)::geography, :radius)::geometry AS b)
```

`:radius` is one of the rider-chosen values in `RideCheckService::ALLOWED_RADII`, 100, 250, 500 or
1000 metres. The buffer runs in `geography`, so that number really is metres on the ground, exactly
the rule chapter 3 gave for "buffering by a real-world distance": cast to `geography` for the buffer
itself, then cast straight back to `geometry` for what comes next, here, the `::geometry` at the
very end of the line above. What comes next is a topology question again (the `ST_Intersects` you
already met two sections back), and there is no reason to pay for curved-earth maths twice.

Put the last three sections together and `corridorGroups()`'s shape falls out on its own: `ST_Buffer`
turns the uploaded ride into a corridor, once; `ST_Intersects` then tests every catalog item's point
against that one corridor, cheaply, because both sides are now ordinary flat shapes.

## How far along? ST_LineLocatePoint

Every predicate up to here treats a shape as a static thing to test a point against. A LineString is
different, chapter 2 already said its point order is part of what it means, not incidental to it.
`ST_LineLocatePoint(line, point)` is the function that turns that order into a single, usable number:
given a point that sits *on* the line (or is snapped onto it), it returns a fraction between 0 and 1
for how far along the line, measured from its very first vertex, that point sits. 0 means the line's
start. 1 means its end. 0.5 means exactly halfway along by length. It does not answer "how far" in
metres (that's `ST_Distance`) and it does not answer "which exact point" (that's `ST_ClosestPoint`);
it answers "how far along," which is a completely different axis of information from either.

This is exactly the tool for "what did I ride past, in the order I actually passed it." A rider's GPX
track is a LineString with a real direction, the direction they rode, and every fountain, bench, or
repair stand near it has some closest point somewhere along that line. `ST_LineLocatePoint` is what
turns "somewhere along that line" into a sortable number.

`RideCheckService::corridorGroups()` chains it directly onto the `ST_ClosestPoint` you saw two
sections ago:

<!-- CODE-FROM web/src/Catalog/RideCheckService.php -->
```sql
ST_LineLocatePoint((SELECT g FROM track), ST_ClosestPoint(i.geom, (SELECT g FROM track))) AS frac
```

It would be tempting to read this inside-out as "first find the point on the track closest to this
item, then ask how far along that point sits", but that is not quite what happens, and the previous
section is exactly why: `ST_ClosestPoint(i.geom, track)` returns a point on `i.geom`, the item's own
geometry, which for the ordinary Point fountain is just the fountain's own coordinates handed back
unchanged. The projection onto the track, finding *where on the line* a given point sits nearest to,
is done by `ST_LineLocatePoint` itself. Its second argument does not need to already lie on the
line; `ST_LineLocatePoint(line, point)` locates the position along `line` closest to whatever `point`
it is given, exactly the same nearest-point calculation `ST_ClosestPoint` performs, just built into
`ST_LineLocatePoint`'s own definition instead of taken as an input. So the real reading is: hand the
fountain's coordinates to `ST_LineLocatePoint`, and let it do its own internal closest-point
projection onto the track, in the same step as converting that projection into a 0–1 fraction. The
`ST_ClosestPoint` call is only load-bearing here for a non-Point item, a mapped path or area, where
it first collapses that shape down to the single point on it nearest the track, before
`ST_LineLocatePoint` projects that point onto the track in turn. The result, `frac`, is what the query
then sorts by, `ORDER BY frac, i.id`, and it is also what the PHP code turns into the kilometre
figure a rider actually reads:

<!-- CODE-FROM web/src/Catalog/RideCheckService.php -->
```php
'alongKm' => round((float) $row['frac'] * $rawM / 1000.0, 1),
```

`$rawM` is the ride's total length in metres, computed once up front. Multiply the 0–1 fraction by
that total and you get "you passed this at kilometre 23.4", a real distance along the ride, out of a
number that started out as a plain fraction. Without `frac`, `ORDER BY i.id` would list fountains in
whatever order they happened to be inserted into the `item` table, arbitrary, and almost certainly
not the order a rider pedalled past them. With it, the list comes back in the one order that actually
matches the ride.

<figure class="gis-fig">
<svg viewBox="0 0 640 500" role="img" aria-labelledby="f7-t f7-d" xmlns="http://www.w3.org/2000/svg">
  <title id="f7-t">Three fountains beside one ride, each located as a fraction along it</title>
  <desc id="f7-d">A ride is drawn as one line running left to right across the panel, marked start at its left end and finish, with an arrowhead, at its right end. Three fountains sit near the line at different distances from it: the first below the line towards the left, the second above the line near the middle, the third below the line towards the right. From each fountain a dashed segment drops at a right angle to the closest point on the ride, where a small dot marks that point on the line. The three dots are labelled, in left-to-right order along the ride, 0.12, then 0.55, then 0.91, each one the fraction of the ride's total length reached at that tick, measured from the start. Below the drawing: 0 is the start of the ride and 1 the finish; ST_LineLocatePoint of the ride and a fountain returns that fraction; sorting by it puts the three fountains in the ride's own order.</desc>
  <defs><marker id="gis-arrow-f7" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs>
  <text class="gis-label-sm" x="320" y="44" text-anchor="middle">One ride, three fountains beside it</text>
  <path class="gis-accent" stroke-width="3" marker-end="url(#gis-arrow-f7)" d="M 60 300 L 180 220 L 300 260 L 420 180 L 560 220"/>
  <line class="gis-muted" stroke-width="2" stroke-dasharray="6 5" x1="153.7" y1="319.3" x2="116" y2="262.7"/>
  <line class="gis-muted" stroke-width="2" stroke-dasharray="6 5" x1="300.8" y1="193.4" x2="331.3" y2="239.2"/>
  <line class="gis-muted" stroke-width="2" stroke-dasharray="6 5" x1="495.6" y1="261.9" x2="511.5" y2="206.1"/>
  <circle class="gis-fill-ink" cx="116" cy="262.7" r="5"/>
  <circle class="gis-fill-ink" cx="331.3" cy="239.2" r="5"/>
  <circle class="gis-fill-ink" cx="511.5" cy="206.1" r="5"/>
  <circle class="gis-fill-ink" cx="60" cy="300" r="6"/>
  <circle class="gis-ink gis-fill-accent" cx="153.7" cy="319.3" r="10"/>
  <circle class="gis-ink gis-fill-accent" cx="300.8" cy="193.4" r="10"/>
  <circle class="gis-ink gis-fill-accent" cx="495.6" cy="261.9" r="10"/>
  <text class="gis-label-mono gis-halo" x="99" y="238" text-anchor="middle">0.12</text>
  <text class="gis-label-mono gis-halo" x="354" y="273" text-anchor="middle">0.55</text>
  <text class="gis-label-mono gis-halo" x="521" y="173" text-anchor="middle">0.91</text>
  <text class="gis-label-sm gis-halo" x="40" y="332">start</text>
  <text class="gis-label-sm gis-halo" x="560" y="256" text-anchor="middle">finish</text>
  <text class="gis-label-sm" x="30" y="386">0 is the start of the ride, 1 the finish.</text>
  <text class="gis-label-mono" x="30" y="428">ST_LineLocatePoint(ride, fountain)</text>
  <text class="gis-label-sm" x="30" y="462">sorted by that fraction: the ride's own order</text>
</svg>
<figcaption>Each fountain's closest point on the ride sits at a fraction between 0 (the very start) and 1 (the very finish). Sorting the ride-check results by that fraction, not by the order rows happen to sit in the database, is what lets a rider see 0.12 before 0.55 before 0.91: the same order they actually pedalled past them.</figcaption>
</figure>

## Asking by name instead

Every predicate in this chapter starts from coordinates already in hand. But a rider setting their
base location does not type a latitude and a longitude, they type "Namur." Turning a place name into
coordinates, or coordinates into a place name, is called **geocoding**, and it is worth being honest
about what kind of problem it actually is.

It looks spatial, because the result is a pair of coordinates. It is not, mostly. "Namur" is a string.
Finding out what it might refer to is a text-matching problem: fuzzy, tolerant of misspellings,
weighted toward more common or more prominent places. There is more than one real place in the world
called Namur, so the search cannot stop at "found a match", it also has to pick *which* Namur. That
pick is where a spatial idea finally enters, as a tiebreak: prefer the match closest to the user, or
inside a given country, or inside a given bounding box. Geocoding is a text problem with a spatial
tiebreak, not a spatial query with some text bolted on.

**Forward geocoding**, name in, coordinates out, is what this project actually uses, twice. The map
page's own place search and the settings page's base-location field both call the same external
service, **Photon**, a free, keyless, OpenStreetMap-based geocoder. `web/assets/settings/base-location.js`
builds its request against

<!-- CODE-FROM web/assets/settings/base-location.js -->
```js
var PH_BASE = 'https://photon.komoot.io/api/?limit=6'
```

restricting the results to place-like OSM tags (city, town, village, hamlet, municipality) so a
search for a town name does not come back full of unrelated hits. `web/assets/map/search-ui.js`'s
`runPhoton()` does the equivalent for the map's own search box. Neither call needs an API key or a
server-side proxy, which is also why `web/src/EventSubscriber/CspSubscriber.php` lists
`https://photon.komoot.io` directly in its `connect-src` allow-list, a browser-side fetch straight to
Photon, not a request that passes through our own backend first.

**Reverse geocoding**, coordinates in, a place name out, is the direction this project does not
call. It would be reasonable to expect it: the map already draws a "Near Namur · 40 km" label next to
a rider's base location, and that label looks exactly like the output of a reverse-geocode call. It
is not. The name is captured forwards, at the moment a rider picks a town, and stored. Course 2's
[geocoding chapter](../gis-beyond/geocoding.md) has the decision, the column it is stored in, and
the boundary between "never reverse-geocodes" and the point-to-region lookups this project does run
in its own PostGIS.

!!! note "Not in the Commons, yet"
    Reverse geocoding, asking "what place is at this coordinate?", is how a "starts near Namur" or
    "you are here, near X" feature is usually built elsewhere. This project does not call a
    reverse-geocode service anywhere. Where a place name is needed, it is captured once, at the
    moment a rider actually searches for and picks a named place, and stored from then on.
    `docs/specs/route-domain.md` lists deriving route start-town labels this way as recorded future
    work, not something built today.

<!-- UNANCHORED id=U40 type=absent concept="reverse geocoding (coordinates -> place name)" -->

## What to carry into chapter 5

- `ST_Contains(a, b)`: is `b` entirely inside `a`? A topology question, no cast needed.
- `ST_Intersects(a, b)`: do they share any point at all? The broadest yes/no test, and the one a
  spatial index can serve directly.
- `ST_DWithin(a, b, d)`: are they within distance `d`? Cast to `geography` when `d` means real
  metres, per chapter 3.
- `ST_Distance(a, b)` and `ST_ClosestPoint(a, b)`: how far apart, and exactly where the nearest point
  is. One returns a number, the other returns a shape.
- `ST_Buffer(g, d)`: grow a shape by a real distance; a line becomes a corridor, a point becomes a
  circle.
- `ST_LineLocatePoint(line, point)`: how far along a line, as a fraction from 0 to 1. The tool for
  "in what order," not "how far" or "where exactly."
- Geocoding is a text-search problem with a spatial tiebreak, and this project only ever asks it in
  one direction: name to coordinates, never coordinates back to name.

Every predicate above works by checking every row it is asked about. That is exactly fine on a
catalog with a few hundred entries and exactly ruinous on one with hundreds of thousands. Chapter 5
is what closes that gap.

## Try it

!!! tip "Hands-on: how far along, on a real ride"
    Ask the two questions `RideCheckService` asks about a real recommended route and a real catalog
    item: how far off the route does it sit, and how far along the route is it. Both come from
    `make course-data`. The route is selected by `name`, "Rondje Super Stockeu"; the item, the
    waterfall that sits right beside its start, by `source_ref`, the seed's own stable key
    (`manual:cascade-de-coo`), because an item's `name` stops being unique once a harvest adds an
    OpenStreetMap or Wikidata row for the same place. Ids are assigned per install and will not
    match anyone else's.

    <!-- CODE-ILLUSTRATIVE psql query against the dev catalog, the same ST_Distance / ST_ClosestPoint / ST_LineLocatePoint pattern RideCheckService::corridorGroups() uses -->
    ```sql
    SELECT
      round(ST_Distance(i.geom::geography, r.geom::geography)::numeric, 1)      AS dist_m,
      round(ST_LineLocatePoint(r.geom, ST_ClosestPoint(i.geom, r.geom))::numeric, 3) AS frac
    FROM recommended_route r, item i
    WHERE r.name = 'Rondje Super Stockeu' AND i.source_ref = 'manual:cascade-de-coo';
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM fresh-clone; sample output; a re-seeded fixture can move the decimals a little -->
    ```text
     dist_m | frac
    --------+-------
       19.8 | 0.005
    ```

    `dist_m` is the plain answer to "how far off the track": about 20 real metres, cast to
    `geography` the way chapter 3 said a distance in metres should be. `frac` is the more
    interesting one: `0.005` means the waterfall's nearest point on the route sits half a percent of
    the way along it, measured from the route's very first vertex, right near the start, which is
    exactly where a waterfall at the start of a loop ought to land. Multiply that fraction by the
    route's total length and you get the same "kilometre 23.4"-style figure `RideCheckService` shows
    a rider; here it says "you'd meet this almost immediately," which anyone who knows Rondje Super
    Stockeu can check against the ride itself. Now drop the single-item filter and let the corridor
    answer for every seeded pin within 3 km of the line, ordered the way a rider would meet them:

    <!-- CODE-ILLUSTRATIVE psql query against the same two tables, ordering a whole corridor by how far along the route each pin sits -->
    ```sql
    SELECT i.name,
      round(ST_Distance(i.geom::geography, r.geom::geography)::numeric, 1)           AS dist_m,
      round(ST_LineLocatePoint(r.geom, ST_ClosestPoint(i.geom, r.geom))::numeric, 3) AS frac
    FROM recommended_route r, item i
    WHERE r.name = 'Rondje Super Stockeu' AND i.source = 'manual'
      AND ST_DWithin(i.geom::geography, r.geom::geography, 3000)
    ORDER BY frac;
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM fresh-clone; sample output; every row is a seeded pin, the decimals move with the seed, the order does not -->
    ```text
                name             | dist_m | frac
    -----------------------------+--------+-------
     Cascade de Coo              |   19.8 | 0.005
     Fontaine Nicolay · Stavelot |  154.6 | 0.217
     Stavelot Abbey              |   19.8 | 0.273
     Public fountain · Stavelot  |   85.6 | 0.276
     Fountain · Stavelot centre  |   10.2 | 0.277
     North Bike · Stavelot       |  471.8 | 0.280
     Côte de Stockeu             |    3.9 | 0.294
     Hockai · via RAVeL L44a     |  291.6 | 0.843
    (8 rows)
    ```

    Read that ordering as a ride: the waterfall almost immediately, then the whole of Stavelot in a
    tight cluster around 27% of the way round, the Côte de Stockeu climbing out of it at 30%, and the
    long RAVeL drag near the end. `frac` is doing all of that work, sorting by `i.id` or by `dist_m`
    would scramble it, and that ordering is the entire reason `ST_LineLocatePoint` is in this function
    at all.
