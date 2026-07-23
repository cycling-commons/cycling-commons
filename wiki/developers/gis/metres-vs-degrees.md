<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Metres vs degrees

A rider opens the map, drops a pin somewhere in Wallonia, and sets it as their base location. Then
they set a radius: "show me the regions within 50 km of here." Fifty kilometres is a distance. It is
also, from this point on, just a number sitting in a settings form β€” and every geometry column this
project has costs its way through `region.geom`, storing that region's outline in degrees, per
chapter 1 and chapter 2. Kilometres in, degrees on disk. Something has to translate between the two,
and this chapter is about how that translation is done properly, and the ways it is done wrong.

The narrower version of the same question β€” "is the fountain within 5 km of me?" β€” belongs to chapter
4, which covers the functions that ask a spatial question at all. This chapter comes first because
both questions share one underlying number, and getting that number wrong breaks both of them the
same way.

## The bug everybody writes once

Here is the shortcut almost everyone reaches for first, because it looks like ordinary unit
conversion. A degree of latitude is about 111 km, chapter 1 already established that, so 50 km is
about 50 / 111.32 β‰ˆ **0.45 degrees**. Use that number as a radius, and skip straight to comparing
coordinates:

```sql
-- Looks reasonable. It is wrong twice over.
WHERE ST_DWithin(r.geom, ST_SetSRID(ST_Point(:lng, :lat), 4326), 0.45)
```

Hold that query in your head β€” the "Real example" section below is the same predicate, with two
small pieces added back in, and the difference is the whole chapter.

This is wrong in two separate ways at once.

**First, it is the wrong shape.** Chapter 1 showed that a degree of latitude is worth about 111 km
almost anywhere, but a degree of longitude shrinks by a factor of `cos(latitude)` as you move away
from the equator β€” about 71 km at 50Β°N, roughly two-thirds as much. `0.45` used as a single radius,
applied equally to latitude and longitude, does not draw a circle on the ground. It draws an
ellipse, flattened along the east-west axis, because that axis buys less real ground per degree than
the north-south one does.

**Second, and independently, it is the wrong size.** `50 / 111.32` only holds due north and south of
the point, where a degree really is worth 111.32 km. It does not hold due east or west, so even the
one direction where the ellipse happens to come out round is only accidentally the right size β€” and
the squashed direction is smaller again on top of that.

The picture below uses a rounder number, 10 km, so the arithmetic is easy to check by hand. The same
shrinkage applies at any radius, including the rider's 50 km.

