<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Making it fast

A rider finishes a long day in the Ardennes, exports the GPX from their bike computer, and drops it
into the map's ride-check box. The question is easy to say out loud: *which of the things on this
map did I actually pass?* Our fountain is one of the answers — 80 metres off the track, somewhere
around kilometre 23.

Chapter 4 ([`spatial-questions.md`](spatial-questions.md)) already gave you everything you need to
express that question, and chapter 3 ([`metres-vs-degrees.md`](metres-vs-degrees.md)) gave you the
`::geography` cast that makes "within 250 metres" mean real metres instead of degrees. Put the two
together and you get a query that is short, readable, and correct.

It is also, written the obvious way, unusably slow. Not wrong. Slow.

That gap is what this chapter is about, and it is the one thing in this series you cannot discover
by reading code carefully. A query that compares your shape against every row in the table works
perfectly on a freshly seeded development database with a few hundred catalog items. It works so
well that nothing suggests there is a problem. Then the same code meets a table that has grown:
this project's `coverage_poi` table holds **375,078 rows** across Belgium, the Netherlands and
Germany, of which Germany alone contributed **317,887** (see
`docs/specs/2026-07-22-country-onboarding-design.md`), and the planet-wide target for that table is
around 4.7 million points (`pipeline/coverage/load.py`, the sizing comment above `_TABLE_DDL`). The
query does not change. The answer does not change. The time it takes changes by four orders of
magnitude.

The thing that closes that gap is an index. Not the ordinary kind — the ordinary kind cannot help
here at all — but a spatial one, and it works differently enough from a B-tree that it is worth
understanding rather than just switching on.

This chapter ends with a real rewrite that happened in this repository, is recorded in the code's
own comments, and took one query from 62 seconds to under a second without changing what it
returns.

## Bounding boxes

Start with the simplest idea in spatial indexing, because everything else is built on it.

A **bounding box** is the smallest upright rectangle that completely contains a shape. "Upright"
means its sides run parallel to the axes — it is never rotated to fit the shape more snugly. That
makes it four numbers and nothing else: the smallest x, the smallest y, the largest x, the largest
y. For a shape stored in EPSG:4326 (chapter 1, [`coordinates.md`](coordinates.md)) those are degrees
of longitude and latitude, which is fine, because a box is only ever compared with other boxes
measured the same way.

Two things make bounding boxes useful.

**They are cheap to compute.** One pass over the shape's coordinates, keeping four running minimums
and maximums. A region outline with twelve thousand points still reduces to four numbers. And you
only compute it once: the database stores it and reuses it.

**They are cheap to compare.** Two boxes overlap if, and only if, they overlap on the x axis *and*
they overlap on the y axis. That is four number comparisons. Compare that with asking whether a
twelve-thousand-point outline actually contains a given point, which means walking the outline.

Now the part that matters, and that people get backwards:

> A bounding box test can prove that two shapes **cannot** touch. It can never prove that they do.

If the boxes do not overlap, you are finished — the shapes are definitely apart, and you did not
have to look at either shape to know it. That is a proof, not a guess.

If the boxes *do* overlap, you have learned nothing certain. Wallonia's bounding box contains a
good deal of ground that is in France, and a point sitting in that ground passes the box test while
being firmly outside the region. The box is a *conservative* approximation: it never wrongly
excludes, and it frequently wrongly includes.

That asymmetry is not a flaw to be worked around. It is the whole design. It means a box test can
be used to throw work away safely, and it means a box test alone can never be the final answer.

## What a GiST index actually does

**GiST** stands for Generalised Search Tree. The generalisation is the point. An ordinary B-tree
index — the one you get by default on an integer or a string column — works because those values
can be put in order. `4` is less than `7`; `"amsterdam"` sorts before `"berlin"`. A tree of sorted
values lets the database jump straight to a range and ignore the rest.

Geometry has no such order. Is a Belgian province "less than" a drinking fountain? The question is
meaningless. So B-trees are not merely slow for shapes, they are inapplicable. GiST is PostgreSQL's
framework for building index trees over data where "less than" makes no sense but "contains" and
"overlaps" do, and PostGIS uses it to index geometry.

