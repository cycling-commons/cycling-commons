<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Putting it on screen

The fountain's tile has just arrived in the browser. `tiles.md` covered everything that happened to
get it there: a `coverage_poi` row became a feature inside a `.pmtiles` archive, and a range request
pulled out the one tile that covers our fountain's corner of Wallonia. What the browser now holds is
not a picture. It is geometry, a point, with properties attached, sitting in memory.

None of that geometry draws itself. Something still has to decide that this particular point should
look like a small blue drop, roughly this big, fading in above the roads but below the pins. This
chapter is that "something": MapLibre GL JS, the library this project's map runs on, and the three
words it uses to describe what to draw and how.

!!! info "Where this code lives"
    This chapter walks the map's client code, which lives in `web/assets/map/`
    as **one module per concern**. `map.js` itself is only the import list, the
    `CC_*` payload hand-off and the boot sequence. How many modules there are,
    and how long `map.js` runs to, are on the [numbers page](../numbers.md),
    which is generated from the directory rather than typed here. When this chapter names a function, it names the
    module that holds it, and it is worth knowing the shape before you go
    looking:

    | Module | What it owns |
    |---|---|
    | `map-init.js` | the MapLibre object itself, the controls, basemap labels, the `_styleReady` flag |
    | `render.js` | which catalogue features draw, and the shown/total counts |
    | `coverage.js` | the per-country PMTiles sources and their per-`(letter, country)` layers |
    | `osm-pools.js` | the clustered dot layers and their DOM markers |
    | `spotlight.js` | the region mask, outline and "my area" circle |
    | `picking.js`, `scope-ui.js` | click handling and click-to-scope |
    | `drawer.js`, `sheet.js` | the detail panel and the mobile sheet |
    | `search-ui.js`, `places.js` | the search box, Photon calls, deep links |
    | `icons.js`, `mapillary.js`, `panels.js`, … | icon minting, street-level imagery, the rail chrome |

    That last row is an ellipsis rather than a list because the table names the modules this
    chapter walks, not every file in the directory. For the complete set, read the directory: it is
    one module per concern, and the count is on the [numbers page](../numbers.md). The map shell and
    its boot contract are in `docs/specs/map-and-search.md`, §2.

## Style, source, layer

MapLibre needs three kinds of instruction, and it uses three ordinary English words to name them,
narrowly, in a way this reader has not met before. Get these three words straight and almost
everything else across the map's modules reads as a combination of them.

A **style** is the whole document describing what the map draws: every source, every layer, the
background colour, all of it, together. This project does not hand-write one. Look at the
`new maplibregl.Map({...})` call that boots the whole thing, it lives in
`web/assets/map/catalog-load.js`, and `map-init.js` adopts the instance it makes:

<!-- CODE-FROM web/assets/map/catalog-load.js -->
```js
    window.__ccMapOpts = {
      container: 'map', style: 'https://tiles.openfreemap.org/styles/liberty',
```

(It sits in `catalog-load.js`, not in `map-init.js` where the name suggests, for a reason:
`map-init.js` is inside the module graph that only runs once the map's
~1 MB catalog has arrived. Constructing the map there meant the basemap, the first thing anybody
sees, queued behind a payload describing layers drawn much later. Building it before that fetch
starts lets tiles paint while the catalog is still travelling.)

That URL *is* the starting style, a ready-made document served by OpenFreeMap, containing the
roads, place names and land colours you see under everything else. The map never ships a second
style document of its own. Once that one finishes loading, it calls functions, `addSource()`,
`addLayer()`, that reach into the already-loaded style and add to it at runtime. Every pin,
every coverage icon, every route line you will read about below is one of those additions, made in
JavaScript, to a style that started life as somebody else's file. (This is also why so much of the
map's code is written to run only after that document has finished loading, `addSource`/`addLayer`
throw if you call them before the style is ready, which is why `render.js` gates its own drawing on
the `_styleReady` flag `map-init.js` sets from the map's `load` event; see `map-and-search.md` §2's `_styleReady` rule if you want the detail.)

A **source** is where data comes from. Naming a source does not draw anything by itself, it just
tells MapLibre "here is a pool of geometry you can read from," and gives it an id to be read from.

