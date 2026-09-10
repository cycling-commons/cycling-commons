<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Reprojection

**Kind: we do not need it.** `ST_Transform` — the PostGIS function that actually converts a
geometry's numbers from one coordinate system to another — is a real, load-bearing tool across GIS
work in general. This codebase has never called it, and today has no reason to. This chapter
explains what it does, why a whole family of coordinate systems exists to be converted *into*, and
then gets concrete about the one kind of day that would change the "today" in that sentence.

## Recap: what this codebase already decided

[`coordinates.md`](../gis/coordinates.md) covers this ground properly; here is only the part this
chapter builds on. Every geometry column in this project is declared `geometry(Geometry, 4326)`,
with one narrower sibling, `coverage_poi.geom`, declared `geometry(Point, 4326)` because a coverage
row is always a single point; all of them are plain latitude/longitude, in degrees, WGS84.
`ST_SetSRID` *labels* a geometry with a coordinate
system, changing nothing about its numbers. `ST_Transform` *converts* it, recomputing every
coordinate so the same real-world position is expressed in a different system. The two are easy to
confuse because both take a geometry and an SRID and return a geometry — but one is a relabelling
and the other is real arithmetic, and using the label where you needed the conversion gives you a
geometry that confidently claims to be somewhere it has never actually been moved to.

The one place a projection genuinely happens to this project's data is on the way out to tiles:
`build_pmtiles()` in `pipeline/coverage/tiles.py` hands tippecanoe geometries in 4326, and tippecanoe
— a third-party tool, not our own code — projects them to Web Mercator (EPSG:3857) as part of
cutting them into tiles. That happens to a copy, after every read our own code ever does, and it is
the only projection this system performs at all.

You can check the central claim of this chapter yourself. It is one command:

<!-- CODE-ILLUSTRATIVE a command you can run yourself against this repository, not a stored result -->
```sh
git grep -n "ST_Transform" -- . ':!wiki'
```

Run today, against this tree, it returns nothing. Every remaining hit lives in the wiki pages that
describe this fact, not in a line of application, pipeline, or migration code. That is worth
checking rather than trusting, because "nothing calls X" is exactly the kind of claim that quietly
stops being true the moment someone adds one line and nobody updates the sentence that said
otherwise.

## What `ST_Transform` actually does

Every coordinate reference system (CRS) is built from an ellipsoid model of the Earth's shape (a
**datum**), an origin, and — for a projected system — a mathematical rule for turning a position on
that ellipsoid into a position on a flat plane. `ST_Transform(geom, target_srid)` looks up the
source SRID a geometry already carries and the target SRID you asked for, and runs the geometry's
coordinates through the maths that converts one to the other: undoing the source projection back to
a position on the ellipsoid if needed, correcting for any datum difference between the two systems,
then applying the target projection forward. PostGIS does not implement this maths itself — it
calls out to **PROJ**, a widely used, independently maintained coordinate-transformation library
that ships the parameters for essentially every system in the EPSG registry chapter 1 introduced.

This is genuine computation, not a lookup. Two systems built on different datums do not even agree,
down to the metre, on where the ellipsoid's surface *is* under a given latitude and longitude — the
datum correction is real physics-adjacent work, not a formality. That is part of why `ST_SetSRID` and
`ST_Transform` being different functions matters as much as it does: relabelling skips all of this,
silently.

## Why a projected CRS exists at all

[`metres-vs-degrees.md`](../gis/metres-vs-degrees.md) already covers the tool this project actually
reaches for when it needs a real-world distance or area: casting to `geography`, which measures
directly on the curved ellipsoid and hands back an answer in metres without ever leaving degrees.
That raises a fair question — if `geography` already gives you honest metres, what is a projected,
flat, metre-based CRS still *for*?

Three answers, none of which this project currently needs:

- **Algorithms that assume a flat plane.** A great deal of geometry software — including parts of
  PostGIS's own `geometry` type, and most general-purpose computational geometry libraries outside
  PostGIS entirely — is written in ordinary Euclidean maths: straight lines, Pythagoras, the shoelace
  formula for area. `geography` supports only a limited menu of operations for exactly this reason.
  Something that needs the *full* toolbox — precise polygon offsetting, certain simplification
  algorithms, convex hulls, some overlay operations — often gets there fastest by projecting into a
  flat, metre-based CRS first, doing ordinary flat-plane maths, and living with the small, bounded
  distortion that a well-chosen local projection introduces.
