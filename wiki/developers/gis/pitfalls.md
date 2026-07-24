<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Pitfalls, glossary, where to look

This chapter does not follow the fountain. Every chapter before it built one idea on the last;
this one assumes you have read all nine and is the page you come back to afterwards, when you are
writing code of your own and something about it feels off. It has three parts: a table of mistakes
that actually happen in GIS code — most of which have happened in this one — a table for "I need to
change X, where do I start", and a glossary of every term the series used.

## Pitfalls

Each row is a trap that is either real GIS knowledge worth knowing generally, or a mistake that has
genuinely happened somewhere in this codebase's history. Nothing here is invented to look plausible.

| Trap | What it looks like | Why it happens | What to do |
|---|---|---|---|
| **Longitude before latitude** | A spatial query runs without error and returns zero rows, or a point lands somewhere absurd — chapter 1's example is a fountain near Spa landing in the Indian Ocean off Somalia | GeoJSON, PostGIS (`ST_Point(x, y)`) and most geometry libraries take `(longitude, latitude)` because they treat a position as `(x, y)`; humans, and some hand-entered data in this repo (`SeedManualCatalogCommand`), say latitude first | Check argument order before anything else when a spatial query mysteriously returns nothing. `SpatialResolver::resolve()` and `map.js`'s `setCircleSpotlight()` are the two places in this codebase where the flip from human order to `(lng, lat)` happens on purpose — read them as the reference shape. See [`coordinates.md`](coordinates.md) |
| **Missing or mismatched SRID** | PostGIS refuses to compare two geometries ("Operation on mixed SRID geometries"), or worse: no error at all, but the geometry is silently labelled as being somewhere it was never converted to | Numbers alone are not a place — an SRID is what says which coordinate system they belong to. `ST_SetSRID` only relabels a geometry; it does not recompute anything. `ST_Transform` actually converts. Using the first where you meant the second gives a geometry that claims a system it was never moved into | Every geometry column in this project is declared `geometry(Geometry, 4326)` by `GeometryType::getSQLDeclaration()` (`web/src/Catalog/Doctrine/GeometryType.php`), so there should never be a reason to call `ST_Transform` here at all — if you find yourself wanting one, stop and ask why the data isn't already in 4326. See [`coordinates.md`](coordinates.md) |
| **Treating degrees as metres** | A "within 50 km" filter returns too few rows, or the wrong-shaped set of rows, and the miss gets worse depending on latitude — nothing throws an error | A degree of latitude is about 111 km everywhere, but a degree of longitude shrinks by `cos(latitude)` away from the equator. Doing arithmetic on raw degrees as if they were a fixed-size unit produces an ellipse, not a circle, and the wrong size in every direction except due north-south | Cast to `geography` for any real distance comparison — `BaseAreaResolver::resolve()`'s `ST_DWithin(r.geom::geography, …, :m)` is the worked example, with `:m` arriving already converted from kilometres. See [`metres-vs-degrees.md`](metres-vs-degrees.md) |
| **Buffering in degrees instead of on geography** | A "within 250 m of this route" corridor is subtly the wrong shape and size — an ellipse flattened east-west, not the real 250 m stadium-shape around the line | `ST_Buffer(geom, d)` on a plain `geometry` value treats `d` as degrees, exactly the same bug as the row above, just applied to growing a shape instead of comparing two of them | Cast to `geography` for the buffer itself, then cast the *result* back to `geometry` — `RideCheckService::corridorGroups()`'s `ST_Buffer((SELECT g FROM track)::geography, :radius)::geometry` is this project's own example. See [`metres-vs-degrees.md`](metres-vs-degrees.md) and [`making-it-fast.md`](making-it-fast.md) |
| **A `::geography` cast that silently disables the index** | A query that reads correctly and returns the right answer takes tens of seconds instead of under one — `RideCheckService`'s own comment records a real 62-second run on a development catalog | Every GiST index here is built on the bare `geom` column. Casting *that* column to `::geography` compares a value the index knows nothing about, so PostgreSQL falls back to reading and measuring every row — the cost is not the ellipsoid maths, it is that every row now pays for it | Move the cast off the indexed side. Build the corridor once (cast the *other* side to geography, buffer, cast back), then compare the bare column against that one precomputed shape with `ST_Intersects` — the actual rewrite this project shipped. See [`making-it-fast.md`](making-it-fast.md) |
| **A missing GiST index on a new geometry column** | `EXPLAIN` shows `Seq Scan` on a spatial table; fine and invisible on a seeded development database, ruinous once the table holds real volume | Geometry has no natural order, so an ordinary B-tree index cannot help at all — PostGIS needs `USING GIST (geom)` specifically, and a new geometry column does not get one automatically | Add `CREATE INDEX … USING GIST (geom)` in the same migration that adds the column, the way every searched geometry column in this project already has one. `users.base_point` is the one deliberate exception — nothing ever searches *by* it, so it has no index on purpose, not by omission. See [`making-it-fast.md`](making-it-fast.md) |
| **An inlined CTE re-parsing geometry per row** | Correct results, still slow, and the query's text gives no hint why — the difference between a fast and a slow version of the same query is a single word | PostgreSQL may inline a `WITH name AS (…)` block instead of computing it once, so an expression like `ST_GeomFromGeoJSON(:geom)` referenced several times gets recomputed — reparsed from a JSON string — at every one of those places, once per row wherever the reference sits inside a `SELECT` list | Write `MATERIALIZED` to force the CTE to compute once and be reused. `RideCheckService::corridorGroups()`'s own comment: "MATERIALIZED is load-bearing twice over." See [`making-it-fast.md`](making-it-fast.md) |
| **Polygon ring winding order** | PostGIS reads a polygon fine either way; hand the same coordinates to some other renderer or GIS library and a hole gets drawn as a fill, or the reverse | The direction a ring is walked — clockwise or counter-clockwise — is called its winding, and some tools use it as the only signal for "this ring is a hole, not a fill." PostGIS itself is forgiving about it | Be deliberate about winding whenever geometry from this project is handed to a tool that is not PostGIS. Nothing in this codebase currently depends on getting it right, which is exactly why it is easy to forget the rule exists until a non-PostGIS consumer shows up. See [`shapes.md`](shapes.md) |
| **Assuming an OSM tag is present** | Code that reads a tag key throws on a missing key, or silently treats "not present" as a confident "no" | OSM tags are a convention followed by volunteers, not a schema enforced by any software — a drinking fountain with no `amenity` tag at all is not a contradiction, and a tag can be spelled inconsistently across mappers and countries | Treat every tag read as optional, and never read absence as a negative answer — only as "nobody recorded this." `pipeline/coverage/parse.py`'s trim to `storedTagKeys` and the whole "tags are not a schema" argument in this chapter's source is the reference case. See [`osm-to-database.md`](osm-to-database.md) |
| **Antimeridian / dateline wrapping** | A region's bounding box spans the entire globe instead of a thin sliver near ±180° | Longitude wraps from +180 back to −180 at the antimeridian. A naive `min`/`max` union of longitudes breaks the instant a shape's coordinates straddle that seam — the two extreme values end up on opposite sides of the world instead of close together | Nothing in this repository handles it today, and nothing has needed to: every region the Commons has onboarded (Belgium, the Netherlands, Germany) sits comfortably between about 2° and 15° east, nowhere near 180°. It is not untested territory nobody has thought about, though — `docs/specs/2026-07-19-region-scoping-design.md` §8 risk 11 names the exact spot it would break if a country like Chukotka, Fiji or New Zealand (including the Chathams) were ever onboarded: `RegionRegistryProvider`'s `ST_XMin`/`ST_XMax` extents and `CCScope.bbox()`'s (`web/assets/map/scope.js`) naive min/max union would both produce a world-wrapping box. The document records a required fix — split boxes, or a longitude-normalised union — before any such country is seeded, not a promise that the current code already copes |

