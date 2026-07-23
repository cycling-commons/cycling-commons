<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Tiles

Germany alone contributes **317,887** rows to `coverage_poi`
(`docs/specs/2026-07-22-country-onboarding-design.md`) — bike shops, water fountains, viewpoints,
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

<figure class="gis-fig gis-todo">
<p class="gis-todo-h">Figure F12 · not yet drawn</p>
<p><strong>Must make the reader see:</strong> that each zoom level is not "more tiles"; it is every
existing tile splitting into exactly four, so the count explodes multiplicatively (1 → 4 → 16 → …)
while each individual tile stays the same size in bytes and covers a quarter of the ground.</p>
<p><strong>Drawing brief:</strong> three stacked rows, one per zoom level, each row a grid of small
squares: row 1 one square labelled <code>z=0</code>; row 2 a 2×2 grid labelled <code>z=1</code>; row
3 a 4×4 grid labelled <code>z=2</code>. In each row, highlight one square in <code>gis-accent</code>
and draw thin <code>gis-muted</code> connector lines from that one highlighted square down to the
four squares in the next row's grid that came from subdividing it — the point is "this one tile
became these four", not just "there are now more squares". Do not attempt to draw z=6 through z=14 as
actual grids (4,096 squares does not fit); instead add a text annotation under the three drawn rows
along the lines of "and so on — <code>4^z</code> tiles at zoom <code>z</code>", then a second line
giving the real range this project uses, <code>z6 → z14</code> (4,096 tiles at the shallow end, about
268 million addressable at the deep end, of which this project builds only the ones with data in
them). Label each tile's address format once, e.g. write <code>z/x/y</code> next to the highlighted
tile in row 2 pointing at one specific square.</p>
<figcaption>Every zoom level splits every tile from the level above into four. The count grows as
4^z, but every individual tile stays small — which is the whole reason a browser can fetch one
instead of the world.</figcaption>
</figure>
<!-- FIGURE-TODO id=F12 ch=7 -->

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
the client-side filtering and clustering behaviour later in this chapter (and the whole of chapter 8)
possible at all.

## PMTiles

A tile pyramid sounds, so far, like it produces one small file per `z/x/y` address — and historically
that is exactly what it meant: a tile *server* that owns millions of tiny files (or database rows) and
answers `GET /6/32/21.pbf` one request at a time. That is a real piece of infrastructure to run,
scale, and keep alive.

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
`web/assets/map/map.js` — `maplibregl.addProtocol('pmtiles', new pmtiles.Protocol().tile)`, followed a
couple of lines later (`mintWaterDrops()` runs in between, `map.js:1089-1091`) by
`map.addSource('coverage', {type: 'vector', url: 'pmtiles://' + window.CC_COVERAGE_URL})`.
Nothing in that request path is a tile server: it is a `GET` against a static file, with `Range:`
headers doing the work a tile server used to do, served straight off the bucket (or through nginx's
range proxy, per the memory-noted `cache.example.net`-style infra this project can reuse).

Before any of that upload happens, `build_pmtiles()` never reads `coverage_poi` directly — tippecanoe
takes files, not a database connection. `export_geojsonl()` bridges the two, running one `COPY`
per `(letter, country)` pair straight off the table, and the `WHERE` clause is the whole story of
"one layer per country" made concrete:

<!-- CODE-FROM pipeline/coverage/tiles.py -->
```python
"COPY (SELECT jsonb_build_object("
"'type', 'Feature', "
...
"'geometry', ST_AsGeoJSON(geom)::jsonb, "
f"'properties', jsonb_strip_nulls(jsonb_build_object({', '.join(props)}))"
f")::text FROM coverage_poi "
f"WHERE letter = {_lit(letter)} AND COALESCE(country_code, 'ZZ') = {_lit(cc)}) TO STDOUT"
```

Every one of the `letter × country` files this function writes is its own `COPY`, filtered down to
exactly one letter and one country. tippecanoe never sees a query or a `WHERE` clause at all — by the
time it runs, the split has already happened one file at a time, which is what makes "tippecanoe
clusters within one layer" and "a layer is single-country by construction" the same fact seen from two
angles.

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

## Clustering