- **Software with no concept of "degrees" at all.** Plenty of tools that consume geometry — desktop
  GIS packages, plotting libraries, some routing engines' internal graphs — expect two axes in the
  same linear unit, full stop. Handing them 4326 coordinates and asking for an area or a length in
  metres is asking them to silently do the wrong maths; the fix upstream of them is often "project
  first, hand over metres, let the tool be as naive as it likes."
- **A single, legally fixed, national grid.** A country's cadastre — the official record of who owns
  which parcel of land — cannot be re-surveyed every time a better global datum ships. National
  mapping agencies fix one fairly local, low-distortion, metre-based CRS as the legal reference
  system for their own territory, and property boundaries, printed topographic maps, and construction
  drawings are defined against it for decades at a stretch.

## The national grids you will meet

None of these are used anywhere in this codebase. They are what you will find *other* people's
spatial data expressed in, the moment you go looking outside this project.

**UTM (Universal Transverse Mercator).** The world is sliced into 60 north-south zones, each 6° of
longitude wide, and each zone gets its own Mercator-style projection centred on its own middle
meridian. Distortion inside a zone stays small — well under a tenth of a percent — and grows fast
once you stray outside it, which is exactly the trade a 6°-wide zone is built to make. Each zone/
hemisphere pair has its own EPSG code; UTM zone 31N, EPSG:32631, covers most of Belgium, the
Netherlands and northern France. It is not this project's system, but it is the one you will meet
most often in general-purpose geospatial tooling and default projected-CRS suggestions, because it
is defined everywhere on Earth, not just in one country.

**Lambert 93 (EPSG:2154).** France's official metric grid since 2000, a single Lambert Conformal
Conic projection tuned specifically to minimise distortion across the whole of mainland France,
replacing an older system of four separate regional Lambert zones. IGN, France's national mapping
agency, publishes its own cartography and cadastral data in it.

**Belgian Lambert 72 (EPSG:31370).** Belgium's own national grid, another Lambert Conformal Conic,
tuned to Belgium's own shape rather than the whole planet's. It remains the long-standing reference
system for Belgian cadastral parcels and topographic maps, alongside a newer satellite-datum grid
(Lambert 2008, EPSG:3812) tied to a more modern reference frame.

All three exist for the same underlying reason: a global, one-size-fits-all system like Web Mercator
buys worldwide consistency at the cost of real distortion almost everywhere; a national grid gives up
that worldwide reach in exchange for a projection tuned tightly to one country's own borders, where
it barely distorts anything at all.

## A day this project already stood at the edge of it

This is not hypothetical warm-up — it already happened, just resolved on somebody else's server
instead of ours. `tools/wallonia/pivot.py` harvests Walloon tourist-accommodation data from the
Géoportail de la Wallonie's ArcGIS REST service, and its query URL is explicit about the coordinate
system it wants back:

<!-- CODE-FROM tools/wallonia/pivot.py -->
```python
url = (f"{SERVICE}/{lid}/query?where=1%3D1"
       "&outFields=NOM,COMMUNE,CODE_POSTAL,SITE_WEB"
       "&orderByFields=OBJECTID&returnGeometry=true&outSR=4326&f=json"
       f"&resultOffset={offset}&resultRecordCount=2000")
```

`outSR=4326` is an *output spatial reference* parameter — this project is explicitly telling the
Walloon government's own server "give me the answer already converted to WGS84, please." A query
parameter for the output CRS only exists because the service's data is not simply *assumed* to be in
4326 already; Belgian government mapping data, like Belgian topographic maps generally, has a long
history of living natively in Belgian Lambert 72. Whatever this particular service's own internal
storage is, the code takes no chances: it asks the server to reproject before the numbers ever leave
Géoportail's own infrastructure, so by the time `pivot.py` reads a `geometry.x`/`geometry.y` pair,
the reprojection question is already answered. No `ST_Transform` was needed, because the
transformation happened upstream, for free, one HTTP query parameter away.

That will not always be available.

## The day this project would need it

Picture the same kind of onboarding this project already does routinely — a new open geodata
source feeding the catalog, the way PIVOT feeds Walloon stays today, or the way a new country's
Geofabrik extract feeds `coverage_poi`. Now picture that source is not a queryable API with an
`outSR` parameter, but a flat file: a shapefile or GeoPackage download from a national cadastre,
forestry service, or other government mapping agency, published only in that country's own national
grid, with no "give it to me in WGS84 instead" option at all — because the file simply *is* the grid,
end to end, and nobody downstream of the agency has ever needed anything else.