<!-- UNANCHORED id=U100 type=general concept="antimeridian / dateline wrapping" -->

Two of those rows — the `::geography` cast that disables an index, and the inlined CTE that
re-parses geometry per row — are really one story: they are the same real rewrite,
`RideCheckService::corridorGroups()`, seen from two different angles. It is worth seeing both sides
of that one fix side by side, because the "before" is exactly the shape a reasonable-looking query
takes if you don't already know either trap:

<!-- CODE-ILLUSTRATIVE the naive shape corridorGroups()'s own comment describes, not a runnable query in this codebase -->
```sql
SELECT * FROM item
WHERE ST_DWithin(geom::geography, ST_GeomFromGeoJSON(:geom)::geography, :radius)
```

Casting `geom` itself — the indexed column — means every row now pays for spheroid distance maths,
and re-evaluating `ST_GeomFromGeoJSON(:geom)` inline means every row re-parses the same JSON string
too. The shipped rewrite moves both costs off the per-row path:

<!-- CODE-FROM web/src/Catalog/RideCheckService.php -->
```php
'WITH track AS MATERIALIZED (SELECT ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326) AS g),
      corridor AS MATERIALIZED (SELECT ST_Buffer((SELECT g FROM track)::geography, :radius)::geometry AS b)
 SELECT i.id, i.letter, i.name, ST_AsGeoJSON(i.geom) AS geom,
...
 WHERE i.letter <> \'A\'
   AND i.state IN '.ItemState::servedSqlTuple().'
   AND ST_Intersects(i.geom, (SELECT b FROM corridor))
```