The pyramid, vector tiles and PMTiles together solve "get the right small piece of data to the
browser fast". They do not solve a different problem that shows up the moment you zoom out: even one
tile's worth of ground can hold more points than a screen can usefully show. Zoom out to see all of
Belgium and a single water-point layer might have to represent thousands of fountains sitting in a
patch of screen a few hundred pixels wide. Drawing every one of them as its own pin is not "cluttered"
— at that scale, dots simply stack on top of each other and stop being readable at all.

The naive fix — thin the points, i.e. just draw a random subset and drop the rest — is exactly what
this project tried first, and the result is recorded plainly in the comment above
`build_pmtiles()`: tippecanoe's *default* point-thinning behaviour at overview zooms let only 23 of
2,015 D-services (bike shops, repair stations, pumps) survive at zoom 8, while the on-screen count
still read "2015/2015" because that number comes from an honest SQL count
(`/map/coverage/counts`, coverage-provider.md §5), not from what the tile happened to keep. The map
looked nearly empty. The rail said everything was there. Both were telling the truth about different
things, and that mismatch is worse than either one being wrong on its own.

**Clustering is the fix, and it is a fundamentally different promise than thinning.** Instead of
discarding points to make room, `build_pmtiles()` merges nearby points into a single feature that
carries a **count** — `tippecanoe`'s injected `point_count` property. A "bubble" reading `48` on the
map is not one representative fountain standing in for forty-eight others that got thrown away; it is
a promise that all forty-eight are still there, accounted for, just drawn as one marker until you zoom
in far enough to tell them apart.

The actual flags, all in `pipeline/coverage/tiles.py::build_pmtiles`:

<!-- CODE-FROM pipeline/coverage/tiles.py -->
```python
"--minimum-zoom", "6", "--maximum-zoom", "14",
...
"--cluster-distance", "20",
...
"--accumulate-attribute", "ridtok:concat",
"--accumulate-attribute", "cctok:concat",
...
"--cluster-maxzoom", "11",
"-r1",
"--cluster-densest-as-needed",
```

- **`--cluster-distance 20`** — points within 20 *screen pixels* of each other, at a given zoom, are
  candidates to merge into one cluster feature. (It is screen pixels, not metres — at Belgium's
  latitude the same 20 px works out to roughly 7-8 kilometres of ground at zoom 8 and roughly a
  kilometre at zoom 11 (Web Mercator's ground distance per pixel halves every zoom level and also
  shrinks with latitude), which is why bubbles dissolve as you zoom in far faster than the underlying
  density of fountains actually changes; coverage-provider.md §4 walks through what a rider watching
  this actually sees.)
- **`--cluster-maxzoom 11`** — clustering only happens from zoom 6 up to zoom 11. Above that, every
  feature renders individually, with no `point_count` at all — not "the count counts down to one", the
  count simply stops existing at that point, because from zoom 12 on a rider zoomed into a town is
  meant to see the actual shops, not a bubble standing in for them.
- **`-r1`** — turn off tippecanoe's own point-dropping ("rate") behaviour entirely, so every single
  point survives into the tile-building process; clustering is then the *only* thing allowed to
  combine points, never silently discard them.
- **`--cluster-densest-as-needed`** — this is the flag that makes the "merges, does not drop" promise
  actually hold under pressure. Every tile has a hard byte-size budget; if a tile would still be too
  big even after ordinary clustering, this flag merges the *densest* remaining clusters further, again
  and again, until the tile fits. It never throws a point away to make room — it makes existing bubbles
  bigger and rarer instead. That is the distinction worth being precise about: **dropping** data and
  **merging** data are very different promises to make to a reader. Dropping means "some of what
  exists is not shown, and there is no number that says how much." Merging means "everything that
  exists is shown, just grouped, and the count on the bubble tells you exactly how much is in it."
  Nothing in this pipeline drops a coverage point once it has passed `osmium tags-filter`
  (chapter 6, `osm-to-database.md`); from there on it is only ever counted, never silently discarded.