Here is what it stores, which is the whole trick: **not the shapes, the boxes.** Every row's
geometry is reduced to its bounding box, and those boxes are grouped into a tree. Every node in the
tree stores one box that encloses all the boxes beneath it. A leaf holds a row's own box and a
pointer to the row.

Searching is then a descent. You give the tree the box of the thing you are asking about, and at
each node you compare boxes. If a node's box does not overlap your query box, nothing underneath it
can possibly match — so you skip the node and every row beneath it, without ever touching them.
That is why the tree wins: it discards rows in whole branches instead of one at a time.

What comes out of the descent is a **candidate set**: every row whose box overlaps yours. From the
previous section you know exactly what that is worth. It is guaranteed to contain every true match,
and it is also guaranteed to contain some rows that are not matches at all.

So the database runs a second phase. For each candidate it fetches the real geometry and runs the
real, exact test. The survivors of that recheck are the answer.

Two phases, and they have different jobs:

1. **The index scan.** Box against box, in a tree. Fast, approximate, and it only ever errs by
   letting too much through.
2. **The exact recheck.** Real geometry against real geometry, on the small set phase 1 handed
   over. Slow per row, but there are now very few rows.

PostGIS wires this into the functions themselves. Something like `ST_Intersects(a, b)` is defined
as a bounding-box overlap test — written `a && b`, and that is the part the GiST index can answer —
combined with the exact internal test. You write one function call; you get both phases.

<figure class="gis-fig"><svg viewBox="0 0 640 620" role="img" aria-labelledby="f8-t f8-d" xmlns="http://www.w3.org/2000/svg"><title id="f8-t">A bounding box around a region outline, and the four points it sorts differently</title><desc id="f8-d">An irregular, many-sided region outline is drawn as a filled shape. Around it, touching it on all four sides, is a dashed rectangle labelled "phase 1, the bounding box". The outline itself is labelled "phase 2, the real shape". Four numbered points are placed on the picture. Point 1 sits well outside the dashed rectangle: the index rejects it without ever looking at the region's real outline. Point 2 sits inside the rectangle but in a corner of it that the region's outline does not reach, so it passes the box test and is then dropped by the exact recheck against the real shape. Points 3 and 4 sit inside both the rectangle and the outline, and are the genuine answers. A key below repeats the three outcomes: point 1, outside the box, rejected free; point 2, in the box only, dropped at recheck; points 3 and 4, in both, the real answers.</desc><rect class="gis-muted" x="120" y="90" width="400" height="260" stroke-dasharray="7 6"/><path class="gis-ink gis-fill-paper" d="M 120 250 L 180 150 L 260 90 L 330 160 L 420 110 L 520 200 L 470 300 L 380 350 L 300 290 L 200 340 Z"/><line class="gis-muted" x1="238" y1="374" x2="252" y2="318"/><circle class="gis-ink gis-fill-glacier" cx="62" cy="140" r="11"/><circle class="gis-ink gis-fill-clay" cx="470" cy="130" r="11"/><circle class="gis-ink gis-fill-accent" cx="300" cy="200" r="11"/><circle class="gis-ink gis-fill-accent" cx="400" cy="250" r="11"/><text class="gis-label-sm gis-halo" x="62" y="118" text-anchor="middle">1</text><text class="gis-label-sm gis-halo" x="470" y="108" text-anchor="middle">2</text><text class="gis-label-sm gis-halo" x="300" y="178" text-anchor="middle">3</text><text class="gis-label-sm gis-halo" x="400" y="228" text-anchor="middle">4</text><text class="gis-label-sm gis-halo" x="120" y="74">phase 1 · the bounding box</text><text class="gis-label-sm gis-halo" x="120" y="386">phase 2 · the real shape</text><text x="20" y="446">Two phases, one question</text><circle class="gis-ink gis-fill-glacier" cx="34" cy="486" r="11"/><text class="gis-label-sm" x="58" y="494">1 — outside the box: rejected free</text><circle class="gis-ink gis-fill-clay" cx="34" cy="530" r="11"/><text class="gis-label-sm" x="58" y="538">2 — in the box only: dropped at recheck</text><circle class="gis-ink gis-fill-accent" cx="34" cy="574" r="11"/><text class="gis-label-sm" x="58" y="582">3, 4 — in both: the real answers</text></svg><figcaption>The dashed rectangle is the region's bounding box: the smallest upright rectangle that contains it. The index stores that rectangle, not the outline. Point 1 fails the box test, so it is discarded without the outline ever being read. Point 2 passes the box test and then fails the exact one — the box promised nothing, and this is the price of that. Points 3 and 4 pass both. The index's job is not to answer the question; it is to make the question much smaller before the expensive part starts.</figcaption></figure>