`MATERIALIZED` forces the GeoJSON parse and the buffer to happen once, not once per row; casting the
buffer to `::geography` and back to `::geometry` keeps the cast on the one-off corridor shape instead
of on `i.geom`, so the comparison that actually runs per row — `ST_Intersects(i.geom, …)` — is a plain
`geometry` test the `idx_item_geom` GiST index can serve directly. Same underlying question, "what's
near this route", two very differently priced ways to ask it.

## I need to change X — where do I look

A quick map from a task to the files that do it. These are starting points, not the only files
involved — read what they import before assuming the whole story is in one place.

| I need to… | Start here |
|---|---|
| Add a spatial query (is this point inside/near/along that shape?) | `web/src/Contribution/SpatialResolver.php` (containment), `web/src/Service/BaseAreaResolver.php` (nearby, with real distance) — see [`spatial-questions.md`](spatial-questions.md) |
| Add a geometry column to a new or existing table | A migration declaring the column via the shared `geometry` DBAL type, plus its own `CREATE INDEX … USING GIST (geom)` in the same migration, and `web/src/Catalog/Doctrine/GeometryType.php` if the declaration itself needs to change — see [`coordinates.md`](coordinates.md) and [`making-it-fast.md`](making-it-fast.md) |
| Change how a spatial query performs at scale | `web/src/Catalog/RideCheckService.php` (`corridorGroups()`, `followedRoutes()`) is the reference rewrite — read its own comments before touching anything else — see [`making-it-fast.md`](making-it-fast.md) |
| Change what the map draws, or how it's styled | `web/assets/map/map.js` — sources, layers, paint/layout, clustering, click handling all live here — see [`on-screen.md`](on-screen.md) |
| Change what comes out of OpenStreetMap (which objects, which tags) | `pipeline/coverage/extract.py` (which objects survive `osmium tags-filter`), `pipeline/coverage/parse.py` (which tag keys survive onto the stored row), and the shared contract, `pipeline/contract/coverage-contract.json` (read by `pipeline/coverage/contract.py::load_contract()`) — see [`osm-to-database.md`](osm-to-database.md) |
| Change how tiles are built (zoom range, clustering, per-country layers) | `pipeline/coverage/tiles.py` — `build_pmtiles()` and `export_geojsonl()` — see [`tiles.md`](tiles.md) |
| Change which tag keys the item drawer is allowed to show | `web/src/Coverage/CoverageRepository.php`'s `TAG_WHITELIST` constant, which must stay a subset of the contract's `storedTagKeys` (enforced by `web/tests/Catalog/CoverageContractTest.php`) — see [`osm-to-database.md`](osm-to-database.md) |
| Change how a route's surface estimate is computed | `web/src/Catalog/SurfaceProfiler.php` (`profile()`, `recomputeAll()`), triggered by `app:catalog:route-surfaces` (`web/src/Catalog/Command/RouteSurfacesCommand.php`) — see [`routes.md`](routes.md) |
| Change how a GPX upload becomes a stored route | `web/src/Contribution/Gpx/GpxParser.php` (reading the file), `web/src/Contribution/Gpx/TrackProcessor.php` (distance, ascent, simplification), `web/src/Contribution/RouteProposalService.php` (assembling the stored geometry) — see [`routes.md`](routes.md) |
| Change how a rider searches for a place by name | `web/assets/settings/base-location.js` and `map.js`'s `runPhoton()` — both call Photon directly from the browser, allow-listed in `web/src/EventSubscriber/CspSubscriber.php` — see [`spatial-questions.md`](spatial-questions.md) |
| Change which regions a rider's base point resolves to | `web/src/Service/BaseAreaResolver.php` and `web/src/Catalog/RegionRegistryProvider.php` — see [`spatial-questions.md`](spatial-questions.md) |

