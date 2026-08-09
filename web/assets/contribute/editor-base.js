// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Base-layer + street-level controls for the two CONTRIBUTE maps (add-climb's
   climb editor and improve's locate map).

   /map has had satellite and Mapillary for a long time; the editors — the one
   place a rider is actually tracing a road — had neither, so you drew a climb
   over a flat vector basemap with no way to see the hairpins or check what the
   road actually looks like (owner-reported 2026-08-07).

   Deliberately NOT a reuse of map/mapillary.js: that module binds the /map
   singleton (`import { map } from './map-init.js'`) and carries the viewer
   dock, its resize/fullscreen behaviour and the i18n plumbing that goes with
   it. An editor wants the coverage lines as a TRACING REFERENCE, so this is
   the layers plus a click-through — clicking a dot opens the image on
   mapillary.com rather than embedding a second viewer inside a wizard step.

   Loaded as a plain <script> like the other contribute editors, so it hangs off
   the same window.Cc namespace rather than being an ES module. */
(function () {
  'use strict';
  window.Cc = window.Cc || {};

  var MLY_GREEN = '#05CB63';

  /** True when a real Mapillary token is configured, as opposed to the placeholder. */
  function mapillaryEnabled(token) {
    return /^MLY\|/.test(token || '') && !/PASTE_TOKEN_HERE/.test(token || '');
  }

  /**
   * Adds a satellite base and (when configured) a Mapillary coverage overlay to
   * an editor map, plus the on-map control that switches them.
   *
   * Call AFTER the style has loaded — sources cannot be added before that.
   *
   * @param {object} map           a MapLibre map
   * @param {object} [opts]
   * @param {string} [opts.token]  Mapillary client token
   * @param {object} [opts.labels] {map, satellite, street} display strings
   */
  function mountBaseControl(map, opts) {
    var o = opts || {};
    var labels = o.labels || {};
    var token = o.token || '';
    var streetOn = false;

    // Esri World Imagery, keyed since 2026-08-09 — see map-init.js's
    // addSatellite() for why the keyless path had to go. No key, no source:
    // the Satellite toggle then has nothing to show, which is the same
    // behaviour a lapsed key produces.
    var esriKey = window.CC_ESRI_KEY || '';
    if (!map.getSource('ed-satellite') && esriKey) {
      map.addSource('ed-satellite', {
        type: 'raster', tileSize: 256, maxzoom: 19,
        tiles: ['https://ibasemaps-api.arcgis.com/arcgis/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}?token='
          + encodeURIComponent(esriKey)],
        attribution: 'Imagery © Esri, Maxar, Earthstar Geographics',
      });
      // NO beforeId, matching /map's addSatellite(). Inserting it before the
      // style's first layer buries it under the basemap's own opaque land and
      // background fills, so the imagery loads, bills tiles and renders
      // nothing. It goes on top of the basemap instead; everything the editor
      // draws is added after this and therefore stays above it.
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

      // Open the photo on mapillary.com, in a NEW TAB on purpose: the rider is
      // mid-wizard with unsaved geometry, and navigating away from a half-drawn
      // climb to look at a photo would lose the drawing.
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

    // Without a key there is no ed-satellite layer, and setLayoutProperty on a
    // layer MapLibre does not have throws — so this used to be one missing env
    // var away from breaking the editor's base toggle outright, not merely
    // leaving it inert. The button is removed rather than guarded silently.
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
