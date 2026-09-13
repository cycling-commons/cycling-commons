<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Metres vs degrees

A rider opens the map, drops a pin somewhere in Wallonia, and sets it as their base location. Then
they set a radius: "show me the regions within 50 km of here." Fifty kilometres is a distance. It is
also, from this point on, just a number sitting in a settings form, and the thing it has to be
compared against is `region.geom`, which holds that region's outline in degrees, exactly as chapter
1 and chapter 2 described. Kilometres in, degrees on disk. Something has to translate between the
two, and this chapter is about how that translation is done properly, and the ways it is done
wrong.

The narrower version of the same question, "is the fountain within 5 km of me?", belongs to chapter
4, which covers the functions that ask a spatial question at all. This chapter comes first because
both questions share one underlying number, and getting that number wrong breaks both of them the
same way.

## Degrees are not metres

The shortcut looks like ordinary unit conversion, which is why it is taken. A degree of latitude
is about 111 km, chapter 1 already established that, so 50 km is
about 50 / 111.32 ≈ **0.45 degrees**. Use that number as a radius, and skip straight to comparing
coordinates:

<!-- CODE-ILLUSTRATIVE naive degree-radius query, not our code -->
```sql
-- Looks reasonable. It is wrong twice over.
WHERE ST_DWithin(r.geom, ST_SetSRID(ST_Point(:lng, :lat), 4326), 0.45)
```

`ST_DWithin` there is a **predicate**: a function that takes two shapes plus, in this case, one
threshold, and answers nothing but true or false. Chapter 4 is the full vocabulary of them; the word
is worth having now because the rest of this chapter turns on one of its arguments. Hold that query
in your head, the "Real example" section below is the same predicate, with two small pieces added
back in, and the difference is the whole chapter.

This is wrong in two separate ways at once.

**First, it is the wrong shape.** Chapter 1 showed that a degree of latitude is worth about 111 km
almost anywhere, but a degree of longitude shrinks by a factor of `cos(latitude)` as you move away
from the equator, about 71 km at 50°N, roughly two-thirds as much. `0.45` used as a single radius,
applied equally to latitude and longitude, does not draw a circle on the ground. It draws an
ellipse, flattened along the east-west axis, because that axis buys less real ground per degree than
the north-south one does.

**Second, and independently, it is the wrong size.** `50 / 111.32` only holds due north and south of
the point, where a degree really is worth 111.32 km. It does not hold due east or west, so even the
one direction where the ellipse happens to come out round is only accidentally the right size, and
the squashed direction is smaller again on top of that.

The picture below uses a rounder number, 10 km, so the arithmetic is easy to check by hand. The same
shrinkage applies at any radius, including the rider's 50 km.