## Glossary

Every term this series defined, in one place, one sentence each. Alphabetical, not teaching order —
if you want the concept built up from scratch, follow the chapter link.

**Bounding box** — the smallest upright rectangle that fully contains a shape; cheap to compute and
compare, and able to prove two shapes *cannot* touch but never that they do. [`making-it-fast.md`](making-it-fast.md)

**Buffer (`ST_Buffer`)** — a function that grows a shape by a fixed distance in every direction; a
point becomes a circle, a line becomes a corridor. [`spatial-questions.md`](spatial-questions.md)

**Buffer-based attribution** — this project's technique for estimating a route's surface: draw a
corridor around the route, total the mapped road metres inside it per surface, and treat the
largest totals as the likely answer. [`routes.md`](routes.md)

**Candidate set** — the rows a GiST index's box comparison lets through; guaranteed to contain every
true match, and guaranteed to also contain some rows that are not matches at all. [`making-it-fast.md`](making-it-fast.md)

**Cast (`::geography`)** — a per-expression reinterpretation of a stored value's type, applied for the
length of one function call rather than changing the column itself. [`metres-vs-degrees.md`](metres-vs-degrees.md)

**Cluster / clustering** — merging nearby points into one feature carrying a count, so a map stays
readable when zoomed out. This project used to cluster the huge coverage layer offline at tile-build
time too, but a cluster's rendered position couldn't be trusted to stay inside its scoped region
(phantom bubbles), so that layer was switched to individual points plus a density heatmap instead;
the only clustering left is live, in the browser, for the small confirmed-points pool.
[`tiles.md`](tiles.md), [`on-screen.md`](on-screen.md)

**Convention (tagging)** — the informal, voluntarily-followed agreement among OSM mappers about which
tag key and value describe a given real-world thing; nothing in OSM's own software enforces it.
[`osm-to-database.md`](osm-to-database.md)

**Corridor** — the polygon `ST_Buffer` produces when applied to a line: a stadium-shaped strip
running the line's whole length. [`spatial-questions.md`](spatial-questions.md)

**CTE (Common Table Expression)** — the `WITH name AS (…)` block at the top of a query, naming a
subquery so the rest of the statement can refer to it by name. [`making-it-fast.md`](making-it-fast.md)

**Equator** — the circle exactly halfway between the poles, where latitude is 0. [`coordinates.md`](coordinates.md)

**EPSG registry** — the long-running public catalogue of coordinate systems that most SRIDs are drawn
from. [`coordinates.md`](coordinates.md)

**EPSG:3857 (Web Mercator)** — the projected, metre-labelled coordinate system tiled web maps draw on;
conformal (shapes stay right), but badly wrong about area away from the equator. [`coordinates.md`](coordinates.md)

**EPSG:4326 (WGS84)** — plain latitude/longitude in degrees; what GPS, GPX, OpenStreetMap and GeoJSON
all speak, and the only SRID any geometry column in this project is ever stored in. [`coordinates.md`](coordinates.md)

**Expression (MapLibre)** — a small nested-array formula a paint or layout property can hold instead
of a fixed value, evaluated per feature against that feature's own properties. [`on-screen.md`](on-screen.md)

**Extract** — a downloaded slice of OpenStreetMap's data for one region, published by Geofabrik as a
PBF file, instead of the whole planet. [`osm-to-database.md`](osm-to-database.md)

**Forward geocoding** — turning a place name into coordinates; the direction this project actually
uses, via Photon. [`spatial-questions.md`](spatial-questions.md)

**Geocoding** — a text-matching problem with a spatial tiebreak, not a spatial query with text bolted
on: finding what a place name might refer to, then picking which candidate using location.
[`spatial-questions.md`](spatial-questions.md)

