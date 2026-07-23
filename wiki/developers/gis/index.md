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

Here is that journey, one stop per chapter for chapters 1 through 8:

1. The fountain sits at a point on the Earth — a latitude and a longitude. Chapter 1 covers what
   those two numbers actually mean, and why they don't behave like ordinary graph-paper coordinates.
2. That point is stored as a *geometry* — the same kind of value that also stores a route's path or
   a region's outline. Chapter 2 covers the shapes GIS uses and GeoJSON, the text format they travel
   in.
3. "Is the fountain within 5 km of me?" sounds like ordinary arithmetic, but degrees are not metres.
   Chapter 3 explains why not, and how the database is told to measure real distance instead.
4. "Which region is the fountain in?" and "did a rider pass it on their ride?" are both spatial
   questions with names. Chapter 4 introduces the small set of functions that answer them.
5. Those same questions have to stay fast once the table holds hundreds of thousands of points, not
   just our one fountain. Chapter 5 covers why, and what makes a spatial query slow or fast.
6. Before it was a database row, the fountain was a *node* in OpenStreetMap: a point with a set of
   free-form tags, shipped inside a Geofabrik export file. Chapter 6 follows it from that raw file
   into a row in our own `coverage_poi` table.
7. A database row still isn't something a browser can draw directly. Chapter 7 covers tiles — how a
   stored point becomes part of a small, pre-cut file the map can fetch and render instantly.
8. Chapter 8 puts that tile on the screen: the map library draws the fountain as a pixel, and a
   rider can tap it and answer "still here?".

Chapter 9 steps off this spine on purpose — a route is a line, not a point, and the difference is
the whole lesson. Chapter 10 doesn't follow the fountain at all; it's the pitfalls list and glossary
you come back to later.

No figure summarises this whole journey in one picture. Each chapter already draws the figure for
its own stop, and a single all-in-one diagram would go stale the first time any one stop changed.

## How to read the code pointers

Every concept in this series that exists in this codebase is followed by a pointer to a real file
and the name of the thing inside it that does the work, written like this:
`web/src/Contribution/SpatialResolver.php`, `SpatialResolver::resolve()`. Open the file if you
want to see it for yourself — that's the reason it's written this way instead of the code being
pasted into the wiki.

A line number is only given when the anchor is stable enough that it won't drift — a database
migration file, for instance, which is never edited again once it has run. Everywhere else you get
the file and the symbol name and nothing more, because a citation pointing at line 214 of a file
that gets refactored next month is worse than no citation at all. If the wiki and the repository
ever disagree, the repository is right: every pointer was checked against the working tree at the
time its chapter was written, but the tree keeps moving and the wiki doesn't always catch up the
same day.

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

Ten chapters. The ones that have been written are linked; the rest are named here so you can see
the whole series at a glance, and each becomes a link as it lands. Every chapter covers one idea
and ends with a slot for a hands-on exercise.

1. [**The Earth is awkward**](coordinates.md) — latitude and longitude, why a degree of longitude
   shrinks toward the poles, what a map projection is and what it costs, and the two coordinate
   systems this project actually uses.
2. [**The shapes**](shapes.md) — points, lines, and polygons; GeoJSON, the format they travel in;
   and why the order of points around a ring matters.
3. [**Metres vs degrees**](metres-vs-degrees.md) — the most common beginner mistake in GIS:
   treating degrees as if they were a unit of distance, and how the database is told to measure real
   ones instead.
4. [**Asking spatial questions**](spatial-questions.md) — the handful of functions that answer "is
   this point inside that shape", "how far apart are these two things", and "what's nearby" — plus
   why searching by place name is a different kind of problem with its own answer.
5. [**Making it fast**](making-it-fast.md) — why spatial queries need their own kind of index, and
   why a query that works fine on a hundred rows can fail once the table holds hundreds of
   thousands.
6. [**From OpenStreetMap to our database**](osm-to-database.md) — how OpenStreetMap describes the
   world, and the steps that turn that raw, foreign data into rows this project owns.
7. [**Tiles**](tiles.md) — why a browser can't be handed hundreds of thousands of points at once,
   and how they get cut into small, pre-built files a map can fetch instead.
8. [**Putting it on screen**](on-screen.md) — how the map library turns a tile into pixels, styles
   them, and lets a rider click or tap on one.
9. [**Lines that mean something**](routes.md) — why a route is a harder problem than a single
   point, and how this project measures things like surface coverage honestly, without overstating
   its own certainty.
10. [**Pitfalls, glossary, where to look**](pitfalls.md) — the mistakes that show up again and
    again in GIS code, a glossary of every term used across this series, and a table for "I need to
    change X, start here".

<!-- EXERCISE-SLOT ch=0 — hands-on box goes here (spec D5); do not remove -->