Every geometry column this project searches spatially carries one of these indexes, created in the
same statement block that created its table:

- `CREATE INDEX idx_item_geom ON item USING GIST (geom)` —
  `web/migrations/Version20260703153611.php:27`
- `idx_route_geom` on `recommended_route` and `idx_heat_geom` on `heat_point`, same file, lines 31
  and 33
- `idx_region_geom` on `region` — `web/migrations/Version20260703152605.php:24`
- `idx_submission_geom` on `submission` — `web/migrations/Version20260704222148.php:30`
- `coverage_poi_geom_idx` on `coverage_poi` — not a Symfony migration at all. That table is owned by
  the Python pipeline, so its schema and its indexes live in `pipeline/coverage/load.py`, in the
  `_INDEX_DDL` tuple next to the table's own DDL.

One geometry column in this codebase has no GiST index: `users.base_point`, the rider's coarse home
location added by `web/migrations/Version20260721160000.php`. That is deliberate rather than
forgotten. Nothing ever searches for users *by* that point. It is read back out for a row the code
already has — `BaseLocationService` pulls it apart with `ST_X` and `ST_Y`
(`web/src/Service/BaseLocationService.php`) — and the searching it feeds is done against `region`,
which does have an index. An index you never search is storage and write cost for nothing.

## Which predicates can use it

Here is the rule that the rest of this chapter is a demonstration of. It is short, and it is easy
to half-remember in a way that is wrong.

> An index holds boxes for **the exact expression it was built on**. Every one of ours was built on
> the bare column: `USING GIST (geom)`. So the only comparisons the index can serve are comparisons
> whose indexed side is `geom`, exactly as it is stored.

The predicates from chapter 4 are all built to cooperate with that. `ST_Intersects`, `ST_Contains`,
`ST_Within`, `ST_Crosses` and the geometry form of `ST_DWithin` are each defined in terms of a
bounding-box operator over their arguments, which is precisely the thing a GiST index answers. Hand
one of them a bare indexed column and a value that does not depend on the row, and the two-phase
machinery from the previous section is available.

Two things take that away.

**A function wrapping the indexed column.** `ST_Buffer(i.geom, 0.01)`, `ST_Centroid(i.geom)`,
`ST_PointOnSurface(i.geom)` — each of these computes a *new* shape, per row. `idx_item_geom` holds
boxes for `i.geom`. It holds nothing whatsoever about `ST_Centroid(i.geom)`. It is not that the
database refuses to use the index; it is that the index genuinely contains no information about the
value being asked for.

You can watch both halves of that in one line of this repository. The region backfill in
`web/src/Catalog/Command/ImportCatalogCommand.php` joins items to regions with
`ST_Contains(r.geom, ST_PointOnSurface(i.geom))`. The right-hand side is a function of `i.geom`, so
nothing about `idx_item_geom` applies to it. The left-hand side is `r.geom`, bare and indexed, so
the box test on the region side is available. One predicate, one indexed side and one not — which
is the right shape for a backfill that has to visit every item anyway.

**A cast.** This is the one that bites, because a cast does not look like a function call and does
not read like work.

`i.geom::geography` is not `i.geom`. It is a different value, of a different type, with different
semantics — chapter 3 is entirely about what those semantics buy you. It has to be computed, and it
is computed once per row. The GiST index was built on `i.geom`, so it knows nothing about
`i.geom::geography`, and it cannot serve a comparison phrased in terms of it.

