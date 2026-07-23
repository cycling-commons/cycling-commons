<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# The shapes

The fountain now has a position and a coordinate system to interpret it in — chapter 1 covered
both. That is enough to describe a single point. It is not enough to describe everything else this
project puts on a map.

A ride is not one position, it is a thread of them, in order, from where a rider clipped in to
where they stopped. A region is not a position either — it is an area, an outline enclosing
everywhere that counts as "inside". Three different ideas, and it would be reasonable to expect
three different kinds of database column. There are not. This project uses exactly three **shapes**
— Point, LineString, Polygon — and every geometry anywhere in this codebase is one of them. This
chapter is what those three words mean, how each one is stored, and how a shape travels from the
database to the browser as text.

## Point

A **Point** is one coordinate pair. Nothing else. It is the simplest shape there is, and it is
exactly what the fountain needs: `50.4894, 5.8792` is already a complete description of where it is.

Every catalog item in this project — every fountain, every repair stand, every viewpoint — is stored
as a Point. See `web/migrations/Version20260703153611.php`, which creates the `item` table with a
`geom geometry(Geometry, 4326)` column: one geometry value per row, and for `item` that value is
always a Point.

## LineString

A **LineString** is an ordered list of points, joined into a path. "Ordered" is doing real work in
that sentence: a LineString is not just a bag of coordinates, it is those coordinates *in a
sequence*, and the sequence is part of what it means. Reverse the list and you have not described
the same shape read backwards — you have described riding the same road the other way.

A recommended route is stored as a LineString: the ordered points of the ride, from start to
finish. See the same migration, `web/migrations/Version20260703153611.php`, which also creates
`recommended_route` with its own `geom geometry(Geometry, 4326)` column.

## Polygon

A **Polygon** is a closed ring of points — the first point and the last point are the same one, so
the outline joins up into an area rather than trailing off into an open path — optionally with one
or more inner rings cut out of it as holes (a region with a lake excluded from it, for instance).

A region's outline is stored as a Polygon: the boundary of Wallonia, or of a Belgian province, is
one closed ring. See `web/migrations/Version20260703152605.php`, which creates `region` with a
`geom geometry(Geometry, 4326)` column.

The direction a ring is walked in — clockwise or counter-clockwise — is called its **winding**, and
it turns out to matter to some software: it is how a renderer tells a filled area from a hole cut out
of one, without needing any other clue. PostGIS is forgiving about it; a good few other tools are
not, and quietly draw the wrong thing rather than raising an error. Chapter 10 comes back to winding
as one of the traps this series collects. Nothing in this chapter depends on getting it right.

## The Multi- forms

Real outlines are not always one ring. A region can have an island, or an exclave separated from
its own mainland by another region entirely — Wallonia does not, but plenty of real administrative
areas do. A single Polygon cannot describe two disconnected pieces of ground, so GIS adds a second
family of shapes: **MultiPoint**, **MultiLineString**, **MultiPolygon** — each one simply "more than
one of the plain version, held together as a single value".

This project already stores real examples. `Region.php` — `web/src/Catalog/Entity/Region.php` —
says as much directly in its own doc comment: `geom` holds "GeoJSON MultiPolygon (SRID 4326)". Several
of the German Bundesländer this project onboarded include North Sea and Baltic islands (Schleswig-Holstein
is one), so their `region.geom` rows are genuinely a MultiPolygon, several rings held as one value,
not a Polygon that happens to look like one.

None of this needed a new database column. Look back at the three declarations from the sections
above — `region`, `item`, `recommended_route` — and every one of them says `geometry(Geometry,
4326)`, never `geometry(Point, 4326)` or `geometry(Polygon, 4326)`. `Geometry` is the permissive
option: it means "any of the shapes above, plain or Multi-", decided per row by whatever the
application actually writes into it, not pinned once at the table level. `GeometryType::getSQLDeclaration()`
in `web/src/Catalog/Doctrine/GeometryType.php` is what generates that exact string, and it is the
same string in every migration for this reason: it is one method's output, not six independent
choices.

## GeoJSON — the wire format

Every shape above is an idea. **GeoJSON** is the text format that idea travels in — the JSON-based
way of writing a shape down so it can cross a network, sit in a request body, or be logged to a
screen. Chapter 1 mentioned it in passing; this is where it earns the promise.

