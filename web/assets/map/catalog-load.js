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
   (frontend review 2026-07-12 C5), so boot() runs it from cache. */
(function () {
  function boot() {
    var s = document.createElement('script');
    // map.js is an ES module (2026-07-26-map-js-module-split-design.md §3): it
    // is the entry of the map/*.js module graph, and AssetMapper rewrites its
    // relative imports to digested URLs. A dynamically inserted module script
    // still executes as soon as its graph is fetched, so the catalog gate this
    // function implements is unchanged — the CC_* globals are already set.
    s.type = 'module';
    s.src = window.CC_MAP_SRC;
    document.body.appendChild(s);
  }
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
      window.CC_ROUTES = { routes: d.K, heat: d.L };
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