This is worth saying slowly, because the wrong lesson is easy to take away here. The problem is
**not** that geography maths is expensive. It is expensive — an ellipsoid distance is real
trigonometry and it is much more work than comparing four numbers — but that is not what breaks. The
problem is that phrasing the comparison in terms of the cast puts it outside what the index knows,
so *every row in the table* reaches the expensive part. Cheap maths on every row would also be too
slow, once there are enough rows.

Keep that distinction in hand for the next section, because the fix that follows does not make the
maths cheaper. It makes fewer rows reach it.

## A real rewrite

This happened here. The code, the comment explaining it, and the measurement are all in
`web/src/Catalog/RideCheckService.php`.

### The question

A rider uploads a GPX file. `RideCheckService::check()` parses it, simplifies the track, and turns
it into a GeoJSON `LineString` (chapter 2, [`shapes.md`](shapes.md)). Then it asks the database:
which served catalog items lie within the rider's chosen radius of that track? The radius is one of
`ALLOWED_RADII` — 100, 250, 500 or 1000 metres, defaulting to 250 — and the work happens in the
private method `RideCheckService::corridorGroups()`.

Each match also needs two numbers to be useful: how far off the track it was, and how far along the
ride it appeared, so the results can be listed in the order the rider met them. Those come from
`ST_Distance` and from `ST_LineLocatePoint` over `ST_ClosestPoint`, both introduced in chapter 4.

### The naive version

The obvious way to ask "within 250 real metres of this line" is the one chapter 3 teaches:

```sql
ST_DWithin(item.geom::geography, track::geography, :radius)
```

Read it and there is nothing to object to. It is short. It says exactly what it means. The radius
is in metres, honestly measured on the ellipsoid, which is the entire reason for the casts. Every
review would pass it.

And it is the shape the previous section just described. The cast sits on `item.geom`, the indexed
column, so `idx_item_geom` cannot serve the comparison. Every row in `item` is read, and for every
one of them the database computes an exact ellipsoid distance against the whole simplified track —
a line with a lot of vertices in it.

The comment in `corridorGroups()` records what that cost, and it is not a rounding error:

> `62 s down to sub-second, dev catalog`

Sixty-two seconds, on a development catalog, for a request a rider is sitting and waiting for.

### The fix

The rewrite does not touch the maths and does not change the answer. It changes *where the cast
sits*.

Build the corridor first. Take the track, cast **it** to geography, and buffer it by the radius —
`ST_Buffer` on a geography argument grows the shape by that many real metres, for the same reason
`ST_DWithin` on geography measured in real metres. Then cast the *result* back to geometry, so what
comes out is an ordinary EPSG:4326 shape. That is a single value, computed once, that does not
depend on any row.

The two lines that do it:

```sql
WITH track AS MATERIALIZED (SELECT ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326) AS g),
     corridor AS MATERIALIZED (SELECT ST_Buffer((SELECT g FROM track)::geography, :radius)::geometry AS b)
```

The test in the `WHERE` clause is then simply `ST_Intersects(i.geom, (SELECT b FROM corridor))`:
the bare indexed column on one side, one constant geometry on the other. That is exactly the
comparison `idx_item_geom` was built to answer, so the two-phase machinery from the top of this
chapter switches on — the tree prunes the table down to the handful of items whose boxes overlap
the corridor, and only those get an exact geometry test.

Notice what did *not* happen:

- **Nothing got cheaper.** Buffering a long line is considerably more work than one distance
  calculation. It is simply done once instead of once per row.
- **Nothing got less accurate about the radius.** The metres are still real metres, still measured
  in geography, still on the ellipsoid. The `::geography` cast was not removed. It was moved to the
  other side of the comparison, where the row count is one.
- **The exact test still runs.** `ST_Intersects` is not a box test; it is a box test *followed by*
  an exact test. Phase 2 is still there. It just has almost nothing left to check.

The distance and along-the-ride numbers are still computed with `ST_Distance` and
`ST_LineLocatePoint`, in the `SELECT` list — which means they now run only for rows that already
survived the corridor test, rather than for the whole table.

The same idiom appears immediately below in `RideCheckService::followedRoutes()`, which reuses the
identical corridor and probes `recommended_route` with `ST_Intersects(r.geom, …)`. Its own comment
names the index it rides: `idx_route_geom`.

