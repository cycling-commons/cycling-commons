// SPDX-License-Identifier: AGPL-3.0-only
/* Boots the map from the catalog endpoint (docs/specs/map-and-search.md §2):
   fetch CC_CATALOG_URL, expose window.CC_* globals, stays merge, inject map.js.
   The MapLibre instance is built here before that fetch so the basemap does
   not queue behind the catalog. */
(function () {
  /* Said in place of the map when the browser has no WebGL2 context. v6 removed
     WebGL1 and throws GPUInitializationError from the constructor instead of
     returning a map that silently never paints, so this is the first release
     that can tell a rider what is wrong. English fallback for the same reason
     i18n.js carries them: the file must still work with no bundle injected. */
  function reportNoGpu() {
    var box = document.getElementById('map');
    if (!box) return;
    var p = document.createElement('p');
    p.className = 'map-gpu-error';
    p.setAttribute('role', 'alert');
    p.textContent = (window.CC_I18N && window.CC_I18N.gpuUnsupported)
      || 'The map cannot be drawn in this browser. It needs WebGL 2, a graphics '
       + 'feature this browser does not have or has switched off.';
    box.replaceChildren(p);
  }

  /* MapLibre instance. Handshake via window: the boot module in the template
     imports maplibre-gl.mjs and assigns the `maplibregl` global v6 no longer
     defines itself. This file is a module too, so it runs after that one.
     pmtiles protocol is registered later by the module graph. */
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
    // MapLibre's own control labels in the site language (Locate me, map-init.js).
    if (window.CC_I18N && window.CC_I18N.mapUi) window.__ccMapOpts.locale = window.CC_I18N.mapUi;
    try {
      window.__ccMapInstance = new maplibregl.Map(window.__ccMapOpts);
      answerMissingImages(window.__ccMapInstance);
    } catch (e) {
      // Any constructor failure ends the map, not just a missing GPU: without
      // an instance map-init.js throws on import and nothing downstream runs.
      window.__ccMapOpts = null;
      window.CC_MAP_STATE = 'FAILED: ' + (e && e.message ? e.message : e);
      reportNoGpu();
      console.error('MapLibre could not start.', e);
    }
  }

  /* The basemap asks its sprite for an image named after every point's OSM
     class, and the sprite lacks most of them: a warning per class, nothing
     drawn. Answered HERE, the moment the map exists, because the first tiles
     ask before the module graph has loaded. The classes in the basemap icon
     registry (BasemapIcons::set(), window.CC_BASEMAP_ICONS) are minted from
     their paths, 18px in a 24-box at 2x, monochrome with a paper halo; every
     other missing name gets one blank image, so the console stays quiet and
     nothing else changes (docs/specs/map-and-search.md §4.7). */
  function answerMissingImages(map) {
    var icons = window.CC_BASEMAP_ICONS || {};
    map.on('styleimagemissing', function (e) {
      var id = e.id;
      if (map.hasImage(id)) return;
      var def = icons[id];
      if (!def || !def.paths) {
        map.addImage(id, { width: 1, height: 1, data: new Uint8Array(4) });
        return;
      }
      var S = 2, PX = 18, D = PX * S;
      var cv = document.createElement('canvas'); cv.width = D; cv.height = D;
      var x = cv.getContext('2d');
      x.scale(D / 24, D / 24);
      def.paths.forEach(function (p) {
        var path = new Path2D(p.d);
        if (p.stroke) { x.lineWidth = (p.width || 1.2) * 2; x.lineJoin = 'round'; x.strokeStyle = p.stroke; x.stroke(path); }
        x.fillStyle = p.fill; x.fill(path);
      });
      map.addImage(id, { width: D, height: D, data: new Uint8Array(x.getImageData(0, 0, D, D).data.buffer) }, { pixelRatio: S });
    });
  }

  function boot() {
    // map.js adopts the instance built above; with none, importing it only
    // throws. The message reportNoGpu() left in #map stands on its own.
    if (!window.__ccMapInstance) { return; }
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