<figure class="gis-fig"><svg viewBox="0 0 640 740" role="img" aria-labelledby="f5-t f5-d" xmlns="http://www.w3.org/2000/svg"><title id="f5-t">A true 10 kilometre circle against the shape a naive same-degree circle actually produces at 50 degrees north</title><desc id="f5-d">One point, a rider's base location at 50 degrees north, with two shapes drawn around it at the same scale. The accent-coloured true circle has a 10 kilometre radius in every direction. The muted-coloured naive shape comes from taking 0.09 degrees, the usual rule-of-thumb conversion of 10 kilometres, and using it as a radius equally in every direction. Because a degree of longitude buys less ground than a degree of latitude at this latitude, that shape is not a circle: it reaches the true 10 kilometre boundary to the north and to the south, where a degree of latitude behaves normally, but reaches only about 6.4 kilometres to the east and to the west. Two tinted lens-shaped regions, one on each side, mark the gap between the naive shape and the true circle: real ground, up to 3.6 kilometres wide, that a search built on the naive shape silently excludes.</desc><defs><marker id="gis-arrow-f5" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs><text class="gis-label-sm" x="320" y="42" text-anchor="middle">A "10 km" search from a point at 50° N</text><path class="gis-fill-ochre" fill-opacity=".22" d="M 320 200 A 190 190 0 0 1 320 580 A 122 190 0 0 0 320 200 Z"/><path class="gis-fill-ochre" fill-opacity=".22" d="M 320 200 A 190 190 0 0 0 320 580 A 122 190 0 0 1 320 200 Z"/><ellipse class="gis-muted" cx="320" cy="390" rx="122" ry="190"/><circle class="gis-accent" cx="320" cy="390" r="190"/><line class="gis-ink" x1="320" y1="390" x2="320" y2="204" marker-end="url(#gis-arrow-f5)"/><line class="gis-muted" x1="320" y1="390" x2="438" y2="390" marker-end="url(#gis-arrow-f5)"/><line class="gis-clay" x1="442" y1="390" x2="506" y2="390" marker-end="url(#gis-arrow-f5)"/><circle class="gis-ink gis-fill-ink" cx="320" cy="390" r="7"/><text class="gis-label-sm gis-halo" x="320" y="182" text-anchor="middle">N</text><text class="gis-label-sm gis-halo" x="524" y="382" text-anchor="middle">E</text><text class="gis-label-sm gis-halo" x="332" y="300" text-anchor="start">10 km</text><text class="gis-label-sm gis-halo" x="360" y="378" text-anchor="middle">≈6.4 km</text><text class="gis-label-sm gis-halo" x="474" y="378" text-anchor="middle">gap</text><text class="gis-label-sm gis-halo" x="320" y="414" text-anchor="middle">you</text><rect class="gis-ink gis-fill-accent" x="40" y="616" width="26" height="26"/><text class="gis-label-sm" x="76" y="636">geography cast, a true 10 km circle</text><rect class="gis-ink gis-fill-glacier" x="40" y="654" width="26" height="26"/><text class="gis-label-sm" x="76" y="674">geometry, same 0.09° both ways</text><rect class="gis-ink gis-fill-ochre" fill-opacity=".22" x="40" y="692" width="26" height="26"/><text class="gis-label-sm" x="76" y="712">gap, ground the naive shape misses</text></svg><figcaption>The accent circle is a true 10 km radius, the same in every direction. The muted shape is what you get from treating 0.09° (the usual rule-of-thumb conversion of 10 km) as a fixed radius in both latitude and longitude: it lands on the true boundary to the north and south, where a degree of latitude is worth 111 km as always, and falls back to about 6.4 km to the east and west, using the same 111 km and 71 km figures chapter 1's own figure already put on the page. The two tinted slivers are the gap: real ground, up to 3.6 km wide on each side, that sits inside the true 10 km circle and outside the naive one, places a "within 10 km" search would silently fail to find, while still finding everything due north or south.</figcaption></figure>

Read the picture literally: the naive shape matches the true circle exactly to the north and to the
south, a degree of latitude never shrinks, so there is nothing to get wrong in that direction. To
the east and west it falls short, stopping at about 6.4 km. The two tinted slivers are the
consequence: real places, up to 3.6 km further out on each side, that are genuinely within 10 km of
the point on the ground, and that a query using the naive shape will never return. Nothing throws an
error. The query runs, returns rows, and simply returns fewer of them than "within 10 km" promised,
and which rows go missing depends on which side of the point they happen to sit on.

## Two types, one column

PostGIS answers this by giving every stored shape two different types it can be read as:
`geometry` and `geography`. This is a different distinction from chapter 2's *shape kinds*, Point,
LineString, Polygon, and the permissive `Geometry` that means "any of them." Here, `geometry` and
`geography` are two ways of doing the maths on the same value, and every geometry column in this
project is declared as some flavour of `geometry`. Most of them use chapter 2's permissive
`geometry(Geometry, 4326)`, every column the Symfony migrations create, `item.geom`,
`region.geom`, `recommended_route.geom`, `heat_point.geom`, `submission.geom`, `users.base_point`.
The pipeline-owned `coverage_poi.geom` is tighter:

<!-- CODE-FROM pipeline/coverage/load.py -->
```sql
geom          geometry(Point, 4326) NOT NULL, -- nodes as-is; ways centroid at load
```

because that table only ever holds points. What varies between
them is the shape kind. What never varies is the first word: nothing in this project's schema is
declared `geography` anywhere. Chapter 5 introduces one thing that looks like an exception and is
not: an *index* built over `(geom::geography)`, which stores the cast's result without changing the
column's own type.

- **`geometry`** treats the world as a flat plane. Distance, area, "is this point inside that ring",
  all worked out with ordinary planar formulas. Its units are whatever the SRID says, and for 4326
  that is degrees. Ask a `geometry` function for a distance and it hands back a number of degrees,
  which, as the section above just showed, is not a distance at all until you know which direction
  it was measured in.