That is the day. Concretely: whoever writes the one-off import script for that source reads the
file's declared source SRID — from a `.prj` sidecar file, or a GeoPackage's own embedded CRS
metadata — and calls `ST_Transform(geom, 4326)` exactly once per imported row, at the moment the row
is written into this project's own tables. Nothing about this project's storage invariant would
change: the geometry lands in a `geometry(Geometry, 4326)` column exactly like every other row,
because `ST_Transform`'s whole job in that script is to make sure of it before the row is ever
written. The call would live in one importer, run once at ingest time, and never appear anywhere
near a live query — a data problem to solve at the door, the same way a currency conversion happens
once when an invoice is created rather than every time someone reads the total back. Nothing else in
this codebase — no query, no API response, no tile build — would need to know the source ever spoke
a different system at all.


## Further reading

- [PROJ](https://proj.org/): the library underneath every ST_Transform in any database.
- [epsg.io](https://epsg.io/): look up any grid this chapter names, including the ones it does not.

## Try it

!!! tip "Hands-on: three numbers for one pair of climbs"
    Ask PostGIS the distance between two real catalog climbs three ways: in degrees, cast to
    `geography`, and reprojected into Belgian Lambert 72 (EPSG:31370) — the national grid this
    chapter just introduced. The two pins are Côte de la Redoute and Cascade de Coo, the same pair
    course 1's [`spatial-questions.md`](../gis/spatial-questions.md) exercise already uses, seeded by
    `make course-data` and selected by `name` — row ids differ on every install, seeded names do not.

    <!-- CODE-ILLUSTRATIVE psql query against the dev catalog -->
    ```sql
    SELECT
      round(ST_Distance(a.geom, b.geom)::numeric, 6)                                          AS degrees_4326,
      round(ST_Distance(a.geom::geography, b.geom::geography)::numeric, 2)                     AS metres_geography,
      round(ST_Distance(ST_Transform(a.geom, 31370), ST_Transform(b.geom, 31370))::numeric, 2)  AS metres_lambert72,
      round(ST_Distance(ST_Transform(a.geom, 2154),  ST_Transform(b.geom, 2154))::numeric, 2)   AS metres_lambert93
    FROM item a, item b
    WHERE a.name = 'Côte de la Redoute' AND b.name = 'Cascade de Coo';
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM fresh-clone; sample output; the coordinates are fixed by the seed, so all four numbers hold on any install -->
    ```text
     degrees_4326 | metres_geography | metres_lambert72 | metres_lambert93
    --------------+------------------+------------------+------------------
         0.202974 |         16708.41 |         16707.43 |         16732.70
    ```

    Three numbers, one pair of points. `degrees_4326` is meaningless standing alone — 0.2 what? — the
    exact trap [`metres-vs-degrees.md`](../gis/metres-vs-degrees.md) already named. `metres_geography`
    is that chapter's own answer: cast to the curved-earth model, no reprojection needed, 16,708.41
    real metres. `metres_lambert72` is this chapter's addition — `ST_Transform` into Belgium's own
    national grid, then an ordinary flat-plane `ST_Distance` on the transformed coordinates, in the
    grid's own metre units — and it comes out at 16,707.43, **less than a metre away from the
    `geography` answer over a 16.7 km line**. That closeness is not a coincidence: Lambert 72 is a
    projection tuned specifically to Belgium's own borders, and both these climbs sit well inside
    them, exactly where a national grid is built to introduce almost no distortion at all.

    The fourth column is why that sentence needs the words "its own". `metres_lambert93` is the same
    arithmetic in **France's** national grid, EPSG:2154, over the same two Belgian climbs, and it
    reads 16,732.70: about **24 metres** long on 16.7 km. Nothing is broken and no error is raised,
    because a projection will happily give you numbers well outside the region it was fitted to.
    They are simply worse, quietly, by a factor of twenty-four against the neighbouring grid. A
    national grid is not a better coordinate system, it is a coordinate system with a catchment, and
    using one outside its catchment is the commonest way to be precisely wrong. Move the
    same two numbers to a pair of points on opposite sides of the planet and the flat-plane maths
    behind `metres_lambert72` would fall apart long before `metres_geography`'s curved-earth answer
    did — which is the entire reason this project reaches for `geography`, never `ST_Transform`, for
    every real distance it actually asks for.
