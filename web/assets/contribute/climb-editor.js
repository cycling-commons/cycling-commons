// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
(function () {
  'use strict';
  window.Cc = window.Cc || {};

  var SOURCE_ID = 'cc-climb-editor-route';
  var LAYER_ID = 'cc-climb-editor-route';

  // Mounts the shared three-point climb editor (foot/summit/steepest) on an
  // existing MapLibre map. Internal coordinates are always OSRM/MapLibre order
  // [lng,lat]; the stored/hidden-field order [lat,lng] only applies at the two
  // boundaries: writeHidden() (out) and hydrate() (in).
  window.Cc.mountClimbEditor = function (opts) {
    var map = opts.map;
    var hidden = opts.hidden || {};
    var onChange = opts.onChange;

    var state = { start: null, summit: null, steep: null, route: [], grad: [], lengthKm: 0 };
    var footM = null, summitM = null, steepM = null;
    var routeSeq = 0;
    var profileSeq = 0;
    var ready = false;

    initSource();
    if (opts.initial) hydrate(opts.initial);
    map.on('click', onMapClick);
    writeHidden();

    /* ---------- map source/layer for the drawn track ---------- */
    function initSource() {
      try {
        if (!map.getSource(SOURCE_ID)) {
          map.addSource(SOURCE_ID, { type: 'geojson', data: emptyLine() });
          map.addLayer({
            id: LAYER_ID, type: 'line', source: SOURCE_ID,
            layout: { 'line-cap': 'round', 'line-join': 'round' },
            paint: { 'line-color': '#FF5A1F', 'line-width': 4 }
          });
        }
        ready = true;
        drawLine();
      } catch (e) {
        map.once('load', initSource);
      }
    }

    function emptyLine() {
      return { type: 'Feature', geometry: { type: 'LineString', coordinates: [] } };
    }

    function drawLine() {
      if (!ready) return;
      var src = map.getSource(SOURCE_ID);
      if (!src) return;
      src.setData({ type: 'Feature', geometry: { type: 'LineString', coordinates: state.route } });
    }

    /* ---------- hydrate from opts.initial (edit flow) ---------- */
    // initial.route / initial.steep.at are stored order [lat,lng]; reverse to [lng,lat].
    function hydrate(initial) {
      if (initial.route && initial.route.length) {
        state.route = initial.route.map(function (c) { return [c[1], c[0]]; });
        state.start = state.route[0];
        state.summit = state.route[state.route.length - 1];
        state.lengthKm = routeLengthKm(state.route);
      }
      if (initial.grad) state.grad = initial.grad.slice();
      if (initial.steep && initial.steep.at) {
        state.steep = {
          at: [initial.steep.at[1], initial.steep.at[0]],
          pct: initial.steep.pct,
          manual: !!initial.steep.manual
        };
      }
      if (state.start) { footM = mkMarker(state.start, 'foot', true, 'START · foot'); bindDrag(footM, onFootMoved); }
      if (state.summit) { summitM = mkMarker(state.summit, 'summit', true, 'END · summit'); bindDrag(summitM, onSummitMoved); }
      if (state.steep) { steepM = mkMarker(state.steep.at, 'steep', true, 'STEEPEST · ' + state.steep.pct); bindDrag(steepM, onSteepDragged); }
      drawLine();
    }

    /* ---------- clicks: foot, then summit, then (optional) steepest ---------- */
    function onMapClick(e) {
      var ll = [e.lngLat.lng, e.lngLat.lat];
      if (!state.start) {
        state.start = ll;
        footM = mkMarker(ll, 'foot', true, 'START · foot');
        bindDrag(footM, onFootMoved);
        onPointsChanged();
      } else if (!state.summit) {
        state.summit = ll;
        summitM = mkMarker(ll, 'summit', true, 'END · summit');
        bindDrag(summitM, onSummitMoved);
        onPointsChanged();
      } else {
        setManualSteep(ll);
      }
    }

    function onFootMoved(ll) { state.start = ll; onPointsChanged(); }
    function onSummitMoved(ll) { state.summit = ll; onPointsChanged(); }

    function onPointsChanged() {
      if (state.start && state.summit) {
        // Immediate straight-line feedback; replaced by the OSRM route on success.
        state.route = [state.start, state.summit];
        state.lengthKm = haversineKm(state.start, state.summit);
        drawLine();
        writeHidden();
        routeClimb();
      } else {
        state.route = [];
        state.lengthKm = 0;
        drawLine();
        writeHidden();
      }
    }

    /* ---------- routing (OSRM, copied from add-climb.js's routeClimb) ---------- */
    function routeClimb() {
      var seq = ++routeSeq;
      var a = state.start, b = state.summit;
      var url = 'https://router.project-osrm.org/route/v1/driving/' + a[0] + ',' + a[1] + ';' + b[0] + ',' + b[1] + '?overview=full&geometries=geojson';
      fetch(url).then(function (r) { return r.json(); }).then(function (d) {
        if (seq !== routeSeq || !state.start || !state.summit) return;
        if (d.code !== 'Ok' || !d.routes || !d.routes[0]) return;
        var route = d.routes[0];
        state.route = route.geometry.coordinates;
        state.lengthKm = route.distance / 1000;
        drawLine();
        recomputeProfile();
      }).catch(function () {});
    }

    /* ---------- elevation-driven gradient profile + steepest placement ---------- */
    function recomputeProfile() {
      var seq = ++profileSeq;
      window.Cc.profileFromRoute(state.route).then(function (res) {
        if (seq !== profileSeq) return; // a newer route/profile superseded this one
        if (!res) {
          state.grad = [];
          if (!state.steep || !state.steep.manual) state.steep = null;
          // manual steep: position stays; pct keeps its prior value (no grad to sample).
        } else {
          state.grad = res.grad;
          if (!state.steep || !state.steep.manual) {
            state.steep = { at: res.steep.at, pct: res.steep.pct, manual: false };
          } else {
            // manual: never move it, but refresh its % from the new grad at its fixed position.
            state.steep.pct = nearestGradPct(state.steep.at) || state.steep.pct;
          }
        }
        placeSteepMarker();
        writeHidden();
      });
    }

    function placeSteepMarker() {
      if (!state.steep) {
        if (steepM) { steepM.remove(); steepM = null; }
        return;
      }
      var label = 'STEEPEST · ' + state.steep.pct;
      if (steepM) {
        steepM.setLngLat(state.steep.at);
        setLabel(steepM, label);
      } else {
        steepM = mkMarker(state.steep.at, 'steep', true, label);
        bindDrag(steepM, onSteepDragged);
      }
    }

    /* ---------- steepest lock: drag or re-tap sets manual, stops auto-updates ---------- */
    function onSteepDragged(ll) { setManualSteep(ll); }

    function setManualSteep(ll) {
      state.steep = { at: ll, pct: nearestGradPct(ll), manual: true };
      placeSteepMarker();
      writeHidden();
    }

    // Maps a point to the nearest route vertex -> that fraction along the route
    // -> the corresponding grad bar -> '~N%'. Keeps the prior pct if grad is empty.
    function nearestGradPct(ll) {
      if (!state.grad.length || state.route.length < 2) {
        return state.steep && state.steep.pct ? state.steep.pct : '';
      }
      var bestI = 0, bestD = Infinity;
      for (var i = 0; i < state.route.length; i++) {
        var d = haversineKm(state.route[i], ll);
        if (d < bestD) { bestD = d; bestI = i; }
      }
      var frac = bestI / (state.route.length - 1);
      var barIdx = Math.min(state.grad.length - 1, Math.floor(frac * state.grad.length));
      return '~' + Math.round(state.grad[barIdx]) + '%';
    }

    function routeLengthKm(route) {
      var km = 0;
      for (var i = 1; i < route.length; i++) km += haversineKm(route[i - 1], route[i]);
      return km;
    }

    /* ---------- write hidden inputs (stored order [lat,lng]) + notify ---------- */
    function writeHidden() {
      if (hidden.route) hidden.route.value = JSON.stringify(state.route.map(function (c) { return [c[1], c[0]]; }));
      if (hidden.grad) hidden.grad.value = JSON.stringify(state.grad);
      if (hidden.steep) hidden.steep.value = state.steep
        ? JSON.stringify({ at: [state.steep.at[1], state.steep.at[0]], pct: state.steep.pct, manual: !!state.steep.manual })
        : '';
      if (onChange) onChange(publicState());
    }

    function publicState() {
      return { start: state.start, summit: state.summit, steep: state.steep, lengthKm: state.lengthKm };
    }

    /* ---------- reset / destroy ---------- */
    function reset() {
      state.start = null; state.summit = null; state.steep = null;
      state.route = []; state.grad = []; state.lengthKm = 0;
      if (footM) { footM.remove(); footM = null; }
      if (summitM) { summitM.remove(); summitM = null; }
      if (steepM) { steepM.remove(); steepM = null; }
      drawLine();
      writeHidden();
    }

    function destroy() {
      map.off('click', onMapClick);
      if (footM) footM.remove();
      if (summitM) summitM.remove();
      if (steepM) steepM.remove();
      try {
        if (map.getLayer(LAYER_ID)) map.removeLayer(LAYER_ID);
        if (map.getSource(SOURCE_ID)) map.removeSource(SOURCE_ID);
      } catch (e) { /* style already torn down */ }
    }

    /* ---------- markers (teardrop SVG, extended from add-climb.js's mkMarker) ---------- */
    function bindDrag(marker, handler) {
      marker.on('dragend', function () {
        var ll = marker.getLngLat();
        handler([ll.lng, ll.lat]);
      });
    }

    function mkMarker(ll, cls, draggable, labelText) {
      var fill = cls === 'summit' ? '#FF5A1F' : cls === 'steep' ? '#D92D20' : '#1C3A2A';
      var glyph = cls === 'steep'
        ? '<text x="12" y="15" text-anchor="middle" font-size="10" font-weight="bold" fill="#EFE6D4">▲</text>'
        : '';
      var wrap = document.createElement('div');
      wrap.className = 'cc-marker';
      wrap.dataset.mkType = cls;
      // MapLibre's .maplibregl-marker CSS sets pointer-events:none on the root
      // (expected for custom elements to opt back in); without this, drag/click
      // on the marker falls through to the map canvas and dragging never engages.
      wrap.style.pointerEvents = 'auto';
      wrap.innerHTML =
        '<svg width="24" height="32" viewBox="0 0 24 32" xmlns="http://www.w3.org/2000/svg">' +
        '<path d="M12 1 C6 1 1 5.6 1 11.4 C1 19 12 31 12 31 C12 31 23 19 23 11.4 C23 5.6 18 1 12 1 Z" ' +
        'fill="' + fill + '" stroke="#EFE6D4" stroke-width="2"/>' +
        '<circle cx="12" cy="11" r="3.4" fill="#EFE6D4"/>' + glyph + '</svg>' +
        '<div class="cc-mk-label"></div>';
      var marker = new maplibregl.Marker({ element: wrap, anchor: 'bottom', draggable: !!draggable }).setLngLat(ll).addTo(map);
      setLabel(marker, labelText);
      return marker;
    }

    function setLabel(marker, text) {
      var el = marker.getElement().querySelector('.cc-mk-label');
      if (el) el.innerHTML = escHtml(text == null ? '' : text);
    }

    function haversineKm(a, b) {
      var R = 6371;
      function toR(x) { return x * Math.PI / 180; }
      var dLat = toR(b[1] - a[1]), dLng = toR(b[0] - a[0]);
      var s = Math.pow(Math.sin(dLat / 2), 2) + Math.cos(toR(a[1])) * Math.cos(toR(b[1])) * Math.pow(Math.sin(dLng / 2), 2);
      return R * 2 * Math.atan2(Math.sqrt(s), Math.sqrt(1 - s));
    }

    function escHtml(str) {
      return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    return { destroy: destroy, reset: reset };
  };
})();