<figure class="gis-fig"><svg viewBox="0 0 640 1320" role="img" aria-labelledby="f9-t f9-d" xmlns="http://www.w3.org/2000/svg"><title id="f9-t">The same ride-check question written two ways: cast on the column, and cast on the track</title><desc id="f9-d">Two stacked panels comparing one query before and after a rewrite. The upper panel is headed "Before" and badged "Seq Scan". It shows a vertical stack of six identical row boxes, labelled "every row in item, one by one", with an arrow from each row converging on a large globe drawn with a meridian and parallels, labelled "ellipsoid maths on every single row". Below it the predicate is written out over three lines, WHERE ST_DWithin, then i.geom colon colon geography, then track colon colon geography comma 250. The middle line, the one carrying the cast, is highlighted, and a note reads "the cast sits on the indexed column". The lower panel is headed "After" and badged "Index Scan". It shows a track drawn as a bent line with a wide shaded band around it, labelled "one corridor, built once". Four filled points lie on the track inside the band and are the matches; eight pale points lie well outside the band and are never examined. A note reads "a handful of candidates, not the table". Beneath that is a small tree of three levels: a root box, two child boxes of which the left one is drawn dashed and empty and labelled "pruned", and two leaf boxes under the right child labelled "candidates", with the label "the GiST tree drops whole branches". Below the tree the rewritten query is written over three lines, ST_Buffer of track colon colon geography comma 250, then colon colon geometry AS corridor, then WHERE ST_Intersects of i.geom and corridor. The buffer line, which now carries the cast, is highlighted, and a note reads "the column is compared as it is stored".</desc><defs><marker id="gis-arrow-f9" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs><text x="20" y="48">Before</text><text class="gis-label-mono" x="620" y="48" text-anchor="end">Seq Scan</text><text class="gis-label-sm" x="20" y="86">every row in item, one by one</text><rect class="gis-box" rx="4" x="20" y="100" width="170" height="26"/><rect class="gis-box" rx="4" x="20" y="138" width="170" height="26"/><rect class="gis-box" rx="4" x="20" y="176" width="170" height="26"/><rect class="gis-box" rx="4" x="20" y="214" width="170" height="26"/><rect class="gis-box" rx="4" x="20" y="252" width="170" height="26"/><rect class="gis-box" rx="4" x="20" y="290" width="170" height="26"/><line class="gis-ink" x1="196" y1="113" x2="400" y2="208" marker-end="url(#gis-arrow-f9)"/><line class="gis-ink" x1="196" y1="151" x2="400" y2="208" marker-end="url(#gis-arrow-f9)"/><line class="gis-ink" x1="196" y1="189" x2="400" y2="208" marker-end="url(#gis-arrow-f9)"/><line class="gis-ink" x1="196" y1="227" x2="400" y2="208" marker-end="url(#gis-arrow-f9)"/><line class="gis-ink" x1="196" y1="265" x2="400" y2="208" marker-end="url(#gis-arrow-f9)"/><line class="gis-ink" x1="196" y1="303" x2="400" y2="208" marker-end="url(#gis-arrow-f9)"/><circle class="gis-ink gis-fill-paper" cx="470" cy="208" r="62"/><ellipse class="gis-muted" cx="470" cy="208" rx="24" ry="62"/><line class="gis-muted" x1="408" y1="208" x2="532" y2="208"/><line class="gis-muted" x1="424" y1="170" x2="516" y2="170"/><line class="gis-muted" x1="424" y1="246" x2="516" y2="246"/><text class="gis-label-sm" x="470" y="302" text-anchor="middle">ellipsoid maths</text><text class="gis-label-sm" x="470" y="330" text-anchor="middle">on every single row</text><rect class="gis-fill-clay" fill-opacity=".22" rx="4" x="24" y="392" width="320" height="34"/><text class="gis-label-mono" x="30" y="386">WHERE ST_DWithin(</text><text class="gis-label-mono" x="30" y="418">  i.geom::geography,</text><text class="gis-label-mono" x="30" y="450">  track::geography, 250)</text><text class="gis-label-sm" x="30" y="490">the cast sits on the indexed column</text><line class="gis-muted" x1="20" y1="524" x2="620" y2="524"/><text x="20" y="572">After</text><text class="gis-label-mono" x="620" y="572" text-anchor="end">Index Scan</text><path class="gis-fill-glacier" fill-opacity=".3" d="M 53.9 682.7 L 330 621 L 606.5 687.8 A 28 28 0 0 1 593.5 742.2 L 330 679 L 66.1 737.3 A 28 28 0 0 1 53.9 682.7 Z"/><path class="gis-muted" d="M 53.9 682.7 L 330 621 L 606.5 687.8 A 28 28 0 0 1 593.5 742.2 L 330 679 L 66.1 737.3 A 28 28 0 0 1 53.9 682.7 Z"/><path class="gis-accent" d="M 60 710 L 330 650 L 600 715"/><circle class="gis-ink gis-fill-glacier" cx="75" cy="640" r="8"/><circle class="gis-ink gis-fill-glacier" cx="200" cy="770" r="8"/><circle class="gis-ink gis-fill-glacier" cx="350" cy="600" r="8"/><circle class="gis-ink gis-fill-glacier" cx="390" cy="775" r="8"/><circle class="gis-ink gis-fill-glacier" cx="480" cy="605" r="8"/><circle class="gis-ink gis-fill-glacier" cx="560" cy="770" r="8"/><circle class="gis-ink gis-fill-glacier" cx="620" cy="640" r="8"/><circle class="gis-ink gis-fill-glacier" cx="40" cy="780" r="8"/><circle class="gis-ink gis-fill-accent" cx="150" cy="690" r="10"/><circle class="gis-ink gis-fill-accent" cx="255" cy="667" r="10"/><circle class="gis-ink gis-fill-accent" cx="420" cy="672" r="10"/><circle class="gis-ink gis-fill-accent" cx="520" cy="696" r="10"/><text class="gis-label-sm gis-halo" x="20" y="612">one corridor, built once</text><text class="gis-label-sm" x="20" y="820">a handful of candidates, not the table</text><text class="gis-label-sm" x="20" y="856">the GiST tree drops whole branches</text><rect class="gis-box" rx="8" x="278" y="876" width="84" height="56"/><line class="gis-muted" stroke-dasharray="6 5" x1="320" y1="932" x2="192" y2="960"/><line class="gis-ink" x1="320" y1="932" x2="448" y2="960"/><rect class="gis-muted" rx="8" stroke-dasharray="6 5" x="132" y="960" width="120" height="56"/><rect class="gis-box" rx="8" x="406" y="960" width="84" height="56"/><line class="gis-ink" x1="448" y1="1016" x2="388" y2="1044"/><line class="gis-ink" x1="448" y1="1016" x2="508" y2="1044"/><rect class="gis-box" rx="8" x="346" y="1044" width="84" height="56"/><rect class="gis-box" rx="8" x="466" y="1044" width="84" height="56"/><text class="gis-label-sm" x="192" y="1052" text-anchor="middle">pruned</text><text class="gis-label-sm" x="448" y="1136" text-anchor="middle">candidates</text><rect class="gis-fill-clay" fill-opacity=".22" rx="4" x="24" y="1152" width="475" height="34"/><text class="gis-label-mono" x="30" y="1178">ST_Buffer(track::geography, 250)</text><text class="gis-label-mono" x="30" y="1210">  ::geometry AS corridor</text><text class="gis-label-mono" x="30" y="1250">WHERE ST_Intersects(i.geom, corridor)</text><text class="gis-label-sm" x="30" y="1290">the column is compared as it is stored</text></svg><figcaption>The same question, the same answer, the same radius in real metres. What moved is the <code>::geography</code> cast. Above, it sits on <code>i.geom</code> — the indexed column — so the index holds nothing about the value being compared and every row in the table has to be read and measured. Below, it sits on the track, inside a corridor that is built once; the comparison that reaches the table is <code>i.geom</code> exactly as stored, which is what <code>idx_item_geom</code> holds boxes for, so whole branches of the tree are skipped and only a handful of rows are ever examined exactly. The plan node may appear as <code>Index Scan</code> or as <code>Bitmap Index Scan</code> feeding a <code>Bitmap Heap Scan</code>; both mean the index was used.</figcaption></figure>

