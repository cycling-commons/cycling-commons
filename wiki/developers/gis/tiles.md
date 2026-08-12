<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Tiles

Germany alone contributes **317,887** rows to `coverage_poi` — bike shops, water fountains, viewpoints,
shelters, ruins, one country's worth of the fountain's neighbours. Add Belgium and the Netherlands
and the table holds 375,078 rows today (chapter 5, [`making-it-fast.md`](making-it-fast.md)), and the
planet-wide target for the same table is about 4.7 million (the sizing comment above `_TABLE_DDL` in
`pipeline/coverage/load.py`, reconciled against `osm-data-architecture.md §5`'s taginfo breakdown).

You cannot send that to a browser. Not "should not" — a rider's phone opening the map cannot download
four million rows, a JavaScript map layer cannot usefully draw four million markers even if it had
them, and nobody could read a map covered edge to edge with a third of a million pins stacked on top
of each other in every town. Chapter 6 (`osm-to-database.md`) gets the fountain into a table row that
answers questions fast. This chapter is about the completely different problem of turning a table
that big into something a browser can actually fetch and draw — and it is where the fountain finally
becomes a pixel.

The answer has three separate ideas in it, and they solve three separate problems: a way to cut the
world into small, cacheable pieces (the tile pyramid), a way to hand the browser real shapes instead
of a picture (the vector tile), and a way to ship millions of those pieces as one file instead of
millions of small ones (PMTiles). None of the three is optional; drop any one and the other two stop
being enough.

## The pyramid

Start with the simplest possible version of "cut the world into pieces": one picture of the whole
planet. Call that **zoom level 0**. It is one square image (Web Mercator makes the world square,
chapter 1, [`coordinates.md`](coordinates.md)) covering everything, at a resolution too coarse to
show anything but continents.

Zoom in one level, to **zoom level 1**, and that single square splits into **four** — a 2×2 grid,
each quarter covering one quadrant of the world at twice the resolution. Zoom in again, to level 2,
and *each of those four* splits into four more: sixteen tiles total, each covering a sixteenth of the
world. Every zoom level quarters every tile from the level above it. That gives a clean rule for how
many tiles exist at zoom level *z*:

<!-- CODE-ILLUSTRATIVE formula, hand-written -->
```text
number of tiles at zoom z = 4^z
```

`z=0` is 1 tile. `z=6` — the lowest zoom this project's coverage tiles are built at — is `4^6 =
4,096` tiles. `z=14`, the highest, is `4^14 = 268,435,456` — about 268 *million* tiles for the whole
world. Nobody builds all 268 million of them; a tile only gets built where a tile-cutting tool finds
features to put in it, which is exactly why the pyramid works: the *addressing scheme* covers the
whole planet at every zoom, but the *actual files* only exist wherever there is data.

That addressing scheme has a name: every tile is identified by three numbers, written **`z/x/y`**.
`z` is the zoom level just described. `x` and `y` are the tile's column and row inside that level's
grid, counting from 0 at the top-left. A slippy map — chapter 1's name for the pan-and-zoom web map
this project uses — never asks for "the whole world"; it works out which `z/x/y` tiles cover the
current view and asks for exactly those.

This project's coverage layer is built across `--minimum-zoom 6` to `--maximum-zoom 14`
(`pipeline/coverage/tiles.py::build_pmtiles`) — the region-scoping "fit the map to Belgium" view lands
around z7, so z6 is the lowest zoom coverage needs to still be visible at an overview, and z14 is
close enough to street level that individual pins are the right thing to draw.

<figure class="gis-fig"><svg viewBox="0 0 640 820" role="img" aria-labelledby="f12-t f12-d" xmlns="http://www.w3.org/2000/svg"><title id="f12-t">The tile pyramid: one tile at zoom 0, four at zoom 1, sixteen at zoom 2</title><desc id="f12-d">Three stacked rows of squares, every square drawn the same size, so the growth is visible as more ground covered rather than smaller pieces. The top row, labelled z equals 0, is a single highlighted tile: 1 tile. Two lines fan out from its bottom edge, marked times 4, down to the second row, labelled z equals 1: a 2 by 2 grid of four tiles, all four tinted because all four are that one tile subdivided. One of them, the top-right, is outlined and an arrow labels it 1 slash 1 slash 0, that is z slash x slash y. Two more lines fan from that one tile, again marked times 4, down to the third row, labelled z equals 2: a 4 by 4 grid of sixteen tiles, in which the 2 by 2 block of four that came from the highlighted tile above is tinted, and one square inside that block is outlined in turn. Below the three rows: and so on, 4 to the power z tiles at zoom z; at z6, 4 to the 6 equals 4,096; at z14, 4 to the 14 equals 268,435,456; that is the range this project builds, and only the tiles with data in them are actually written.</desc><defs><marker id="gis-arrow-f12" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs><rect class="gis-accent gis-fill-accent" fill-opacity=".22" x="222" y="56" width="56" height="56"/><text x="20" y="78">z = 0</text><text class="gis-label-sm" x="390" y="92">1 tile</text><line class="gis-muted" x1="222" y1="112" x2="194" y2="186"/><line class="gis-muted" x1="278" y1="112" x2="306" y2="186"/><text class="gis-label-mono gis-halo" x="250" y="158" text-anchor="middle">× 4</text><text class="gis-label-sm" x="390" y="158">each tile → four</text><rect class="gis-fill-accent" fill-opacity=".22" x="194" y="186" width="112" height="112"/><rect class="gis-ink" x="194" y="186" width="112" height="112"/><line class="gis-ink" x1="250" y1="186" x2="250" y2="298"/><line class="gis-ink" x1="194" y1="242" x2="306" y2="242"/><rect class="gis-accent" x="250" y="186" width="56" height="56"/><text x="20" y="236">z = 1</text><line class="gis-ink" x1="352" y1="214" x2="316" y2="214" marker-end="url(#gis-arrow-f12)"/><text class="gis-label-mono" x="362" y="206">1/1/0</text><text class="gis-label-sm" x="362" y="238">z / x / y</text><text class="gis-label-sm" x="390" y="288">4 tiles</text><line class="gis-muted" x1="250" y1="242" x2="250" y2="372"/><line class="gis-muted" x1="306" y1="242" x2="362" y2="372"/><text class="gis-label-mono gis-halo" x="292" y="344" text-anchor="middle">× 4</text><text class="gis-label-sm" x="30" y="344">and again</text><rect class="gis-fill-accent" fill-opacity=".22" x="250" y="372" width="112" height="112"/><rect class="gis-ink" x="138" y="372" width="224" height="224"/><line class="gis-ink" x1="194" y1="372" x2="194" y2="596"/><line class="gis-ink" x1="138" y1="428" x2="362" y2="428"/><line class="gis-ink" x1="250" y1="372" x2="250" y2="596"/><line class="gis-ink" x1="138" y1="484" x2="362" y2="484"/><line class="gis-ink" x1="306" y1="372" x2="306" y2="596"/><line class="gis-ink" x1="138" y1="540" x2="362" y2="540"/><rect class="gis-accent" x="306" y="372" width="56" height="56"/><text x="20" y="490">z = 2</text><text class="gis-label-sm" x="390" y="490">16 tiles</text><text x="20" y="648">and so on: 4^z tiles at zoom z</text><text class="gis-label-mono" x="20" y="692">z6   4^6  = 4,096</text><text class="gis-label-mono" x="20" y="724">z14  4^14 = 268,435,456</text><text class="gis-label-sm" x="20" y="768">the range this project builds — and</text><text class="gis-label-sm" x="20" y="798">only the tiles with data in them.</text></svg><figcaption>Every zoom level splits every tile from the level above into four. The count grows as
4^z, but every individual tile stays small — which is the whole reason a browser can fetch one
instead of the world.</figcaption></figure>

## Why tiles work

The pyramid is only useful because of three things that fall out of it almost for free.

**The viewport only ever needs a handful of tiles.** A rider looking at one town on their screen is
looking at a few square kilometres, which at any reasonable zoom is a small, fixed number of `z/x/y`
tiles — not the whole table, and not even the whole country. Panning the map swaps a few tiles at the
edge for a few new ones; it does not re-fetch everything.

**Each tile is small.** A tile only has to hold what happens to sit in that one small square of the
world at that one zoom level, so no single request is ever large, however big the underlying table
gets. Doubling the size of `coverage_poi` does not double the size of any one tile a browser fetches
— it mostly just fills in tiles that used to be empty, or adds a few more features to already-small
ones.

**Tiles are identical for every user, so they cache perfectly.** A tile's contents depend only on its
`z/x/y` address and the data behind it — not on who is asking, what they searched for, or what time
it is. The exact same `6/32/21.pmtiles` bytes serve every rider who ever looks at that patch of
Belgium, so a cache — a CDN edge, a browser's own disk cache, an intermediate proxy — only ever has to
fetch and store it once and can then answer every later request itself. Contrast that with `/map/
coverage/search?q=` (coverage-provider.md §5), which depends on `q` and is cached for a much shorter
`max-age` for exactly that reason: tiles are the same for everyone, search results are not.

## Raster vs vector

Tiles come in two fundamentally different flavours, and the difference is about *what the tile
actually contains*.

A **raster tile** is a picture — a PNG or JPEG, typically 256×256 or 512×512 pixels, already drawn.
Whoever built the tile decided the colours, the labels, the icons, everything, at build time. The
browser's job is just to place the image and nothing else. That is how classic map tiles (OpenStreetMap's
own `tile.openstreetmap.org`, or any traditional web map) have always worked, and it is simple, but it
means every visual choice is frozen into the pixels: want a different colour for one category, or to
hide one layer, or to know what a particular pixel represents? You cannot — you would have to fetch a
completely different picture.

A **vector tile** contains the *geometry* and its *properties* instead of a picture: "there is a point
at this location, and its properties are `{ref: "node/61146471", t: "Drinking water"}`" rather than a
pre-rendered dot. The format this project uses is **MVT** — Mapbox Vector Tile, a small, widely-adopted
binary encoding for exactly this — and the browser decides how to draw it, at the moment it draws it.
That single difference is what lets the client:

- **restyle** without refetching — recolour every water point, or swap the whole map's colour scheme,
  by changing paint rules in JavaScript, with the same tile bytes already sitting in memory;
- **filter** without refetching — hide everything outside the rider's chosen region by testing a
  property already inside the tile (`ridtok`/`cctok`, coverage-provider.md §4), rather than asking the
  server for a different set of pixels;
- **hit-test** without refetching — answer "what did the rider just click on?" by looking up which
  feature's geometry is under the cursor, because the geometry is *there*, in the tile, not baked into
  colour. Chapter 8 (`on-screen.md`) covers `queryRenderedFeatures`, the MapLibre call that does this.

We serve vector. Every coverage feature this project draws — the fountain included — arrives at the
browser as geometry plus a handful of flat properties, never as a picture, which is exactly what makes
the client-side filtering and rendering choices covered later in this chapter (and the whole of
chapter 8) possible at all.

## PMTiles

So far, a tile pyramid sounds like it produces one small file per `z/x/y` address — and historically
that is exactly what it meant: a tile *server* that owns millions of tiny files (or database rows) and
answers `GET /6/32/21.pbf` one request at a time. That is a real piece of infrastructure to run,
scale, and keep alive.

!!! note "Two different files both called `.pbf`"
    The `.pbf` in `6/32/21.pbf` is **not** the same file as the Geofabrik `country.osm.pbf` you
    downloaded in chapter 6. The three letters collide because both are **Protocol Buffers** —
    Google's binary serialization format, `.pbf` — but they carry completely different things:

    - **`.osm.pbf`** (Geofabrik, chapter 6) is a *source-data dump*: the raw OpenStreetMap database
      for a whole country — every node, way, and relation with its full tags. It is not tiled and
      not styled; it is the input the pipeline reads to build `coverage_poi` rows.
    - **`.pbf`** here (a tile) is *one rendered tile*: the MVT geometry for a single `z/x/y` square,
      ready for the browser to draw. It is an output, cut and packed long after the `.osm.pbf` was
      parsed away.

    Same encoding, opposite ends of the pipeline. One country's `.osm.pbf` goes in; many tile `.pbf`s
    (bundled into the one `.pmtiles` file below) come out.

**PMTiles** is a file format that packs an entire tile pyramid — every zoom, every `x`, every `y` —
into **one single file**, together with an index of where each tile's bytes live inside it. The client
does not download the whole file to read one tile. It reads the index (a small, fixed-location chunk
at a known offset), works out the byte range the tile it wants occupies, and issues an HTTP **range
request** — a request that asks a server for bytes 4,102,558 through 4,109,884 of a file, not the
whole thing (the `Range:` HTTP header, which any ordinary static file host understands). One PMTiles
archive, many range reads, no tile-serving process at all.

This project's coverage layer is exactly that: `build_pmtiles()` in `pipeline/coverage/tiles.py`
writes one `.pmtiles` file, `pipeline/coverage/publish.py` uploads it to the `cc-maps` object storage
bucket under a versioned key (`coverage/<YYYYMMDD-HHMM>.pmtiles`, coverage-provider.md §3 step 8), and
the browser talks to it through the `pmtiles://` protocol handler registered in
`web/assets/map/coverage.js` — `maplibregl.addProtocol('pmtiles', new pmtiles.Protocol().tile)`, followed a
couple of lines later (`mintWaterDrops()`, which lives in `icons.js`, runs in between) by
`map.addSource('coverage', {type: 'vector', url: 'pmtiles://' + window.CC_COVERAGE_URL})`.
Nothing in that request path is a tile server: it is a `GET` against a static file, with `Range:`
headers doing the work a tile server used to do, served straight off the bucket (or through an
nginx range proxy).

Before any of that upload happens, `build_pmtiles()` never reads `coverage_poi` directly — tippecanoe
takes files, not a database connection. `export_geojsonl()` bridges the two, running one `COPY`
per letter straight off the table — the country bucket rides along as the first column and the rows
come back ordered by it, so the function can route each row into the right per-country file as it
streams:

<!-- CODE-FROM pipeline/coverage/tiles.py -->
```python
"COPY (SELECT COALESCE(country_code, 'ZZ'), jsonb_build_object("
"'type', 'Feature', "
...
"'geometry', ST_AsGeoJSON(geom)::jsonb, "
f"'properties', jsonb_strip_nulls(jsonb_build_object({', '.join(props)}))"
f")::text FROM coverage_poi "
f"WHERE letter = {_lit(letter)} "
f"ORDER BY COALESCE(country_code, 'ZZ')) TO STDOUT"
```

One `COPY` per letter, not one per `letter × country`: an earlier version scanned the whole table once
for *every* (letter, country) pair — up to twenty-eight passes — which on the shared production database
evicted the working set from the page cache on every run. Emitting the country as a column and ordering
by it collapses that to one scan per letter, and the per-country split still happens before tippecanoe:
each row is appended to its own `<letter>_<cc>.geojsonl` file as it arrives. tippecanoe never sees a
mixed file — by the time it runs, the split has already happened one row at a time, which is what makes
a tile layer single-country **by construction**, not by a filter applied afterwards: the other
country's points were never in the file tippecanoe read to build that layer. "Why the layers are split
per country" below is why that still matters even with nothing left to cluster.

`build_pmtiles()` runs, and then one more step happens before any of it reaches a rider: a sanity gate
that refuses to publish a broken archive. `verify_pmtiles()` in the same module opens the freshly built
file with `pmtiles show` and asserts the basics a corrupt or empty build would fail:

<!-- CODE-FROM pipeline/coverage/tiles.py -->
```python
m = re.search(r"addressed tiles(?: count)?:\s*(\d+)", show)
if not m or int(m.group(1)) == 0:
    raise RuntimeError(f"pmtiles verify: no addressed tiles in {path}\n{show}")
...
missing = expected - layers
if missing:
    raise RuntimeError(
        f"pmtiles verify: missing layer(s) {sorted(missing)} in {path} (found {sorted(layers)})")
```

Zero addressed tiles, or a layer this run was supposed to produce simply missing, both raise before
the file ever reaches `publish.py`'s upload step. The same function goes on to decode one real tile
inside the header's bounds, so "the index says tiles exist" and "a tile actually decodes to something"
are both checked, not just the first one.

That buys something concrete: **the whole coverage layer, for the whole world eventually, is one
artifact you can host anywhere a static file can be hosted** — no database, no application server, no
process to keep alive, on the read path. A new build is one new file at a new versioned key; readers
mid-pan keep reading the bytes they already started reading, because nothing at the old key ever
changes (coverage-provider.md §3 step 8). The manifest (`coverage/manifest.json`, `max-age=300`) is
the one small, frequently-refetched pointer that says which versioned key is current; the tiles
themselves are cached forever (`max-age=31536000, immutable`), because a versioned key's bytes are
defined never to change.

## No clustering — individual points and a heatmap

The pyramid, vector tiles and PMTiles together solve "get the right small piece of data to the
browser fast". They do not solve a different problem that shows up the moment you zoom out: even one
tile's worth of ground can hold more points than a screen can usefully show. Zoom out to see all of
Belgium and a single water-point layer might have to represent thousands of fountains sitting in a
patch of screen a few hundred pixels wide. Drawing every one of them as its own pin is not "cluttered"
— at that scale, dots simply stack on top of each other and stop being readable at all.

This project has tried two different fixes for that before landing on the one it ships today, and both
earlier attempts are worth knowing because each one taught a real lesson. The first was the naive one:
let tippecanoe's own default point-thinning behaviour keep a random subset at low zoom and drop the
rest. It was tried, and the result is recorded in the project's design history: default thinning let only 23 of 2,015 D-services (bike shops,
repair stations, pumps) survive at zoom 8, while the on-screen count still read "2015/2015" because
that number comes from an honest SQL count (`/map/coverage/counts`, coverage-provider.md §5), not from
what the tile happened to keep. The map looked nearly empty. The rail said everything was there. Both
were telling the truth about different things, and that mismatch is worse than either one being wrong
on its own.

