<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Relations and complex shapes

**Kind: we do not do it yet.** This is a genuine, named, documented gap. The coverage pipeline reads
two of OpenStreetMap's three primitives — nodes and ways — and has no code path for the third,
relations, at all. `docs/specs/coverage-provider.md` calls closing it an approved fast-follow, with
no schedule attached. This chapter is what that gap actually costs, in concrete terms, and roughly
how big it is.

## Verifying the gap, not just repeating it

[`osm-to-database.md`](../gis/osm-to-database.md) already introduces the OSM data model — node, way,
relation — and already names this exact gap in an admonition. Rather than repeat that, here is the
gap checked directly, at the two places it actually lives in the pipeline.

`pipeline/coverage/extract.py` is the first stage: it hands `osmium tags-filter` a list of selector
expressions built like this:

<!-- CODE-FROM pipeline/coverage/extract.py -->
```python
def selector_expressions(contract: Contract) -> list[str]:
    """osmium tags-filter expressions, nodes + ways (decision B1), deduped in order."""
```

and, a few lines further down, each expression is built with an explicit `nw/` prefix — node and
way, and nothing else:

<!-- CODE-FROM pipeline/coverage/extract.py -->
```python
            expr = f"nw/{sel.tag}"   # Selector.tag is already "key=value"
```

`osmium tags-filter` supports an `r/` prefix for relations too. This pipeline never asks for it. A
relation matching every tag this project cares about is dropped from the extracted PBF at the very
first step, before any of this project's own parsing code ever runs.

Even if it survived that filter, the second stage would still have nowhere to put it.
`pipeline/coverage/parse.py`'s `_Collector` is an `osmium.SimpleHandler` — a class whose methods are
called by name as osmium streams through a file, one callback per primitive kind. It defines two:

<!-- CODE-FROM pipeline/coverage/parse.py -->
```python
    def node(self, n) -> None:
```

<!-- CODE-FROM pipeline/coverage/parse.py -->
```python
    def way(self, w) -> None:
```

There is no `relation()` method anywhere in the class. This is not a silent partial handling of
relations — osmium's dispatch simply never calls a method that was never defined. A relation that
somehow reached this stage would produce nothing: no row, no error, no log line. It would pass
through this code exactly as if it had never existed.

## What an OSM relation actually is

A **node** is a bare coordinate. A **way** is an ordered list of nodes — chapter 6's LineString
idea, closed into an area if the first and last node match. A **relation** is the third primitive,
and it is structurally different from the other two in a way that matters for why it is harder to
support: a relation is a list of **members**, and every member is a reference to some other element
— a node, a way, or even another relation — paired with a **role**, a short string saying what job
that member plays inside the whole.

That role is what turns a bag of unrelated shapes into one coherent thing. A hiking or cycling route
relation lists its member ways in the role `""` (no distinguishing role needed, order matters); a
bus route relation lists stops with the role `stop` and the road ways with the role of their own; a
turn-restriction relation names one way `from`, another `to`, and the junction node `via`. The
member list plus the roles is the entire relation — a relation carries no coordinates of its own at
all, only pointers to other elements that do.

## Multipolygons: the shape this project's castles are missing

The relation type this section is actually about is the **multipolygon** — the way OSM represents an
area whose boundary a single closed way cannot express. That happens for three ordinary reasons:

- the area has a hole in it (a park with a pond excluded, a country with a wholly enclosed foreign
  exclave inside it);
- the area is made of more than one disconnected outer ring (an administrative area with an island
  or exclave of its own);
- the boundary itself is stitched together from several way segments that each also belong to some
  other feature — a country border made of dozens of short ways, each shared with the neighbouring
  country's own border relation, rather than duplicated as one giant standalone ring.

A multipolygon relation's members carry exactly two roles: `outer`, for ways that trace an outer
boundary, and `inner`, for ways that trace a hole cut out of one. Reassembling the actual polygon
means walking the member ways, joining the ones that share endpoints into closed rings, and matching
each resulting ring to its role — the general shape [`shapes.md`](../gis/shapes.md) already
describes for a stored `Polygon` or `MultiPolygon` (a ring, optionally with holes, optionally more
than one of them held together as one value). The difference is where that shape comes from: chapter
2 describes it once it is already a PostGIS geometry, sitting quietly in a `geometry(Geometry,
4326)` column. This section is about the step *before* that — turning a relation's raw member list
into a ring at all — and that step is exactly the one this pipeline has no code for.