<figure class="gis-fig"><svg viewBox="0 0 640 740" role="img" aria-labelledby="f5-t f5-d" xmlns="http://www.w3.org/2000/svg"><title id="f5-t">A true 10 kilometre circle against the shape a naive same-degree circle actually produces at 50 degrees north</title><desc id="f5-d">One point, a rider's base location at 50 degrees north, with two shapes drawn around it at the same scale. The accent-coloured true circle has a 10 kilometre radius in every direction. The muted-coloured naive shape comes from taking 0.09 degrees, the usual rule-of-thumb conversion of 10 kilometres, and using it as a radius equally in every direction. Because a degree of longitude buys less ground than a degree of latitude at this latitude, that shape is not a circle: it reaches the true 10 kilometre boundary to the north and to the south, where a degree of latitude behaves normally, but reaches only about 6.4 kilometres to the east and to the west. Two tinted lens-shaped regions, one on each side, mark the gap between the naive shape and the true circle: real ground, up to 3.6 kilometres wide, that a search built on the naive shape silently excludes.</desc><defs><marker id="gis-arrow-f5" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs><text class="gis-label-sm" x="320" y="42" text-anchor="middle">A "10 km" search from a point at 50Β° N</text><path class="gis-fill-ochre" fill-opacity=".22" d="M 320 200 A 190 190 0 0 1 320 580 A 122 190 0 0 0 320 200 Z"/><path class="gis-fill-ochre" fill-opacity=".22" d="M 320 200 A 190 190 0 0 0 320 580 A 122 190 0 0 1 320 200 Z"/><ellipse class="gis-muted" cx="320" cy="390" rx="122" ry="190"/><circle class="gis-accent" cx="320" cy="390" r="190"/><line class="gis-ink" x1="320" y1="390" x2="320" y2="204" marker-end="url(#gis-arrow-f5)"/><line class="gis-muted" x1="320" y1="390" x2="438" y2="390" marker-end="url(#gis-arrow-f5)"/><line class="gis-clay" x1="442" y1="390" x2="506" y2="390" marker-end="url(#gis-arrow-f5)"/><circle class="gis-ink gis-fill-ink" cx="320" cy="390" r="7"/><text class="gis-label-sm gis-halo" x="320" y="182" text-anchor="middle">N</text><text class="gis-label-sm gis-halo" x="524" y="382" text-anchor="middle">E</text><text class="gis-label-sm gis-halo" x="332" y="300" text-anchor="start">10 km</text><text class="gis-label-sm gis-halo" x="360" y="378" text-anchor="middle">β‰ˆ6.4 km</text><text class="gis-label-sm gis-halo" x="474" y="378" text-anchor="middle">gap</text><text class="gis-label-sm gis-halo" x="320" y="414" text-anchor="middle">you</text><rect class="gis-ink gis-fill-accent" x="40" y="616" width="26" height="26"/><text class="gis-label-sm" x="76" y="636">geography cast β€” a true 10 km circle</text><rect class="gis-ink gis-fill-glacier" x="40" y="654" width="26" height="26"/><text class="gis-label-sm" x="76" y="674">geometry, same 0.09Β° both ways</text><rect class="gis-ink gis-fill-ochre" fill-opacity=".22" x="40" y="692" width="26" height="26"/><text class="gis-label-sm" x="76" y="712">gap β€” ground the naive shape misses</text></svg><figcaption>The accent circle is a true 10 km radius, the same in every direction. The muted shape is what you get from treating 0.09Β° (the usual rule-of-thumb conversion of 10 km) as a fixed radius in both latitude and longitude: it lands on the true boundary to the north and south, where a degree of latitude is worth 111 km as always, and falls back to about 6.4 km to the east and west, using the same 111 km and 71 km figures chapter 1's own figure already put on the page. The two tinted slivers are the gap: real ground, up to 3.6 km wide on each side, that sits inside the true 10 km circle and outside the naive one β€” places a "within 10 km" search would silently fail to find, while still finding everything due north or south.</figcaption></figure>

Read the picture literally: the naive shape matches the true circle exactly to the north and to the
south β€” a degree of latitude never shrinks, so there is nothing to get wrong in that direction. To
the east and west it falls short, stopping at about 6.4 km. The two tinted slivers are the
consequence: real places, up to 3.6 km further out on each side, that are genuinely within 10 km of
the point on the ground, and that a query using the naive shape will never return. Nothing throws an
error. The query runs, returns rows, and simply returns fewer of them than "within 10 km" promised β€”
and which rows go missing depends on which side of the point they happen to sit on.

## Two types, one column

PostGIS answers this by giving every stored shape two different types it can be read as:
`geometry` and `geography`. This is a different distinction from chapter 2's *shape kinds* β€” Point,
LineString, Polygon, and the permissive `Geometry` that means "any of them." Here, `geometry` and
`geography` are two ways of doing the maths on the same value, and every geometry column in this
project is declared once, as `geometry` β€” chapter 2's `geometry(Geometry, 4326)`. Nothing in the
schema is ever declared `geography`.

- **`geometry`** treats the world as a flat plane. Distance, area, "is this point inside that ring" β€”
  all worked out with ordinary planar formulas. Its units are whatever the SRID says, and for 4326
  that is degrees. Ask a `geometry` function for a distance and it hands back a number of degrees β€”
  which, as the section above just showed, is not a distance at all until you know which direction
  it was measured in.
- **`geography`** treats the world as a curved surface β€” an ellipsoid, the standard slightly-squashed
  model of the real Earth β€” and its functions always return real ground distances, in metres, at any
  latitude.

Same stored data. `::geography` is a **cast**, applied for the length of one function call, not a
different column, not a copy, and not a second index to keep in sync. Write `r.geom::geography` and
PostGIS reads that same value through the curved-earth model for just that expression; the column
itself never changes.

## What the cast costs

If `geography` always gives the right answer, why not cast everywhere and stop worrying about it?
Because the curved-earth maths is genuinely more expensive than the flat-plane version. A `geometry`
calculation is plane trigonometry β€” subtract two numbers, maybe a square root. A `geography`
calculation solves distances on an ellipsoid, for every row a query touches. On a handful of rows
the difference is invisible. On a real table it is not.

