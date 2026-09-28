// SPDX-License-Identifier: AGPL-3.0-only
/* Boots the map from the catalog endpoints (docs/specs/map-and-search.md §2):
   fetch the regions the rider's scope shows, expose window.CC_* globals,
   stays merge, inject map.js. The MapLibre instance is built here before
   those fetches so the basemap does not queue behind the catalog. */
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

  // Shared by the boot fetch, the region patch and the tab-return refresh.
  // Never mutates `d`: the raw payload is kept and re-applied every time a
  // region is patched into it, so a second pass must not double-append.
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
    // Stays merge: tag the authority's features and hand the map one list, so
    // the drawer can credit their publisher instead of OSM. A fresh collection
    // rather than an append into `d.O.osm`, because `d` is re-applied after
    // every region patch and an append would grow the list each time.
    var O = d.O && d.O.osm, P = d.O && d.O.authority;
    if (O && P) {
      P.features.forEach(function (f) { f.properties.src = 'authority'; });
      window.CC_STAYS_OSM = { type: 'FeatureCollection', features: O.features.concat(P.features) };
    }
  }

  /* Area-first catalog (docs/specs/catalog-data-model.md §9.1).

     The map holds only the regions it shows. Each region is its own small
     document, /map/catalog/region/<rid>.json?v=<stamp>, and the stamps
     document (/map/catalog/stamps.json, revalidated on every read) says which
     of them moved. The first paint waits for the regions of the rider's scope,
     plus the regions a link points into (CC_CATALOG_BOOT). A new scope fetches
     its own regions; a tab that comes back refetches the ones whose stamp
     moved. A rider in the Netherlands downloads the Netherlands, not the
     world. Region 0 is the places no region holds, a slice like any other.

     Nothing downloads the whole world. A worldwide search asks the server
     (/v1/search?q=, search-ui.js) and loads the one region a picked hit lies
     in (CCCatalog.ensureRegion). */
  var BOOT = window.CC_CATALOG_BOOT || { regions: [] };
  var rawCatalog = null;
  var slices = {};          // rid -> the slice last spliced in
  var kept = (BOOT.regions || []).slice();   // held beyond the scope: link targets, picked hits
  var inflight = {};        // 'rid@stamp' -> the fetch of that slice
  var booted = false;

  function emptyLayer() { return { type: 'FeatureCollection', features: [] }; }
  // The payload's shape with no rows, so every consumer reads the same keys
  // however many regions are held.
  function emptyCatalog() {
    var d = { A: [], N: [], R: [], O: { osm: emptyLayer(), authority: emptyLayer() }, refs: [], providers: {}, stamps: {} };
    ['B', 'C', 'D', 'E', 'F', 'G', 'P', 'Q'].forEach(function (L) { d[L] = emptyLayer(); });
    return d;
  }

  function currentScope() {
    return window.CCScope && window.CCScope.get ? window.CCScope.get() : null;
  }
  // The regions the map keeps current: the scope's, plus the ones kept for a
  // link or a picked search hit (their drawer lifts the scope there for a
  // while).
  function wantedRegionIds() {
    var s = currentScope();
    var ids = (s && s.regionIds) ? s.regionIds.slice() : [];
    kept.forEach(function (rid) { if (ids.indexOf(rid) < 0) { ids.push(rid); } });
    return ids;
  }

  // Tile dedupe (coverage-provider.md §6): every ref a held region claims. A
  // ref a region stopped claiming leaves with that region's next slice.
  function collectRefs(d) {
    var seen = {};
    var refs = [];
    Object.keys(slices).forEach(function (k) {
      (slices[k].refs || []).forEach(function (r) { if (!seen[r]) { seen[r] = true; refs.push(r); } });
    });
    d.refs = refs;
  }

  // Replace every row of one region, in each shape the payload uses. Removal
  // comes free: the region's old rows are dropped before the new ones land, so
  // a retired place leaves without needing a tombstone. A row with no `rid`
  // is region 0's.
  function spliceRegion(d, rid, slice) {
    var ridOf = function (x) { return x == null ? 0 : x; };
    var keep = function (list) { return list.filter(function (x) { return x && ridOf(x.rid) !== rid; }); };
    var keepF = function (fc) {
      return fc.features.filter(function (f) { return !f.properties || ridOf(f.properties.rid) !== rid; });
    };
    d.A = keep(d.A).concat(slice.A || []);
    d.N = keep(d.N).concat(slice.N || []);
    d.R = keep(d.R).concat(slice.R || []);
    ['B', 'C', 'D', 'E', 'F', 'G', 'P', 'Q'].forEach(function (L) {
      if (!d[L] || !slice[L]) { return; }
      d[L] = { type: 'FeatureCollection', features: keepF(d[L]).concat(slice[L].features || []) };
    });
    ['osm', 'authority'].forEach(function (k) {
      if (!d.O || !d.O[k] || !slice.O || !slice.O[k]) { return; }
      d.O[k] = { type: 'FeatureCollection', features: keepF(d.O[k]).concat(slice.O[k].features || []) };
    });
    d.providers = slice.providers || d.providers;
    slices[String(rid)] = slice;
    collectRefs(d);
    (d.stamps || (d.stamps = {}))[String(rid)] = slice.stamp;
  }

  function fetchStamps() {
    // No Accept header: the page preloads this URL, and a preload is only
    // reused by a request that asks for it the same way.
    return fetch('/map/catalog/stamps.json')
      .then(function (r) { return r.ok ? r.json() : null; })
      .catch(function () { return null; });
  }

  /* One stamps answer for every caller for STAMPS_REUSE_MS: the boot, the
     area the map restores at start (a cc:scopechange) and the window's first
     focus all asked within a second of each other, three fetches of the same
     2 kB on one page load (owner-reported 2026-09-28). The stamps cover every
     region, so a new area within the window needs no fresh read. A failed
     read is not kept: the next caller tries again. */
  var STAMPS_REUSE_MS = 15000;
  var stampsPromise = null;
  var stampsAt = 0;
  function currentStamps() {
    var now = Date.now();
    if (stampsPromise && now - stampsAt < STAMPS_REUSE_MS) { return stampsPromise; }
    stampsAt = now;
    stampsPromise = fetchStamps().then(function (s) {
      if (!s) { stampsAt = 0; }
      return s;
    });
    return stampsPromise;
  }

  /* Fetch every wanted region whose stamp the payload in hand does not hold:
     a region never loaded, or one that moved. Resolves to true when the
     payload changed. */
  function refreshRegions(stamps) {
    return stamps.then(function (live) {
      if (!live) { return false; }
      var held = rawCatalog.stamps || {};
      // A region with no stamp never held a row: there is nothing to fetch.
      var stale = wantedRegionIds().filter(function (rid) {
        var k = String(rid);
        return live[k] !== undefined && live[k] !== held[k];
      });
      if (!stale.length) { return false; }
      return Promise.all(stale.map(function (rid) { return fetchRegion(rid, live[String(rid)]); }))
        .then(function (got) { return got.indexOf(true) !== -1; });
    }).catch(function () { return false; });
  }

  // One request per slice however many callers want it at once: the boot and
  // the area the map restores at start ask for the same regions together.
  function fetchRegion(rid, stamp) {
    var key = rid + '@' + stamp;
    if (inflight[key]) { return inflight[key]; }
    inflight[key] = fetch('/map/catalog/region/' + rid + '.json?v=' + encodeURIComponent(stamp),
      { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (slice) {
        if (!slice) { return false; }
        spliceRegion(rawCatalog, rid, slice);
        return true;
      })
      .catch(function () { return false; })
      .then(function (spliced) {
        delete inflight[key];
        return spliced;
      });
    return inflight[key];
  }

  function repaint() {
    applyCatalog(rawCatalog);
    if (window.__ccApplyCatalog) { window.__ccApplyCatalog(); }
  }

  function repaintAfter(stamps) {
    return refreshRegions(stamps).then(function (moved) {
      if (moved && booted) { repaint(); }
      return moved;
    });
  }

  /* Hold one more region and resolve once its rows are on the map: a
     worldwide search hit (search-ui.js) opens its place only after this. The
     region stays held for the rest of the visit, like a link's. Resolves to
     false when the region has nothing to load. */
  function ensureRegion(rid) {
    rid = Number(rid) || 0;
    if (kept.indexOf(rid) < 0) { kept.push(rid); }
    return currentStamps().then(function (live) {
      var k = String(rid);
      if (!live || live[k] === undefined) { return false; }
      if ((rawCatalog.stamps || {})[k] === live[k]) { return true; }
      return fetchRegion(rid, live[k]).then(function (spliced) {
        if (spliced && booted) { repaint(); }
        return spliced;
      });
    });
  }

  // For search-ui.js and panels.js, which cannot import this file.
  window.CCCatalog = {
    ensureRegion: ensureRegion,
    // The data-version readout (panels.js): how many regions the map holds.
    readout: function () { return 'area ' + Object.keys(slices).length; }
  };

  rawCatalog = emptyCatalog();
  currentStamps()
    .then(function (live) {
      if (!live) { throw new Error('stamps.json unavailable'); }
      return refreshRegions(Promise.resolve(live));
    })
    .then(function () {
      window.CC_CATALOG_STATE = 'ok';
    }, function (e) {
      window.CC_CATALOG_STATE = 'FAILED: ' + (e && e.message ? e.message : e);
      console.error('Catalog load failed: map layers unavailable.', e);
    })
    .then(function () {
      // Applied and marked booted in one step, so a slice that lands after
      // this repaints and one that landed before is in what this applies.
      applyCatalog(rawCatalog);
      booted = true;
      boot();
    });

  // A new scope draws regions this tab may never have held.
  window.addEventListener('cc:scopechange', function () { repaintAfter(currentStamps()); });

  /* Tab-return: a moderator's loop is approve-in-the-desk-tab, switch back to
     the open map, and that tab asks for nothing on its own. It reads the
     stamps document, two kilobytes, and pulls only the regions on screen
     whose stamp moved, so watching one approval never costs a continent. */
  function recheckCatalog() {
    if (document.visibilityState !== 'visible' || !booted) { return; }
    // At most one read per STAMPS_REUSE_MS, shared with the boot.
    repaintAfter(currentStamps());
  }
  document.addEventListener('visibilitychange', recheckCatalog);
  window.addEventListener('focus', recheckCatalog);
}());