A **layer** is one drawing instruction that reads one source. It says which source, which subset of
that source's features, and how to turn each feature into pixels, what colour, what icon, how big.

Here is the idea worth slowing down for, because it is the one that makes the rest of this chapter
(and a good deal of the map code) unsurprising: **one source can feed many layers.** A source is just
data sitting there under a name; nothing stops five different layers from reading the same source
and drawing five different things from it. The coverage tiles are the clearest example in this
codebase. Every country gets its own archive, and `mountInView()` in
`web/assets/map/tile-sources.js` adds that country's vector source once its bounds meet the
viewport, and not before:

<!-- CODE-FROM web/assets/map/tile-sources.js -->
```js
map.addSource(id, { type: 'vector', url: 'pmtiles://' + url });
```

`addCoverageLayers()` in `web/assets/map/coverage.js` then adds one icon layer and one heatmap
layer for every catalogue letter against that same source, `source:src` on every one of them. One
`.pmtiles` archive, fetched once per country, feeds every icon and heatmap layer for that country.
Restyle any one of them, change a colour, swap an icon, and nothing is re-fetched. The source does
not change; only the instruction reading it does.

<figure class="gis-fig">
<svg viewBox="0 0 640 1010" role="img" aria-labelledby="f15-t f15-d" xmlns="http://www.w3.org/2000/svg"><title id="f15-t">One country's tile archive, one source, three layers, one rendered map</title><desc id="f15-d">A vertical flow in four tiers. At the top, one box labelled coverage dash be dot pmtiles, Belgium's tile archive. An arrow down from it into a single box labelled source coverage dash be, type vector. From that source a horizontal bar fans out into three layer boxes side by side: water icons on source layer b_be, water heat on the same source layer b_be, and services icons on source layer d_be. A dashed leader runs from the water icons box down into a detail panel headed inside one layer, split in two: paint, holding icon-opacity and icon-color, labelled how it looks; and layout, holding visibility and icon-image, labelled whether, and as what. Below that, each of the three layers sends an arrow into one shared panel labelled the rendered map. Inside it a soft blurred patch, the heat surface, sits underneath a scatter of small individual dots, the icons. There is no cluster bubble and no count label anywhere in it. A note records that the heat surface and the icons are separate layers over the same already-loaded source, so restyling either refetches nothing.</desc><defs><marker id="gis-arrow-f15" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path class="gis-fill-accent" d="M 0 0 L 10 5 L 0 10 Z"/></marker><radialGradient id="f15heat"><stop offset="0%" class="gis-fill-accent" stop-opacity="0.5"/><stop offset="60%" class="gis-fill-accent" stop-opacity="0.18"/><stop offset="100%" class="gis-fill-accent" stop-opacity="0"/></radialGradient></defs><text class="gis-label-sm" x="20" y="30">one archive</text><rect class="gis-box" rx="8" x="212" y="46" width="216" height="76"/><text class="gis-label-mono" x="320" y="89" text-anchor="middle">coverage-be.pmtiles</text><line class="gis-accent" x1="320" y1="122" x2="320" y2="160" marker-end="url(#gis-arrow-f15)"/><text class="gis-label-sm" x="20" y="196">one source</text><rect class="gis-box" rx="8" x="212" y="162" width="216" height="76"/><text class="gis-label-mono" x="320" y="190" text-anchor="middle">source: "coverage-be"</text><text class="gis-label-sm" x="320" y="220" text-anchor="middle">type: vector</text><path class="gis-accent" d="M 320 238 L 320 268 M 96 268 L 544 268"/><line class="gis-accent" x1="96" y1="268" x2="96" y2="300" marker-end="url(#gis-arrow-f15)"/><line class="gis-accent" x1="320" y1="268" x2="320" y2="300" marker-end="url(#gis-arrow-f15)"/><line class="gis-accent" x1="544" y1="268" x2="544" y2="300" marker-end="url(#gis-arrow-f15)"/><text class="gis-label-sm" x="20" y="336">three layers</text><rect class="gis-box" rx="8" x="20" y="302" width="152" height="110"/><text class="gis-label-mono" x="96" y="332" text-anchor="middle">water icons</text><text class="gis-label-sm" x="96" y="362" text-anchor="middle">type: symbol</text><text class="gis-label-sm" x="96" y="392" text-anchor="middle">source-layer b_be</text><rect class="gis-box" rx="8" x="244" y="302" width="152" height="110"/><text class="gis-label-mono" x="320" y="332" text-anchor="middle">water heat</text><text class="gis-label-sm" x="320" y="362" text-anchor="middle">type: heatmap</text><text class="gis-label-sm" x="320" y="392" text-anchor="middle">source-layer b_be</text><rect class="gis-box" rx="8" x="468" y="302" width="152" height="110"/><text class="gis-label-mono" x="544" y="332" text-anchor="middle">services icons</text><text class="gis-label-sm" x="544" y="362" text-anchor="middle">type: symbol</text><text class="gis-label-sm" x="544" y="392" text-anchor="middle">source-layer d_be</text><path class="gis-muted" stroke-dasharray="5 5" d="M 96 412 L 96 452"/><rect class="gis-box" rx="8" x="20" y="452" width="380" height="196"/><text class="gis-label-sm" x="40" y="486">inside one layer</text><line class="gis-muted" x1="20" y1="500" x2="400" y2="500"/><text class="gis-label-mono" x="44" y="536">paint</text><text class="gis-label-sm" x="150" y="536">icon-opacity</text><text class="gis-label-sm" x="150" y="566">icon-color</text><text class="gis-label-sm" x="44" y="596">how it looks</text><line class="gis-muted" x1="410" y1="500" x2="410" y2="640"/><text class="gis-label-mono" x="434" y="536">layout</text><text class="gis-label-sm" x="540" y="536">visibility</text><text class="gis-label-sm" x="540" y="566">icon-image</text><text class="gis-label-sm" x="434" y="596">whether, and as what</text><rect class="gis-box" rx="8" x="410" y="452" width="210" height="196"/><path class="gis-accent" d="M 96 648 L 96 684" marker-end="url(#gis-arrow-f15)"/><path class="gis-accent" d="M 320 412 L 320 436 L 630 436 L 630 668 L 320 668 L 320 684" marker-end="url(#gis-arrow-f15)"/><path class="gis-accent" d="M 544 412 L 544 424 L 618 424 L 618 676 L 544 676 L 544 684" marker-end="url(#gis-arrow-f15)"/><text class="gis-label-sm" x="20" y="720">one rendered map</text><rect class="gis-box" rx="8" x="20" y="686" width="600" height="300"/><line class="gis-muted" x1="20" y1="734" x2="620" y2="734"/><ellipse cx="250" cy="912" rx="170" ry="72" fill="url(#f15heat)"/><ellipse cx="430" cy="928" rx="120" ry="52" fill="url(#f15heat)"/><circle class="gis-ink gis-fill-ink" cx="502" cy="939" r="3"/><circle class="gis-ink gis-fill-ink" cx="140" cy="879" r="3"/><circle class="gis-ink gis-fill-ink" cx="375" cy="874" r="3"/><circle class="gis-ink gis-fill-ink" cx="215" cy="925" r="3"/><circle class="gis-ink gis-fill-ink" cx="485" cy="875" r="3"/><circle class="gis-ink gis-fill-ink" cx="116" cy="924" r="3"/><circle class="gis-ink gis-fill-ink" cx="557" cy="893" r="3"/><circle class="gis-ink gis-fill-ink" cx="88" cy="896" r="3"/><circle class="gis-ink gis-fill-ink" cx="353" cy="878" r="3"/><circle class="gis-ink gis-fill-ink" cx="275" cy="904" r="3"/><circle class="gis-ink gis-fill-ink" cx="269" cy="922" r="3"/><circle class="gis-ink gis-fill-ink" cx="545" cy="893" r="3"/><circle class="gis-ink gis-fill-ink" cx="427" cy="883" r="3"/><circle class="gis-ink gis-fill-ink" cx="531" cy="931" r="3"/><circle class="gis-ink gis-fill-ink" cx="405" cy="888" r="3"/><circle class="gis-ink gis-fill-ink" cx="245" cy="910" r="3"/><circle class="gis-ink gis-fill-ink" cx="100" cy="878" r="3"/><circle class="gis-ink gis-fill-ink" cx="127" cy="905" r="3"/><circle class="gis-ink gis-fill-ink" cx="273" cy="913" r="3"/><circle class="gis-ink gis-fill-ink" cx="253" cy="956" r="3"/><circle class="gis-ink gis-fill-ink" cx="409" cy="914" r="3"/><circle class="gis-ink gis-fill-ink" cx="363" cy="889" r="3"/><circle class="gis-ink gis-fill-ink" cx="378" cy="957" r="3"/><circle class="gis-ink gis-fill-ink" cx="493" cy="933" r="3"/><circle class="gis-ink gis-fill-ink" cx="394" cy="926" r="3"/><circle class="gis-ink gis-fill-ink" cx="209" cy="895" r="3"/><circle class="gis-ink gis-fill-ink" cx="265" cy="934" r="3"/><circle class="gis-ink gis-fill-ink" cx="418" cy="960" r="3"/><circle class="gis-ink gis-fill-ink" cx="399" cy="925" r="3"/><circle class="gis-ink gis-fill-ink" cx="292" cy="896" r="3"/><circle class="gis-ink gis-fill-ink" cx="544" cy="916" r="3"/><circle class="gis-ink gis-fill-ink" cx="441" cy="900" r="3"/><circle class="gis-ink gis-fill-ink" cx="447" cy="897" r="3"/><circle class="gis-ink gis-fill-ink" cx="140" cy="910" r="3"/><text class="gis-label-sm" x="40" y="766">no bubble, no count: the heat surface and the icons are</text><text class="gis-label-sm" x="40" y="796">separate layers over the same already-loaded source, so</text><text class="gis-label-sm" x="40" y="826">restyling either one refetches nothing</text></svg>
<figcaption>One source, many layers: each country's archive feeds an icon layer and a heatmap
layer per catalogue letter, never a cluster bubble. Restyling either needs no refetch.</figcaption>
</figure>
<!-- FIGURE-TODO id=F15 ch=8 -->