**Geofabrik** — a community mirror that continuously republishes OpenStreetMap's planet, cut into
per-country and per-region PBF files. [`osm-to-database.md`](osm-to-database.md)

**GeoJSON** — the JSON-based text format a geometry travels in across a network or a request body;
defined to be longitude-first and to carry no SRID of its own. [`shapes.md`](shapes.md)

**Geography** — a way of reading a stored geometry value that treats the Earth as a curved surface, so
its distance and area functions return real ground measurements in metres, at any latitude.
[`metres-vs-degrees.md`](metres-vs-degrees.md)

**Geometry** (as a shape kind) — the permissive PostGIS column type meaning "any shape", decided per
row rather than pinned once at the table level. [`shapes.md`](shapes.md)

**Geometry** (as opposed to geography) — a way of reading a stored value that treats the world as a
flat plane, so its functions return plain numbers in whatever unit the SRID uses — degrees, for 4326.
[`metres-vs-degrees.md`](metres-vs-degrees.md)

**GiST (Generalised Search Tree)** — PostgreSQL's indexing framework for data that has no natural sort
order but does have "contains" and "overlaps"; PostGIS uses it to index geometry by storing bounding
boxes in a tree. [`making-it-fast.md`](making-it-fast.md)

**GPX** — the XML format bike computers, phone apps and GPS watches export a ride as; underneath the
XML, an ordered list of `<trkpt>` points. [`routes.md`](routes.md)

**Graph search** — the general kind of problem "find the cheapest route from A to B through a network
of costed road segments" is; not something this project implements itself. [`routes.md`](routes.md)

**Hit-testing** — answering "what did the rider just click on?" by asking already-rendered geometry in
the browser, with no request to a server — what `queryRenderedFeatures` does, and only possible because
vector tiles ship real geometry rather than a picture. [`on-screen.md`](on-screen.md)

**Layer** (MapLibre) — one drawing instruction: which source, which subset of its features, and how to
turn each one into pixels. [`on-screen.md`](on-screen.md)

**Layout** — the group of a MapLibre layer's properties governing placement and participation
(visibility, icon choice, size); more expensive to change than paint, because it can force MapLibre to
redo collision and placement work. [`on-screen.md`](on-screen.md)

**LineString** — an ordered list of points joined into a path, where the order is part of what the
shape means; a route is stored as one. [`shapes.md`](shapes.md)

**MATERIALIZED** — the keyword that forces a CTE to be computed once and reused, instead of being
substituted inline everywhere it is referenced. [`making-it-fast.md`](making-it-fast.md)

**Meridian** — a line joining every point of the same longitude; unlike parallels, every meridian
meets every other one at both poles. [`coordinates.md`](coordinates.md)

**MultiLineString** — more than one LineString held together as a single geometry value. [`shapes.md`](shapes.md)

**MultiPoint** — more than one Point held together as a single geometry value. [`shapes.md`](shapes.md)

**MultiPolygon** — more than one Polygon held together as a single geometry value, used for regions
with an island or an exclave. [`shapes.md`](shapes.md)

**MVT (Mapbox Vector Tile)** — the small, widely-adopted binary encoding a vector tile is packed in.
[`tiles.md`](tiles.md)

**Node** — an OSM primitive: one latitude, one longitude, nothing else structural; our fountain is a
node. [`osm-to-database.md`](osm-to-database.md)

**Overpass API** — a public, shared community service that answers ad-hoc bulk queries against live
OSM data; this project never calls it, for harvesting or for serving. [`osm-to-database.md`](osm-to-database.md)

**Paint** — the group of a MapLibre layer's properties governing appearance only (colour, opacity,
width); cheap to change, because MapLibre can repaint already-placed pixels without recomputing where
anything goes. [`on-screen.md`](on-screen.md)

**Parallel** — a line joining every point of the same latitude; parallels never meet, and shrink toward
each pole. [`coordinates.md`](coordinates.md)

**PBF** — a compact binary encoding of OSM's node/way/relation/tag model, much smaller than the
older plain-text XML format. [`osm-to-database.md`](osm-to-database.md)

**Photon** — the free, keyless, OpenStreetMap-based geocoding service this project calls directly from
the browser for place search. [`spatial-questions.md`](spatial-questions.md)

