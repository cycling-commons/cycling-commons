<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Putting it on screen

The fountain's tile has just arrived in the browser. `tiles.md` covered everything that happened to
get it there: a `coverage_poi` row became a feature inside a `.pmtiles` archive, and a range request
pulled out the one tile that covers our fountain's corner of Wallonia. What the browser now holds is
not a picture. It is geometry — a point, with properties attached, sitting in memory.

None of that geometry draws itself. Something still has to decide that this particular point should
look like a small blue drop, roughly this big, fading in above the roads but below the pins. This
chapter is that "something": MapLibre GL JS, the library this project's map runs on, and the three
words it uses to describe what to draw and how.

## Style, source, layer

MapLibre needs three kinds of instruction, and it uses three ordinary English words to name them —
narrowly, in a way this reader has not met before. Get these three words straight and almost
everything else in `map.js` reads as a combination of them.

A **style** is the whole document describing what the map draws: every source, every layer, the
background colour, all of it, together. This project does not hand-write one. Look near the top of
`web/assets/map/map.js`, at the `new maplibregl.Map({...})` call that boots the whole thing:

<!-- CODE-FROM web/assets/map/map.js -->
```js
const map = new maplibregl.Map({
  container:'map', style:'https://tiles.openfreemap.org/styles/liberty',
```

That URL *is* the starting style — a ready-made document served by OpenFreeMap, containing the
roads, place names and land colours you see under everything else. `map.js` never ships a second
style document of its own. Once that one finishes loading, it calls functions — `addSource()`,
`addLayer()` — that reach into the already-loaded style and add to it at runtime. Every pin,
every coverage icon, every route line you will read about below is one of those additions, made in
JavaScript, to a style that started life as somebody else's file. (This is also why so much of the
map's code is written to run only after that document has finished loading — `addSource`/`addLayer`
throw if you call them before the style is ready, which is why `map.js` gates its own rendering on
the map's `load` event; see `map-and-search.md` §2's `_styleReady` rule if you want the detail.)

A **source** is where data comes from. Naming a source does not draw anything by itself — it just
tells MapLibre "here is a pool of geometry you can read from," and gives it an id to be read from.

A **layer** is one drawing instruction that reads one source. It says which source, which subset of
that source's features, and how to turn each feature into pixels — what colour, what icon, how big.

Here is the idea worth slowing down for, because it is the one that makes the rest of this chapter
(and a good deal of `map.js`) unsurprising: **one source can feed many layers.** A source is just
data sitting there under a name; nothing stops five different layers from reading the same source
and drawing five different things from it. The coverage tiles are the clearest example in this
codebase. `addCoverage()` in `map.js` adds exactly one vector source:

<!-- CODE-FROM web/assets/map/map.js -->
```js
map.addSource('coverage',{type:'vector', url:'pmtiles://'+window.CC_COVERAGE_URL});
```

and then, still inside the same function, adds one icon layer and one cluster-bubble layer for
every catalogue letter and every country — all of them with `source:'coverage'`. One `.pmtiles`
archive, fetched once, feeds every one of those pin and cluster-bubble layers. Restyle any one of
them — change a colour, swap an icon — and nothing is re-fetched. The source does not change; only
the instruction reading it does.

