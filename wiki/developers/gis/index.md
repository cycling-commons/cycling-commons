<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# GIS from scratch

This series assumes you have never worked with maps. It takes a competent developer with no GIS
background to the point where the spatial parts of this repository read as ordinary code, not as a
foreign specialism. GIS shows up in three places here: in the database (every place, route, and
region is a geometry column, not just a row of text), in the tile pipeline (turning millions of
OpenStreetMap points into files a browser can actually load), and in the map front-end (drawing
those points, lines, and shapes on screen and letting a rider tap one). By the end of the series
you should be able to open any of the three and follow what it's doing.

## Who this is for

You're a competent PHP, JavaScript, or Python developer. You've never worked with GIS before, or
you have and want the parts specific to this codebase. Either way, no chapter assumes you already
know what a projection is, what SRID 4326 means, or why a tile server exists — every term is
defined the first time it's used.

The chapters build in order, and each one assumes you've read the ones before it. None of them
checks whether you skipped ahead; if a later chapter suddenly feels like it's using a term it never
explained, the fix is to go back, not to keep pushing forward.

## One fountain, all the way through

Every chapter from here on opens with the same worked example: a single drinking-water fountain in
Wallonia, mapped by somebody using OpenStreetMap, on its way to becoming a pixel a rider taps on the
Cycling Commons map. Following one real thing the whole way through beats sixteen unrelated
examples, because you can watch it change shape at every stop instead of learning each stop in
isolation.

The chapter list below is that journey — chapters 1 to 8 are one stop each. Chapter 9 steps off the
spine on purpose, because a route is a line rather than a point and the difference is the whole
lesson. Chapter 10 doesn't follow the fountain at all; it's the pitfalls list and glossary you come
back to later.

No figure summarises the whole journey in one picture. Each chapter draws the figure for its own
stop, and a single all-in-one diagram would go stale the first time any one stop changed.

## How to read the code pointers

Every concept in this series that exists in this codebase is followed by a pointer to a real file
and the name of the thing inside it that does the work, written like this:
`web/src/Contribution/SpatialResolver.php`, `SpatialResolver::resolve()`. Open the file and you can
see the whole thing in its own context, which no excerpt can give you.

Where an excerpt makes the point better than a description, the code is quoted directly — and those
quotes are **checked against the source automatically**. A build gate reads every code block in this
wiki and verifies each quoted line still exists, in order, in the file it claims to come from. If
someone changes that code, the check fails and the page has to be brought back into line. So a
quoted block here is the code as it actually is, not as it once was.

Blocks that are *not* quotes — a deliberately naive query, a minimal hand-written example, sample
output — are marked as such where they appear, so you always know whether you are looking at this
project's code or at an illustration.

A line number is only given when the anchor is stable enough that it won't drift — a database
migration file, for instance, which is never edited again once it has run. Everywhere else you get
the file and the symbol name and nothing more, because a citation pointing at line 214 of a file
that gets refactored next month is worse than no citation at all. If the wiki and the repository
ever disagree, the repository is right.

## When something has no code behind it

Almost everything in this series points at something this repository actually does. Two exceptions
exist, and it's worth being able to spot them.

Some pages describe a piece of ordinary GIS knowledge that this codebase has no counterpart for,
simply because nothing here has ever needed it. These pass without any special marking — you'll
just notice that one paragraph has no file pointer next to it. That absence means "general
knowledge, not used here today", nothing more.

The other case is flagged every time. When a page describes how a problem is usually solved
elsewhere, but the Commons does not solve it that way — or at all — today, it says so, in a box like
this:

!!! note "Not in the Commons — yet"
    This is how the problem is usually solved. We do not do it here today.

Whenever you see that box, read the paragraph above it as background, not as a description of this
codebase. It is not a promise that the feature is coming; it is a flag that keeps the page honest
about what actually runs today versus what's merely useful to understand.

## The chapters

Ten chapters, each covering one idea and ending with a slot for a hands-on exercise. The
*where the fountain is* line tracks the worked example above.