**PMTiles** — a file format that packs an entire tile pyramid into one file with an index, read by
range request, so no dedicated tile-serving process is needed. [`tiles.md`](tiles.md)

**Point** — a single coordinate pair and nothing else; every catalog item is stored as one. [`shapes.md`](shapes.md)

**Polygon** — a closed ring of points, optionally with holes cut out, describing an area; a region's
outline is stored as one. [`shapes.md`](shapes.md)

**Predicate** — a spatial function that answers true or false, such as `ST_Contains` or
`ST_Intersects`. [`spatial-questions.md`](spatial-questions.md)

**Prime meridian** — the arbitrary line through Greenwich, London, agreed as 0° longitude. [`coordinates.md`](coordinates.md)

**Projection** — a rule for turning a position on the curved Earth into a position on a flat plane;
every one distorts at least one of area, shape, distance or direction. [`coordinates.md`](coordinates.md)

**PostGIS** — the spatial extension to PostgreSQL that turns an ordinary database into one that can
store and query shapes on the Earth. [`coordinates.md`](coordinates.md)

**Range request** — an HTTP request asking a server for a specific byte range of a file rather than the
whole thing; what a PMTiles client issues to read one tile. [`tiles.md`](tiles.md)

**Raster tile** — a tile that is a finished picture (PNG/JPEG); every visual choice is frozen into the
pixels at build time. [`tiles.md`](tiles.md)

**Reference vs fork** — this project's stance on OpenStreetMap data: `coverage_poi` references OSM
objects by id and exists to serve queries fast, it is never presented as an independent survey or a
replacement authority. [`osm-to-database.md`](osm-to-database.md)

**Relation** — an OSM primitive that groups other elements (nodes, ways, even other relations) into
something bigger, such as a bus route or a country border; this project's pipeline does not read
relations today. [`osm-to-database.md`](osm-to-database.md)

**Reverse geocoding** — turning coordinates into a place name; this project never calls it, storing a
place name once at the moment a rider picks it instead. [`spatial-questions.md`](spatial-questions.md)

**Routing** — computing a path between two points through a road network; not implemented in this
project's application code, though Valhalla is available as an opt-in local profile. [`routes.md`](routes.md)

**Slippy map** — the pan-and-drag, zoom-with-the-wheel kind of web map, as opposed to a single fixed
picture. [`coordinates.md`](coordinates.md)

**Source** (MapLibre) — a named pool of data a layer can read from; naming one draws nothing by
itself. [`on-screen.md`](on-screen.md)

**`source-layer`** — which named feature collection inside a vector source's tiles a layer should read;
needed only for vector sources, which can pack more than one collection per tile. [`on-screen.md`](on-screen.md)

**Spatial question** — a question whose answer depends on where things are, not just what they are —
"is this inside that", "what's nearby", "did I pass this". [`spatial-questions.md`](spatial-questions.md)

**SRID (Spatial Reference IDentifier)** — an integer naming a coordinate system, stored alongside every
PostGIS geometry so a value always carries its own answer to "what do these numbers mean?". [`coordinates.md`](coordinates.md)

**`ST_Buffer`** — grows a shape by a distance; see Buffer. [`spatial-questions.md`](spatial-questions.md)

**`ST_ClosestPoint`** — returns the specific point on one shape nearest to another shape. [`spatial-questions.md`](spatial-questions.md)

**`ST_Contains`** — true if one shape lies entirely within another; a topology question needing no
`geography` cast. [`spatial-questions.md`](spatial-questions.md)

**`ST_Distance`** — returns the shortest distance between two shapes, in whatever unit the type (`geometry`
or `geography`) implies. [`spatial-questions.md`](spatial-questions.md)

**`ST_DWithin`** — true if two shapes are within a given distance of each other; the predicate behind
every "near me" question. [`spatial-questions.md`](spatial-questions.md)

**`ST_Intersects`** — true if two shapes share any point at all; the broadest yes/no test, and the one
a GiST index can serve most directly. [`spatial-questions.md`](spatial-questions.md)

**`ST_LineLocatePoint`** — returns a 0–1 fraction for how far along a line a point sits, measured from
its first vertex; the tool for ordering things by "how far along the ride". [`spatial-questions.md`](spatial-questions.md)

**`ST_SetSRID`** — labels a geometry with a coordinate system without changing any of its numbers.
[`coordinates.md`](coordinates.md)