Chapter 5 walks through exactly this happening in this codebase: `Catalog/RideCheckService.php`,
`RideCheckService::corridorGroups()` once compared a rider's uploaded track against every catalog
item using `ST_DWithin` with `::geography` on both sides, and PostgreSQL had no faster way to answer
it than checking the ellipsoid maths against every single row in the table. The method's own comment
records the result plainly: the naive `ST_DWithin(::geography)` formulation "seq-scanned with
spheroid maths against the full track per item." The fix is chapter 5's subject, not this chapter's β€”
what belongs here is smaller: **cast to `geography` when the question is genuinely "how far apart, in
the real world" β€” not by reflex, and not just because metres sound more trustworthy than degrees.**

## Real example

Here is the pattern this project actually uses for the question this chapter opened with β€” "which
regions are within N km of this rider's base point?" β€” in `Service/BaseAreaResolver.php`,
`BaseAreaResolver::resolve()`:

```php
public function resolve(float $lat, float $lng, int $radiusKm): array
```

`$radiusKm` arrives exactly as a rider typed it into a settings form β€” kilometres. Before it reaches
SQL it is multiplied by `1000.0` into metres, because metres are what `::geography` deals in. The
query itself:

```sql
ST_DWithin(r.geom::geography, ST_SetSRID(ST_Point(:lng, :lat), 4326)::geography, :m)
```

Compare this against the naive version earlier in this chapter: same predicate, same shape, and
exactly two things added β€” `::geography` on the stored region, and `::geography` on the freshly-built
point. Both sides have to be cast, not just one, because `ST_DWithin` needs both of its arguments in
the same type to know what its third argument is even measured in. Cast only one side and you are
back to comparing a real distance against a degree pretending to be one β€” the exact bug from the top
of this chapter, one layer further down.

The ordering finishes the job. `BaseAreaResolver`'s own doc comment describes it directly: "ST_DWithin
over region polygons, containing region always first." The query sorts by `ST_Contains(r.geom, β€¦)
DESC` before anything else β€” the region that genuinely contains the rider's point always sorts to
the top, ahead of any neighbour that merely happens to be close β€” then by `ST_Distance(β€¦)` ascending,
using the same `::geography` cast, so "nearby" means nearest in real ground distance, not nearest in
whatever order degrees happen to fall in.

## Rule of thumb

- **Comparing a distance in metres** β€” "is this within N km?" β€” cast to `geography`. This project's
  own example is the `ST_DWithin` line above.
- **Testing whether a point is inside a polygon** β€” `geometry`, no cast needed. It is a topology
  question, "is this point inside this ring", not a distance question, so there is no unit to get
  wrong. Chapter 1 already introduced the query that does exactly this β€”
  `Contribution/SpatialResolver.php`, `SpatialResolver::resolve()` β€” and it never casts to
  `geography`, because it never needs to.
- **Buffering by a real-world distance** β€” cast to `geography` for the buffer, then cast straight
  back. `Catalog/RideCheckService.php`, `RideCheckService::corridorGroups()` builds a search corridor
  around an uploaded ride with `ST_Buffer((SELECT g FROM track)::geography, :radius)::geometry`: the
  buffer itself runs in `geography` so `:radius` really is metres, and the *result* is cast straight
  back to `geometry`, because the very next thing done with it β€” `ST_Intersects` β€” is a topology
  question again, and there is no reason to pay the curved-earth cost twice.

## What to carry into chapter 4

- A degree is an angle, not a distance β€” chapter 1's lesson β€” and treating it as one produces an
  answer that is wrong in both shape and size at once, silently, with no error to notice.
- `geometry` and `geography` read the same stored value two different ways: flat-plane maths in
  whatever the SRID's units are, or curved-earth maths that always returns metres.
- `::geography` is a cast for the length of one expression. It is never a second column, and nothing
  in this project's schema is ever declared as `geography`.
- Cast when the question is genuinely about real ground distance. Reach for it by need, not by
  reflex β€” chapter 5 covers what the reflexive version costs.

The fountain now has a position, a shape, and a unit of ground distance it can be compared against.
What is still missing is the vocabulary for asking about it: "is it inside this region", "did a rider
pass it", "what is nearby." Chapter 4 is that vocabulary.

<!-- EXERCISE-SLOT ch=3 β€” hands-on box goes here (spec D5); do not remove -->