## Why the castle is invisible, not just wrong

Picture a real hilltop castle whose grounds are mapped properly: an `outer` way traces the full
perimeter of the walls, one or two `inner` ways cut out an internal courtyard or moat that is not
part of the built structure, and the tag `historic=castle` sits on the **relation itself**, not on
any single node or way. That is a completely ordinary, fully valid way to map a castle — arguably the
*more* correct one, since a single point can only mark where a castle roughly is, while the
multipolygon actually records its real extent.

Run this project's pipeline over it today, and nothing happens. `extract.py`'s `nw/`-only filter
drops the relation at the door. Even granting it a hypothetical pass through that filter, `parse.py`
has no `relation()` handler to call. The castle produces no `coverage_poi` row, which means it never
reaches a tile, which means it never reaches the map, which means no rider ever sees it — and
nothing anywhere logs that a matching object was found and skipped, because as far as this
pipeline's own accounting is concerned, no matching object was found at all.

Now picture the same real castle mapped the plainer way instead: one point node, tagged
`historic=castle`, sitting roughly in the middle of the grounds. That node sails through `node()`
completely unaffected by any of this. Two equally valid OSM representations of the exact same
real-world castle, and this pipeline's outcome for them is not "slightly different accuracy" — it is
"present" versus "does not exist here at all." [`osm-to-database.md`](../gis/osm-to-database.md)
makes the point that OSM tags are a convention, not a schema, and that the same real object can be
tagged inconsistently by different mappers. This is that same lesson one level up: the *primitive* a
mapper reaches for to represent a feature is not fixed either, and this pipeline currently has an
opinion about which primitives it is willing to notice.

There is a second, smaller wrinkle worth being precise about, because it shapes what "closing the
gap" would actually mean. Even a way, which this pipeline *does* read, is not stored as its own
shape today. `coverage_poi.geom` is declared `geometry(Point, 4326) NOT NULL` — a Point, always,
never a LineString or a Polygon regardless of which primitive produced the row — so a way's real
outline is thrown away in favour of the plain mean of its member nodes' coordinates, its centroid.
[`osm-to-database.md`](../gis/osm-to-database.md) covers this reduction and the test that pins it.
The pipeline, in other words, has already decided that `coverage_poi` only ever needs a marker
location, not a shape to draw — which matters for what "supporting relations" would even mean here,
below.

## Roughly what share of the map this is

`docs/specs/coverage-provider.md` puts a number on it directly, in two places, describing it as
"v1 extracts nodes + ways-as-centroid; multipolygon relations (~1–3% of objects) are a fast-follow."
That percentage is measured against the objects that match this project's own tag selectors in the
first place — not against every relation OSM contains, the overwhelming majority of which (bus
routes, hiking routes, administrative boundaries) were never going to match a drinking-fountain or
bike-shop selector regardless of how this pipeline handled relations.

Set against the same document's own corrected planet-wide target — roughly 4.7 million
`coverage_poi` rows once every configured region is onboarded — a rough, order-of-magnitude read of
that percentage puts the *currently invisible* count somewhere in the tens of thousands to
low-hundred-thousands of real-world objects, planet-wide, once every region this project plans to
cover is actually loaded. That is a genuinely small slice of the whole — well under 3% by
construction — but it is not zero, and "some castles," the document's own example, undersells it
slightly: any feature a mapper chose to represent as an area with a hole, or as more than one
disconnected outer ring, falls in the same gap, not only historic buildings.

## What closing the gap would take

`docs/specs/coverage-provider.md`'s Open Questions section is explicit about the shape of the fix:
"pyosmium area assembly is an approved fast-follow with no scheduled plan yet." That phrase is doing
real work, and it explains why this has stayed a fast-follow rather than a quick addition.

Reading a node or a way, the way this pipeline already does, is a one-pass streaming operation:
osmium hands `_Collector` each element once, in whatever order the PBF file stores them, and
`node()`/`way()` can compute an answer immediately from what they are handed. Turning multipolygon
*relations* into real polygons is not that kind of operation, in general. A relation's member ways
can appear anywhere in the file — before or after the relation itself — and reassembling a ring
correctly means matching up shared endpoints across potentially many separately-stored ways, some of
which may also belong to other, unrelated relations. Doing that correctly and efficiently is exactly
the job **pyosmium's area-assembly support** exists for: a two-pass approach that first identifies,
while streaming through once, which ways are actually needed by which relations, then makes a second
pass to build the completed rings from the ways collected along the way — deliberately heavier, in
both code and memory, than a single `node()`/`way()` callback, because the problem it solves is
genuinely a different shape of problem.

