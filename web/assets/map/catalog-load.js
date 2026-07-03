// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Boots the map from the catalog endpoint: fetches /map/catalog.json (URL
   handed over by the template as window.CC_CATALOG_URL), exposes the layers
   as the window.CC_* globals map.js has always consumed, applies the stays
   merge (absorbed from the retired stays-merge.js), then injects map.js
   (AssetMapper-digested URL in window.CC_MAP_SRC). On fetch failure the map
   still boots — layer guards degrade to an empty catalog, and the curator
   pending layer (inline-injected) keeps working. */
(function () {
  function boot() {
    var s = document.createElement('script');
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
      window.CC_TRANSIT_OSM = d.G;
      window.CC_SHELTER_OSM = d.H;
      window.CC_SCENIC_OSM = d.I;
      window.CC_HISTORY_OSM = d.J;
      window.CC_ROUTES = { routes: d.K, heat: d.L };
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