<figure class="gis-fig">
<svg viewBox="0 0 640 940" role="img" aria-labelledby="f15-t f15-d" xmlns="http://www.w3.org/2000/svg"><title id="f15-t">One MapLibre source feeding several layers, and what one layer is made of</title><desc id="f15-d">A vertical flow. At the top a box reads: one tile archive, coverage.pmtiles. One arrow leads down from it into a second box: one source, source colon "coverage". From that single source box the flow fans out across a horizontal bar into three separate layer boxes standing side by side: pins, whose source-layer is c_be; clusters, on the same c_be; and pins on d_be. Each of the three layers then sends its own arrow down into one shared rendered-map panel, where water pins, a cluster bubble reading 12 and service pins all appear together on the same map. Below, tied to the first layer box by a dashed leader, a detail panel opens that one layer up: source-layer c_be, and its properties split into a paint group, holding icon-opacity, the only paint it sets, and a layout group, holding visibility and icon-image. Nothing about the layer boxes or the detail panel reaches back up into the source box.</desc><defs><marker id="gis-arrow-f15" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path class="gis-fill-accent" d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs><rect class="gis-box" rx="8" x="180" y="24" width="280" height="80"/><text class="gis-label-sm" x="320" y="58" text-anchor="middle">one tile archive</text><text class="gis-label-mono" x="320" y="90" text-anchor="middle">coverage.pmtiles</text><line class="gis-accent" x1="320" y1="106" x2="320" y2="142" marker-end="url(#gis-arrow-f15)"/><rect class="gis-box" rx="8" x="166" y="144" width="308" height="80"/><text class="gis-label-sm" x="320" y="178" text-anchor="middle">one source</text><text class="gis-label-mono" x="320" y="210" text-anchor="middle">source: "coverage"</text><line class="gis-accent" x1="320" y1="226" x2="320" y2="262"/><line class="gis-accent" x1="112" y1="262" x2="528" y2="262"/><line class="gis-accent" x1="112" y1="262" x2="112" y2="300" marker-end="url(#gis-arrow-f15)"/><line class="gis-accent" x1="320" y1="262" x2="320" y2="300" marker-end="url(#gis-arrow-f15)"/><line class="gis-accent" x1="528" y1="262" x2="528" y2="300" marker-end="url(#gis-arrow-f15)"/><rect class="gis-box" rx="8" x="21" y="300" width="182" height="118"/><text x="112" y="338" text-anchor="middle">pins</text><text class="gis-label-sm" x="112" y="370" text-anchor="middle">source-layer</text><text class="gis-label-mono" x="112" y="400" text-anchor="middle">c_be</text><rect class="gis-box" rx="8" x="229" y="300" width="182" height="118"/><text x="320" y="352" text-anchor="middle">clusters</text><text class="gis-label-mono" x="320" y="384" text-anchor="middle">c_be</text><rect class="gis-box" rx="8" x="437" y="300" width="182" height="118"/><text x="528" y="352" text-anchor="middle">pins</text><text class="gis-label-mono" x="528" y="384" text-anchor="middle">d_be</text><line class="gis-accent" x1="112" y1="420" x2="112" y2="458" marker-end="url(#gis-arrow-f15)"/><line class="gis-accent" x1="320" y1="420" x2="320" y2="458" marker-end="url(#gis-arrow-f15)"/><line class="gis-accent" x1="528" y1="420" x2="528" y2="458" marker-end="url(#gis-arrow-f15)"/><rect class="gis-box" rx="8" x="21" y="460" width="598" height="210"/><text class="gis-label-sm" x="41" y="492">rendered map</text><rect class="gis-fill-glacier" fill-opacity=".22" x="41" y="506" width="558" height="144"/><path class="gis-muted" d="M 41 592 C 140 570, 220 616, 320 596 S 500 560, 599 588"/><path class="gis-muted" d="M 232 506 L 262 566 L 246 650"/><circle class="gis-ink gis-fill-accent" cx="95" cy="606" r="9"/><circle class="gis-ink gis-fill-accent" cx="150" cy="548" r="9"/><text class="gis-label-sm gis-halo" x="168" y="542">Spa</text><circle class="gis-ink gis-fill-ochre" cx="320" cy="560" r="26"/><text class="gis-label-sm gis-halo" x="320" y="569" text-anchor="middle">12</text><circle class="gis-ink gis-fill-clay" cx="505" cy="602" r="9"/><circle class="gis-ink gis-fill-clay" cx="556" cy="546" r="9"/><path class="gis-muted" stroke-dasharray="4 5" d="M 21 372 L 8 372 L 8 760 L 21 760"/><rect class="gis-box" rx="8" x="21" y="706" width="598" height="210"/><text class="gis-label-sm" x="41" y="742">inside the pins layer</text><text class="gis-label-mono" x="41" y="778">source-layer: c_be</text><line class="gis-muted" x1="21" y1="796" x2="619" y2="796"/><line class="gis-muted" x1="330" y1="796" x2="330" y2="916"/><text class="gis-label-mono" x="41" y="830">paint</text><text class="gis-label-sm" x="125" y="830">appearance</text><text class="gis-label-sm" x="41" y="864">icon-opacity</text><text class="gis-label-sm" x="41" y="894">the only paint it sets</text><text class="gis-label-mono" x="350" y="830">layout</text><text class="gis-label-sm" x="448" y="830">placement</text><text class="gis-label-sm" x="350" y="864">visibility</text><text class="gis-label-sm" x="350" y="894">icon-image</text></svg>
<figcaption>One source, many layers — the reason a restyle needs no refetch.</figcaption>
</figure>