**`ST_Transform`** — recomputes a geometry's numbers to genuinely convert it from one coordinate system
to another. [`coordinates.md`](coordinates.md)

**Style** (MapLibre) — the whole document describing everything a map draws: every source, every
layer, background colours, all of it together. [`on-screen.md`](on-screen.md)

**Tag** — a free-form `key=value` pair any OSM element can carry, giving an otherwise-empty node, way
or relation its meaning. [`osm-to-database.md`](osm-to-database.md)

**Tile** — one small, addressable piece of a map, covering a fixed square of the world at a fixed zoom
level. [`tiles.md`](tiles.md)

**Tile pyramid** — the whole addressing scheme by zoom level, where zoom 0 is one tile and every level
quarters every tile from the one before, giving `4^z` possible tiles at zoom `z`. [`tiles.md`](tiles.md)

**Vector tile** — a tile that ships geometry and properties instead of a picture, letting the browser
restyle, filter and hit-test without a new request. [`tiles.md`](tiles.md)

**Way** — an OSM primitive: an ordered list of nodes joined into a path, closed into an area if the
first and last node match. [`osm-to-database.md`](osm-to-database.md)

**Winding** — the direction (clockwise or counter-clockwise) a polygon's ring is walked in; some
renderers use it to tell a fill from a hole. [`shapes.md`](shapes.md)

**`z/x/y`** — the three-number address of a tile: zoom level, then column and row within that level's
grid. [`tiles.md`](tiles.md)

**Zoom level** — one level of the tile pyramid; each one covers the same whole world at four times the
tile count, and four times the resolution, of the level before it. [`tiles.md`](tiles.md)

## Try it

!!! tip "Hands-on — diagnose a real zero-row query"
    Here is a query near Namur (`50.4700, 4.8700`, where the coverage fixture's own nodes sit) that
    runs without error and returns nothing. Work through the pitfalls table above before reading the
    fix below it.

    <!-- CODE-ILLUSTRATIVE shell command against the dev stack's Postgres — the broken query -->
    ```sh
    docker compose -f developers/docker/compose.yaml exec db psql -U cc -d cyclingcommons -c "
    SELECT count(*) AS broken_count FROM coverage_poi
    WHERE ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(50.4700, 4.8700), 4326)::geography, 5000);
    "
    ```

    <!-- CODE-ILLUSTRATIVE sample output; zero on any install, the swapped point has nothing near it on Earth's dry land -->
    ```text
     broken_count
    --------------
                0
    (1 row)
    ```

    Zero rows within 5 km of real mapped Belgian ground, in a table that certainly holds some. No
    error, no warning — exactly the shape of the **longitude before latitude** row in the table
    above. `ST_MakePoint(x, y)` wants `(longitude, latitude)`; this query hands it
    `(50.4700, 4.8700)`, latitude first, which `ST_MakePoint` reads as a valid point roughly 50.47°
    east of Greenwich and 4.87° north of the equator — in the Indian Ocean off the Somali coast,
    nowhere near Belgium. Swap the two arguments and the same query finds real rows:

    <!-- CODE-ILLUSTRATIVE shell command against the dev stack's Postgres — the fix -->
    ```sh
    docker compose -f developers/docker/compose.yaml exec db psql -U cc -d cyclingcommons -c "
    SELECT count(*) AS fixed_count FROM coverage_poi
    WHERE ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(4.8700, 50.4700), 4326)::geography, 5000);
    "
    ```

    <!-- CODE-ILLUSTRATIVE sample output on a stack seeded by `make course-data`; a full `make coverage-refresh` returns 183 for the same query -->
    ```text
     fixed_count
    -------------
               5
    (1 row)
    ```

    Real rows within 5 km, as soon as the coordinates go in `(lng, lat)` order. How many depends on
    how much coverage your stack has loaded — five on the offline fixture, 183 once a real Belgian
    extract is in — and that is fine, because the number is not the lesson. The transition from zero
    to non-zero is. Nothing else about the query changed — same table, same radius, same cast to
    `::geography` — which is exactly why this trap is so easy to miss under pressure: the query is
    otherwise correct, and correct-looking SQL that quietly returns nothing is the signature this
    whole drill is meant to train you to recognise.