<figure class="gis-fig"><svg viewBox="0 0 640 680" role="img" aria-labelledby="f3-t f3-d" xmlns="http://www.w3.org/2000/svg"><title id="f3-t">The three shapes this project stores, each on its real table and column</title><desc id="f3-d">Three stacked panels, one per shape. The top panel is labelled item.geom: a small map fragment made of two crossing lines for streets, with one filled circle marking the fountain, captioned "Point — every catalog item". The middle panel is labelled recommended_route.geom: a winding curved line with small dots marking a couple of its points and an arrowhead at the far end, captioned "LineString — an ordered ride", the arrow showing which end is the start and which is the end. The bottom panel is labelled region.geom: a closed, filled, irregular outline with its first and last point marked as the same dot, captioned "Polygon — a closed area".</desc><defs><marker id="gis-arrow-f3" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs><text class="gis-label-mono" x="30" y="49">item.geom</text><text class="gis-label-sm" x="30" y="75">Point — every catalog item</text><rect class="gis-muted" x="30" y="93" width="580" height="112"/><line class="gis-muted" x1="100" y1="93" x2="100" y2="205"/><line class="gis-muted" x1="30" y1="149" x2="610" y2="149"/><circle class="gis-ink gis-fill-accent" cx="250" cy="149" r="14"/><text class="gis-label-sm gis-halo" x="272" y="145">the fountain</text><text class="gis-label-mono" x="30" y="269">recommended_route.geom</text><text class="gis-label-sm" x="30" y="295">LineString — an ordered ride</text><rect class="gis-muted" x="30" y="313" width="580" height="112"/><path class="gis-accent" stroke-width="3" marker-end="url(#gis-arrow-f3)" d="M 60 400 C 160 320, 260 410, 360 340 S 520 400, 590 325"/><circle class="gis-fill-ink" cx="60" cy="400" r="6"/><circle class="gis-fill-ink" cx="360" cy="340" r="6"/><text class="gis-label-sm gis-halo" x="40" y="422">start</text><text class="gis-label-sm gis-halo" x="520" y="310">end</text><text class="gis-label-mono" x="30" y="489">region.geom</text><text class="gis-label-sm" x="30" y="515">Polygon — a closed area</text><rect class="gis-muted" x="30" y="533" width="580" height="112"/><path class="gis-ink gis-fill-paper" d="M 120 625 L 220 545 L 380 545 L 480 595 L 420 635 L 200 635 Z"/><circle class="gis-fill-ink" cx="120" cy="625" r="5"/><text class="gis-label-sm gis-halo" x="140" y="612">first = last</text></svg><figcaption>The same three shapes as above, each next to the real table and column that stores it. A Point is one filled dot; a LineString is an ordered line with a direction, shown here by the arrowhead at its end; a Polygon is a closed, filled outline whose first point and last point are the same one. Every one of these three columns is declared <code>geometry(Geometry, 4326)</code> — permissive about which shape it holds, not pinned to just one.</figcaption></figure>

Written as GeoJSON, the fountain is a `Point` geometry:

<!-- CODE-ILLUSTRATIVE minimal GeoJSON, hand-written -->
```json
{
  "type": "Point",
  "coordinates": [5.8792, 50.4894]
}
```

Look at the order inside `coordinates` again: `[5.8792, 50.4894]` is `[longitude, latitude]`, not
`[latitude, longitude]`. This is the same trap chapter 1 spent a whole section on, and GeoJSON is
one of the two places that section named it happening: the specification defines `coordinates` as
longitude first, always, with no exception and no configuration flag to change it.

A LineString's GeoJSON looks the same shape, just with a list of pairs instead of one:
`{"type": "LineString", "coordinates": [[5.879, 50.489], [5.881, 50.491], …]}`. A Polygon nests one
level deeper again — `coordinates` becomes a list of rings, each ring a list of point pairs, first
pair repeated last: `{"type": "Polygon", "coordinates": [[[5.87, 50.48], [5.89, 50.48], [5.89, 50.50],
[5.87, 50.50], [5.87, 50.48]]]}`. Same three shapes, same nesting-by-one-level pattern all the way
up.

## Crossing the boundary

PostGIS does not store geometry as GeoJSON internally. It keeps its own compact binary
representation, built for fast comparison and indexing, not for being read by a human or handed
straight to a browser. The application, on the other side, never wants to see that binary form —
every geometry that reaches PHP is a GeoJSON string, and every geometry PHP hands back to the
database is a GeoJSON string too. Something has to sit exactly on that boundary and translate in
both directions, on every single read and every single write, without anybody having to remember to
call it.

That something is `GeometryType` — `web/src/Catalog/Doctrine/GeometryType.php`, the same class
chapter 1 introduced for its `getSQLDeclaration()` method. Its other two methods are the translation
itself:

- `GeometryType::convertToDatabaseValueSQL()` wraps every value written into a geometry column in
  `ST_SetSRID(ST_GeomFromGeoJSON(…), 4326)` — parse the GeoJSON string into a PostGIS geometry, then
  stamp it as SRID 4326.
