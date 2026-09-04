// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Boots the map from the catalog endpoint (docs/specs/map-and-search.md §2):
   fetch CC_CATALOG_URL, expose window.CC_* globals, stays merge, inject map.js.
   The MapLibre instance is built here before that fetch so the basemap does
   not queue behind the catalog. */
(function () {
  /* MapLibre instance. Handshake via window: a classic script cannot import
     an ES module. pmtiles protocol is registered later by the module graph. */
  function buildMap() {
    if (window.__ccMapInstance || typeof maplibregl === 'undefined') return;
    if (!document.getElementById('map')) return;
    // viewBbox is the framing box (main landmass), not the true extent (islands).
    var bb = window.CCScope ? (window.CCScope.viewBbox ? window.CCScope.viewBbox() : window.CCScope.bbox()) : null;
    window.__ccMapOpts = {
      container: 'map', style: 'https://tiles.openfreemap.org/styles/liberty',
      // Active scope bbox; Everywhere has none, so this is the Wallonia anchor.
      bounds: bb ? [[bb[0], bb[1]], [bb[2], bb[3]]] : [[2.84, 49.45], [6.41, 50.85]],
      fitBoundsOptions: { padding: 24 }, attributionControl: false
    };
    window.__ccMapInstance = new maplibregl.Map(window.__ccMapOpts);
  }

  function boot() {
    var s = document.createElement('script');
    s.type = 'module';
    s.src = window.CC_MAP_SRC;
    document.body.appendChild(s);
  }
  // Before the fetch: the basemap must not queue behind the catalog.
  buildMap();

  // Shared by the boot fetch and the tab-return refresh.
  function applyCatalog(d) {
    window.CC_SURFACE = { segments: d.A };
    window.CC_CLIMBS = d.N;
    window.CC_WATER_OSM = d.B;
    window.CC_SERVICES_OSM = d.D;
    window.CC_STAYS_OSM = d.O.osm;
    window.CC_STAYS_AUTHORITY = d.O.authority;
    window.CC_HAZARDS = d.E;
    window.CC_TRANSIT_OSM = d.F;
    window.CC_SHELTER_OSM = d.G;
    window.CC_SCENIC_OSM = d.P;
    window.CC_HISTORY_OSM = d.Q;
    // Heat points live on /map/heat.json — off by default, not on the critical payload.
    window.CC_ROUTES = { routes: d.R };
    window.CC_TOILETS_OSM = d.C;
    // Coverage dedupe (docs/specs/coverage-provider.md §6): source_refs already served as items.
    window.CC_CURATED_REFS = d.refs || [];
    // Who to credit, keyed by the `pk` a feature carries. Sent with the
    // payload rather than held here, so adding a provider is a row in a table
    // and never a deploy (docs/specs/data-provider-hierarchy.md §7).
    window.CC_PROVIDERS = d.providers || {};
    // Stays merge: tag the authority's features and append them once, so
    // the drawer can credit their publisher instead of OSM.
    var O = window.CC_STAYS_OSM, P = window.CC_STAYS_AUTHORITY;
    if (O && P && !O._authority) {
      P.features.forEach(function (f) { f.properties.src = 'authority'; });
      O.features = O.features.concat(P.features);
      O._authority = 1;
    }
  }

  var catalogEtag = null;
  fetch(window.CC_CATALOG_URL)
    .then(function (r) {
      if (!r.ok) { throw new Error('catalog.json HTTP ' + r.status); }
      catalogEtag = r.headers.get('ETag');
      return r.json();
    })
    .then(function (d) {
      applyCatalog(d);
      window.CC_CATALOG_STATE = 'ok';
    })
    .catch(function (e) {
      window.CC_CATALOG_STATE = 'FAILED: ' + (e && e.message ? e.message : e);
      console.error('Catalog load failed — map layers unavailable.', e);
    })
    .then(boot);

  // Tab-return: revalidate against the boot ETag so an already-open map picks up approvals.
  var lastCheck = 0;
  function recheckCatalog() {
    if (document.visibilityState !== 'visible' || !catalogEtag) { return; }
    var now = Date.now();
    if (now - lastCheck < 15000) { return; }
    lastCheck = now;
    fetch(window.CC_CATALOG_URL, {
      cache: 'no-store',
      headers: { 'If-None-Match': catalogEtag },
    })
      .then(function (r) {
        if (r.status === 304 || !r.ok) { return null; }
        catalogEtag = r.headers.get('ETag');
        return r.json();
      })
      .then(function (d) {
        if (!d) { return; }
        applyCatalog(d);
        if (window.__ccApplyCatalog) { window.__ccApplyCatalog(); }
      })
      .catch(function () { /* transient — the next tab return retries */ });
  }
  document.addEventListener('visibilitychange', recheckCatalog);
  window.addEventListener('focus', recheckCatalog);
}());