## `source-layer`

A vector tile is not one flat bag of features. `tiles.md` showed tippecanoe building the coverage
tiles per catalogue letter and per country, so that a symbol never clusters across a border it
shouldn't. Inside a single `.pmtiles` archive, those splits survive as **named layers packed inside
each tile** — several independent feature collections, sitting in the same file, each with its own
name.

That means a MapLibre layer reading a vector source cannot just say "read the `coverage` source." It
has to say which of the named layers *inside* that source's tiles it means. That second answer is
the `source-layer` property, and it exists only for vector sources — a GeoJSON or raster source
carries just one collection, so there is nothing to disambiguate.

`addCoverage()` builds that name from the same per-`(letter, country)` split chapter 7 described:

<!-- CODE-FROM web/assets/map/map.js -->
```js
const srcLayer = cc ? letter+'_'+cc : letter;
...
map.addLayer({id, type:'symbol', source:'coverage', 'source-layer':srcLayer,
```

`letter` is the lowercase catalogue letter (`c` for water, `d` for bike services, and so on) and `cc`
is a lowercase country code, so our fountain's icon layer reads the `c_be` source-layer inside the
`coverage` source. Rows with no country recorded live in a `zz` bucket, and `cc === null` is a
fallback for a tile archive built before this split existed, reading the plain `c` source-layer
instead. A `(letter, country)` pair with nothing in it simply renders nothing — there is no special
case for an empty source-layer, it is just an empty layer.

This is also where the "one source, many layers" idea from the previous section gets a second
dimension. The very same `source` and the very same `source-layer` — `coverage` and `c_be` — feed
*two* layers in `addCoverage()`: an icon layer for the unclustered features and a cluster-bubble
layer for the clustered ones. What tells them apart is not the source, and not even the
source-layer — it is each layer's `filter`, narrowing the *same* underlying features down to the
ones that layer is allowed to draw. One pool of data, sliced twice.

## The source types we use

`map.js` uses four kinds of source, and each one exists for a different reason.

**`vector` over a `pmtiles://` URL** is the coverage source you just read about —
`addCoverage()`'s `map.addSource('coverage', {type:'vector', url:'pmtiles://'+window.CC_COVERAGE_URL})`.
Tiled, pre-built, fetched a piece at a time.