- `GeometryType::convertToPHPValueSQL()` wraps every value read back out in `ST_AsGeoJSON(…)` — take
  whatever PostGIS geometry is stored and render it back to a GeoJSON string.

<figure class="gis-fig"><svg viewBox="0 0 640 360" role="img" aria-labelledby="f4-t f4-d" xmlns="http://www.w3.org/2000/svg"><title id="f4-t">The GeoJSON ⇄ PostGIS round trip</title><desc id="f4-d">A box on the left labelled PHP, GeoJSON, and a box on the right labelled PostGIS, geometry(Geometry, 4326). An arrow above the boxes runs left to right, from the PHP box to the PostGIS box, labelled ST_SetSRID wrapped around ST_GeomFromGeoJSON of the value, comma 4326 — the write direction. A second arrow below the boxes runs right to left, from the PostGIS box back to the PHP box, labelled ST_AsGeoJSON of the value — the read direction. Only the write-direction arrow's label mentions an SRID; the read-direction arrow's does not.</desc><defs><marker id="gis-arrow-f4" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs><text class="gis-label-mono" x="320" y="50" text-anchor="middle">ST_SetSRID(ST_GeomFromGeoJSON(…), 4326)</text><line class="gis-ink" x1="40" y1="75" x2="600" y2="75" marker-end="url(#gis-arrow-f4)"/><line class="gis-muted" stroke-dasharray="3 4" x1="125" y1="145" x2="125" y2="75"/><line class="gis-muted" stroke-dasharray="3 4" x1="460" y1="145" x2="460" y2="75"/><rect class="gis-box" rx="8" x="20" y="145" width="210" height="90"/><text x="125" y="196" text-anchor="middle">PHP / GeoJSON</text><rect class="gis-box" rx="8" x="300" y="145" width="320" height="90"/><text x="460" y="180" text-anchor="middle">PostGIS</text><text class="gis-label-mono" x="460" y="210" text-anchor="middle">geometry(Geometry, 4326)</text><line class="gis-muted" stroke-dasharray="3 4" x1="125" y1="235" x2="125" y2="305"/><line class="gis-muted" stroke-dasharray="3 4" x1="460" y1="235" x2="460" y2="305"/><line class="gis-ink" x1="600" y1="305" x2="40" y2="305" marker-end="url(#gis-arrow-f4)"/><text class="gis-label-mono" x="320" y="335" text-anchor="middle">ST_AsGeoJSON(…)</text></svg><figcaption>PHP only ever holds a GeoJSON string; PostGIS only ever holds a <code>geometry(Geometry, 4326)</code> value. <code>GeometryType</code> sits on the boundary and translates both ways on every read and write. Notice which arrow carries the SRID: only the write, top arrow does — <code>ST_SetSRID</code> is there because GeoJSON has nowhere of its own to carry one, so it has to be re-asserted every single time a value crosses into PostGIS. The read arrow needs no such step, because the value is already labelled 4326 the moment it left the database.</figcaption></figure>

Notice that the SRID only appears going one way. GeoJSON, as a text format, has no field for "which
coordinate system are these numbers in" — chapter 1's `50.4894, 5.8792` is meaningless without an
SRID attached, and a bare GeoJSON string is exactly that: numbers with no SRID of their own. So on
the way in, `ST_SetSRID` has to state it explicitly, every time, because there is nothing in the
input to state it for us. On the way out there is nothing to re-assert: the geometry sitting in the
database already carries `4326`, `ST_AsGeoJSON` only has to print the numbers, and the resulting
string is quietly missing an SRID again the moment it lands in PHP — until the next write sends it
back through `ST_SetSRID` once more. The boundary is crossed constantly, and `GeometryType` is what
keeps it from ever mattering to the rest of the codebase.

## What to carry into chapter 3

- This project uses exactly three shapes — Point, LineString, Polygon — plus their Multi- forms, and
  every geometry column is declared permissively enough to hold any of them.
- A LineString's point order is meaningful: reverse it and you have described a different ride.
- A Polygon's ring closes (first point = last point) and can have holes; its winding direction can
  matter to other tools even though PostGIS does not enforce it.
- GeoJSON is longitude-first, the same rule chapter 1 already warned about, and it carries no SRID
  of its own — which is exactly why `GeometryType` re-asserts `4326` on every single write.

The fountain has a position, a coordinate system, and now a shape. What is still missing is a
question: *how far away is it?* Degrees are angles, not distances, and the next chapter is about
asking PostGIS for a real one.

<!-- EXERCISE-SLOT ch=2 — hands-on box goes here (spec D5); do not remove -->