- **`--accumulate-attribute ridtok:concat` / `cctok:concat`** — when tippecanoe merges several
  features into one cluster, it has to decide what the cluster's own properties are. Left alone it
  would just keep one arbitrary member's values. These two flags instead **union** the region-scope and
  country-scope tokens (`ridtok`/`cctok`, coverage-provider.md §4) across every member of the cluster,
  so a bubble's scope reflects everything inside it, not one lottery-picked member. This matters for
  the same reason the merge-not-drop promise matters: a scope filter that trusted one representative
  member could hide or show a whole bubble based on a coin flip about which member tippecanoe happened
  to keep a property from.

<figure class="gis-fig gis-todo">
<p class="gis-todo-h">Figure F14 · not yet drawn</p>
<p><strong>Must make the reader see:</strong> that the three small bubbles at low zoom and the
individual pins at high zoom represent the exact same underlying points — nothing appeared or
disappeared between the two panels, only the grouping changed, and the numbers on the bubbles are
the proof.</p>
<p><strong>Drawing brief:</strong> two panels, stacked (640 units is a large-type drawing at this
width per the site convention — a left/right pair would squeeze both below the legible label floor,
so stack top and bottom instead, each panel full width). Top panel labelled <code>z9</code>: draw a
loose cluster of three <code>gis-accent</code> filled circles of visibly different sizes — largest
first — each carrying its <code>point_count</code> as a centred label: <code>48</code>,
<code>12</code>, <code>7</code>. Bottom panel labelled <code>z12</code>: the same rough area, now
drawn as 48 + 12 + 7 = 67 small individual <code>gis-ink</code> pins with no count badges at all,
loosely arranged in three groups so a reader can see they occupy roughly where the three bubbles
sat above. A thin <code>gis-muted</code> connector or bracket from each top-panel bubble down to its
corresponding bottom-panel group would reinforce the one-to-one correspondence. Annotate the boundary
between the two panels with the zoom-11 cluster-maxzoom cutoff in a text label, e.g. "clustering
stops at z11 — z12 pins carry no count at all."</p>
<figcaption>The count on a bubble is a promise that nothing was thrown away: forty-eight water points
merge into one marker at zoom 9 and the same forty-eight resolve into forty-eight individual pins by
zoom 12. That is why the pipeline is built to merge rather than drop — a bubble's number has to stay
true at every zoom in between.</figcaption>
</figure>
<!-- FIGURE-TODO id=F14 ch=7 -->

## Why the layers are split per country

Every tile a tippecanoe run produces is organised into named **layers** — one named collection of
features living inside a tile, nothing to do with anything drawn on screen. Chapter 8
(`on-screen.md`) gives this exact idea its MapLibre name, `source-layer`; for now, just picture a
layer as a named group of features packed inside a tile. The obvious design is one layer per
catalogue letter (chapter 6,
`osm-to-database.md`, covers what the letters mean): `c` for water, `d` for services, and so on. That
is what this project shipped first, and it worked, right up until a second bordering country
(the Netherlands) was onboarded next to the first (Belgium).

The problem is not a performance problem. It is that **tippecanoe clusters within one layer**, with no
awareness of anything the data means — it only sees points and screen distances. A single `c` layer
straddling the Belgian-Dutch border let a low-zoom cluster merge fountains from both countries into
one bubble, whose `point_count` and map position then mixed the two, and whose unioned `ridtok`/
`cctok` scope tokens could make that mixed bubble match a scope filter it should not have. The measured
before-picture, recorded in `coverage-provider.md §4`: under a Netherlands-only scope, 42 clusters
rendered pure-NL, 31 mixed, and 0 pure-BE.

The fix in `build_pmtiles()` and `export_geojsonl()` (both in `pipeline/coverage/tiles.py`) is to give
tippecanoe one layer per **`(letter, country_code)`** pair instead of one per letter — `c_be`, `c_nl`,
and so on, lowercase, with unstamped rows bucketed under `<letter>_zz` so a POI that could not be
matched to a country is never silently dropped. Because tippecanoe only ever clusters *within* a
layer, and a layer is now single-country by construction, **no cluster can ever contain points from
two countries** — not because of a filter applied afterwards, but because the two countries' points
are never in the same layer to begin with.