The second fix was clustering. Instead of discarding points to make room, tippecanoe merged nearby
points into a single feature carrying a **count** — the injected `point_count` property. A bubble
reading `48` on the map was not one representative fountain standing in for forty-seven others that got
thrown away; it was a promise that all forty-eight were still there, accounted for, just drawn as one
marker until you zoomed in far enough to tell them apart. That promise held, and it is a genuinely
honest fix to the thinning problem above — but clustering turned out to carry two flaws of its own,
neither of them a tuning mistake:

- **Phantom bubbles.** A cluster renders at the *centroid* of its members. A cluster straddling a
  region border sits at the centroid of whichever points tippecanoe happened to merge — which can land
  **outside** the scoped region altogether. Investigation on 2026-07-24 found this directly: a shelter
  bubble rendered in Thuringia carried a single Hesse `ridtok`. No tile attribute could fix it — union tokens,
  leader tokens, a tighter `--cluster-distance`, bigger tile budgets were all tried, and none reached
  zero phantoms, because the bug was never in which token a cluster carried; it was in *where the
  geometry itself was drawn*.
- **It does not scale.** "Ship a region's points to the client and cluster them" is bounded for a
  Belgian province or a German Land (Bavaria, on the order of tens of thousands of coverage points),
  but it breaks the moment a region the size of a US state or a Chinese province is onboarded whole —
  California is roughly 200,000-400,000 points, Guangdong 500,000 to over 2 million. Clustering that many points was never going to be
  the shape of a worldwide coverage layer.