One honest footnote. A buffer is a polygon, and a polygon's edge approximates a true circle with a
finite number of straight segments. So "inside the corridor" and "within exactly N metres" are not
character-for-character the same set: a point sitting almost precisely on the boundary could fall on
either side of it. At the radii this feature offers — 100 metres to a kilometre — that difference is
far below the accuracy of the GPS trace being measured against, and well below the point where it
could change an answer a rider would notice.

## Why MATERIALIZED is load-bearing

You will have noticed the word `MATERIALIZED` in both of those CTEs. It is not decoration, and the
reasons it is there are worth separating, because there are two of them and they are independent.

A **CTE** — a Common Table Expression — is the `WITH name AS (…)` block at the top of a query. It
names a subquery so the rest of the statement can refer to it. PostgreSQL is allowed to *inline* a
CTE: instead of computing it once and keeping the result, it substitutes the definition at each
place the name is used, which lets the planner optimise across the boundary. Usually that is a good
thing. Writing `MATERIALIZED` removes the choice: compute it once, keep the result, use it
everywhere.

The comment above `corridorGroups()` states both reasons in the developers' own words:

> MATERIALIZED is load-bearing twice over: an inlined `track` CTE re-parses the whole GeoJSON per
> row per ST_* occurrence, and the one-off `corridor` buffer turns the containment test into a plain
> ST_Intersects the idx_item_geom GIST index can serve.