That is worth pausing on, because it is a different *kind* of decision than everything else in this
chapter. Zoom ranges, cluster distances, `-r1`, the size-budget merge — all of those are performance
and rendering tuning. The per-country layer split is not: it exists because **a bubble straddling a
national border would misrepresent what the data means**, not because it would be slow or oversized.
Coverage per country is a real distinction a rider cares about — the region-scoping selector lets
someone view "just the Netherlands", and a bubble whose count and position blended two countries would
make that selector lie. Splitting the layers is a rendering choice driven by the meaning of the data,
not by its size.

## The whole path

Put the pieces in order, from the row in the database to the pixel the fountain becomes:

<figure class="gis-fig gis-todo">
<p class="gis-todo-h">Figure F13 · not yet drawn</p>
<p><strong>Must make the reader see:</strong> the single hinge point in this whole pipeline — that
everything up to and including the built <code>.pmtiles</code> file happens once, on a schedule,
regardless of who is looking at the map, while everything after it happens once per rider, per pan,
and touches no server process at all. That is *why* a restyle or a re-filter in the browser costs
nothing: the expensive part is already finished by the time a rider opens the map.</p>
<p><strong>Drawing brief:</strong> use the shared flowchart convention (site stylesheet point 8:
<code>.gis-box</code> rounded rectangles, the standard <code>gis-arrow</code> marker, same visual
language as figure F10 in chapter 6) — six boxes, left to right in concept:
<code>coverage_poi</code> → <code>GeoJSONL per (letter, country)</code> → <code>tippecanoe</code> →
<code>coverage.pmtiles</code> → <code>HTTP range request</code> → <code>MapLibre</code>. At 640
units wide with labels at the gis-label-sm floor, six boxes in one row will not fit legibly (point 4
of the stylesheet convention: a 640-unit figure is large-type, roughly 50 characters per line) — wrap
to two rows of three, left to right on each row, with a short down-and-across return arrow from the
end of row one to the start of row two (the same wrapping shape as a text paragraph, not a zig-zag).
Row one: <code>coverage_poi</code>, <code>GeoJSONL per (letter, country)</code>,
<code>tippecanoe</code>. Row two: <code>coverage.pmtiles</code>, <code>HTTP range request</code>,
<code>MapLibre</code>. Use <code>gis-label-mono</code> for the two filename-shaped labels
(<code>coverage_poi</code> and <code>coverage.pmtiles</code>) and ordinary <code>gis-label-sm</code>
prose labels for the rest. Draw a dashed <code>gis-muted</code> vertical divider on row two, between box 4
(<code>coverage.pmtiles</code>) and box 5 (<code>HTTP range request</code>) — the boundary between the
last box that runs at build time and the first that runs at request time. Label the left side of that
divider "build time — weekly, shared by every rider" and the right side "request time — every pan,
every rider". That divider is the figure's whole point; make it visually louder
than the ordinary box-to-box arrows (e.g. a labelled bracket above the divider spanning the box on
each side of it), because it is the reason a restyle or a client-side filter costs nothing: everything
left of it already happened before any rider opened the map.</p>
<figcaption>Everything left of the dashed line runs once, on the weekly batch, regardless of who is
looking. Everything right of it runs per rider, per pan, and never touches a server process — a
range read against a static file. That boundary is why filtering, restyling and hit-testing in the
browser (chapter 8) are free: the expensive work already happened before the map was ever opened.</figcaption>
</figure>
<!-- FIGURE-TODO id=F13 ch=7 -->

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
- **Clustering merges, it does not drop.** `--cluster-densest-as-needed` grows bubbles to fit a tile's
  size budget instead of discarding points; the count on a bubble is a promise that everything it
  represents is still there.
- The **per-country layer split** (`<letter>_<cc>`) exists so a cluster can never straddle a national
  border — a decision driven by what the data *means*, not by how big or slow it is.
- Build time and request time are cleanly separated by the moment `coverage.pmtiles` is written:
  everything before that line runs once a week; everything after it runs per rider, per pan, with no
  server process in the path at all.

The fountain is now sitting inside a tile, addressed, clustered or not depending on the zoom, waiting
to be fetched. Chapter 8 (`on-screen.md`) is where it actually appears: MapLibre's model of style,
source, layer and `source-layer`, and how a rider's click turns a pixel back into the same row this
chapter started from.

<!-- EXERCISE-SLOT ch=7 — hands-on box goes here (spec D5); do not remove -->
