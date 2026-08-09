// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Boots the map from the catalog endpoint: fetches /map/catalog.json (URL
   handed over by the template as window.CC_CATALOG_URL), exposes the layers
   as the window.CC_* globals map.js has always consumed, applies the stays
   merge (absorbed from the retired stays-merge.js), then injects map.js
   (AssetMapper-digested URL in window.CC_MAP_SRC). On fetch failure the map
   still boots — layer guards degrade to an empty catalog, and the curator
   pending layer (inline-injected) keeps working.
   map.js EXECUTION is gated on the catalog fetch (its globals must exist),
   but its DOWNLOAD is not: the template preloads CC_MAP_SRC in parallel
   (frontend review 2026-07-12 C5), so boot() runs it from cache.

   THE MAP ITSELF IS NOT GATED (2026-08-09, frontend review's first
   architectural item). It used to be: `map` is constructed at map-init.js's
   module scope, map-init.js is inside the graph map.js heads, and that graph
   only executes once the ~1 MB catalog has arrived — so the basemap style
   request, and therefore the first tile a visitor sees, queued behind a
   payload describing layers that are drawn much later. The instance is built
   here instead, before the fetch starts, and map-init.js adopts it. Tiles
   paint while the catalog is still in flight.

   Everything that needs the catalog still waits for the catalog: nothing in
   the module graph runs any earlier than it did, because boot() is unchanged.
   What moved is one constructor call. */
(function () {
  /* The MapLibre instance, built as early as the page allows.

     Safe here: maplibre-gl and pmtiles are classic blocking scripts above,
     window.CCScope is defined by scope.js above, and #map is in the body. The
     pmtiles PROTOCOL is registered later by the module graph, which is fine —
     no pmtiles source exists until coverage.js adds one.

     Kept on window rather than passed, because the consumer is an ES module
     the browser loads by URL: there is no import path from a classic script
     to a module, and a global handshake is the one seam available. */
  function buildMap() {
    if (window.__ccMapInstance || typeof maplibregl === 'undefined') return;
    if (!document.getElementById('map')) return;
    var bb = window.CCScope ? window.CCScope.bbox() : null;
    window.__ccMapOpts = {
      container: 'map', style: 'https://tiles.openfreemap.org/styles/liberty',
      // Initial viewport = the active scope's bbox (Wallonia by default; a
      // saved Flanders/Brussels scope reopens there). Everywhere has NO bbox
      // (null), so it — like a missing registry — opens on the old hardcoded
      // Wallonia literal: a deliberate anchor view, not a scope (review 07-20
      // info c).
      bounds: bb ? [[bb[0], bb[1]], [bb[2], bb[3]]] : [[2.84, 49.45], [6.41, 50.85]],
      fitBoundsOptions: { padding: 24 }, attributionControl: false
    };
    window.__ccMapInstance = new maplibregl.Map(window.__ccMapOpts);
  }

  function boot() {
    var s = document.createElement('script');
    // map.js is an ES module: it
    // is the entry of the map/*.js module graph, and AssetMapper rewrites its
    // relative imports to digested URLs. A dynamically inserted module script
    // still executes as soon as its graph is fetched, so the catalog gate this
    // function implements is unchanged — the CC_* globals are already set.
    s.type = 'module';
    s.src = window.CC_MAP_SRC;
    document.body.appendChild(s);
  }
  // Before the fetch, deliberately: the whole point is that the basemap does
  // not queue behind it.
  buildMap();

  fetch(window.CC_CATALOG_URL)
    .then(function (r) {
      if (!r.ok) { throw new Error('catalog.json HTTP ' + r.status); }
      return r.json();
    })
    .then(function (d) {
      window.CC_SURFACE = { segments: d.A };
      window.CC_CLIMBS = d.B;
      window.CC_WATER_OSM = d.C;
      window.CC_SERVICES_OSM = d.D;
      window.CC_STAYS_OSM = d.E.osm;
      window.CC_STAYS_PIVOT = d.E.pivot;
      window.CC_HAZARDS = d.F;
      window.CC_TRANSIT_OSM = d.G;
      window.CC_SHELTER_OSM = d.H;
      window.CC_SCENIC_OSM = d.I;
      window.CC_HISTORY_OSM = d.J;
      // `heat` is deliberately absent: the ~6,600 heat points moved to
      // /map/heat.json (window.CC_HEAT_URL) so they stop riding the critical
      // payload for a layer that is Off by default. render.js's addHeatmap()
      // fetches them the first time somebody switches it on.
      window.CC_ROUTES = { routes: d.K };
      window.CC_TOILETS_OSM = d.M;
      // Coverage dedupe (coverage-provider.md §6): the set
      // of source_refs already served as items — the coverage tile layers
      // filter these out so an object never draws twice (once as a tile dot,
      // once as a served pool feature). The CatalogProvider ships `refs` from
      // plan Task 13 on; until then (and on payloads without it) [] simply
      // means "filter nothing", which is correct — no refs, no possible twin.
      window.CC_CURATED_REFS = d.refs || [];
      // Stays merge: tag PIVOT features and append them to the OSM stays
      // collection once (drives the Tourisme-Wallonie attribution branch).
      var O = window.CC_STAYS_OSM, P = window.CC_STAYS_PIVOT;
      if (O && P && !O._pivot) {
        P.features.forEach(function (f) { f.properties.src = 'pivot'; });
        O.features = O.features.concat(P.features);
        O._pivot = 1;
      }
    })
    .catch(function (e) {
      console.error('Catalog load failed — map layers unavailable.', e);
    })
    .then(boot);
}());