- **`geography`** treats the world as a curved surface, an ellipsoid, the standard slightly-squashed
  model of the real Earth, and its functions always return real ground distances, in metres, at any
  latitude.

Same stored data. `::geography` is a **cast**, applied for the length of one function call, not a
different column and not a copy. Write `r.geom::geography` and PostGIS reads that same value through
the curved-earth model for just that expression; the column itself never changes. (Whether an index
can serve a comparison written that way is chapter 5's question; one table here answers it by
adding a second index on the cast.)

## What the cast costs

If `geography` always gives the right answer, why not cast everywhere and stop worrying about it?
Because the curved-earth maths is genuinely more expensive than the flat-plane version. A `geometry`
calculation is plane trigonometry, subtract two numbers, maybe a square root. A `geography`
calculation solves distances on an ellipsoid, for every row a query touches. On a handful of rows
the difference is invisible. On a real table it is not.

Chapter 5 walks through exactly this in this codebase: `Catalog/RideCheckService.php`,
`RideCheckService::corridorGroups()` compares a rider's uploaded track against every catalog item.
Written the obvious way, `ST_DWithin` with `::geography` on both sides, PostgreSQL has no faster way
to answer it than checking the ellipsoid maths against every single row in the table. The design
note that shipped the fix records that shape at 62 seconds live
(`docs/specs/Dated/2026-07-14-town-search-and-ride-check-design.md`); the shipped version answers in
under one.

The fix is chapter 5's subject, not this chapter's.
What belongs here is smaller: **cast to `geography` when the question is genuinely "how far apart, in
the real world", not by reflex, and not just because metres sound more trustworthy than degrees.**

## Real example

Here is the pattern this project actually uses for the question this chapter opened with, "which
regions are within N km of this rider's base point?", in `Service/BaseAreaResolver.php`,
`BaseAreaResolver::resolve()`:

<!-- CODE-FROM web/src/Service/BaseAreaResolver.php -->
```php
public function resolve(float $lat, float $lng, int $radiusKm): array
```

`$radiusKm` arrives exactly as a rider typed it into a settings form, kilometres. Before it reaches
SQL it is multiplied by `1000.0` into metres, because metres are what `::geography` deals in. The
query itself:

<!-- CODE-FROM web/src/Service/BaseAreaResolver.php -->
```sql
ST_DWithin(r.geom::geography, ST_SetSRID(ST_Point(:lng, :lat), 4326)::geography, :m)
```

Compare this against the naive version earlier in this chapter: same predicate, same shape, and
exactly two things added, `::geography` on the stored region, and `::geography` on the
freshly-built point.

### Cast both sides: but not for the reason you would guess

The obvious guess is that casting only one side leaves `ST_DWithin` comparing a real distance
against a degree pretending to be one: the bug from the top of this chapter, one layer further down.
That guess is wrong, and the real problem is more awkward than the bug would have been.

PostGIS registers `geometry → geography` as an **implicit** cast. A cast marked implicit is one
PostgreSQL is allowed to insert on its own, without being asked. So in a call where only one
argument carries `::geography`, function resolution does not fall back to the `geometry` overload
with its degree-based threshold. It picks the `geography` overload, the only one both arguments
can be made to fit, and quietly casts the other argument to match. The third argument is still
metres. The rows that come back are the same rows.

Both halves of that are checkable in a `psql` session against the dev database (chapter 5 shows how
to open one). First the cast catalogue:

<!-- CODE-ILLUSTRATIVE ad-hoc psql query against Postgres system catalogs, not our code -->
```sql
SELECT castsource::regtype, casttarget::regtype, castcontext
  FROM pg_cast
 WHERE castsource::regtype::text IN ('geometry', 'geography')
   AND casttarget::regtype::text IN ('geometry', 'geography');
```

`geometry → geography` comes back with `castcontext` of `i`, for implicit. The reverse,
`geography → geometry`, comes back `e`, for explicit, which is exactly why `::geometry` always has
to be written by hand and `::geography` sometimes does not have to be. Then the behaviour that
follows from it, on two points 0.005° of longitude apart at 50° N:

<!-- CODE-ILLUSTRATIVE ad-hoc psql demo of implicit-cast behaviour, not our code -->
```sql
WITH p AS (
  SELECT ST_SetSRID(ST_Point(4.0,   50.0), 4326) AS a,
         ST_SetSRID(ST_Point(4.005, 50.0), 4326) AS b
)
SELECT ST_DWithin(a, b, 1)                       AS both_geometry,
       ST_DWithin(a::geography, b, 1)            AS one_side_cast,
       ST_DWithin(a::geography, b::geography, 1) AS both_cast,
       ST_Distance(a::geography, b)              AS one_sided_distance
FROM p;
```

