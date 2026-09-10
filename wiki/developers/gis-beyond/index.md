<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Beyond this codebase

!!! abstract "The short version"
    Six short chapters, about seventy-five minutes in total, on the GIS this project does **not**
    do and why. Same stack as course 1, so if you have run `make setup` and `make course-data` you
    are ready. Read them in any order; each one stands alone.

The [GIS course](../gis/index.md) has a promise attached to it: everything it teaches exists in this
repository, and every line of code it quotes is checked against the source automatically. That
promise is what makes it trustworthy, and it is also what it cannot cover.

This second course covers the other half: real GIS work that Cycling Commons mostly **does not
do**, and the narrow, deliberate places where it does.

Some of it we deliberately avoid. Some of it we would need if the project grew in an obvious
direction, and one or two are gaps somebody will eventually have to close. Either way you will meet
these ideas as soon as you read anybody else's spatial code, and not knowing them is how a
reasonable-looking decision turns out to have been the wrong one three months later.

## How to read this course, and how it differs

Course 1 anchors every claim to a file you can open. **This one mostly cannot**, and it says so.
Each chapter is explicit about which of three kinds it is:

- **We do not need it.** The concept is real and widely used, and this project's data or design
  means it never comes up. Worth knowing so you recognise it, not something to add.
- **We do not do it yet.** A genuine gap. The chapter says what closing it would take, and what
  the current workaround costs.
- **We deliberately do not.** We considered it and chose otherwise. The chapter gives the reasoning
  so a future contributor can revisit the decision rather than rediscover it.

Where a chapter *can* point at real code — usually the thing we do instead — it does, with the same
verified quoting as course 1.

## The chapters

1. [**Reprojection**](reprojection.md) · ~10 min: why everything here stays in EPSG:4326, what `ST_Transform`
   is for, and the day you will need it.
2. [**Relations and complex shapes**](relations.md) · ~10 min: the OSM primitive our pipeline skips, and what
   it costs us today.
3. [**Geocoding, both directions**](geocoding.md) · ~10 min: we search by name through one external
   geocoder, resolve points to regions only in our own PostGIS tables, and never ask an external
   service what a coordinate is called. That boundary is a design decision worth understanding.
4. [**Routing**](routing.md) · ~10 min: finding a way from A to B is graph search over a weighted network, not
   a spatial query. The most commonly confused pair of ideas in this field.
5. [**Elevation and terrain**](elevation.md) · ~15 min: where ascent numbers come from, why two tools disagree
   about the same ride, and why that is not a bug.
6. [**Edge cases that bite**](edge-cases.md) · ~20 min: the antimeridian, the poles, ring winding, and the
   other places round-Earth reality breaks flat-plane assumptions.

Every chapter ends with a hands-on exercise, the same as course 1, and most of them run against the
local Docker stack and nothing else. Two are honest exceptions, each said so on its own page:
chapter 3's needs the internet, because the geocoder it asks is not something this project hosts,
and chapter 4's is a conceptual substitute, because there is no routing engine here to demonstrate.

!!! tip "If an exercise does not work"
    [When an exercise does not work](../troubleshooting.md) collects the failures that
    actually happen: an empty result, a missing table, a map drawing nothing, a harvest
    exiting non-zero, an elevation call answering plausibly-shaped zeros.

## Try it

!!! tip "Hands-on: confirm the stack answers, and that PostGIS ships what this course talks about"
    This course runs against the same dev stack course 1 does, seeded the same way: `make setup`
    then `make course-data`. Course 1's [own Try it box](../gis/index.md#try-it) sets both up and
    proves they worked, and there is no reason to repeat it here.

    Before trusting a course that keeps saying "this function exists but we never call it," check
    both halves of that claim yourself: that the dev stack answers at all, and that `ST_Transform`
    and `ST_Segmentize` — the two functions chapter 1 and chapter 6 lean on most — are real,
    callable PostGIS functions rather than something hypothetical.

    <!-- CODE-ILLUSTRATIVE shell command against the dev stack's Postgres -->
    ```sh
    docker compose -f developers/docker/compose.yaml exec db psql -U cc -d cyclingcommons -c "SELECT 1;"
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM any-install; sample output from the dev stack -->
    ```text
     ?column?
    ----------
            1
    (1 row)
    ```

    The stack answers. Now check the two functions by name, in Postgres's own catalogue of installed
    functions:

    <!-- CODE-ILLUSTRATIVE psql query against Postgres system catalogs -->
    ```sql
    SELECT proname FROM pg_proc WHERE proname IN ('st_transform', 'st_segmentize') GROUP BY proname;
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM any-install; sample output from the dev stack -->
    ```text
        proname
    ---------------
     st_segmentize
     st_transform
    (2 rows)
    ```

    Both are installed. Call each once, on a real pair of catalog coordinates, to see they actually
    run rather than merely being registered:

    <!-- CODE-ILLUSTRATIVE psql query against Postgres; the two coordinate pairs are the seeded Côte de la Redoute and Cascade de Coo pins, written as literals so this check needs no rows at all -->
    ```sql
    SELECT
      ST_AsText(ST_Transform(ST_SetSRID(ST_Point(5.69924, 50.49222), 4326), 31370)) AS transformed_lambert72,
      ST_NPoints(ST_Segmentize(ST_MakeLine(ST_Point(5.69924,50.49222), ST_Point(5.87664,50.39359))::geography, 1000)::geometry) AS segments_every_1km;
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM any-install; sample output; stable for these literal coordinates -->
    ```text
             transformed_lambert72          | segments_every_1km
    ---------------------------------------------+--------------------
     POINT(244402.57047243137 132115.3036512304) |                 33
    (1 row)
    ```

    `ST_Transform` reprojected one point into Belgian Lambert 72 — chapter 1's subject. `ST_Segmentize`
    walked the geodesic between two real climbs and cut it into 33 vertices, never more than 1 km
    apart along the ellipsoid — the same "don't trust a straight line between two far-apart
    endpoints" idea chapter 6 raises for great circles. Neither call reads or writes a single row of
    this project's own tables. Both functions are exactly as real, and exactly as unused by this
    codebase, as the rest of this course says.
