// SPDX-License-Identifier: AGPL-3.0-only
/* Satellite + Mapillary coverage for contribute editor maps. Not a reuse of
   map/mapillary.js (that module binds the /map singleton). Click opens
   mapillary.com in a new tab so unsaved geometry is not lost. */
(function () {
  'use strict';
  window.Cc = window.Cc || {};

  var MLY_GREEN = '#05CB63';

  function mapillaryEnabled(token) {
    return /^MLY\|/.test(token || '') && !/PASTE_TOKEN_HERE/.test(token || '');
  }

  function mountBaseControl(map, opts) {
    var o = opts || {};
    var labels = o.labels || {};
    var token = o.token || '';
    var streetOn = false;

    // No Esri key, no source: the Satellite toggle then has nothing to show.
    var esriKey = window.CC_ESRI_KEY || '';
    if (!map.getSource('ed-satellite') && esriKey) {
      map.addSource('ed-satellite', {
        type: 'raster', tileSize: 256, maxzoom: 19,
        tiles: ['https://ibasemaps-api.arcgis.com/arcgis/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}?token='
          + encodeURIComponent(esriKey)],
        attribution: 'Imagery © Esri, Maxar, Earthstar Geographics',
      });
      // No beforeId: inserting before the style's first layer buries imagery under the basemap.
      map.addLayer({
        id: 'ed-satellite', type: 'raster', source: 'ed-satellite',
        layout: { visibility: 'none' },
      });
    }

    var mly = mapillaryEnabled(token);
    if (mly && !map.getSource('ed-mly')) {
      map.addSource('ed-mly', {
        type: 'vector', minzoom: 6, maxzoom: 14,
        tiles: ['https://tiles.mapillary.com/maps/vtp/mly1_public/2/{z}/{x}/{y}?access_token=' + token],
      });
      map.addLayer({
        id: 'ed-mly-cov', type: 'line', source: 'ed-mly', 'source-layer': 'sequence',
        layout: { visibility: 'none', 'line-cap': 'round', 'line-join': 'round' },
        paint: {
          'line-color': MLY_GREEN, 'line-opacity': 0.7,
          'line-width': ['interpolate', ['linear'], ['zoom'], 10, 1.5, 14, 3, 16, 4],
        },
      });
      map.addLayer({
        id: 'ed-mly-img', type: 'circle', source: 'ed-mly', 'source-layer': 'image',
        layout: { visibility: 'none' },
        paint: {
          'circle-color': MLY_GREEN, 'circle-opacity': 0.9,
          'circle-stroke-color': '#0b3d22', 'circle-stroke-width': 1,
          'circle-radius': ['interpolate', ['linear'], ['zoom'], 13, 2, 16, 4, 19, 6],
        },
      });

      map.on('click', 'ed-mly-img', function (e) {
        var f = e.features && e.features[0];
        var id = f && f.properties && f.properties.id;
        if (id) {
          window.open('https://www.mapillary.com/app/?pKey=' + encodeURIComponent(id) + '&focus=photo',
            '_blank', 'noopener');
        }
      });
      ['ed-mly-img', 'ed-mly-cov'].forEach(function (id) {
        map.on('mouseenter', id, function () { if (streetOn) map.getCanvas().style.cursor = 'pointer'; });
        map.on('mouseleave', id, function () { map.getCanvas().style.cursor = ''; });
      });
    }

    var ctrl = document.createElement('div');
    ctrl.className = 'ed-base maplibregl-ctrl maplibregl-ctrl-group';

    function mk(text) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = text;
      b.title = text;
      return b;
    }

    var bMap = mk(labels.map || 'Map');
    var bSat = mk(labels.satellite || 'Satellite');
    bMap.classList.add('on');

    // setLayoutProperty on a missing layer throws — hide the button instead.
    if (!map.getLayer('ed-satellite')) bSat.hidden = true;

    function setBase(sat) {
      if (!map.getLayer('ed-satellite')) return;
      map.setLayoutProperty('ed-satellite', 'visibility', sat ? 'visible' : 'none');
      bMap.classList.toggle('on', !sat);
      bSat.classList.toggle('on', sat);
      ctrl.classList.toggle('is-sat', sat);
    }

    bMap.addEventListener('click', function () { setBase(false); });
    bSat.addEventListener('click', function () { setBase(true); });
    ctrl.appendChild(bMap);
    ctrl.appendChild(bSat);

    if (mly) {
      var bStreet = mk(labels.street || 'Street-level');
      bStreet.classList.add('ed-base-street');
      bStreet.setAttribute('aria-pressed', 'false');
      bStreet.addEventListener('click', function () {
        streetOn = !streetOn;
        ['ed-mly-cov', 'ed-mly-img'].forEach(function (id) {
          if (map.getLayer(id)) {
            map.setLayoutProperty(id, 'visibility', streetOn ? 'visible' : 'none');
          }
        });
        bStreet.classList.toggle('on', streetOn);
        bStreet.setAttribute('aria-pressed', streetOn ? 'true' : 'false');
      });
      ctrl.appendChild(bStreet);
    }

    map.addControl({
      onAdd: function () { return ctrl; },
      onRemove: function () { ctrl.remove(); },
    }, 'top-left');

    return { setBase: setBase };
  }

  window.Cc.mountEditorBase = mountBaseControl;
  window.Cc.mapillaryEnabled = mapillaryEnabled;
})();