Both problems are artifacts of clustering itself, not of anything about the underlying data, so the fix
is to stop clustering. **Coverage tiles carry individual points only, at zoom 6 through 14, and nothing
is ever merged.** A single point carries exactly one `ridtok`/`cctok` token pair — its own region and
country — so the scope filter (coverage-provider.md §4) is exact for that one point, at any zoom, in
any country, with no cross-feature union to get wrong and no rendered position that is anything other
than the point's own coordinate. There is no `point_count` property anywhere in the coverage tiles any
more, and there is not meant to be one again.

Dropping clustering does not make the original overview problem disappear — a rider still lands on a
region at roughly z7-z9 (the scope selector's own fit zoom), and thousands of individual points still
cannot be drawn as pins at that scale. The fix this time is not to pretend the overview problem is
gone; it is to stop asking individual pins to solve it and give the overview a different kind of
picture instead: a **density heatmap**. `build_pmtiles()` still builds one continuous pyramid from z6
to z14, and the *same* two tippecanoe behaviours from the naive-thinning attempt come back — `-r1` and
`--drop-densest-as-needed` — but this time with an honest job to do. z11-14 tiles are **complete**: a
z11 tile is small enough that `--drop-densest-as-needed` never actually fires there, so every point in
a z11-14 tile is really present, and those are the tiles the client draws as individual **icons**. z6-10
tiles are **thinned** — `--drop-densest-as-needed` drops the densest overflow, proportionally, wherever
a whole-region tile would exceed the tile's byte budget — but nothing built from a z6-10 tile ever
claims to be a complete list of points again. It feeds a heatmap instead: a smooth, density surface
built from the thinned sample, answering "where is coverage dense" rather than "here is every
fountain". A thinned sample is exactly what a density surface needs (relative density survives even
heavy thinning, because the drop is proportional across the tile) and exactly what a pin list must
never be handed — the same distinction the "23 of 2015" story above was already teaching, just applied
correctly this time instead of ignored.