**Reason one: the parsing.** The `track` CTE's body is `ST_GeomFromGeoJSON(:geom)` — it takes the
uploaded track as a JSON string and parses it into a geometry. The query then refers to `track` in
four separate places: once to build the corridor, once inside `ST_Distance`, and twice inside the
`ST_LineLocatePoint` / `ST_ClosestPoint` pair. If that CTE were inlined, each of those references
would become its own parse of the entire GeoJSON text, and the two in the `SELECT` list would do it
again for every row that comes back. `MATERIALIZED` means the string is parsed exactly once.

**Reason two: the shape of the test.** As the comment puts it, it is the *one-off* corridor that
turns the containment question into a plain `ST_Intersects` the GiST index can serve. "One-off" is
the load-bearing part of that sentence, and `MATERIALIZED` is what guarantees it: one buffer, one
value, computed before the table is touched.

Do not merge those two into one idea. The first is about how many times a string is parsed. The
second is about which comparison arrives at the table. Fixing either one alone would leave the
query slow for the other's reason.

And here is the property that makes this section worth a heading of its own: **none of this is
visible in the query's text.** The version with `MATERIALIZED` and the version without it look
almost identical, return identical rows, and differ by one word. There is no error, no warning, and
no test that fails. The only way to see the difference is to read the query plan — which is the next
section.

## How to tell

Put `EXPLAIN ANALYZE` in front of the statement. `EXPLAIN` alone reports what the planner *intends*
to do, with estimated costs. Adding `ANALYZE` actually executes the statement and reports real
timings and real row counts alongside the estimates, which is what you want, because a plan that
looks sensible and estimates badly is the usual failure. Both of the ride-check queries are
read-only, so running them is safe; for anything that writes, wrap it in a transaction and roll
back.

**You cannot copy the SQL out of the PHP file and paste it in.** That statement is assembled at
runtime. `:geom` and `:radius` are Doctrine DBAL placeholders, not values, and
`ItemState::servedSqlTuple()` (`web/src/Catalog/ItemState.php`) is PHP string interpolation that
expands to the tuple `('unverified', 'verified')`. You need the expanded statement — either from the
Doctrine panel of the Symfony profiler in the dev environment, which logs what was actually sent, or
by substituting a real GeoJSON `LineString` and a radius by hand.

Then run it against the dev stack's database:

```sh
docker compose -f developers/docker/compose.yaml exec db psql -U cc -d cyclingcommons
```

(`make sh c=db` opens a shell in the same container if you would rather get there that way.)

