<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Worked example: a route planner

The running example: you build a route-planner application. It already has a
[MapLibre GL JS](https://maplibre.org/maplibre-gl-js/docs/) map with its own basemap, and users
draw a planned ride on it. You want two Commons overlays behind their planning: the cycle-route
network (so the plan can follow signed routes) and water points along the way (letter `B`). This
page wires both, start to finish. Every value is real; swap the coordinates for your viewport.

The example assumes the `maplibre-gl` and `pmtiles` packages, but nothing here depends on a
framework; it is plain JavaScript against the map instance you already have. Pin both: the Commons
ships MapLibre 6.8.0 and pmtiles 4.4.1, and `new Protocol().tile` below is the pmtiles 2.x-and-later
shape. On an older pmtiles the protocol is registered differently and the routes layer will simply
never draw.

*Where the fountain is: the water layer this page adds is letter `B`, so the Stavelot fountain
course 1 follows is one of the circles that appear once you pan over the Ardennes.*

## Step 1: fetch the config once

<!-- CODE-ILLUSTRATIVE consumer-side JavaScript; hand-written for this example -->
```js
const CC_API = 'https://cyclingcommons.org';

async function loadCcConfig() {
  const res = await fetch(`${CC_API}/v1/map-config`);
  if (!res.ok) throw new Error(`map-config failed: ${res.status}`);
  return res.json();          // cache this object; re-fetch at most hourly
}

// Hold it in one place. Every later step reads this same object.
const ccConfig = await loadCcConfig();
```

A plain `fetch` with no headers is enough: the endpoint is open, cross-origin access is allowed,
and the response is cacheable for an hour. Keep the parsed object around; both overlays read from
it.

## Step 2: the routes overlay, from tiles

Register the PMTiles protocol once, add the archive as a vector source, then add one line layer
per country and style group. The config tells you the archive URL, the source-layer names, and the
Commons colours.

<!-- CODE-ILLUSTRATIVE consumer-side JavaScript; hand-written for this example -->
```js
import maplibregl from 'maplibre-gl';
import { Protocol } from 'pmtiles';

maplibregl.addProtocol('pmtiles', new Protocol().tile);   // once per app, before addSource

function addCcRoutes(map, cc) {
  if (!cc.routes.tilesUrl) return;                        // no tileset published: skip quietly
  if (!map.getSource('cc-routes')) {
    map.addSource('cc-routes', {
      type: 'vector',
      url: 'pmtiles://' + cc.routes.tilesUrl,
    });
  }
  for (const country of cc.routes.countries) {            // e.g. ['be', 'nl', 'de']
    const sourceLayer = cc.routes.sourceLayers.lines.replace('{cc}', country);
    for (const group of cc.routes.style.groups) {         // national / regional / mtb
      const id = `cc-routes-${country}-${group.key}`;
      if (map.getLayer(id)) continue;
      map.addLayer({
        id,
        type: 'line',
        source: 'cc-routes',
        'source-layer': sourceLayer,                      // routes_be, routes_nl, ...
        minzoom: 6,                                       // see the note below on the two floors
        filter: ['in', ['get', 'net'], ['literal', group.nets]],
        paint: { 'line-color': group.color, 'line-width': 2, 'line-opacity': 0.55 },
      });
    }
  }
}
```

Three things worth noticing:

- The `filter` splits one source-layer into three styled layers by the `net` property: the config
  says `['icn', 'ncn']` paint rose (`#C84E64`), `['rcn', 'lcn', 'other']` paint purple
  (`#7A4FCF`), `['mtb']` paints brown. That reproduces the Commons look; you are free to paint
  differently.
- `minzoom: 6` keeps country-level zooms clean, and it is a choice, not a requirement. The archive
  publishes **two floors** on one artifact: `planningMinZoom` is 5, for the international networks
  a rider plans at the zoom where a whole country fits on screen, and `localMinZoom` is 8 for
  everything else, stamped per feature so the client needs no rule of its own. Both are in
  `pipeline/contract/coverage-contract.json` under `routes`. Setting 6 here means the planning tier
  is hidden for one zoom level that the Commons itself shows; use 5 if you want the same behaviour
  as the Commons map, and nothing breaks either way, because a network with no features in a tile
  simply draws nothing.
- If your planner draws its own route line, pass its layer id as the `beforeId` argument of
  `addLayer` so the Commons corridors render underneath the user's plan, not on top of it.

At this point, panning around the Low Countries at zoom 8 shows the node-network grid in purple
and the long-distance routes in rose, and the network tab shows only small range requests against
the archive.

## Step 3: the water-point overlay, from GeoJSON

Fetch items for the visible viewport whenever the map settles, and render them as a circle layer
coloured from the category table.

<!-- CODE-ILLUSTRATIVE consumer-side JavaScript; hand-written for this example -->
```js
function bboxParam(map) {
  const b = map.getBounds();
  const f = (n) => n.toFixed(3);                          // stable strings help HTTP caching
  return `${f(b.getWest())},${f(b.getSouth())},${f(b.getEast())},${f(b.getNorth())}`;
}

let ccBackoffUntil = 0;                                   // set by a 429, read on the next call

async function refreshCcWater(map, cc) {
  if (Date.now() < ccBackoffUntil) return;                // the server asked us to wait
  if (map.getZoom() < 8) return;                          // viewport far too large below this
  const url = `${CC_API}/v1/search?bbox=${bboxParam(map)}&letter=B&limit=200`;
  const res = await fetch(url);
  if (res.status === 429) {                               // over the limit: the server says how long
    ccBackoffUntil = Date.now() + Number(res.headers.get('Retry-After') || 5) * 1000;
    return;
  }
  if (!res.ok) return;                                    // a 400 is a bug in the request, not a retry
  const collection = await res.json();

  const water = cc.categories.find((c) => c.letter === 'B');
  if (!map.getSource('cc-water')) {
    map.addSource('cc-water', { type: 'geojson', data: collection });
    map.addLayer({
      id: 'cc-water',
      type: 'circle',
      source: 'cc-water',
      paint: {
        'circle-radius': 5,
        'circle-color': water.color,                      // '#8FB6A8' from the config
        'circle-stroke-width': 1,
        'circle-stroke-color': '#ffffff',
      },
    });
  } else {
    map.getSource('cc-water').setData(collection);        // later refreshes just swap the data
  }
}

let ccMoveTimer;
map.on('moveend', () => {
  clearTimeout(ccMoveTimer);
  ccMoveTimer = setTimeout(() => refreshCcWater(map, ccConfig), 300);
});
```

The 300 millisecond debounce means a continuous drag produces one request, not twenty. Rounding
the bounding box to three decimals (about 100 metres) keeps repeated views producing identical
URLs, which lets the five-minute shared cache and ETags do their work: settle on the same
viewport twice and the second fetch is a 304.

A click handler on the layer gets you a popup for free, because every feature carries its name:

<!-- CODE-ILLUSTRATIVE consumer-side JavaScript; hand-written for this example -->
```js
map.on('click', 'cc-water', (e) => {
  const p = e.features[0].properties;                     // see the trust envelope on the previous page
  new maplibregl.Popup()
    .setLngLat(e.lngLat)
    .setText(`${p.name || 'Water point'} (${p.grade})`)   // claimed / attested / minimum / high
    .addTo(map);
});
```

## Step 4: survive a basemap switch

The one MapLibre trap in this whole integration: `map.setStyle()` (a basemap switcher, a
light/dark toggle) **removes every custom source and layer**. The fix is to make the add functions
idempotent, which steps 2 and 3 already are (`getSource`/`getLayer` guards), and re-run them when
a new style has loaded:

<!-- CODE-ILLUSTRATIVE consumer-side JavaScript; hand-written for this example -->
```js
map.on('styledata', () => {
  addCcRoutes(map, ccConfig);
  refreshCcWater(map, ccConfig);
});
```

If your planner never switches styles, you still lose nothing by wiring this; the guards make the
re-run free.

One caveat the Commons hit and solved on its own map. `styledata` fires more than once per style,
and on some MapLibre versions it fires before the new style is ready to accept a source, so a
re-add can land on nothing. Chapter 8 of the GIS course
([`on-screen.md`](../gis/on-screen.md)) describes the flag the Commons keeps for exactly this.
The framework-free equivalent is to wait for the one-shot event instead:

<!-- CODE-ILLUSTRATIVE consumer-side JavaScript; the safer form of the handler above -->
```js
map.setStyle(nextStyle);
map.once('style.load', () => {                            // fires once, after the style is usable
  addCcRoutes(map, ccConfig);
  refreshCcWater(map, ccConfig);
});
```

## Step 5: attribution

While the overlays are visible, the config's attribution string must be on screen. The idiomatic
MapLibre way is the built-in control:

<!-- CODE-ILLUSTRATIVE consumer-side JavaScript; hand-written for this example -->
```js
map.addControl(new maplibregl.AttributionControl({
  customAttribution: ccConfig.attribution,   // '© Cycling Commons contributors (ODbL) · © OpenStreetMap contributors'
}));
```

If your app renders its own attribution strip, append the string there instead; what matters is
that it is visible, not which widget shows it.

## Try it

!!! tip "Hands-on: run this example against the dev stack, and watch the three request shapes"
    Everything above works unchanged against a local stack; only the host differs. Put the file
    somewhere outside the repository tree, or in your scratch directory, and set the API to the
    local one:

    <!-- CODE-ILLUSTRATIVE the one line to change, then serve the file over http rather than file:// -->
    ```js
    const CC_API = 'http://localhost:8001';
    ```

    <!-- CODE-ILLUSTRATIVE shell command; a module import will not run from a file:// page -->
    ```sh
    python3 -m http.server 8090 --directory /path/to/your/example
    ```

    Open `http://localhost:8090`, centre on Stavelot at `[5.93, 50.396]` around zoom 12, and read
    the browser's network panel rather than the map. Three request shapes should appear, and each
    one is a claim this section made:

    - **`/v1/map-config`**, once, `200`. Reload the page and it becomes `304 Not Modified`, which is
      the entity tag from the first page of this section doing its job.
    - **`/v1/search?bbox=...&letter=B&limit=200`**, once per settled pan, `200` with content type
      `application/geo+json`. Pan a little and watch the debounce hold it to one request; pan back
      to a box you have already visited and watch the browser answer from its own cache, which is
      what the three-decimal rounding in `bboxParam` is for.
    - **Range reads against the tile archive**, `206 Partial Content`, against
      `localhost:9100/cc-maps/routes/...`. Not one download of the archive: a handful of byte ranges,
      which is the whole argument for tiles over a bulk file.

    If the routes layer draws nothing, check `map-config`'s `routes.tilesUrl` first. It is allowed
    to be `null` on a stack that has never published a routes tileset, and the code above skips the
    layer quietly when it is, which is correct behaviour and looks exactly like a bug.

## The whole flow, summarised

1. On startup: `loadCcConfig()`, then `addCcRoutes()`.
2. On `moveend` (debounced): `refreshCcWater()`, which covers letter `B` only. A planner that
   wants more letters gives the function a `letter` parameter and calls it once per letter, or
   drops `letter` from the query string and splits the one all-letters response by
   `properties.letter`.
3. On `styledata`: re-run both add functions.
4. Attribution visible whenever the overlays are.

Total surface used: two REST endpoints and one static tile archive. Nothing else is required, and
everything your app decided (which letters to show, colours, zoom gates, popups) stayed in your
code, which is exactly the design: the Commons serves data, consumers own the look.