`both_geometry` is **true**: with no cast anywhere, the threshold `1` is read as one degree, and
0.005 degrees is comfortably inside it. `one_side_cast` and `both_cast` are **both false**, and
`one_sided_distance` comes back as `358.47876801`, metres, on the ellipsoid, from a call in which
only one of the two arguments carries a cast.

So the half-cast version is not wrong. It is **right by accident**, which is the harder thing to
find in a codebase, and there are two reasons to write the second cast anyway.

**It costs exactly what the full cast costs.** Whichever side you leave bare, PostgreSQL casts that
one for you. So the indexed column ends up wrapped in `::geography` either way, by your own hand if
you wrote the cast on it, by the planner's if you wrote the cast on the other side. Chapter 5 is
entirely about what a cast on an indexed column does to a query plan, and a half-cast predicate
loses the index precisely as thoroughly as a fully cast one. `EXPLAIN` prints the same filter for
both forms, with `::geography` on the column in each. The shorter spelling reads cheaper than it
plans.

**It leaves the units unreadable.** `ST_DWithin(r.geom::geography, p, 50000)` and
`ST_DWithin(r.geom, p, 0.45)` are both valid, and to the eye they differ by one cast and one number.
Working out which of `50000` and `0.45` is metres and which is degrees requires already knowing the
implicit-cast rule. Writing both casts states the unit in the text of the query, where the next
reader cannot miss it and cannot get it wrong. That is worth far more than the eleven characters it
costs.

### The ordering

The ordering finishes the job. `BaseAreaResolver`'s own doc comment sums the method up as "My-area
regions from a coarse base point + radius. Cap 8; operational regions only." The `ORDER BY` clause
is where the priority inside that cap is actually written down:

<!-- CODE-FROM web/src/Service/BaseAreaResolver.php -->
```sql
ORDER BY ST_Contains(r.geom, ST_SetSRID(ST_Point(:lng, :lat), 4326)) DESC,
         ST_Distance(r.geom::geography, ST_SetSRID(ST_Point(:lng, :lat), 4326)::geography) ASC,
         r.area_km2 ASC NULLS LAST, r.id ASC
```

the region that genuinely contains the rider's point always sorts to the top, ahead of any
neighbour that merely happens to be close, then by `ST_Distance(…)` ascending, using the same
`::geography` cast, so "nearby" means nearest in real ground distance, not nearest in whatever order
degrees happen to fall in.

## Rule of thumb

- **Comparing a distance in metres**, "is this within N km?": cast to `geography`. This project's
  own example is the `ST_DWithin` line above.
- **Testing whether a point is inside a polygon**: `geometry`, no cast needed. It is a topology
  question, "is this point inside this ring", not a distance question, so there is no unit to get
  wrong. Chapter 1 already introduced the query that does exactly this,
  `Contribution/SpatialResolver.php`, `SpatialResolver::resolve()`, and it never casts to
  `geography`, because it never needs to.
- **Buffering by a real-world distance**: cast to `geography` for the buffer, then cast straight
  back. `Catalog/RideCheckService.php`, `RideCheckService::corridorGroups()` builds a search corridor
  around an uploaded ride with `ST_Buffer((SELECT g FROM track)::geography, :radius)::geometry`: the
  buffer itself runs in `geography` so `:radius` really is metres, and the *result* is cast straight
  back to `geometry`, because the very next thing done with it, `ST_Intersects`, is a topology
  question again, and there is no reason to pay the curved-earth cost twice.

That corridor build reads, in full:

<!-- CODE-FROM web/src/Catalog/RideCheckService.php -->
```sql
SELECT ST_Buffer((SELECT g FROM track)::geography, :radius)::geometry AS b
```

`::geography` on the way in, `::geometry` on the way straight back out, one line, both casts, each
earning its keep.

## What to carry into chapter 4

- A degree is an angle, not a distance, chapter 1's lesson, and treating it as one produces an
  answer that is wrong in both shape and size at once, silently, with no error to notice.
- `geometry` and `geography` read the same stored value two different ways: flat-plane maths in
  whatever the SRID's units are, or curved-earth maths that always returns metres.