Two different amounts of that work would close two different amounts of the gap:

- **The smaller lift, consistent with what this pipeline already does to ways:** treat an assembled
  multipolygon exactly the way `way()` already treats a way — reduce it to a single representative
  point (its centroid, or a proper "point on surface" so the marker always lands inside the shape
  rather than possibly in a hole) — and add a `relation()` handler that does the area assembly and
  emits that one point. No schema change: `coverage_poi.geom` stays `geometry(Point, 4326)`, exactly
  as today.
- **The fuller lift:** actually store the assembled shape, not just a point derived from it. That
  would mean widening `coverage_poi.geom` from `geometry(Point, 4326)` to the more permissive
  `geometry(Geometry, 4326)` [`shapes.md`](../gis/shapes.md) already describes every other geometry
  column in this project using, a migration to match, and an audit of every downstream consumer that
  currently assumes a coverage row's `geom` is always a single coordinate pair — the tile builder, the
  drawer, anywhere a marker is drawn at a point rather than an outline.

Nothing in the public record commits this project to either path, or to a date. It is written down
as an accepted gap, not a hidden one — which is the entire point of flagging it here rather than
letting a new contributor discover it by noticing a castle is missing and wondering why.

## Try it

!!! tip "Hands-on: verify the gap yourself, on whatever coverage you have loaded"
    Two checks against the live `coverage_poi` table, together proving what this chapter argued: no
    relation-derived row exists in it today, and even if one did, the column has nowhere to put
    anything but a point. Neither check names a row, so both are true at every size — they are
    statements about the whole table, and the table is the thing being cross-examined.

    Every stored `ref` is shaped `<osm-primitive>/<id>` — `extract.py`'s `nw/`-only filter and
    `parse.py`'s missing `relation()` handler both predict only two prefixes will ever appear:

    <!-- CODE-ILLUSTRATIVE psql query against the dev stack's coverage_poi table -->
    ```sql
    SELECT split_part(ref, '/', 1) AS ref_kind, count(*)
    FROM coverage_poi
    GROUP BY 1 ORDER BY 2 DESC;
    ```

    <!-- CODE-ILLUSTRATIVE sample output on a stack seeded by `make course-data`; the counts are that seed's, the two-row shape is not -->
    ```text
     ref_kind | count
    ----------+-------
     node     |     9
     way      |     1
    (2 rows)
    ```

    Two kinds, `node` and `way`, and nothing else. Ten rows here, because `make course-data` builds
    coverage from a small committed OSM fixture so the course runs offline; on a machine that has
    harvested every onboarded country the same query returns, at the time of writing, roughly 1.6
    million `node` rows and 440,000 `way` rows across 19 countries, still exactly two kinds. **That
    the list has two entries is the finding; how long each one is is not.** Confirm the third primitive is
    genuinely absent, not merely rare enough to round to zero in a table of whatever size yours is:

    <!-- CODE-ILLUSTRATIVE psql query against the same table -->
    ```sql
    SELECT count(*) FROM coverage_poi WHERE ref LIKE 'relation/%';
    ```

    <!-- CODE-ILLUSTRATIVE sample output; zero on any install, at any coverage size -->
    ```text
     count
    -------
         0
    (1 row)
    ```

    Zero, across the whole table. Now the second half of the argument: even a relation that somehow
    slipped past both the extract filter and the missing handler would have nowhere to land, because
    of what this column is declared as:

    <!-- CODE-ILLUSTRATIVE psql query against the same table -->
    ```sql
    SELECT DISTINCT GeometryType(geom) FROM coverage_poi;
    ```

    <!-- CODE-ILLUSTRATIVE sample output; one row on any install, because the column's own type forbids a second -->
    ```text
     geometrytype
    --------------
     POINT
    (1 row)
    ```

    One value, `POINT`, across every row you have — `coverage_poi.geom` is `geometry(Point, 4326)`,
    not the permissive `geometry(Geometry, 4326)` every other geometry column in this project uses.
    A reassembled castle multipolygon could not be stored here even on the day somebody writes the
    `relation()` handler this chapter describes; the schema itself would have to change first, exactly
    as the "fuller lift" section above says.