Now read the output. Do not try to memorise what a good plan looks like — plans vary with the data,
the version, and the statistics. Look for these four things instead.

**The scan node on the spatial table.** If you see `Seq Scan on item` and you know `idx_item_geom`
exists, something in the predicate stopped the index being usable. In practice it is almost always
one of the two things from earlier in this chapter: a cast on the indexed column, or a function
wrapping it. Check the `WHERE` clause for `::geography` and for `ST_`-something applied to the
column before you look anywhere else.

**The index actually being named.** A healthy plan mentions it: `Index Scan using idx_item_geom`, or
a `Bitmap Index Scan using idx_item_geom` feeding a `Bitmap Heap Scan`. Both mean the index was
used. Which one the planner chooses depends on how many rows it expects to get back, and neither is
a problem.

**`Rows Removed by Filter` on the node above the index scan.** That number is phase 2 doing its job:
candidates the box test let through and the exact geometry rejected — point 2 in figure F8. A small
number is healthy and expected. A large one means the boxes are poor stand-ins for the shapes they
represent. The classic case is a long diagonal line: a diagonal `LineString`'s bounding box is
mostly empty space, so it collects candidates it will then throw away.

**Whether the CTE is computed once.** With `MATERIALIZED` you should see the CTE as its own node,
executed once. Inlined, it will not appear as a node at all; the expression turns up inside the scan
that uses it, and it runs as often as that scan does.

One more warning, and it is the reason this chapter exists at all. **`EXPLAIN ANALYZE` against a
seeded development database tells you very little.** For a table of a few hundred rows the planner
will choose a sequential scan on purpose, because reading the whole table is genuinely cheaper than
descending a tree and then fetching rows one at a time. That choice is correct, and it means a
missing-index problem is completely invisible at that size. If you want to know whether a spatial
query will hold up, test it against a table with a realistic number of rows in it.

Finally, a caution against reading this chapter as a checklist. The `::geography` shape appears in
several other places in this repository — `SurfaceProfiler::profile()`
(`web/src/Catalog/SurfaceProfiler.php`), `BaseAreaResolver::resolve()`
(`web/src/Service/BaseAreaResolver.php`), and both arms of `CoverageRepository::nearby()`
(`web/src/Coverage/CoverageRepository.php`). That is not a list of bugs. Whether the shape matters
depends entirely on how many rows the *other* conditions leave for it: `BaseAreaResolver` filters
region outlines, of which there are not many, and the coverage queries carry region and country
filters with their own ordinary indexes. The rule is not "never cast a column". The rule is know
which comparison the index can serve, and measure the path you are actually changing.

## What to carry into chapter 6

- A **bounding box** is four numbers: the smallest upright rectangle containing a shape. It can
  prove two shapes cannot touch. It can never prove that they do.
- A **GiST index** stores those boxes in a tree, so the database can skip whole branches instead of
  rows. What it produces is a candidate set, not an answer.
- Every spatial query therefore has **two phases**: a cheap approximate filter from the index, then
  an exact recheck on the survivors. Both are real work, and only the second one is correct on its
  own.
- An index holds boxes for **the expression it was built on**. Ours are built on the bare `geom`
  column, so a cast or a function applied to that column puts the comparison beyond the index's
  reach.
- The ride-check fix was not cheaper maths. The maths is the same and the metres are still real.
  What changed is **which side of the comparison the cast sits on**, so the question that reaches
  the table is one the index can answer.
- `MATERIALIZED` is worth understanding for two separate reasons: it stops a CTE's body being
  re-evaluated, and it pins the corridor to a single value computed once.
- "It works on the seed data" is not evidence. Test the plan against realistic row counts, with
  `EXPLAIN ANALYZE`.

The fountain now has a position, a shape, a way to be asked about, and a way to be asked about
quickly. What it does not yet have is an origin. Everything so far has assumed the row already
exists in our database — but somebody mapped that fountain in OpenStreetMap, in a data model that
looks nothing like ours, and it had to get from there to here. Chapter 6
([`osm-to-database.md`](osm-to-database.md)) follows it the whole way.

<!-- EXERCISE-SLOT ch=5 — hands-on box goes here (spec D5); do not remove -->
