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
    // Marker labels (foot/summit/steepest) — translatable (i18n Task 5).
    // Precedence: explicit opts.labels > a template-set global (both the
    // add-climb and improve templates emit window.CC_EDITOR_LABELS from the
    // 'js.climb_marker_*' catalog keys) > this English default.
    var labels = opts.labels || (typeof window !== 'undefined' && window.CC_EDITOR_LABELS) || {
      foot: 'START · foot', summit: 'END · summit', steepest: 'STEEPEST'
    };

    var state = { start: null, summit: null, steep: null, steepPoint: null, route: [], grad: [], avg: '', lengthKm: 0 };
    var footM = null, summitM = null, steepM = null, riderM = null;
    /* When armed, the next map tap places the RIDER's steepest point instead of
       moving our derived marker. A mode rather than a fourth tap in the
       sequence, because it is optional and repeatable: most climbs never get
       one, and a rider who places one usually wants to nudge it afterwards. */
    var placingRider = false;
    var routeSeq = 0;
    var profileSeq = 0;
    var routeCtl = null;      // AbortController for the in-flight OSRM request
    var profileCtl = null;    // AbortController for the in-flight elevation request
    var routing = false;      // OSRM route request in flight
    var profiling = false;    // elevation profile request in flight
    var routeError = false;   // last OSRM attempt failed — the straight line stayed
    var profileError = false; // last elevation attempt failed — no gradient profile
    var FETCH_TIMEOUT_MS = 10000;
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
      if (state.start) { footM = mkMarker(state.start, 'foot', true, labels.foot); bindDrag(footM, onFootMoved); }
      if (state.summit) { summitM = mkMarker(state.summit, 'summit', true, labels.summit); bindDrag(summitM, onSummitMoved); }
      if (state.steep) { steepM = mkMarker(state.steep.at, 'steep', true, labels.steepest + ' · ' + state.steep.pct); bindDrag(steepM, onSteepDragged); }
      // storage order [lat,lng] -> editor order [lng,lat], as for route/steep.
      if (initial.steepPoint && initial.steepPoint.at) {
        state.steepPoint = { at: [initial.steepPoint.at[1], initial.steepPoint.at[0]],
                             pct: initial.steepPoint.pct || '', note: initial.steepPoint.note || '' };
        placeRiderMarker();
      }
      drawLine();
      /* Put the climb on screen. The map's centre comes from whatever the page
         could work out before this ran — the item's coordinates if the caller
         put them in the URL, otherwise a generic regional centre — and a
         generic centre means the rider opens the editor looking at countryside
         with their climb somewhere off screen, and has to zoom out hunting for
         it (owner-reported 2026-08-04). We have the actual geometry here, so
         nothing else should be deciding the view. */
      fitToRoute();
    }

    /** Frame the drawn climb, with room for the marker labels. */
    function fitToRoute() {
      if (state.route.length < 2) return;
      var lngs = state.route.map(function (p) { return p[0]; });
      var lats = state.route.map(function (p) { return p[1]; });
      map.fitBounds(
        [[Math.min.apply(null, lngs), Math.min.apply(null, lats)],
          [Math.max.apply(null, lngs), Math.max.apply(null, lats)]],
        { padding: 70, duration: 0, maxZoom: 15 }
      );
    }

    /* ---------- undo ----------

       Placing a climb is three separate acts (foot, summit, steepest) plus any
       number of drag corrections, and the only escape used to be Reset — start
       the whole climb again because you nudged the summit 40 m too far. Undo
       restores the previous state; each act pushes one snapshot first.

       Snapshots are values, not markers: the markers are rebuilt from state on
       restore, so an undone drag cannot leave a stale pin behind. `grad`/route
       are copied because the OSRM/elevation resolves mutate them in place. */
    var history = [];
    var HISTORY_MAX = 25;

    function snapshot() {
      history.push({
        start: state.start ? state.start.slice() : null,
        summit: state.summit ? state.summit.slice() : null,
        steep: state.steep ? { at: state.steep.at.slice(), pct: state.steep.pct, manual: state.steep.manual } : null,
        route: state.route.map(function (c) { return c.slice(); }),
        grad: state.grad.slice(),
        avg: state.avg,
        steepPoint: state.steepPoint ? { at: state.steepPoint.at.slice(), pct: state.steepPoint.pct, note: state.steepPoint.note } : null,
        lengthKm: state.lengthKm
      });
      if (history.length > HISTORY_MAX) history.shift();
      if (opts.onHistory) opts.onHistory(history.length);
    }

    /** Rebuild every marker from state — the one way markers get (re)created. */
    function remarkers() {
      if (footM) { footM.remove(); footM = null; }
      if (summitM) { summitM.remove(); summitM = null; }
      if (steepM) { steepM.remove(); steepM = null; }
      if (state.start) { footM = mkMarker(state.start, 'foot', true, labels.foot); bindDrag(footM, onFootMoved); }
      if (state.summit) { summitM = mkMarker(state.summit, 'summit', true, labels.summit); bindDrag(summitM, onSummitMoved); }
      if (state.steep) { steepM = mkMarker(state.steep.at, 'steep', true, labels.steepest + (state.steep.pct ? ' · ' + state.steep.pct : '')); bindDrag(steepM, onSteepDragged); }
      placeRiderMarker();
    }

    function undo() {
      if (!history.length) return false;
      // Anything in flight belongs to the state being undone: invalidate it, or
      // a late resolve repaints the gradient of a route that no longer exists.
      routeSeq++; profileSeq++;
      abortRoute();
      abortProfile();
      routeError = false; profileError = false;
      var prev = history.pop();
      state.start = prev.start; state.summit = prev.summit; state.steep = prev.steep;
      state.route = prev.route; state.grad = prev.grad; state.lengthKm = prev.lengthKm;
      state.avg = prev.avg;
      state.steepPoint = prev.steepPoint;
      placeRiderMarker();
      remarkers();
      drawLine();
      writeHidden();
      if (opts.onHistory) opts.onHistory(history.length);
      return true;
    }

    /* ---------- clicks: foot, then summit, then (optional) steepest ---------- */
    function onMapClick(e) {
      var ll = [e.lngLat.lng, e.lngLat.lat];
      snapshot();
      if (!state.start) {
        state.start = ll;
        footM = mkMarker(ll, 'foot', true, labels.foot);
        bindDrag(footM, onFootMoved);
        onPointsChanged();
      } else if (!state.summit) {
        state.summit = ll;
        summitM = mkMarker(ll, 'summit', true, labels.summit);
        bindDrag(summitM, onSummitMoved);
        onPointsChanged();
      } else if (placingRider) {
        setRiderPoint(ll);
      } else {
        setManualSteep(ll);
      }
    }

    function onFootMoved(ll) { state.start = ll; onPointsChanged(); }
    function onSummitMoved(ll) { state.summit = ll; onPointsChanged(); }

    function onPointsChanged() {
      // The route is about to change: the previous profile's sampler describes
      // a climb that no longer exists.
      sustainedAtSteep = null;
      // Any endpoint change invalidates whatever profile is still in flight —
      // a late resolve must not apply the OLD route's grad/steep to the new one.
      profileSeq++;
      abortProfile();
      if (state.start && state.summit) {
        // Immediate straight-line feedback; replaced by the OSRM route on success.
        state.route = [state.start, state.summit];
        state.lengthKm = haversineKm(state.start, state.summit);
        drawLine();
        routeClimb(); // before writeHidden so onChange already sees `routing`
        writeHidden();
      } else {
        routeSeq++;
        abortRoute();
        routeError = false;
        profileError = false;
        state.route = [];
        state.lengthKm = 0;
        drawLine();
        writeHidden();
      }
    }

    function abortRoute() {
      routing = false;
      if (routeCtl) { routeCtl.abort(); routeCtl = null; }
    }

    function abortProfile() {
      profiling = false;
      if (profileCtl) { profileCtl.abort(); profileCtl = null; }
    }

    /* ---------- routing (OSRM, copied from add-climb.js's routeClimb) ---------- */
    function routeClimb() {
      var seq = ++routeSeq;
      abortRoute();
      var ctl = routeCtl = new AbortController();
      var timer = setTimeout(function () { ctl.abort(); }, FETCH_TIMEOUT_MS);
      routing = true;
      routeError = false;
      var a = state.start, b = state.summit;
      /* OUR Valhalla via /contribute/route, not the public OSRM demo server —
         its policy forbids production reliance, and it was the one third party
         in a rider's hot path (external-systems audit 2026-08-09). The proxy
         answers in OSRM's response shape on purpose, so the parsing and the
         no-route branch below are unchanged. Bicycle costing now, too: driving
         refused the greenways some climbs actually ride. */
      fetch('/contribute/route', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ a: a, b: b }),
        signal: ctl.signal
      }).then(function (r) {
        if (!r.ok) throw new Error('route HTTP ' + r.status);
        return r.json();
      }).then(function (d) {
        clearTimeout(timer);
        if (seq !== routeSeq || !state.start || !state.summit) return;
        routing = false;
        if (d.code !== 'Ok' || !d.routes || !d.routes[0]) {
          // No route between the points — keep the straight line, but say so.
          routeError = true;
          writeHidden();
          return;
        }
        var route = d.routes[0];
        state.route = route.geometry.coordinates;
        state.lengthKm = route.distance / 1000;
        drawLine();
        recomputeProfile(); // before writeHidden so onChange already sees `profiling`
        writeHidden();
      }).catch(function () {
        clearTimeout(timer);
        if (seq !== routeSeq) return; // superseded (aborted by a newer request/reset)
        // Network failure or timeout — the straight line stays; surface it via onChange.
        routing = false;
        routeError = true;
        writeHidden();
      });
    }

    /* ---------- elevation-driven gradient profile + steepest placement ---------- */
    function recomputeProfile() {
      var seq = ++profileSeq;
      abortProfile();
      if (state.route.length < 2) return; // nothing to profile (reset raced the route)
      var ctl = profileCtl = new AbortController();
      var timer = setTimeout(function () { ctl.abort(); }, FETCH_TIMEOUT_MS);
      profiling = true;
      profileError = false;
      var manualAt = (state.steep && state.steep.manual) ? state.steep.at : null;
      window.Cc.profileFromRoute(state.route, ctl.signal, manualAt).then(function (res) {
        clearTimeout(timer);
        if (seq !== profileSeq) return; // a newer route/profile superseded this one
        profiling = false;
        if (!res) {
          state.grad = [];
          // The marker stays. Same rule as above: the position is a fact about
          // the climb, and a profile we could not fetch is not a reason to
          // delete it — only the % cannot be refreshed without a gradient.
        } else {
          state.grad = res.grad;
          state.avg = res.avg;
          /* An existing steepest marker STAYS PUT while the route changes.

             It used to be re-derived from the profile on every resolve unless
             the rider had placed it by hand, so dragging the summit a little
             further up the road made the steepest ramp jump to somewhere else
             on the climb — the rider had changed one end and watched a
             different marker move (owner-reported 2026-08-03). Extending a
             climb does not relocate its steepest ramp; only the numbers around
             it change, so the position is kept and the % is re-read from the
             new profile at that fixed position.

             The one case that must move it is the route no longer passing it:
             shorten the climb past the steepest ramp and the marker would
             otherwise float beside a road that is no longer part of it. Then,
             and only then, it is re-derived — including a hand-placed one,
             because a marker stranded off the climb is wrong however it got
             there. */
          // The server re-read the hand-placed marker's gradient at its own
          // position, so the drag path below has a fresh number without a
          // second round trip.
          sustainedAtSteep = res.sustainedAtSteep || null;
          /* A marker the RIDER placed is theirs and stays put; an automatic one
             is re-derived from the new profile every time the line changes.

             This is climb-elevation.md 5: the steepest ramp is found, not
             placed, and "an automatic marker is re-derived whenever the line
             changes". The code used to keep an automatic marker where it was
             and only re-read its percentage, which is why redrawing
             Roche-aux-Faucons from 1.75 km to 4.35 km left the marker on the
             old ramp still reading ~11% when the new line's steepest 100 m is
             nearly 18% (owner-reported 2026-08-04).

             That rule was added for a real reason - dragging an endpoint made
             the number drift, 19% to 10% to 16%, which is what a measurement
             artefact looks like rather than news about the road. But the fix
             for an artefact is to stop producing it, not to freeze the value on
             top of it. Both causes are now addressed: the maximum comes from a
             fixed ~150 m window rather than the display bars, whose width
             follows the climb's length, and the average is binned before it is
             summed. What is left moves because the ROAD changed, which is
             exactly what a rider redrawing a line is telling us.

             A hand-placed marker still survives a redraw, and is only
             re-derived when the route no longer passes it - a marker stranded
             beside a road that is no longer part of the climb is wrong however
             it got there. */
          if (state.steep && state.steep.manual && steepStillOnRoute()) {
            state.steep.pct = sustainedAtSteep || state.steep.pct;
          } else {
            state.steep = { at: res.steep.at, pct: res.steep.pct, manual: false };
          }
        }
        placeSteepMarker();
        writeHidden();
      }).catch(function () {
        clearTimeout(timer);
        if (seq !== profileSeq) return; // superseded (aborted by a newer request/reset)
        // Elevation API failed/timed out — no profile for this route; surface it via onChange.
        profiling = false;
        profileError = true;
        state.grad = [];
        // Marker kept, as above — a timed-out elevation API must not silently
        // erase the steepest ramp the rider can see on their screen.
        placeSteepMarker();
        writeHidden();
      });
    }

    function placeSteepMarker() {
      if (!state.steep) {
        if (steepM) { steepM.remove(); steepM = null; }
        return;
      }
      var label = labels.steepest + ' · ' + state.steep.pct;
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
      state.steep = { at: ll, pct: steepPctAt(ll), manual: true };
      placeSteepMarker();
      writeHidden();
    }

    /* The rider's steepest point — where the wall actually is.

       Kept apart from `state.steep` on purpose. Ours is the steepest sustained
       100 m the elevation model can see, measured identically on every climb,
       which is what makes it comparable. Theirs is knowledge the model does not
       have: a hairpin smaller than one DEM cell is invisible at any window
       width, so no amount of computing recovers Mur de Huy's 26%
       (climb-elevation.md 5a). It never moves on its own — only the rider
       places, drags or clears it. */
    function setRiderPoint(ll, pct, note) {
      state.steepPoint = {
        at: ll,
        pct: pct !== undefined ? pct : (state.steepPoint ? state.steepPoint.pct : ''),
        note: note !== undefined ? note : (state.steepPoint ? state.steepPoint.note : '')
      };
      placingRider = false;
      placeRiderMarker();
      writeHidden();
    }

    function clearRiderPoint() {
      snapshot();
      state.steepPoint = null;
      placingRider = false;
      placeRiderMarker();
      writeHidden();
    }

    function armRiderPoint() {
      placingRider = true;
      if (onChange) onChange(publicState());
    }

    function placeRiderMarker() {
      if (riderM) { riderM.remove(); riderM = null; }
      if (!state.steepPoint) return;
      riderM = mkMarker(state.steepPoint.at, 'rider', true,
        (labels.riderSteep || 'STEEPEST POINT') + (state.steepPoint.pct ? ' · ' + state.steepPoint.pct : ''));
      bindDrag(riderM, function (ll) { snapshot(); setRiderPoint(ll); });
    }

    /* Is the steepest marker still ON the climb?

       Measured against the nearest route vertex. OSRM returns a dense line
       (~40 m between vertices on a 2 km climb), so a marker still on the road
       sits well inside the tolerance while one left behind by a shortened
       route is hundreds of metres out. */
    var STEEP_ON_ROUTE_KM = 0.1;

    function steepStillOnRoute() {
      if (!state.steep || state.route.length < 2) return false;
      var best = Infinity;
      for (var i = 0; i < state.route.length; i++) {
        var d = haversineKm(state.route[i], state.steep.at);
        if (d < best) best = d;
      }
      return best <= STEEP_ON_ROUTE_KM;
    }

    /* The gradient to print beside the steepest marker.

       While DRAGGING there is no fresh measurement to read: the sustained
       window is computed server-side now, so the exact figure arrives with the
       next profile rather than under the cursor. The bar lookup gives the
       rider an immediate approximate number so the label is never blank, and
       recomputeProfile() replaces it with the measured one — asking the server
       on every drag frame would be a request per pixel. */
    var sustainedAtSteep = null;

    function steepPctAt(ll) {
      return nearestGradPct(ll);
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
      // Fraction by DISTANCE along the route, not by vertex index: OSRM packs
      // vertices tightly through curves, so index/total is not where you are on
      // the climb and picked the wrong bar whenever the shape changed.
      var travelled = 0, total = 0;
      for (var j = 1; j < state.route.length; j++) {
        var seg = haversineKm(state.route[j - 1], state.route[j]);
        total += seg;
        if (j <= bestI) travelled += seg;
      }
      var frac = total > 0 ? travelled / total : 0;
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
      // Derived, never typed - see climb-elevation.md 4 and ascentOnlyAverage().
      if (hidden.avg) hidden.avg.value = state.avg || '';
      if (hidden.steepPoint) hidden.steepPoint.value = state.steepPoint
        ? JSON.stringify({ at: [state.steepPoint.at[1], state.steepPoint.at[0]],
                           pct: state.steepPoint.pct || '', note: state.steepPoint.note || '' })
        : '';
      if (hidden.steep) hidden.steep.value = state.steep
        ? JSON.stringify({ at: [state.steep.at[1], state.steep.at[0]], pct: state.steep.pct, manual: !!state.steep.manual })
        : '';
      if (onChange) onChange(publicState());
    }

    function publicState() {
      return {
        start: state.start, summit: state.summit, steep: state.steep, lengthKm: state.lengthKm, avg: state.avg,
        steepPoint: state.steepPoint, placingRider: placingRider,
        // In-flight/failure signals so the host wizard can gate Next/Submit and
        // tell the contributor when snapping or the gradient profile failed.
        routing: routing, profiling: profiling,
        routeError: routeError, profileError: profileError
      };
    }

    /* ---------- reset / destroy ---------- */
    function reset() {
      sustainedAtSteep = null;
      // Reset is undoable too — it is the most expensive mistake on this
      // editor, and "I meant to move the summit" should not cost the climb.
      snapshot();
      // Invalidate + abort anything in flight: a late OSRM/elevation resolve
      // must not repopulate grad/steep on the now-empty map.
      routeSeq++; profileSeq++;
      abortRoute();
      abortProfile();
      routeError = false; profileError = false;
      state.start = null; state.summit = null; state.steep = null;
      state.route = []; state.grad = []; state.lengthKm = 0;
      if (footM) { footM.remove(); footM = null; }
      if (summitM) { summitM.remove(); summitM = null; }
      if (steepM) { steepM.remove(); steepM = null; }
      drawLine();
      writeHidden();
    }

    function destroy() {
      routeSeq++; profileSeq++;
      abortRoute();
      abortProfile();
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
      // Snapshot on dragSTART: by dragend the marker is already at its new
      // position, and a drag is precisely the mistake Undo exists for.
      marker.on('dragstart', snapshot);
      marker.on('dragend', function () {
        var ll = marker.getLngLat();
        handler([ll.lng, ll.lat]);
      });
    }

    function mkMarker(ll, cls, draggable, labelText) {
      // 'rider' is the contributor's own steepest point — amber, and a filled
      // glyph rather than a triangle, so it is never mistaken for our derived
      // marker sitting beside it (climb-elevation.md 5a).
      var fill = cls === 'summit' ? '#FF5A1F' : cls === 'steep' ? '#D92D20' : cls === 'rider' ? '#C98A22' : '#1C3A2A';
      var glyph = cls === 'steep'
        ? '<text x="12" y="15" text-anchor="middle" font-size="10" font-weight="bold" fill="#EFE6D4">▲</text>'
        : cls === 'rider'
          ? '<text x="12" y="15.5" text-anchor="middle" font-size="10" font-weight="bold" fill="#14160E">⬗</text>'
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

    return {
      destroy: destroy, reset: reset, undo: undo,
      canUndo: function () { return history.length > 0; },
      // The rider's steepest point: arm a placement, drag it, clear it. Exposed
      // rather than driven by a fourth tap because it is optional and
      // repeatable — most climbs never get one (climb-elevation.md 5a).
      markSteepestPoint: armRiderPoint,
      clearSteepestPoint: clearRiderPoint,
      setSteepestPoint: setRiderPoint
    };
  };
})();