## `source-layer`

A vector tile is not one flat bag of features. `tiles.md` showed tippecanoe building the coverage
tiles per catalogue letter and per country, a split that keeps every tile single-country by
construction and lets the density heatmap be scoped per country (`tiles.md`'s "Why the layers are
split per country" has the full story).
Inside a single `.pmtiles` archive, those splits survive as **named layers packed inside each
tile**, several independent feature collections, sitting in the same file, each with its own
name.

That means a MapLibre layer reading a vector source cannot just say "read the `coverage` source." It
has to say which of the named layers *inside* that source's tiles it means. That second answer is
the `source-layer` property, and it exists only for vector sources, a GeoJSON or raster source
carries just one collection, so there is nothing to disambiguate.

`addCoverageLayers()` builds that name from the same per-`(letter, country)` split chapter 7 described:

<!-- CODE-FROM web/assets/map/coverage.js -->
```js
const srcLayer = cc ? letter+'_'+cc : letter;
const id = cc ? key+'-'+cc+'-cov' : key+'-cov';
...
map.addLayer({id, type:'symbol', source:src, 'source-layer':srcLayer,
```

`letter` is the lowercase catalogue letter (`b` for water, `d` for bike services, and so on) and `cc`
is a lowercase country code, so our fountain's icon layer reads the `b_be` source-layer inside
Belgium's own source. Rows with no country recorded live in a `zz` bucket, which gets its own source
the same way any other country does, and `cc === null` is a fallback for a tile archive built before
this split existed, reading the plain `b` source-layer instead. A `(letter, country)` pair with
nothing in it simply renders nothing, there is no special case for an empty source-layer, it is just
an empty layer.

This is also where the "one source, many layers" idea from the previous section gets a second
dimension. The very same `source` and the very same `source-layer`, Belgium's source and `b_be`,
feed *two* layers in `addCoverageLayers()`: a `symbol` icon layer for individual points and a
`heatmap` layer for the overview density surface. What tells them apart is not the source, and not
even the source-layer, it is each layer's `type`, its zoom range (`minzoom: 9` on the icons, `maxzoom:
9` on the heat, so the two cross-fade at z9) and its `filter` (`covIconFilter()` layers scope on top
of the curated-ref dedupe and, for stays, the accessibility narrow; `covHeatFilter()` is scope only, a
density surface has nothing to click, so it needs none of the rest). One pool of data, read twice,
never merged into a cluster.

## The source types we use

The map uses four kinds of source, and each one exists for a different reason.

**`vector` over a `pmtiles://` URL** is the coverage source you just read about,
`mountInView()`'s `map.addSource(id, { type: 'vector', url: 'pmtiles://' + url })`, one per
country in view. Tiled, pre-built, fetched a piece at a time.

**`geojson`** is a source given a plain GeoJSON object directly, no tiling. The region mask and
boundary use it: `drawSpotlightMask()` in `web/assets/map/spotlight.js` builds a `region-mask` polygon (the world with a
hole cut where the scoped region is) and a `region` polygon (the region's own outline), and adds
both as `geojson` sources feeding a dimming fill layer and a dashed line layer.

<!-- CODE-FROM web/assets/map/spotlight.js -->
```js
map.addSource('region-mask',{type:'geojson',data:mask});
map.addSource('region',{type:'geojson',data:{type:'Feature',geometry:g}});
```

Notice what is missing compared to the coverage source above: no `url`, no tile archive, no
`source-layer` to disambiguate, `data` is the geometry itself, computed in JavaScript a moment
earlier and handed over whole. That is the entire difference the "tiled versus not" distinction from
the previous section comes down to in real code.

**`raster`** is a source of pre-rendered image tiles, actual pictures, not geometry. The optional
satellite base uses it: `addSatellite()` points a `raster` source at Esri World Imagery's tile URLs.
There is no geometry to read here at all, only images to place in a grid; this is the one source
type this chapter's later sections on paint, layout and clicking do not really apply to.

<!-- CODE-FROM web/assets/map/map-init.js -->
```js
  map.addSource('satellite',{type:'raster',tileSize:256,
    tiles:[esriTileUrl(ESRI_KEY)],
```

(`esriTileUrl()` builds the keyed Esri URL: the call carries an ArcGIS Location
Platform key, `CC_ESRI_KEY`, because answering without a token is not the same
thing as being licensed to use the imagery. With no key configured the source is
not added at all, so the Satellite control hides itself rather than offering a
button that does nothing.)

Compare that to the `geojson` source just above: no `data`, no features, just a `tiles` URL template
and a `tileSize`. MapLibre never parses a shape out of this source at all, it just requests
whichever `{z}/{y}/{x}` image square the viewport needs and places it, which is exactly why `raster`
sits outside the paint/layout/click-testing story the rest of this chapter tells.

**A clustered `geojson` source** is the fourth kind. It looks like the coverage source and works
nothing like it. `setupConfClusters()` builds one of these per bulk-OSM
pool, for the confirmed (rider-verified) points:

<!-- CODE-FROM web/assets/map/osm-pools.js -->
```js
map.addSource(srcId,{type:'geojson', cluster:true, clusterRadius:48, clusterMaxZoom:13,
```

`cluster:true` tells MapLibre itself to group nearby points into bubbles, live, in the browser, as
you pan and zoom, no pre-built tile archive involved.

**Coverage does not cluster, at build time or in the browser, and here is why.** The coverage pool
is enormous (this project's `coverage_poi` table holds around two million rows at the time of
writing, headed toward several million) and mostly static, so grouping it once, offline, while
`tiles.md`'s tippecanoe run builds the `.pmtiles` archive looks at first like the only version of
that job that could scale. It is not safe. A cluster renders at the *centroid of its members*, and
that position is decoupled from any single member's own region: the measured case is a shelter
cluster rendering in Thuringia while carrying a single Hesse `ridtok`, a **phantom bubble** that no
amount of tuning (tighter cluster distance, larger tile budgets, unioning tokens differently) closes
off. It also does not scale the way tippecanoe's per-tile clustering would need to: a European state
clusters fine, but a whole US state or Chinese province onboarded at once (California
200,000-400,000 rows, Guangdong 500,000-2,000,000+) breaks it. So `addCoverageLayers()` draws
every in-scope point individually from z9 up, and below z9 the overview is a density heatmap built from
the same tiles' thinned point sample rather than from merged counts. A single point carries exactly
one `ridtok`/`cctok` pair, so the scope filter stays exact no matter how far out the map is zoomed.

The confirmed-points source above clusters, live in the browser, because it is the opposite kind of
data: small (a rider-confirmed pool, nowhere near coverage's size) and constantly changing (a new
confirmation can land at any moment). Baking a tile archive for a pool that size would be wasted
effort, and rebuilding it on every confirmation would be absurd. So MapLibre's own `cluster:true`
option does the grouping there, recomputing it for whatever is currently in view, small and volatile
enough that a centroid never has anywhere phantom to land.

The rule: **cluster** is the right tool only for data small and volatile enough that its rendered
position can be trusted. The confirmed-points pool clears that bar; coverage's worldwide,
precisely-scoped pool does not.

## Paint vs layout

Every layer's drawing instructions split into two groups, and the split is not cosmetic, it is
about what changing a property costs MapLibre to redo.

**`paint`** is appearance: colour, opacity, blur, line width, properties that never move a feature
or change whether it's drawn, only how it looks once it's already placed. Paint changes are cheap:
MapLibre can repaint the same already-positioned pixels without recomputing where anything goes.

**`layout`** is placement and participation: whether a feature draws at all (`visibility`), which
icon or text it uses, how big, in what order features that might overlap get to win. Layout changes
are more expensive, because MapLibre may have to redo the placement and collision work, deciding
which icons fit without overlapping, that paint changes never touch.

`syncCoverageLayers()` in `web/assets/map/coverage.js` uses both, back to back, on the very same layer, and the split
tells you exactly what each line is doing:

<!-- CODE-FROM web/assets/map/coverage.js -->
```js
map.setLayoutProperty(id,'visibility', show?'visible':'none');
map.setPaintProperty(id,'icon-opacity', dim);
```

The first line is a layout change: it turns the whole layer on or off, a feature that isn't visible
doesn't participate in placement at all. The second is a paint change: the layer stays fully on and
fully placed, just rendered at 55% opacity when Curated mode wants a utility icon to recede without
disappearing. One function, one layer, one line of each kind, doing two genuinely different jobs.

## Data-driven styling

A paint or layout property does not have to be a fixed value. It can be an **expression**, a small,
nested-array formula that MapLibre evaluates separately for every feature, reading that feature's own
properties out of the tile. This is how one layer draws many different-looking things instead of
needing one layer per variant.

`addCoverageLayers()`'s water-and-food icon is exactly that. Instead of one drop icon for every letter-B
point, it reads each feature's own `food` and `potable` properties and picks one of five kind glyphs
(the drawings live in one registry, `KindIcons`, and are minted into map images named
`kind-b-<kind>`), and then reads the feature's `cd`, its OpenStreetMap `check_date`, to choose
between the plain icon and its twin with the `?` badge (`kind-b-<kind>-q`):

<!-- CODE-FROM web/assets/map/coverage.js -->
```js
const isFood = ['match',['to-string',['get','food']],['true','1','yes'],true,false];
const isPotable = ['match',['to-string',['get','potable']],['yes','true','1'],true,false];
const isNotPotable = ['==',['to-string',['get','potable']],'no'];
```

<!-- CODE-FROM web/assets/map/coverage.js -->
```js
const cutoff = witnessCutoff();
const witnessed = cutoff ? ['>=',['to-string',['coalesce',['get','cd'],'']], cutoff] : false;
const pick = (plain, badged) => ['case', witnessed, plain, badged];
const kindPair = kind => pick(kindImageId('B',kind,false), kindImageId('B',kind,true));
const miniPair = (glyph, suffix) => pick(miniIcon(key, glyph, suffix, false), miniIcon(key, glyph, suffix, true));
const icon = key==='water'
  ? ['case', isFood,
      ['case', isPotable, kindPair('food_water'), kindPair('food')],
      ['case', isPotable, kindPair('tap'), isNotPotable, kindPair('no'), kindPair('unk')]]
```

Read it like nested if/else: is it a food stop? Then the fork-and-knife disc, with a small drop if it
also gives water. Otherwise a tap: the filled blue drop when `potable` says yes, the barred drop when
it says `'no'`, and the unfilled drop when it says nothing. Each arm is itself a two-way `case`: the
plain icon when the point's `cd` is on or after the cutoff the page computed from
`map.confirmation_stale_months` (a dated witness inside the window), the badged twin otherwise. The
cutoff and the date are both `YYYY-MM-DD` strings, so a string compare is a date compare, and a page
that hands over no cutoff keeps the badge on everything. One layer, one `icon-image` line, and
every one of the hundreds of thousands of letter-B points draws its own correct icon. The D · bike-services layer does the same
trick on a `kind` property, picking between a shop, station and pump glyph, each again as a
plain-or-badged pair:

<!-- CODE-FROM web/assets/map/coverage.js -->
```js
['match',['get','kind'],
    'shop', miniPair(),
    'station', miniPair(SERVICE_GLYPH.station, 'station'),
    'pump', miniPair(SERVICE_GLYPH.pump, 'pump'),
    miniPair()]
```

Same shape as the water example, `match` on a tile property, one arm per value, a trailing
catch-all, just with three named values instead of a yes/no split. One `addLayer()` call still draws
shops, stations and pumps as three visually distinct glyphs, because the branching lives in the
expression, not in three separate layers.

## Clicking things

A vector source hands the browser real geometry, not a picture, that is the whole trade `tiles.md`
made when it chose vector tiles over raster ones. This is the section where that trade actually pays
off: because the browser already has the shapes, answering "what did the rider just click on?" never
needs to ask a server. It can be answered by asking the map itself.

`queryRenderedFeatures()` is that question, asked locally: give it a small box in screen pixels and a
list of layer ids, and it hands back whichever already-rendered features from those layers fall
inside the box, read straight out of what MapLibre has already drawn, with no network round trip.
`nearestImageId()` in `web/assets/map/mapillary.js`, which finds the Mapillary image dot nearest a click, shows the pattern
at its plainest, a box centred on the click point, widened in three steps until something is found:

<!-- CODE-FROM web/assets/map/mapillary.js -->
```js
for(const r of [8,16,30]){
  const fs=map.queryRenderedFeatures([[point.x-r,point.y-r],[point.x+r,point.y+r]],{layers:['mly-img']});
```

The same function backs the map's general click handler, deciding whether a click landed on *any*
selectable feature at all before falling through to other click behaviour, `scope-ui.js` calls it
`selectableLayers()`, and the click handler is
`map.queryRenderedFeatures(e.point, {layers:selectableLayers()})`.

That is the concrete thing vector tiles bought this project: hit-testing is a local lookup against
geometry the browser is already holding, not a request to a server asking what's under a pixel. A
raster tile is a picture with no features to query at all, a map built on raster tiles would have no
way to answer "what's here?" except by asking somewhere else.

## What to carry forward

- **Style, source, layer.** A style is the whole document; a source is data under a name; a layer is
  one drawing instruction reading one source. One source can feed many layers, which is why a
  restyle never needs a refetch.
- **`source-layer`** picks a named collection out of a vector source's tiles, the per-`(letter,
  country)` split `tiles.md` builds is exactly what that name encodes.
- Four source types, four different jobs: `vector`/`pmtiles://` for the huge static coverage pool,
  `geojson` for small one-off shapes like the region mask, `raster` for pre-rendered image tiles, and
  a clustered `geojson` source for the small, constantly-changing confirmed-points pool.
- **Clustering happens in one place.** Coverage never clusters (individual points from z9, a density
  heatmap below that), because a build-time cluster's position cannot be trusted to stay inside its
  scoped region at any tuning. The confirmed-points pool is the only thing that clusters, live in the
  browser, because it is small and volatile enough for that to stay safe.
- **`paint`** is cheap appearance; **`layout`** is placement and participation, and costs more to
  change.
- A paint or layout property can be an **expression**, reading a feature's own properties, one layer
  styling many categories.
- **`queryRenderedFeatures`** answers "what's under this click?" from geometry already in the
  browser, the concrete payoff of vector tiles over raster ones.

## Try it

!!! tip "Hands-on: connect a `source-layer` name to real rows"
    `addCoverageLayers()` reads a country's own source and slices it by `source-layer`, `b_be` for
    Belgian water points, and so on. Fetch the running map page to see the URL that source actually
    points at, then ask the database to build those layer names for you, straight from the two
    columns they are made of.

    <!-- CODE-ILLUSTRATIVE shell command against the dev stack's web app and Postgres -->
    ```sh
    curl -s http://localhost:8001/map | grep -o 'CC_TILES *= *{[^;]*};' | head -c 400
    docker compose -f developers/docker/compose.yaml exec db psql -U cc -d cyclingcommons -c "
    SELECT lower(letter) || '_' || lower(coalesce(country_code,'ZZ')) AS source_layer, count(*)
    FROM coverage_poi GROUP BY 1 ORDER BY 1;
    "
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM fresh-clone; sample output on a stack seeded by `make course-data`; the versioned tile key differs on every machine -->
    ```text
    window.CC_TILES = {"coverage":{"zz":{"tiles":{"points":"http:\/\/localhost:9100\/cc-maps\/coverage\/zz\/20260723-1429\/points.pmtiles"}
     source_layer | count
    --------------+-------
     b_zz         |     1
     d_zz         |     2
     f_zz         |     1
     g_zz         |     1
     o_zz         |     2
     p_zz         |     1
     q_zz         |     2
    (7 rows)
    ```

    `window.CC_TILES.coverage` has one key per country the manifest holds tiles for, each with its own
    `pmtiles://` URL; `coverage.js`'s `mountInView()` hands each one to `maplibregl.addSource` only
    once that country's bounds meet the viewport. The versioned key in each URL will differ on your
    machine and change every time that country republishes, which is expected (`tiles.md` covers why).
    The SQL query is chapter 7's `(letter, country_code)` table with the two columns pasted together
    in exactly the order `export_geojsonl()` pastes them, which is what makes it a `source-layer`
    name: the string `b_zz` in that output is the same string a MapLibre
    `addLayer({source:'coverage-points-zz', 'source-layer':'b_zz', ...})` call would read, and the
    count beside it is how many rows that layer holds.

    On a machine that has run the full `make coverage-refresh` the same query names `b_be`, `b_de`,
    `b_nl` and one row per (letter, country) pair the harvest has stamped, because `country_code` is
    stamped for real there; on the offline seed
    every layer ends `_zz`, for the reason chapter 7 spells out. Either way the derivation is the
    same, and that is the point, the layer name is not a label someone typed, it is two columns
    joined by an underscore. If `CC_TILES.coverage` is empty on your machine, no coverage archive has
    been published for any country yet, the row count still works regardless, because it never
    depended on the tile archive existing.

    One step further, and it closes the loop the other way. The query above *predicts* the layer
    names from the rows. Ask one country's own archive what it actually contains, and the two lists
    should agree:

    <!-- CODE-ILLUSTRATIVE ask one country's published archive for its own layer list; needs a published archive -->
    ```sh
    docker compose -f developers/docker/compose.yaml exec pipeline \
      pmtiles show --metadata http://minio:9000/cc-maps/coverage/lu/<stamp>/points.pmtiles \
      | python3 -c "import json,sys; m=json.load(sys.stdin); \
        vl=m.get('vector_layers') or json.loads(m.get('json','{}')).get('vector_layers',[]); \
        print(len(vl),'layers'); print(sorted(l['id'] for l in vl))"
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM author-install; real output, Luxembourg's own archive, 2026-09-24 -->
    ```text
    8 layers
    ['b_lu', 'c_lu', 'd_lu', 'f_lu', 'g_lu', 'o_lu', 'p_lu', 'q_lu']
    ```

    One country, one file, one layer per letter that country's index holds: no other country's rows
    are in this archive at all, by construction, not by a filter applied on read. That is the same
    naming scheme, read out of the finished file rather than derived from the database, which is the
    strongest form the claim comes in: a `source-layer` string in your MapLibre call is not a
    convention this chapter is asking you to trust, it is a key you can list.