- `::geography` is a cast for the length of one expression. It is never a second column, and nothing
  in this project's schema is ever declared as `geography`.
- Cast **both** sides. A one-sided cast is not the degrees-versus-metres bug returning: the implicit
  `geometry → geography` cast silently repairs it and the answer comes out right. It is worse than
  that, it plans exactly as expensively as the full cast, and it leaves the next reader unable to
  tell from the query which unit the threshold is in.
- Cast when the question is genuinely about real ground distance. Reach for it by need, not by
  reflex, chapter 5 covers what the reflexive version costs.

The fountain now has a position, a shape, and a unit of ground distance it can be compared against.
What is still missing is the vocabulary for asking about it: "is it inside this region", "did a rider
pass it", "what is nearby." Chapter 4 is that vocabulary.


## Further reading

- [PostGIS geography type](https://postgis.net/docs/using_postgis_dbmanagement.html#PostGIS_Geography): when to reach for it, from the manual that implements it.

## Try it

!!! tip "Hands-on: the same 'within 10 km' question, two answers"
    Ask whether two real seeded pins in the Amblève valley, a bike shop in Stavelot and a repair
    station in Malmedy, sit within 10 km of each other, once the naive rule-of-thumb way this chapter
    opened with and once cast to `geography`, and watch the two answers disagree on data already
    sitting in the database. Both pins come from `make course-data`, and both are selected by
    `source_ref`, the seed's own stable key. Ids are assigned per install, and `name` is not unique
    either: once the harvests run, a place the seed names can also arrive from OpenStreetMap or
    Wikidata under the same name, and the seed then skips its own copy. `manual:<slug>` names exactly
    one row, or none.

    <!-- CODE-ILLUSTRATIVE psql query against the dev catalog; two seeded pins in the Amblève valley, separated mostly east-west -->
    ```sql
    SELECT
      ST_DWithin(a.geom, b.geom, 10.0/111.32)                 AS within_10km_naive_degrees,
      ST_DWithin(a.geom::geography, b.geom::geography, 10000) AS within_10km_geography
    FROM item a, item b
    WHERE a.source_ref = 'manual:north-bike-stavelot'
      AND b.source_ref = 'manual:repair-station-malmedy';
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM fresh-clone; sample output; the coordinates are fixed by the seed -->
    ```text
     within_10km_naive_degrees | within_10km_geography
    ---------------------------+------------------------
     f                         | t
    ```

    `10.0/111.32` is exactly the rule-of-thumb conversion from the top of this chapter. That version
    says the Malmedy station is *not* within 10 km of the Stavelot shop. The `::geography` version
    says it is. Ask for the real numbers behind the disagreement:

    <!-- CODE-ILLUSTRATIVE psql query against the same two rows, showing the real distance the naive threshold was compared against -->
    ```sql
    SELECT
      round(ST_Distance(a.geom::geography, b.geom::geography)::numeric, 0) AS real_metres,
      round(ST_Distance(a.geom, b.geom)::numeric, 4)                       AS naive_degrees
    FROM item a, item b
    WHERE a.source_ref = 'manual:north-bike-stavelot'
      AND b.source_ref = 'manual:repair-station-malmedy';
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM fresh-clone; sample output; both pins have fixed seeded coordinates -->
    ```text
     real_metres | naive_degrees
    -------------+---------------
            7247 |        0.0956
    ```

    The two really are 7.25 km apart, comfortably inside the 10 km ask, but that distance comes out
    to 0.0956 degrees, bigger than the naive threshold of `10.0/111.32 ≈ 0.0898`, because the gap
    between them is mostly east–west, and at this latitude a degree of longitude is only worth about
    71 km (chapter 1), not the 111 km the naive conversion assumes everywhere. `::geography` gets the
    ground-truth answer right; the naive comparison silently excludes ground that is genuinely in
    range. Neither query errors. They just disagree, exactly the way this chapter's opening section
    said they would, on real rows, not hypothetical ones.

    The disagreement is a property of the *direction* between the two points, not of these two pins.
    Swap in `'manual:cascade-de-coo'` for the Malmedy station, 4.2 km away and well under both
    thresholds, and the two columns agree again, `t` and `t`. The naive rule only lies in the window
    between `0.0898°` of longitude and 10 real kilometres, which at 50°N is roughly 6.4 km to 10 km
    of east–west separation.