On screen (chapter 8, `on-screen.md`, covers MapLibre's side of this in full) the client mirrors every
coverage icon layer with a heatmap layer on the same source-layer: a `<letter>-<cc>-heat` layer with
`maxzoom: 9`, and the existing `<letter>-<cc>-cov` icon layer with `minzoom: 9`. Below z9 a rider sees
the heatmap only — one single hue, semi-transparent, every visible letter's density stacking into
one "how much coverage is here" surface, scope-filtered on the same exact per-point `ridtok`/`cctok`
tokens as the icons, so the surface is phantom-free for the same reason the icons are: it is built only
from points already inside the scoped region, never from anything aggregated across a border. From z9
up the individual icons fade in — the z9-10 icons are drawn from the same thinned tiles the heatmap
uses, so they are a sample too, but they densify into the complete set by z11, where every point is
guaranteed present. The heatmap and the icons cross-fade across that z9-10 handoff, so a rider is never
looking at a gap between "blur" and "dots"
. The rail's `/map/coverage/counts`
(coverage-provider.md §5) stays the one thing in this whole picture that is never a sample: an exact
SQL count, unaffected by what any tile happened to keep — exactly the number that made the
naive-thinning attempt's lie visible in the first place.

The actual flags, all in `pipeline/coverage/tiles.py::build_pmtiles`:

<!-- CODE-FROM pipeline/coverage/tiles.py -->
```python
"--minimum-zoom", "6", "--maximum-zoom", "14",
...
"-r1",
"--drop-densest-as-needed",
```

- **`--minimum-zoom 6` / `--maximum-zoom 14`** — the same full pyramid this project has always built
  coverage at; what changed is what happens inside that range, not the range itself.
- **`-r1`** — turn off tippecanoe's own point-dropping ("rate") behaviour entirely. The only thing left
  that can ever remove a point from a tile is the next flag, and it is asked to, explicitly, rather
  than happening as an unannounced default.
- **`--drop-densest-as-needed`** — every tile has a hard byte-size budget; if a tile would still be too
  big, this flag drops the densest overflowing points, proportionally, until it fits. At z11-14 a tile
  is small enough that the budget is never actually hit, so this flag is a safety valve there in
  theory, not something that fires in practice — the icons stay complete. At z6-10 a whole-region tile
  genuinely does not fit the budget, so this flag fires for real there and produces exactly the thinned
  density sample the heatmap is built from.

<figure class="gis-fig gis-todo">
<p class="gis-todo-h">Figure F14 · to be redrawn</p>
<p><strong>Must make the reader see:</strong> that overview coverage is a smooth density surface built
from a thinned sample of points, never discrete dots and never a count on a bubble; that it hands off
to individual icons at z9, where those z9-10 icons are still drawn from the same thinned sample and
only become the complete set of points at z11 and up; and that the heatmap and the icons are
scope-filtered on the very same exact per-point tokens, so neither one can ever show anything outside
the scoped region — there is no cluster, no centroid and no merged count left anywhere in the
picture.</p>
<p><strong>Drawing brief:</strong> three panels, stacked top to bottom (640 units is a large-type
drawing at this width per the site convention, so stack rather than lay panels side by side). Top
panel labelled <code>z6-8</code>: a soft, single-hue <code>gis-accent</code> blurred surface (a radial
gradient or several overlapping soft-edged blobs, denser toward the middle) filling most of a region
outline, with a visibly feathered edge that fades to nothing at the outline's border and nothing drawn
outside it — no discrete dots, no numbers. Middle panel labelled <code>z9-10</code>: the same region,
the heatmap fading (lower opacity) while a scattered, visibly sparse set of small
<code>gis-ink</code> dots appears over it, concentrated where the heatmap was hottest — label this
panel "thinned sample" so a reader does not mistake the sparseness for the true density. Bottom panel
labelled <code>z11+</code>: the heatmap gone entirely, the same area now filled with a visibly denser,
complete set of small dots (several times as many as the middle panel) covering the same hot area plus
the quieter surrounding ground the middle panel's sample missed. Annotate the boundary between the top
and middle panels "heat maxzoom 9 / icon minzoom 9 — cross-fade" and annotate the boundary between the
middle and bottom panels "thinned sample densifies to complete by z11". A thin <code>gis-muted</code>
connector or bracket linking the sparse dots in the middle panel to their denser counterpart in the
bottom panel would reinforce that it is the same underlying point set becoming visible, not new data
appearing from nowhere.</p>
<figcaption>Below z9 coverage reads as a density heatmap built from a thinned sample of points, never
as a count or a cluster; from z9 the same sample starts appearing as individual icons, and by z11 every
point in the tile is present. Nothing is ever merged and nothing is ever positioned anywhere but its
own coordinate — the phantom-bubble class of bug has no surface left to occur on.</figcaption>
</figure>
<!-- FIGURE-TODO id=F14 ch=7 -->

## Lines, and when to ship no geometry at all

Everything so far has been points. The road-surface layer is **lines** — every way OSM has a
`surface` tag for — and the pyramid handles them the same way, with one difference in the profile:
lines are never *dropped* to make a tile fit. A dropped point at low zoom is a thinner sample; a
dropped line is a road that vanishes from the map. So the surface build simplifies **geometry**
instead — a way loses vertices at low zoom, never its existence.

The more interesting case is the layer's other half: the roads nobody has recorded a surface for.
Drawn as lines, that is hundreds of thousands of features per country, and at z8 a national road
network is an unreadable smear whichever way you style it. But look at the question a rider is
actually asking down there. It is not *"is this lane gravel?"* — you cannot even see the lane. It is
*"which part of the map has nobody surveyed?"*

That question has a far cheaper answer: one square per ~6 km carrying how many kilometres inside it
are unrecorded. For the Benelux that is **0.6 MB against 34.8 MB** of the same information as
lines — about 1.5 % — and it reads better, because a choropleth is what a "where" question wants.
The lines then take over at z11, where a rider is looking at a road they could actually go and ride.

<figure class="gis-fig"><svg viewBox="0 0 640 448" role="img" aria-labelledby="sf2-t sf2-d" xmlns="http://www.w3.org/2000/svg">
<title id="sf2-t">The same question answered as squares at country zoom and as roads close in</title>
<desc id="sf2-d">Two stacked panels of the same piece of country. The upper panel, labelled zoom 9, is covered by a grid of eighteen rectangles, each tinted to a different strength: pale where almost everything has been recorded, strong where almost nothing has. No roads are drawn at all, and it is annotated 0.6 megabytes for the Benelux. An arrow points down from it to the lower panel, labelled at zoom 11 the grid hands over. The lower panel, labelled zoom 12, shows the same area with individual roads instead: three dashed lines are tracks and lanes with no recorded surface, and two plain lines are roads somebody has already recorded. It is annotated 34.8 megabytes for the Benelux. The point is that the upper panel answers where is there work and the lower answers which road, and each is the cheaper way to answer its own question.</desc>
<defs><marker id="gis-arrow-fS2" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs>
<text class="gis-label-sm" x="20" y="30">z9 · one square per ~6 km</text>
<rect class="gis-fill-accent" x="20" y="40" width="100" height="50" fill-opacity="0.10"/><rect class="gis-fill-accent" x="120" y="40" width="100" height="50" fill-opacity="0.18"/><rect class="gis-fill-accent" x="220" y="40" width="100" height="50" fill-opacity="0.40"/><rect class="gis-fill-accent" x="320" y="40" width="100" height="50" fill-opacity="0.55"/><rect class="gis-fill-accent" x="420" y="40" width="100" height="50" fill-opacity="0.30"/><rect class="gis-fill-accent" x="520" y="40" width="100" height="50" fill-opacity="0.12"/><rect class="gis-fill-accent" x="20" y="90" width="100" height="50" fill-opacity="0.16"/><rect class="gis-fill-accent" x="120" y="90" width="100" height="50" fill-opacity="0.34"/><rect class="gis-fill-accent" x="220" y="90" width="100" height="50" fill-opacity="0.62"/><rect class="gis-fill-accent" x="320" y="90" width="100" height="50" fill-opacity="0.70"/><rect class="gis-fill-accent" x="420" y="90" width="100" height="50" fill-opacity="0.44"/><rect class="gis-fill-accent" x="520" y="90" width="100" height="50" fill-opacity="0.20"/><rect class="gis-fill-accent" x="20" y="140" width="100" height="50" fill-opacity="0.08"/><rect class="gis-fill-accent" x="120" y="140" width="100" height="50" fill-opacity="0.22"/><rect class="gis-fill-accent" x="220" y="140" width="100" height="50" fill-opacity="0.38"/><rect class="gis-fill-accent" x="320" y="140" width="100" height="50" fill-opacity="0.30"/><rect class="gis-fill-accent" x="420" y="140" width="100" height="50" fill-opacity="0.18"/><rect class="gis-fill-accent" x="520" y="140" width="100" height="50" fill-opacity="0.10"/><line class="gis-muted" x1="120" y1="40" x2="120" y2="190"/><line class="gis-muted" x1="220" y1="40" x2="220" y2="190"/><line class="gis-muted" x1="320" y1="40" x2="320" y2="190"/><line class="gis-muted" x1="420" y1="40" x2="420" y2="190"/><line class="gis-muted" x1="520" y1="40" x2="520" y2="190"/><line class="gis-muted" x1="20" y1="90" x2="620" y2="90"/><line class="gis-muted" x1="20" y1="140" x2="620" y2="140"/>
<rect class="gis-ink" x="20" y="40" width="600" height="150" fill="none"/>
<text class="gis-label-mono gis-halo" x="610" y="180" text-anchor="end">0.6 MB</text>
<line class="gis-ink" x1="320" y1="204" x2="320" y2="242" marker-end="url(#gis-arrow-fS2)"/>
<text class="gis-label-sm" x="336" y="228">at z11 the grid hands over</text>
<text class="gis-label-sm" x="20" y="272">z12 · the roads themselves</text>
<rect class="gis-ink gis-fill-paper" x="20" y="282" width="600" height="128"/>
<path class="gis-accent" d="M 40 392 C 140 366 210 330 330 322 S 520 300 610 286" stroke-dasharray="7 6"/><path class="gis-accent" d="M 90 296 C 170 322 220 360 300 388" stroke-dasharray="7 6"/><path class="gis-accent" d="M 350 400 C 420 372 470 356 560 352" stroke-dasharray="7 6"/><path class="gis-muted" d="M 20 340 C 160 336 300 348 420 330 S 560 306 620 316"/><path class="gis-muted" d="M 470 402 C 486 366 500 330 508 286"/>
<rect class="gis-ink" x="20" y="282" width="600" height="128" fill="none"/>
<text class="gis-label-mono gis-halo" x="610" y="400" text-anchor="end">34.8 MB</text>
<text class="gis-label-sm" x="20" y="436">dashed · nobody has recorded it</text><text class="gis-label-sm" x="380" y="436">plain · already recorded</text>
</svg>
<figcaption>One legend row, two resolutions. At country zoom a rider is not asking "is <em>this</em> lane gravel?" — they cannot see the lane — they are asking <strong>which part of the map has nobody surveyed</strong>, and a grid answers that in <strong>0.6&nbsp;MB</strong> where the same information as lines costs <strong>34.8&nbsp;MB</strong>. Zoom past z11 and the squares hand over to the roads they were summarising, which is where "which road?" becomes a question you can act on. The handover zoom is one number held in the shared contract and asserted on both sides, because a mismatch would leave a band of zoom showing neither.</figcaption></figure>

That is the general lesson, and it is the mirror image of the no-clustering rule above. There, the
question was *"where exactly is this fountain?"*, and aggregating would have answered a question
nobody asked. Here the question is *"where is there work?"*, and shipping the geometry would answer
it at a hundred times the cost. **Match the resolution of the answer to the question being asked at
that zoom** — sometimes that means every feature at its own coordinate, and sometimes it means
counting.

[Building road-surface tiles](../data-ops/surface-tiles.md) is the runbook for both halves.

## Why the layers are split per country

Every tile a tippecanoe run produces is organised into named **layers** — one named collection of
features living inside a tile, nothing to do with anything drawn on screen. Chapter 8
(`on-screen.md`) gives this exact idea its MapLibre name, `source-layer`; for now, just picture a
layer as a named group of features packed inside a tile. The obvious design is one layer per
catalogue letter (chapter 6,
`osm-to-database.md`, covers what the letters mean): `c` for water, `d` for services, and so on. That
is what this project shipped first, and it worked, right up until a second bordering country
(the Netherlands) was onboarded next to the first (Belgium).

The problem that first forced the split was a clustering problem, from back when this project still
clustered coverage at low zoom (the section above covers why clustering itself is gone now). Tippecanoe
clusters *within* one layer, with no awareness of anything the data means — it only sees points and
screen distances. A single `c` layer straddling the Belgian-Dutch border let a low-zoom cluster merge
fountains from both countries into one bubble, whose `point_count` and map position then mixed the
two, and whose unioned `ridtok`/`cctok` scope tokens could make that mixed bubble match a scope filter
it should not have. The measured before-picture, recorded in `coverage-provider.md §4`: under a
Netherlands-only scope, 42 clusters rendered pure-NL, 31 mixed, and 0 pure-BE. The fix in
`build_pmtiles()` and `export_geojsonl()` (both in `pipeline/coverage/tiles.py`) was to give tippecanoe
one layer per **`(letter, country_code)`** pair instead of one per letter — `c_be`, `c_nl`, and so on,
lowercase, with unstamped rows bucketed under `<letter>_zz` so a POI that could not be matched to a
country is never silently dropped.

The split outlived the reason it was built for. Clustering is gone, and with it went the only mechanism
that could ever mix two countries' points into one feature — an individual point carries exactly one
`ridtok`/`cctok` pair of its own, so the scope filter (coverage-provider.md §4) is exact per point no
matter which layer it sits in. **The per-point token, not the layer boundary, is now the actual
phantom-free guarantee.** So why does the split still exist? Two live reasons, neither about clustering
any more:

- **The heatmap needs it.** Chapter 8 (`on-screen.md`) covers the client side in full, but the shape
  matters here: the density heatmap is built by mirroring each `<letter>_<cc>` layer with its own
  `<letter>-<cc>-heat` MapLibre layer, so "how dense is coverage in the Netherlands" and "how dense is
  coverage in Belgium" are two surfaces the client can toggle and scope independently, rather than one
  blended surface it would have to un-mix after the fact.
- **A tile stays single-country by construction.** Each `(letter, country)` GeoJSONL file
  `export_geojsonl()` writes is filled from a single per-letter `COPY` ordered by country and split
  row by row, so tippecanoe never sees a mixed file to begin with. That keeps "just the Netherlands" a
  real, checkable property of the tiles themselves, not something a filter has to reconstruct at render
  time.

That is worth pausing on, because it is a different *kind* of decision than everything else in this
chapter. Zoom ranges, `-r1`, the size-budget thinning — all of those are performance and rendering
tuning. The per-country layer split is not: it exists because coverage per country is a real
distinction a rider cares about — the region-scoping selector lets someone view "just the Netherlands"
— not because a mixed layer would be slow or oversized. Splitting the layers is a rendering choice
driven by the meaning of the data, not by its size; that it also happened to fix a clustering bug which
no longer exists was a bonus, never the reason it stays.

## The whole path

Put the pieces in order, from the row in the database to the pixel the fountain becomes:

<figure class="gis-fig"><svg viewBox="0 0 640 546" role="img" aria-labelledby="f13-t f13-d" xmlns="http://www.w3.org/2000/svg"><title id="f13-t">From the coverage_poi table to MapLibre, and where build time ends</title><desc id="f13-d">A flowchart of six rounded boxes, wrapped over two rows like a paragraph of text. The first row runs left to right: coverage_poi, then GeoJSONL per letter and country, then tippecanoe. An arrow leaves the bottom of tippecanoe, runs back across to the left and down into the second row. The second row runs left to right: coverage.pmtiles, then HTTP range request, then MapLibre. A dashed vertical line stands on the second row between coverage.pmtiles and HTTP range request, and the arrow joining those two boxes crosses it. A bracket above the line spans coverage.pmtiles and is labelled build time; a second bracket spans HTTP range request and MapLibre and is labelled request time. Everything left of the dashed line runs once a week and is shared by every rider; everything right of it runs on every pan, for every rider, with no server process in the path.</desc><defs><marker id="gis-arrow-f13" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs><rect class="gis-box" rx="8" x="12" y="96" width="202" height="84"/><text class="gis-label-mono" x="113" y="146" text-anchor="middle">coverage_poi</text><line class="gis-ink" x1="214" y1="138" x2="246" y2="138" marker-end="url(#gis-arrow-f13)"/><rect class="gis-box" rx="8" x="246" y="96" width="196" height="84"/><text class="gis-label-sm" x="344" y="132" text-anchor="middle">GeoJSONL per</text><text class="gis-label-sm" x="344" y="162" text-anchor="middle">(letter, country)</text><line class="gis-ink" x1="442" y1="138" x2="474" y2="138" marker-end="url(#gis-arrow-f13)"/><rect class="gis-box" rx="8" x="474" y="96" width="154" height="84"/><text class="gis-label-sm" x="551" y="146" text-anchor="middle">tippecanoe</text><path class="gis-ink" fill="none" d="M 551 180 V 222 H 141 V 300" marker-end="url(#gis-arrow-f13)"/><line class="gis-muted" stroke-dasharray="6 6" x1="292" y1="250" x2="292" y2="416"/><path class="gis-clay" fill="none" d="M 12 390 V 402 H 270 V 390"/><path class="gis-clay" fill="none" d="M 314 390 V 402 H 628 V 390"/><text class="gis-label-sm" x="141" y="434" text-anchor="middle">build time</text><text class="gis-label-sm" x="471" y="434" text-anchor="middle">request time</text><rect class="gis-box" rx="8" x="12" y="300" width="258" height="84"/><text class="gis-label-mono" x="141" y="350" text-anchor="middle">coverage.pmtiles</text><line class="gis-ink" x1="270" y1="342" x2="314" y2="342" marker-end="url(#gis-arrow-f13)"/><rect class="gis-box" rx="8" x="314" y="300" width="154" height="84"/><text class="gis-label-sm" x="391" y="336" text-anchor="middle">HTTP range</text><text class="gis-label-sm" x="391" y="366" text-anchor="middle">request</text><line class="gis-ink" x1="468" y1="342" x2="498" y2="342" marker-end="url(#gis-arrow-f13)"/><rect class="gis-box" rx="8" x="498" y="300" width="130" height="84"/><text class="gis-label-sm" x="563" y="350" text-anchor="middle">MapLibre</text><text class="gis-label-sm" x="20" y="482">left of the line — once a week, shared</text><text class="gis-label-sm" x="20" y="512">right — every pan, every rider, no server</text></svg><figcaption>Everything left of the dashed line runs once, on the weekly batch, regardless of who is
looking. Everything right of it runs per rider, per pan, and never touches a server process — a
range read against a static file. That boundary is why filtering, restyling and hit-testing in the
browser (chapter 8) are free: the expensive work already happened before the map was ever opened.</figcaption></figure>

Reading the six stops in order: `coverage_poi` (chapter 6) is the full index, one row per point.
`export_geojsonl()` splits it into one newline-delimited GeoJSON file per `(letter, country)` pair.
`tippecanoe` — a third-party tile cutter this project shells out to, never reimplemented — reads those
files, projects them from EPSG:4326 to Web Mercator, and cuts the pyramid: this is genuinely the one
place this project's own data leaves 4326 (chapter 1, [`coordinates.md`](coordinates.md)), and it
happens inside that third-party tool, on the way out, to a copy — never inside our own code, and never
to the stored rows. `build_pmtiles()` calls it and gets one `coverage.pmtiles` archive back.
`publish.py` uploads that archive under a versioned key. From there, a rider's browser issues an
**HTTP range request** for exactly the `z/x/y` bytes it needs, and **MapLibre** — the map library
chapter 8 covers in full — decodes the MVT bytes into geometry it can paint, filter and hit-test.

## What to carry into chapter 8

- A **tile pyramid** addresses the world at `z/x/y`: zoom 0 is one tile, and every zoom level
  quarters every tile from the level before, so zoom *z* has `4^z` possible tiles — only the ones
  with data in them actually get built.
- Tiles work because a viewport only needs a few of them, each one is small regardless of how big the
  underlying table is, and the same bytes serve every rider, so they cache perfectly.
- A **vector tile** (MVT) ships geometry and properties, not a picture, so the browser can restyle,
  filter and hit-test without a new request — a raster tile is a finished picture with none of that
  freedom.
- **PMTiles** packs an entire pyramid into one file with an index, and the client reads it with HTTP
  **range requests** — no tile server on the request path, ever.
- **No clustering, ever.** Coverage tiles carry individual points only, z6-14. Clustering's own two
  flaws — the phantom bubble (a cluster's rendered centroid landing outside the region its members
  scope to) and a poor fit for world-scale data — are why. `-r1` plus `--drop-densest-as-needed` still
  thin the z6-10 tiles, but only to build a density *sample*; z11-14 tiles are always complete, and
  nothing is ever merged into a count.
- The **overview is a heatmap, not dots.** A single-hue density surface built from the thinned z6-10
  points fills the gap clustering used to fill, cross-fading into individual icons from z9 up
  (complete by z11) — without a rendered centroid that can ever leave the scoped region. The rail's
  `/counts` stays the one exact number in the picture.
- The **per-country layer split** (`<letter>_<cc>`) still exists — now to keep the heatmap
  single-country and the tiles themselves single-country by construction, not to stop a cluster from
  crossing a border, since nothing clusters any more.
- Build time and request time are cleanly separated by the moment `coverage.pmtiles` is written:
  everything before that line runs once a week; everything after it runs per rider, per pan, with no
  server process in the path at all.

The fountain is now sitting inside a tile, addressed, waiting to be fetched — as its own point at every
zoom from z11 up, and as one contributor to the density heatmap wherever its tile got thinned below
that. Chapter 8 (`on-screen.md`) is where it actually appears: MapLibre's model of style, source, layer
and `source-layer`, and how a rider's click turns a pixel back into the same row this chapter started
from.

## Try it

!!! tip "Hands-on — see the layer list before tippecanoe ever runs"
    "Why the layers are split per country" is easiest to believe by counting the rows behind each
    `(letter, country_code)` pair directly — that grouping is exactly what `export_geojsonl()` turns
    into one file per pair, and what `build_pmtiles()` hands tippecanoe as one `-L` layer per pair.

    <!-- CODE-ILLUSTRATIVE shell command against the dev stack's Postgres -->
    ```sh
    docker compose -f developers/docker/compose.yaml exec db psql -U cc -d cyclingcommons -c "
    SELECT letter, coalesce(country_code,'ZZ') AS country_code, count(*)
    FROM coverage_poi GROUP BY letter, country_code ORDER BY letter, country_code;
    "
    ```

    <!-- CODE-ILLUSTRATIVE sample output on a stack seeded by `make course-data` -->
    ```text
     letter | country_code | count
    --------+--------------+-------
     C      | ZZ           |     1
     D      | ZZ           |     2
     E      | ZZ           |     2
     G      | ZZ           |     1
     H      | ZZ           |     1
     I      | ZZ           |     1
     J      | ZZ           |     2
    (7 rows)
    ```

    Seven letters, seven groups, seven layers — `c_zz`, `d_zz`, `e_zz` and so on. `ZZ` is not a
    country: it is what `coalesce` substitutes when `country_code` is `NULL`, and the offline fixture
    leaves it `NULL` on purpose. `country_code` is stamped from the region a row falls inside, and
    `make course-data` loads no region boundaries (those come from a separate, network-bound
    download), so no row gets stamped. `export_geojsonl()` buckets those rows under `ZZ` rather than
    dropping them — an unstamped point still has to reach the map.

    That is the mechanism, at the smallest size it is visible. The *point* of the mechanism only
    shows once more than one country is loaded:

    ??? note "The same query on a three-country index, and how to get one"
        `make coverage-refresh` (chapter 5 covers what it costs — network, a Geofabrik download)
        loads real extracts, and the region boundaries stamp `country_code` for real. On a machine
        that has run it for Belgium, the Netherlands and Germany, the identical query returns this:

        <!-- CODE-ILLUSTRATIVE sample output captured on a 375,078-row three-country coverage index; the counts are that machine's, the 7 × 3 shape is not -->
        ```text
         letter | country_code | count
        --------+--------------+--------
         C      | BE           |   1369
         C      | DE           |  15894
         C      | NL           |   3699
         D      | BE           |   2013
         D      | DE           |  13224
         D      | NL           |   3039
         E      | BE           |   6454
         E      | DE           |  59090
         E      | NL           |  14916
         G      | BE           |    720
         G      | DE           |   8709
         G      | NL           |    624
         H      | BE           |   1023
         H      | DE           |  23341
         H      | NL           |    595
         I      | BE           |   2501
         I      | DE           |  75359
         I      | NL           |   2582
         J      | BE           |   8815
         J      | DE           | 122076
         J      | NL           |   9035
        (21 rows)
        ```

        Seven letters times three onboarded countries is exactly 21 rows — no row mixes two countries
        under one letter, because `country_code` is a column on the table, not something tippecanoe
        infers. Add the counts and they land on 375,078, the same total this chapter opened with.

    Either way, this table *is* the reason the tile layers are named `c_be`, `c_de`, `c_nl`, `d_be`
    and so on rather than just `c`, `d`, `e`: each row above becomes exactly one
    `(letter, country)` GeoJSONL file, and a tile layer built from one file can never mix two
    countries, because the other country's points were never in that file to begin with. If your dev
    stack has published a `.pmtiles` archive, `pmtiles show <path-or-url>` lists those same names back
    to you as `vector_layers` — but the query above needs nothing built, only the seeded database this
    course already assumes.