1. [**The Earth is awkward**](coordinates.md) — latitude and longitude, why a degree of longitude
   shrinks toward the poles, what a map projection is and what it costs, and the two coordinate
   systems this project actually uses.
   *The fountain is two numbers, and they don't behave like graph paper.*
2. [**The shapes**](shapes.md) — points, lines, and polygons; GeoJSON, the format they travel in;
   and why the order of points around a ring matters.
   *Those numbers become a* geometry *— the same kind of value that stores a route or a region.*
3. [**Metres vs degrees**](metres-vs-degrees.md) — the most common beginner mistake in GIS:
   treating degrees as if they were a unit of distance, and how the database is told to measure real
   ones instead.
   *"Is the fountain within 5 km of me?" — which turns out not to be ordinary arithmetic.*
4. [**Asking spatial questions**](spatial-questions.md) — the handful of functions that answer "is
   this point inside that shape", "how far apart are these two things", and "what's nearby" — plus
   why searching by place name is a different kind of problem with its own answer.
   *"Which region is it in?" and "did a rider pass it?" are questions with names.*
5. [**Making it fast**](making-it-fast.md) — why spatial queries need their own kind of index, and
   why a query that works fine on a hundred rows can fail once the table holds hundreds of
   thousands.
   *The same questions, asked against 375,000 fountains instead of one.*
6. [**From OpenStreetMap to our database**](osm-to-database.md) — how OpenStreetMap describes the
   world, and the steps that turn that raw, foreign data into rows this project owns.
   *Backwards in time: before it was a row, the fountain was a node in a Geofabrik export.*
7. [**Tiles**](tiles.md) — why a browser can't be handed hundreds of thousands of points at once,
   and how they get cut into small, pre-built files a map can fetch instead.
   *The row becomes part of a small pre-cut file the map can fetch instantly.*
8. [**Putting it on screen**](on-screen.md) — how the map library turns a tile into pixels, styles
   them, and lets a rider click or tap on one.
   *The last hop: the fountain is a pixel, and a rider taps it to answer "still here?".*
9. [**Lines that mean something**](routes.md) — why a route is a harder problem than a single
   point, and how this project measures things like surface coverage honestly, without overstating
   its own certainty.
   *Off the spine: a ride is a line, and a line can be asked things a point cannot.*
10. [**Pitfalls, glossary, where to look**](pitfalls.md) — the mistakes that show up again and
    again in GIS code, a glossary of every term used across this series, and a table for "I need to
    change X, start here".
    *Not a stop on the journey — the page you come back to.*

## Try it

!!! tip "Hands-on — prove your stack is up before you start"
    Every chapter from here on runs commands against a live dev database. Before reading chapter 1,
    confirm your stack answers and holds the real data this whole series queries throughout.

    <!-- CODE-ILLUSTRATIVE shell command against the dev stack's Postgres -->
    ```sh
    docker compose -f developers/docker/compose.yaml exec db psql -U cc -d cyclingcommons -c "
    SELECT count(*) AS items FROM item;
    "
    docker compose -f developers/docker/compose.yaml exec db psql -U cc -d cyclingcommons -c "
    SELECT count(*) AS coverage_pois FROM coverage_poi;
    "
    ```

    <!-- CODE-ILLUSTRATIVE sample output from the dev stack -->
    ```text
     items
    -------
      1581
    (1 row)

     coverage_pois
    ---------------
            375078
    (1 row)
    ```

    `item` holds this project's own curated rows; `coverage_poi` holds the much larger OpenStreetMap
    cache chapter 6 explains in full. The exact counts will drift as more data lands — what matters
    right now is that both queries return *some* number instead of a connection error. If either one
    fails, fix that before chapter 1: every exercise later in this series assumes exactly this
    connection works. (`developers/docker/compose.yaml` is also what every later chapter's own
    exercises invoke — the invocation itself does not change from here on, only the SQL after `-c`.)