**`geojson`** is a source given a plain GeoJSON object directly, no tiling. The region mask and
boundary use it: `drawSpotlightMask()` in `map.js` builds a `region-mask` polygon (the world with a
hole cut where the scoped region is) and a `region` polygon (the region's own outline), and adds
both as `geojson` sources feeding a dimming fill layer and a dashed line layer.

<!-- CODE-FROM web/assets/map/map.js -->
```js
map.addSource('region-mask',{type:'geojson',data:mask});
map.addSource('region',{type:'geojson',data:{type:'Feature',geometry:g}});
```

Notice what is missing compared to the coverage source above: no `url`, no tile archive, no
`source-layer` to disambiguate — `data` is the geometry itself, computed in JavaScript a moment
earlier and handed over whole. That is the entire difference the "tiled versus not" distinction from
the previous section comes down to in real code.

**`raster`** is a source of pre-rendered image tiles — actual pictures, not geometry. The optional
satellite base uses it: `addSatellite()` points a `raster` source at Esri World Imagery's tile URLs.
There is no geometry to read here at all, only images to place in a grid; this is the one source
type this chapter's later sections on paint, layout and clicking do not really apply to.

<!-- CODE-FROM web/assets/map/map.js -->
```js
map.addSource('satellite',{type:'raster',tileSize:256,
  tiles:['https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'],
```

Compare that to the `geojson` source just above: no `data`, no features, just a `tiles` URL template
and a `tileSize`. MapLibre never parses a shape out of this source at all — it just requests
whichever `{z}/{y}/{x}` image square the viewport needs and places it, which is exactly why `raster`
sits outside the paint/layout/click-testing story the rest of this chapter tells.

**A clustered `geojson` source** is the fourth kind, and it is worth pausing on because it looks like
the coverage source but works nothing like it. `setupConfClusters()` builds one of these per bulk-OSM
pool, for the confirmed (rider-verified) points:

<!-- CODE-FROM web/assets/map/map.js -->
```js
map.addSource(srcId,{type:'geojson', cluster:true, clusterRadius:48, clusterMaxZoom:13,
```

`cluster:true` tells MapLibre itself to group nearby points into bubbles, live, in the browser, as
you pan and zoom — no pre-built tile archive involved.

**Build-time versus browser-time clustering, and why both exist.** `tiles.md` clusters the coverage
source *before* it ever reaches the browser: tippecanoe groups nearby points into cluster features
while building the `.pmtiles` archive, because that source is enormous (this project's `coverage_poi`
table already holds a few hundred thousand rows worldwide, headed toward several million) and mostly
static — it changes when the pipeline re-runs, not every time a rider looks at the map. Clustering it
once, offline, and shipping the result is the only version of that job that scales.

The confirmed-points source above clusters the opposite way, live in the browser, because it is the
opposite kind of data: small (a rider-confirmed pool, nowhere near coverage's size) and constantly
changing (a new confirmation can land at any moment). Baking a tile archive for a pool that size
would be wasted effort, and rebuilding it on every confirmation would be absurd. So MapLibre's own
`cluster:true` option does the grouping instead, recomputing it for whatever is currently in view.

Same word — **cluster** — two entirely different mechanisms, each chosen for the size and volatility
of the data it serves. Neither is "the right way" in general; each is right for what it clusters.

## Paint vs layout

Every layer's drawing instructions split into two groups, and the split is not cosmetic — it is
about what changing a property costs MapLibre to redo.

**`paint`** is appearance: colour, opacity, blur, line width — properties that never move a feature
or change whether it's drawn, only how it looks once it's already placed. Paint changes are cheap:
MapLibre can repaint the same already-positioned pixels without recomputing where anything goes.

**`layout`** is placement and participation: whether a feature draws at all (`visibility`), which
icon or text it uses, how big, in what order features that might overlap get to win. Layout changes
are more expensive, because MapLibre may have to redo the placement and collision work — deciding
which icons fit without overlapping — that paint changes never touch.

`syncCoverageLayers()` in `map.js` uses both, back to back, on the very same layer, and the split
tells you exactly what each line is doing:

<!-- CODE-FROM web/assets/map/map.js -->
```js
map.setLayoutProperty(id,'visibility', show?'visible':'none');
map.setPaintProperty(id,'icon-opacity', dim);
```

The first line is a layout change: it turns the whole layer on or off — a feature that isn't visible
doesn't participate in placement at all. The second is a paint change: the layer stays fully on and
fully placed, just rendered at 55% opacity when Curated mode wants a utility icon to recede without
disappearing. One function, one layer, one line of each kind, doing two genuinely different jobs.

## Data-driven styling

A paint or layout property does not have to be a fixed value. It can be an **expression** — a small,
nested-array formula that MapLibre evaluates separately for every feature, reading that feature's own
properties out of the tile. This is how one layer draws many different-looking things instead of
needing one layer per variant.

`addCoverage()`'s water icon is exactly that. Instead of one drop icon for every water point, it
reads each feature's own `potable` property and picks between two icons:

<!-- CODE-FROM web/assets/map/map.js -->
```js
['match',['to-string',['get','potable']],['yes','true','1'],'water-drop','water-drop-unk']
```

Read it like an if/else: get the feature's `potable` value, treat it as a string, and if that string
is `'yes'`, `'true'` or `'1'`, use the `water-drop` icon; otherwise fall back to `water-drop-unk` (an
unknown-potability variant). One layer, one `icon-image` line, and every one of the thousands of
water points in the source draws its own correct icon. The D · bike-services layer does the same
trick on a `kind` property, picking between a shop, station and pump glyph:

<!-- CODE-FROM web/assets/map/map.js -->
```js
['match',['get','kind'],
    'shop', miniIcon('services'),
    'station', miniIcon('services', SERVICE_GLYPH.station, 'station'),
    'pump', miniIcon('services', SERVICE_GLYPH.pump, 'pump'),
    miniIcon('services')]
```

Same shape as the water example — `match` on a tile property, one arm per value, a trailing
catch-all — just with three named values instead of a yes/no split. One `addLayer()` call still draws
shops, stations and pumps as three visually distinct glyphs, because the branching lives in the
expression, not in three separate layers.

## Clicking things

A vector source hands the browser real geometry, not a picture — that is the whole trade `tiles.md`
made when it chose vector tiles over raster ones. This is the section where that trade actually pays
off: because the browser already has the shapes, answering "what did the rider just click on?" never
needs to ask a server. It can be answered by asking the map itself.

`queryRenderedFeatures()` is that question, asked locally: give it a small box in screen pixels and a
list of layer ids, and it hands back whichever already-rendered features from those layers fall
inside the box — read straight out of what MapLibre has already drawn, with no network round trip.
`nearestImageId()` in `map.js`, which finds the Mapillary image dot nearest a click, shows the pattern
at its plainest — a box centred on the click point, widened in three steps until something is found:

<!-- CODE-FROM web/assets/map/map.js -->
```js
for(const r of [8,16,30]){
  const fs=map.queryRenderedFeatures([[point.x-r,point.y-r],[point.x+r,point.y+r]],{layers:['mly-img']});
```

The same function backs the map's general click handler, deciding whether a click landed on *any*
selectable feature at all before falling through to other click behaviour — `map.js` calls it
`selectableLayers()`, and the click handler is
`map.queryRenderedFeatures(e.point, {layers:selectableLayers()})`.

That is the concrete thing vector tiles bought this project: hit-testing is a local lookup against
geometry the browser is already holding, not a request to a server asking what's under a pixel. A
raster tile is a picture with no features to query at all — a map built on raster tiles would have no
way to answer "what's here?" except by asking somewhere else.

## What to carry forward

- **Style, source, layer.** A style is the whole document; a source is data under a name; a layer is
  one drawing instruction reading one source. One source can feed many layers, which is why a
  restyle never needs a refetch.
- **`source-layer`** picks a named collection out of a vector source's tiles — the per-`(letter,
  country)` split `tiles.md` builds is exactly what that name encodes.
- Four source types, four different jobs: `vector`/`pmtiles://` for the huge static coverage pool,
  `geojson` for small one-off shapes like the region mask, `raster` for pre-rendered image tiles, and
  a clustered `geojson` source for the small, constantly-changing confirmed-points pool.
- **Clustering happens twice, for opposite reasons**: once at build time (huge, static data) and once
  live in the browser (small, changing data).
- **`paint`** is cheap appearance; **`layout`** is placement and participation, and costs more to
  change.
- A paint or layout property can be an **expression**, reading a feature's own properties — one layer
  styling many categories.
- **`queryRenderedFeatures`** answers "what's under this click?" from geometry already in the
  browser — the concrete payoff of vector tiles over raster ones.

## Try it

!!! tip "Hands-on — connect a `source-layer` name to real rows"
    `addCoverage()` reads one `coverage` source and slices it by `source-layer` — `c_be` for
    Belgian water points, and so on. Fetch the running map page to see the URL that source actually
    points at, then ask the database how many rows feed the `c_be` slice of it.

    <!-- CODE-ILLUSTRATIVE shell command against the dev stack's web app and Postgres -->
    ```sh
    curl -s http://localhost:8001/map | grep -o 'CC_COVERAGE_URL[^;]*;'
    docker compose -f developers/docker/compose.yaml exec db psql -U cc -d cyclingcommons -c "
    SELECT count(*) AS c_be_rows FROM coverage_poi WHERE letter='C' AND country_code='BE';
    "
    ```

    <!-- CODE-ILLUSTRATIVE sample output from the dev stack -->
    ```text
    CC_COVERAGE_URL = "http:\/\/localhost:9100\/cc-maps\/coverage\/20260722-2112.pmtiles";
     c_be_rows
    -----------
          1369
    (1 row)
    ```

    The first line is the exact `pmtiles://` URL `map.js` hands to `maplibregl.addProtocol` for the
    `coverage` source — the versioned key will differ on your machine and change every time the
    pipeline republishes, which is expected (`tiles.md` covers why). The count is the same 1,369 you
    would find in chapter 7's `(letter, country_code)` table for `C`/`BE` — it is not a coincidence,
    it is the same underlying rows, once counted directly and once addressed by the layer name
    `c_be` a MapLibre `addLayer({source:'coverage', 'source-layer':'c_be', ...})` call reads. If
    `CC_COVERAGE_URL` prints empty on your machine, the pipeline hasn't published a coverage archive
    yet (`make coverage-refresh`) — the row count still works regardless, because it never depended
    on the tile archive existing.
