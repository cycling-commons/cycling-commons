// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Contribute wizard (docs/specs/moderation-and-contribution.md §1).
   No innerHTML: every node is createElement + textContent (or review-card.js).
   textContent cannot produce an element, so rider text and catalogue copy stay
   characters. Markup stays in Twig. (docs/specs/security-architecture.md §4.3) */
(function () {
  'use strict';

  var wiz = document.getElementById('wiz');
  if (!wiz) return;

  /* Rider units (docs/specs/account-and-auth.md §9). Inputs are metric. */
  function uKm(km) { return window.ccKm ? window.ccKm(km) : Number(km).toFixed(1) + ' km'; }

  var _q = new URLSearchParams(location.search);
  var _id = _q.get('item') || '';
  var ADD = _q.get('mode') === 'add';

  /* Item position from the server; `?lat=&lng=` still wins (Fix location bridge). */
  var _itemPos = window.CC_ITEM || {};
  var initLat = parseFloat(_q.get('lat'));
  var initLng = parseFloat(_q.get('lng'));
  if (isNaN(initLat) && typeof _itemPos.lat === 'number') initLat = _itemPos.lat;
  if (isNaN(initLng) && typeof _itemPos.lng === 'number') initLng = _itemPos.lng;
  var hasCoords = !isNaN(initLat) && !isNaN(initLng) && Math.abs(initLat) <= 90 && Math.abs(initLng) <= 180;
  // ?z= from the map's "Add a climb here" (map-and-search.md §8.1); clamped like the server did.
  var initZoom = parseFloat(_q.get('z'));
  if (!isNaN(initZoom)) initZoom = Math.max(3, Math.min(18, initZoom));

  var _pair = function (raw) {
    var p = String(raw || '').split(',');
    if (p.length !== 2) return null;
    var lng = parseFloat(p[0]), lat = parseFloat(p[1]);
    return (isNaN(lng) || isNaN(lat) || lng < -180 || lng > 180 || lat < -90 || lat > 90) ? null : [lng, lat];
  };
  var segA = _pair(_q.get('sa'));
  var segB = _pair(_q.get('sb'));
  var hasSegment = !!(segA && segB);
  var CONFIRM_SEGMENT = _q.get('confirm') === '1' && hasSegment;
  var CONFIRM_POINT = _q.get('confirm') === '1' && !hasSegment;

  var RELOCATE = _q.get('fix') === 'location';

  var _type = wiz.dataset.type || '';
  var _locMode = wiz.dataset.locationMode || 'point';

  var DEFAULTS = {
    center: hasCoords ? [initLng, initLat] : [5.86, 50.49],
    icon: wiz.dataset.icon || '✎'
  };

  /* Locate: point / segment / none. Climbs always get a map — the line IS the item
     (docs/specs/edit-items/N-climbs.md). */
  var IS_CLIMB = !!(window.CC_ITEM && 'N' === window.CC_ITEM.letter);
  var LOCATE = (ADD || hasCoords || RELOCATE || (IS_CLIMB && !!(window.CC_ITEM || {}).route)) ? _locMode : 'off';

  // Photos: media-upload.js onChange. Links stay here — onChange replaces its list.
  var WZ = { cur: 1, last: 4, loc: null, media: [] };

  var subjectEl = document.querySelector('[name="improve[subject]"]');
  if (subjectEl && _id) subjectEl.value = _id;

  var modeEl = document.querySelector('[name="improve[mode]"]');
  if (modeEl && ADD) modeEl.value = 'add';

  function fld(sfName) {
    return document.querySelector('[name="improve[' + sfName + ']"]');
  }

  var BAG = window.CC_IMPROVE_I18N || {};

  function t(key, vars) {
    var s = typeof BAG[key] === 'string' ? BAG[key] : '';
    if (vars) {
      for (var p in vars) {
        if (Object.prototype.hasOwnProperty.call(vars, p)) s = s.split(p).join(vars[p]);
      }
    }
    return s;
  }

  var RC = (window.Cc && window.Cc.reviewCard) || null;

  function step(n) {
    if (n < 1 || n > WZ.last) return;
    WZ.cur = n;
    document.querySelectorAll('#wiz .pane').forEach(function (p) {
      p.classList.toggle('on', +p.dataset.s === n);
    });
    document.querySelectorAll('#stepper li').forEach(function (li) {
      li.classList.toggle('on', +li.dataset.s === n);
      li.classList.toggle('done', +li.dataset.s < n);
    });
    var backBtn = document.getElementById('backBtn');
    if (backBtn) backBtn.style.visibility = n > (LOCATE === 'off' ? 2 : 1) ? 'visible' : 'hidden';
    var nextBtn = document.getElementById('nextBtn');
    if (nextBtn) {
      nextBtn.textContent = (n === WZ.last ? t('nav_submit') : t('nav_next')) + ' →';
    }
    if (n === WZ.last) renderReview();
    refreshGate();
    window.scrollTo(0, 0);
  }

  function onNext() {
    if (WZ.cur === WZ.last) {
      submitImprove();
    } else {
      step(WZ.cur + 1);
    }
  }

  /* An edit that changes nothing is not a contribution (server refuses it too). */
  function detailsSnapshot() {
    var out = [];
    document.querySelectorAll('#w-details .field').forEach(function (fieldEl) {
      var ctrl = fieldEl.querySelector('input:not([type="hidden"]), select, textarea');
      if (ctrl) out.push(ctrl.name + '=' + (ctrl.value || ''));
    });
    return out.join('|');
  }
  var INITIAL_DETAILS = detailsSnapshot();

  function pinMoved() {
    if (!hasCoords || !WZ.loc || WZ.loc.type !== 'point') return false;
    return Math.abs(WZ.loc.lat - initLat) > 1e-5 || Math.abs(WZ.loc.lng - initLng) > 1e-5;
  }

  /* Climb route/steepest and surface endpoints live in hidden fields pinMoved() cannot see. */
  var INITIAL_GEOM = null;

  function geomSnapshot() {
    return ['route', 'steep', 'segment'].map(function (k) {
      var f = fld(k);
      return f ? f.value : '';
    }).join('|');
  }

  function geomChanged() {
    return null !== INITIAL_GEOM && geomSnapshot() !== INITIAL_GEOM;
  }

  function nothingChanged() {
    if (ADD) return false;                       // a new place is all change
    if (WZ.media.length) return false;
    if (pinMoved() || geomChanged()) return false;
    return detailsSnapshot() === INITIAL_DETAILS;
  }

  /* Do not advance/submit mid-upload: unclaimed photos become orphans
     (docs/specs/photo-uploads.md §6). */
  var MEDIA_BUSY = false;
  document.addEventListener('cc:media-busy', function (e) {
    MEDIA_BUSY = !!(e.detail && e.detail.busy);
    refreshGate();
  });

  function refreshGate() {
    var nextBtn = document.getElementById('nextBtn');
    if (!nextBtn) return;
    if (MEDIA_BUSY) {
      nextBtn.disabled = true;
      return;
    }
    // A climb mid-route or mid-profile is not placed yet: never send a placeholder.
    if (WZ.cur === 1 && LOCATE !== 'off') {
      nextBtn.disabled = !WZ.loc || !!WZ.locPending;
    } else if (WZ.cur === WZ.last) {
      nextBtn.disabled = nothingChanged() || !!WZ.locPending;
    } else {
      nextBtn.disabled = false;
    }
  }

  var backBtn = document.getElementById('backBtn');
  var nextBtn = document.getElementById('nextBtn');
  if (backBtn) backBtn.addEventListener('click', function () { step(Math.max(WZ.cur - 1, LOCATE === 'off' ? 2 : 1)); });
  if (nextBtn) nextBtn.addEventListener('click', onNext);

  function mkPin() {
    var el = document.createElement('div');
    el.className = 'cc-pin';
    var glyph = document.createElement('span');
    glyph.textContent = '◎';
    el.appendChild(glyph);
    return el;
  }

  var wmap = null;

  /* MapLibre v6 needs WebGL2 and throws GPUInitializationError from the
     constructor when it cannot have it, where v5 handed back a map that simply
     never painted. Catching it keeps the rest of the wizard alive: the name,
     the details and the coordinate fields all still work without a map, so a
     browser with no GPU costs a rider the pin, not the contribution. */
  function makeWizardMap() {
    try {
      return new maplibregl.Map({
        container: 'wmap',
        style: 'https://tiles.openfreemap.org/styles/liberty',
        center: DEFAULTS.center,
        zoom: hasCoords ? (isNaN(initZoom) ? 14 : initZoom) : 12,
        attributionControl: false
      });
    } catch (e) {
      var box = document.getElementById('wmap');
      if (box) {
        var note = document.createElement('p');
        note.className = 'wmap-gpu-error';
        note.setAttribute('role', 'alert');
        note.textContent = BAG.gpu_unsupported || 'The map cannot be drawn in this browser.';
        box.replaceChildren(note);
      }
      console.error('MapLibre could not start.', e);
      return null;
    }
  }

  if (LOCATE !== 'off') {
    wmap = makeWizardMap();
  }

  if (wmap) {
    window.__ccWizMap = wmap;  // smoke tests: project()/unproject()
    wmap.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    wmap.addControl(new maplibregl.AttributionControl({ customAttribution: '© OpenStreetMap contributors · ODbL' }), 'bottom-right');
    wmap.on('load', function () {
      if (window.Cc && window.Cc.mountEditorBase) {
        window.Cc.mountEditorBase(wmap, { token: window.MAPILLARY_TOKEN, labels: window.CC_BASE_LABELS });
      }
    });

    /* Known-places overlay (ADD): nearby coverage so the pin is not a duplicate. */
    var covLetter = window.CC_ITEM && window.CC_ITEM.letter;
    if (ADD && covLetter && 'N' !== covLetter) {
      var covMarkers = [];
      var covLast = null;
      var covNote = document.getElementById('wz-known');
      var clearCov = function () {
        covMarkers.forEach(function (m) { m.remove(); });
        covMarkers = [];
        if (covNote) covNote.hidden = true;
      };
      var refreshCov = function () {
        if (wmap.getZoom() < 12) { clearCov(); covLast = null; return; }
        var c = wmap.getCenter();
        var ne = wmap.getBounds().getNorthEast();
        var km = Math.min(5, Math.max(0.5,
          111 * Math.max(Math.abs(ne.lat - c.lat),
                         Math.abs(ne.lng - c.lng) * Math.cos(c.lat * Math.PI / 180))));
        // Skip refetch when the view barely moved (docs/specs/coverage-provider.md §5).
        if (covLast && Math.abs(covLast.lat - c.lat) * 111 < km * 0.3
            && Math.abs(covLast.lng - c.lng) * 111 < km * 0.3
            && Math.abs(covLast.km - km) < km * 0.3) return;
        covLast = { lat: c.lat, lng: c.lng, km: km };
        fetch('/map/coverage/nearby?lat=' + c.lat.toFixed(5) + '&lng=' + c.lng.toFixed(5) + '&km=' + km.toFixed(1))
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (data) {
            if (!data) return;
            clearCov();
            (data.groups || []).forEach(function (g) {
              if (g.letter !== covLetter) return;
              (g.items || []).forEach(function (p) {
                var el = document.createElement('div');
                el.className = 'cc-cov-dot';
                el.textContent = DEFAULTS.icon;
                covMarkers.push(new maplibregl.Marker({ element: el, anchor: 'center' })
                  .setLngLat([p.lng, p.lat]).addTo(wmap));
              });
            });
            if (covNote) covNote.hidden = 0 === covMarkers.length;
          })
          .catch(function () {});
      };
      wmap.on('load', refreshCov);
      wmap.on('moveend', refreshCov);
    }

    // Climbs: shared three-point editor (docs/specs/edit-items/N-climbs.md).
    var isClimb = !!(window.CC_ITEM && 'N' === window.CC_ITEM.letter);
    var climbEditor = null;
    var wzReset = document.getElementById('wzReset');
    var placeAt = null;

    if (isClimb) {
      var initial = (window.CC_ITEM.route || window.CC_ITEM.grad || window.CC_ITEM.steep || window.CC_ITEM.steepPoint)
        ? { route: window.CC_ITEM.route, grad: window.CC_ITEM.grad, steep: window.CC_ITEM.steep,
            // Keep an existing rider-placed steepest or an edit would drop it.
            steepPoint: window.CC_ITEM.steepPoint }
        : undefined;

      var undoBtn = document.getElementById('wzUndo');
      /* The next tap, said ON the map, where the eye is (carried over from the
         retired /add-climb wizard, owner 2026-08-25). Mirrors the readout and
         hides once foot and summit are set. */
      var setMapHint = function (text) {
        var h = document.getElementById('wz-mapHint');
        if (!h) return;
        if (text) { h.textContent = text; h.hidden = false; } else { h.hidden = true; }
      };
      var uElevM = function (m) { return window.ccElev ? window.ccElev(m) : Math.round(Number(m)) + ' m'; };
      /* Length, gain, average and steepest as the model measured them: read-only,
         under the map, so a rider sees the numbers before the review step. */
      var renderMeasured = function (st) {
        var box = document.getElementById('wz-measured');
        if (!box) return;
        var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v || '—'; };
        var any = !!(st.lengthKm || st.gain || st.avg || (st.steep && st.steep.pct));
        box.hidden = !any;
        set('wzm-len', st.lengthKm ? uKm(st.lengthKm) : '');
        set('wzm-gain', st.gain ? '△ ' + uElevM(st.gain) : '');
        set('wzm-avg', st.avg ? st.avg + ' %' : '');
        set('wzm-max', (st.steep && st.steep.pct) ? String(st.steep.pct).replace(/\s*%$/, '') + ' %' : '');
      };
      climbEditor = window.Cc.mountClimbEditor({
        map: wmap,
        hidden: { route: fld('route'), grad: fld('grad'), steep: fld('steep'), avg: fld('avg'), steepPoint: fld('steepPoint') },
        initial: initial,
        onHistory: function (depth) { if (undoBtn) undoBtn.hidden = 0 === depth; },
        onChange: function (st) {
          var ro = document.getElementById('wz-readout');
          // A climb's pin is its foot: the add intake needs lat/lng like any new item.
          var fLatC = fld('lat'), fLngC = fld('lng');
          if (fLatC) fLatC.value = st.start ? st.start[1] : '';
          if (fLngC) fLngC.value = st.start ? st.start[0] : '';
          WZ.locPending = !!(st.routing || st.profiling);
          if (st.start && st.summit) {
            WZ.loc = { type: 'climb', start: st.start, summit: st.summit, lengthKm: st.lengthKm,
                       gain: st.gain || '', avg: st.avg || '', max: (st.steep && st.steep.pct) || '' };
            if (ro) {
              var txt = t('readout_climb_set');
              if (st.lengthKm) txt += ' · ' + t('climb_length', { '%km%': uKm(st.lengthKm) });
              if (st.routing || st.profiling) txt += ' · ' + t('climb_measuring');
              else if (st.routeError) txt += ' — ' + t('climb_route_error');
              else if (st.profileError) txt += ' — ' + t('climb_profile_error');
              else txt += ' — ' + t('climb_drag_hint');
              ro.textContent = txt;
            }
            setMapHint('');
          } else {
            WZ.loc = null;
            var next = st.start ? t('readout_climb_summit') : t('readout_climb_foot');
            if (ro) ro.textContent = next;
            setMapHint(next);
          }
          renderMeasured(st);
          refreshGate();
        }
      });
      setMapHint(initial ? '' : t('readout_climb_foot'));

      if (wzReset) {
        wzReset.addEventListener('click', function () { if (climbEditor) climbEditor.reset(); });
        wzReset.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); wzReset.click(); }
        });
      }
      // Hydrated shape is the unchanged baseline for the review-step gate.
      INITIAL_GEOM = geomSnapshot();
      if (undoBtn) {
        undoBtn.addEventListener('click', function () { if (climbEditor) climbEditor.undo(); });
        undoBtn.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); undoBtn.click(); }
        });
      }
    } else {
      var placed = [];
      // Non-climb baseline (surface endpoints are not prefilled).
      INITIAL_GEOM = geomSnapshot();

      // Edit-this-item: compact view-only map until the rider expands it.
      var CONFIRM = hasCoords && !ADD && !RELOCATE;
      var confirmView = CONFIRM && _locMode === 'point';
      var mapEl = document.getElementById('wmap');
      var searchWrap = document.querySelector('#w-locate .csearch');
      var changeBtn = document.getElementById('wzChange');
      var locHelp = document.querySelector('#w-locate .help');
      var locSection = document.getElementById('w-locate');
      var origHelp = locHelp ? locHelp.textContent : '';
      // Outer-scope readout handle: nested callbacks' `ro` does not hoist here.
      var ro = document.getElementById('wz-readout');
      function expandEditor() {
        confirmView = false;
        if (mapEl) mapEl.classList.remove('confirm');
        if (searchWrap) searchWrap.hidden = false;
        if (changeBtn) changeBtn.hidden = true;
        if (wzReset) wzReset.style.display = '';
        placed.forEach(function (m) { m.getElement().classList.remove('glow'); });
        placed.forEach(function (m) { if (m.setDraggable) m.setDraggable(true); });
        if (locHelp) locHelp.textContent = origHelp;
        if (ro) ro.textContent = t('readout_expanded');
        if (wmap) wmap.resize();
      }

      var fmt = function (ll) {
        return ll.lat.toFixed(4) + '°N ' + ll.lng.toFixed(4) + '°E';
      };

      /* One leg at a time: a drag recalculates only legs touching the moved point. */
      var legs = [];      // legs[i] between waypoint i and i+1: {line, src}
      var ctrls = [];     // control-point markers, in order along the stretch
      var SNAP_TIMEOUT_MS = 8000;
      var latK = null;    // cos(lat), for planar distances in degrees
      var dd2 = function (a, b) {
        if (latK === null) latK = Math.cos((wmap ? wmap.getCenter().lat : a[1]) * Math.PI / 180);
        var dx = (a[0] - b[0]) * latK;
        var dy = a[1] - b[1];
        return dx * dx + dy * dy;
      };
      var TRIM_NEAR2 = Math.pow(30 / 111320, 2);   // ~30 m: "still on the drawn line"

      var waypoints = function () {
        return placed.length < 2 ? [] : [placed[0]].concat(ctrls).concat([placed[1]]);
      };
      var legLine = function (i) {
        var w = waypoints();
        var l = legs[i];
        if (l && l.line && l.line.length > 1) return l.line;
        return [w[i].getLngLat().toArray(), w[i + 1].getLngLat().toArray()];
      };
      var fullLine = function () {
        var w = waypoints();
        if (w.length < 2) return null;
        var out = [];
        for (var i = 0; i < w.length - 1; i++) {
          var L = legLine(i);
          if (out.length && dd2(out[out.length - 1], L[0]) < 1e-14) L = L.slice(1);
          out = out.concat(L);
        }
        return out;
      };
      var anyRouted = function () {
        return legs.some(function (l) { return l && l.line && l.line.length > 1; });
      };
      /* Server caps segment vertices; even sampling, endpoints kept. */
      var capLine = function (line, max) {
        if (!line || line.length <= max) return line;
        var out = [];
        var step = (line.length - 1) / (max - 1);
        for (var i = 0; i < max; i++) out.push(line[Math.round(i * step)]);
        return out;
      };

      /* Hide-line toggle: the drawn stretch covers the road it describes. */
      var segHidden = false;

      var drawSeg = function () {
        if (syncAddPt) syncAddPt();
        if (!wmap.isStyleLoaded()) { wmap.once('idle', drawSeg); return; }
        var id = 'seg';
        if (wmap.getLayer(id)) wmap.removeLayer(id);
        if (wmap.getSource(id)) wmap.removeSource(id);
        if (placed.length < 2) return;
        var coords = fullLine();
        wmap.addSource(id, { type: 'geojson', data: { type: 'Feature', geometry: { type: 'LineString', coordinates: coords } } });
        wmap.addLayer({
          id: id, type: 'line', source: id,
          layout: { visibility: segHidden ? 'none' : 'visible' },
          paint: { 'line-color': '#FF5A1F', 'line-width': 4 },
        });
      };

      var mountSegPeek = function () {
        var ctrl = document.createElement('div');
        ctrl.className = 'ed-base maplibregl-ctrl maplibregl-ctrl-group ed-peek';
        var b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('aria-pressed', 'false');
        var sync = function () {
          b.textContent = segHidden ? (t('seg_show') || 'Show line') : (t('seg_hide') || 'Hide line');
          b.title = segHidden
            ? (t('seg_show_title') || 'Draw the stretch back on')
            : (t('seg_hide_title') || 'Take the line off to see the road underneath');
          b.setAttribute('aria-pressed', segHidden ? 'true' : 'false');
          b.classList.toggle('on', segHidden);
        };
        b.addEventListener('click', function () {
          segHidden = !segHidden;
          if (wmap.getLayer('seg')) {
            wmap.setLayoutProperty('seg', 'visibility', segHidden ? 'none' : 'visible');
          }
          sync();
        });
        sync();
        ctrl.appendChild(b);
        wmap.addControl({
          onAdd: function () { return ctrl; },
          onRemove: function () { ctrl.remove(); },
        }, 'top-left');
      };

      /* Route one leg; seq/ctl live on the leg (control points splice the array). */
      var snapLeg = function (leg) {
        var i = legs.indexOf(leg);
        var w = waypoints();
        if (i < 0 || w.length < i + 2) return;
        var seq = (leg._seq = (leg._seq || 0) + 1);
        if (leg._ctl) { leg._ctl.abort(); }
        var a = w[i].getLngLat().toArray();
        var b = w[i + 1].getLngLat().toArray();
        var ctl = leg._ctl = new AbortController();
        var timer = setTimeout(function () { ctl.abort(); }, SNAP_TIMEOUT_MS);
        fetch('/contribute/route', {
          method: 'POST',
          // Stateless 'route-snap' CSRF token; server refuses without it.
          headers: { 'Content-Type': 'application/json', 'X-CC-Token': window.CC_ROUTE_TOKEN || '' },
          body: JSON.stringify({ a: a, b: b }),
          signal: ctl.signal
        }).then(function (r) {
          if (!r.ok) throw new Error('route HTTP ' + r.status);
          return r.json();
        }).then(function (d) {
          clearTimeout(timer);
          if (seq !== leg._seq || legs.indexOf(leg) < 0) return;   // superseded
          var line = d && d.code === 'Ok' && d.routes && d.routes[0]
            && d.routes[0].geometry && d.routes[0].geometry.coordinates;
          if (!line || line.length < 2) { leg.line = null; leg.src = null; toast(t('toast_segment_straight')); }
          else { leg.line = line; leg.src = 'route'; }
          syncLoc();
          drawSeg();
        }).catch(function () {
          clearTimeout(timer);
          if (seq !== leg._seq || legs.indexOf(leg) < 0) return;
          leg.line = null; leg.src = null;
          toast(t('toast_segment_straight'));
          syncLoc();
          drawSeg();
        });
      };

      var snapSeg = function () {
        if (placed.length < 2) return;
        legs.forEach(function (leg) { if (!leg.line) snapLeg(leg); });
      };

      /* Drag: only touching legs change; trim if the pin still lies on the line. */
      var legUpdateForWaypoint = function (m) {
        var w = waypoints();
        var wi = w.indexOf(m);
        if (wi < 0) return;
        var idx = [];
        if (wi > 0) idx.push(wi - 1);          // m is this leg's END
        if (wi < w.length - 1) idx.push(wi);   // m is this leg's START
        idx.forEach(function (li) {
          var leg = legs[li];
          if (!leg) return;
          var pt = m.getLngLat().toArray();
          if (leg.line && leg.line.length > 1) {
            var bi = -1, bd = Infinity;
            for (var i = 0; i < leg.line.length; i++) {
              var d = dd2(leg.line[i], pt);
              if (d < bd) { bd = d; bi = i; }
            }
            if (bd <= TRIM_NEAR2) {
              var cut = (li === wi) ? leg.line.slice(bi) : leg.line.slice(0, bi + 1);
              if (cut.length > 1) {
                leg.line = cut;
                var tip = (li === wi) ? cut[0] : cut[cut.length - 1];
                m.setLngLat({ lng: tip[0], lat: tip[1] });
                return;
              }
            }
          }
          leg.line = null; leg.src = null;
          snapLeg(leg);
        });
      };

      var syncLoc = function () {
        var ro = document.getElementById('wz-readout');
        if (LOCATE === 'segment') {
          // Endpoints must reach the form, not just WZ.loc, or POST drops the segment.
          var fSeg = fld('segment');
          if (placed.length < 2) {
            WZ.loc = null;
            if (fSeg) fSeg.value = '';
            if (ro) ro.textContent = placed.length === 1 ? t('readout_segment_end') : t('readout_segment_start');
          } else {
            WZ.loc = { type: 'segment', a: placed[0].getLngLat().toArray(), b: placed[1].getLngLat().toArray() };
            // `line` only when some leg has a real shape; absence means "no road found".
            var seg = { a: WZ.loc.a, b: WZ.loc.b };
            if (anyRouted()) seg.line = capLine(fullLine(), 2900);
            if (fSeg) fSeg.value = JSON.stringify(seg);
            if (ro) ro.textContent = '✓ ' + fmt(placed[0].getLngLat()) + ' → ' + fmt(placed[1].getLngLat());
          }
        } else {
          var fLat = fld('lat');
          var fLng = fld('lng');
          if (!placed.length) {
            WZ.loc = null;
            // Reset must clear hidden fields or POST still carries the old pin.
            if (fLat) fLat.value = '';
            if (fLng) fLng.value = '';
            var fPlace = fld('place');
            if (fPlace) fPlace.value = '';
            if (ro) ro.textContent = t('readout_initial');
          } else {
            var ll = placed[0].getLngLat();
            WZ.loc = { type: 'point', lng: ll.lng, lat: ll.lat };
            if (fLat) fLat.value = ll.lat;
            if (fLng) fLng.value = ll.lng;
            if (ro) ro.textContent = t('readout_point_set', { '%coords%': fmt(ll) });
          }
        }
        refreshGate();
      };

      var announceMove = function () {
        if (WZ.loc && WZ.loc.type === 'point') {
          toast(t('toast_pin_moved', {
            '%coords%': WZ.loc.lat.toFixed(4) + '°N ' + WZ.loc.lng.toFixed(4) + '°E'
          }));
        } else if (WZ.loc && WZ.loc.type === 'segment') {
          toast(t('toast_segment_moved'));
        }
      };

      // `var` so search (shared with the climb branch) can test `if (placeAt)`.
      var hist = [];
      var undoCtl = document.getElementById('wzUndo');
      var dragFrom = null;

      /* Snapshot pre-drag position: by dragend the marker has already moved. */
      var pushHistory = function (moved, from) {
        hist.push({
          pts: placed.map(function (m) { return (moved && m === moved && from) ? from : m.getLngLat().toArray(); }),
          cpts: ctrls.map(function (c) { return (moved && c === moved && from) ? from : c.getLngLat().toArray(); }),
          legs: legs.map(function (l) { return { line: l.line, src: l.src }; })
        });
        if (undoCtl) undoCtl.hidden = false;
      };

      /* Place a marker without touching history. */
      var addMarker = function (lngLat) {
        var m = new maplibregl.Marker({ element: mkPin(), draggable: true, anchor: 'bottom' }).setLngLat(lngLat).addTo(wmap);
        m.on('dragstart', function () { dragFrom = m.getLngLat().toArray(); });
        m.on('dragend', function () {
          pushHistory(m, dragFrom);
          dragFrom = null;
          legUpdateForWaypoint(m);
          syncLoc(); drawSeg(); announceMove();
        });
        placed.push(m);
        return m;
      };

      /* Add-a-point mode: armed tap on the line adds a control; miss does nothing. */
      var armed = false;
      var addPtBtn = document.getElementById('wzAddPt');
      var ctrlHelp = document.getElementById('wzCtrlHelp');
      var setArmed = function (on) {
        armed = on;
        if (addPtBtn) {
          addPtBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
          addPtBtn.classList.toggle('on', on);
        }
        if (ctrlHelp) ctrlHelp.textContent = on ? t('ctrl_point_armed') : t('ctrl_point_help');
      };
      var syncAddPt = function () {
        if (!addPtBtn) return;
        var usable = placed.length === 2 && !confirmView;
        addPtBtn.disabled = !usable;
        if (!usable && armed) setArmed(false);
      };

      var mkCtrl = function (lngLat) {
        var el = document.createElement('div');
        el.className = 'wz-ctrlpt';
        el.title = t('ctrl_remove_title');
        var m = new maplibregl.Marker({ element: el, draggable: true }).setLngLat(lngLat).addTo(wmap);
        m.on('dragstart', function () { dragFrom = m.getLngLat().toArray(); });
        m.on('dragend', function () {
          pushHistory(m, dragFrom);
          dragFrom = null;
          legUpdateForWaypoint(m);
          syncLoc(); drawSeg(); announceMove();
        });
        el.addEventListener('contextmenu', function (ev) {
          ev.preventDefault();
          ev.stopPropagation();
          removeCtrl(m);
        });
        // Marker sits above the canvas; an armed tap lands here, not on the map.
        el.addEventListener('click', function (ev) {
          if (!armed) return;
          ev.preventDefault();
          ev.stopPropagation();
          removeCtrl(m);
        });
        return m;
      };

      var removeCtrl = function (cm) {
        var ci = ctrls.indexOf(cm);
        if (ci < 0) return;
        pushHistory();
        var l1 = legs[ci], l2 = legs[ci + 1];
        var merged = { line: null, src: null };
        if (l1 && l2 && l1.line && l2.line) {
          // Joining two real shapes is the merged road; do not re-route.
          merged.line = l1.line.concat(l2.line.slice(1));
          merged.src = l1.src === l2.src ? l1.src : 'mixed';
        }
        legs.splice(ci, 2, merged);
        ctrls.splice(ci, 1);
        cm.remove();
        if (!merged.line) snapLeg(merged);
        syncLoc();
        drawSeg();
        toast(t('toast_ctrl_removed'));
      };

      var undo = function () {
        var prev = hist.pop();
        if (!prev) return;
        placed.forEach(function (m) { m.remove(); });
        placed.length = 0;
        ctrls.forEach(function (c) { c.remove(); });
        ctrls.length = 0;
        prev.pts.forEach(function (pt) { addMarker({ lng: pt[0], lat: pt[1] }); });
        (prev.cpts || []).forEach(function (pt) { ctrls.push(mkCtrl({ lng: pt[0], lat: pt[1] })); });
        legs = prev.legs.map(function (l) { return { line: l.line, src: l.src }; });
        if (undoCtl) undoCtl.hidden = 0 === hist.length;
        syncLoc();
        drawSeg();
      };

      placeAt = function (lngLat) {
        if (confirmView) return;   // compact confirm-map is view-only until expanded
        var need = LOCATE === 'segment' ? 2 : 1;
        pushHistory();
        if (placed.length >= need) { placed.forEach(function (m) { m.remove(); }); placed.length = 0; }
        addMarker(lngLat);
        ctrls.forEach(function (c) { c.remove(); });
        ctrls.length = 0;
        legs = placed.length === 2 ? [{ line: null, src: null }] : [];
        syncLoc();
        drawSeg();
        snapSeg();
      };

      /* Right-click on the line (35px) pins a control point. */
      /* Nearest vertex of the drawn line to a screen point, within maxPx. Shared
         by the armed tap (generous: a finger) and the line grab (tight: you
         have to be ON the road you are dragging). */
      var nearestOnLine = function (screenPt, maxPx) {
        var best = null;
        for (var li = 0; li < legs.length; li++) {
          var L = legLine(li);
          for (var vi = 0; vi < L.length; vi++) {
            var p = wmap.project({ lng: L[vi][0], lat: L[vi][1] });
            var dx = p.x - screenPt.x, dy = p.y - screenPt.y;
            var d = dx * dx + dy * dy;
            if (!best || d < best.d) best = { d: d, li: li, vi: vi, pt: L[vi] };
          }
        }
        return (best && best.d <= maxPx * maxPx) ? best : null;
      };

      /* Insert a control point at the line vertex nearest the tap. Returns the
         marker so the caller can keep hold of it; the line grab drags the one
         it just made. */
      var rightClickAt = function (lngLat, screenPt, quiet) {
        if (confirmView || placed.length < 2) return null;
        var best = nearestOnLine(screenPt, 35);
        if (!best) return null;
        var L2 = legLine(best.li);
        if (L2.length < 3) return null;                  // nothing between the ends to pin
        if (best.vi < 1) best.vi = 1;
        if (best.vi > L2.length - 2) best.vi = L2.length - 2;
        pushHistory();
        var leg = legs[best.li];
        var hasLine = leg && leg.line && leg.line.length > 1;
        var head = { line: hasLine ? leg.line.slice(0, best.vi + 1) : null, src: hasLine ? leg.src : null };
        var tail = { line: hasLine ? leg.line.slice(best.vi) : null, src: hasLine ? leg.src : null };
        legs.splice(best.li, 1, head, tail);
        var made = mkCtrl({ lng: L2[best.vi][0], lat: L2[best.vi][1] });
        ctrls.splice(best.li, 0, made);
        if (!head.line) snapLeg(head);
        if (!tail.line) snapLeg(tail);
        syncLoc();
        drawSeg();
        if (!quiet) toast(t('toast_ctrl_added'));
        return made;
      };

      /* Grab the drawn road and pull it onto another one.
         
         This is what a rider means by "move the route": press the line where it
         is wrong, drag to the road it should follow, let go. Before this the
         only way was to add a control point on the line and then drag that
         point, two gestures with nothing on screen connecting them, and an
         armed tap on the road you actually wanted was ignored because it was
         further than 35px from the line (owner-reported 2026-08-31: "I do not
         drag the marker, I want to drag the road").
         
         It is the same two steps underneath: insert a control at the vertex
         grabbed, then move it. Fusing them into one press-drag-release is the
         whole change, which is why it reuses rightClickAt rather than routing
         by itself. Quiet: the toast belongs to the deliberate add, not to a
         drag that is about to move the thing anyway.
         
         Mouse only. Touch keeps the armed-tap path, which is what the help text
         describes and what works without a hover state to hint at grabbing. */
      var GRAB_PX = 8;
      var lineDrag = null;
      var canvas = wmap.getCanvas();

      var atPoint = function (ev) {
        var rect = canvas.getBoundingClientRect();
        return { x: ev.clientX - rect.left, y: ev.clientY - rect.top };
      };

      canvas.addEventListener('mousedown', function (ev) {
        if (ev.button !== 0 || armed || confirmView || placed.length < 2) return;
        var pt = atPoint(ev);
        if (!nearestOnLine(pt, GRAB_PX)) return;
        var m = rightClickAt(wmap.unproject(pt), pt, true);
        if (!m) return;
        lineDrag = { m: m, from: m.getLngLat().toArray() };
        wmap.dragPan.disable();          // or the map slides out from under the drag
        ev.preventDefault();
      });

      window.addEventListener('mousemove', function (ev) {
        if (!lineDrag) return;
        lineDrag.m.setLngLat(wmap.unproject(atPoint(ev)));
      });

      window.addEventListener('mouseup', function () {
        if (!lineDrag) return;
        var d = lineDrag;
        lineDrag = null;
        wmap.dragPan.enable();
        /* Exactly what the marker's own dragend does: one path, so a grabbed
           line and a dragged pin can never re-route differently. */
        pushHistory(d.m, d.from);
        legUpdateForWaypoint(d.m);
        syncLoc(); drawSeg(); announceMove();
      });

      /* The line is grabbable, so say so under the cursor. */
      wmap.on('mousemove', function (e) {
        if (armed || lineDrag || confirmView || placed.length < 2) return;
        canvas.style.cursor = nearestOnLine(e.point, GRAB_PX) ? 'grab' : '';
      });

      wmap.on('click', function (e) {
        if (armed) { rightClickAt(e.lngLat, e.point); return; }
        placeAt(e.lngLat);
      });

      if (LOCATE === 'segment') {
        mountSegPeek();
        // DOM contextmenu (MapLibre withholds its own behind right-drag-rotate).
        // rcDown ignores a right-drag's release; preventDefault hides the browser menu.
        var rcDown = null;
        if (addPtBtn) {
          addPtBtn.addEventListener('click', function () { setArmed(!armed); });
        }
        wmap.getCanvas().addEventListener('mousedown', function (ev) {
          if (ev.button === 2) rcDown = { x: ev.clientX, y: ev.clientY };
        });
        wmap.getCanvas().addEventListener('contextmenu', function (ev) {
          ev.preventDefault();
          if (rcDown && (Math.abs(ev.clientX - rcDown.x) > 8 || Math.abs(ev.clientY - rcDown.y) > 8)) return;
          var rect = wmap.getCanvas().getBoundingClientRect();
          var pt = { x: ev.clientX - rect.left, y: ev.clientY - rect.top };
          rightClickAt(wmap.unproject(pt), pt);
        });
      }

      // Existing segment: hydrate stored a/b/line so an untouched edit has no phantom change.
      var itemSeg = (!hasSegment && LOCATE === 'segment' && (window.CC_ITEM || {}).segment
        && Array.isArray(window.CC_ITEM.segment.a) && Array.isArray(window.CC_ITEM.segment.b))
        ? window.CC_ITEM.segment : null;
      if (itemSeg) {
        wmap.on('load', function () {
          addMarker({ lng: itemSeg.a[0], lat: itemSeg.a[1] });
          addMarker({ lng: itemSeg.b[0], lat: itemSeg.b[1] });
          legs = [Array.isArray(itemSeg.line) && itemSeg.line.length > 1
            ? { line: itemSeg.line, src: 'seed' }
            : { line: null, src: null }];
          syncLoc();
          drawSeg();
          INITIAL_GEOM = geomSnapshot();
          var b = new maplibregl.LngLatBounds(itemSeg.a, itemSeg.a);
          b.extend(itemSeg.b);
          (itemSeg.line || []).forEach(function (pt) { b.extend(pt); });
          wmap.fitBounds(b, { padding: 60, maxZoom: 16, duration: 0 });
          var _ro = document.getElementById('wz-readout');
          if (_ro) _ro.textContent = t('readout_segment_prefilled');
        });
      }

      // Blank stretch: ask for START, not the point-mode "set a location" copy.
      if (LOCATE === 'segment' && !hasSegment && !itemSeg && ro) {
        ro.textContent = t('readout_segment_start');
      }

      if (LOCATE === 'segment' && hasSegment) {
        wmap.on('load', function () {
          // addMarker, not placeAt: undo must not return to a blank map.
          addMarker({ lng: segA[0], lat: segA[1] });
          addMarker({ lng: segB[0], lat: segB[1] });
          /* Tile geometry from sessionStorage when endpoints match and the seed is fresh. */
          var seed = null;
          try { seed = JSON.parse(sessionStorage.getItem('ccSegSeed') || 'null'); } catch (e) { seed = null; }
          var nearEnd = function (p, q) {
            return p && q && Math.abs(p[0] - q[0]) < 2e-4 && Math.abs(p[1] - q[1]) < 2e-4;
          };
          var seedOk = seed && seed.line && seed.line.length > 1
            && nearEnd(seed.a, segA) && nearEnd(seed.b, segB)
            && (Date.now() - (seed.ts || 0)) < 15 * 60 * 1000;
          legs = [seedOk ? { line: seed.line, src: 'seed' } : { line: null, src: null }];
          syncLoc();
          drawSeg();
          if (!seedOk) snapSeg();
          var _ro = document.getElementById('wz-readout');
          if (_ro) _ro.textContent = t('readout_segment_prefilled');
          var b = new maplibregl.LngLatBounds(segA, segA);
          b.extend(segB);
          wmap.fitBounds(b, { padding: 60, maxZoom: 16, duration: 0 });
          // Skip to details only after pins exist (empty segment would 422).
          if (CONFIRM_SEGMENT) step(2);
        });
      }

      if (hasCoords && LOCATE === 'point' && CONFIRM_POINT) {
        wmap.on('load', function () { step(2); });
      }
      if (hasCoords && LOCATE === 'point') {
        if (CONFIRM) {
          if (mapEl) mapEl.classList.add('confirm');
          if (searchWrap) searchWrap.hidden = true;
          if (wzReset) wzReset.style.display = 'none';
          if (locSection && locSection.dataset.confirmHelp && locHelp) locHelp.textContent = locSection.dataset.confirmHelp;
          if (changeBtn) { changeBtn.hidden = false; changeBtn.addEventListener('click', expandEditor); }
        }
        wmap.on('load', function () {
          // CONFIRM shrinks #wmap after build; resize or the canvas stays tall and the pin is off-screen.
          if (CONFIRM) { wmap.resize(); wmap.setCenter([initLng, initLat]); }
          var m = new maplibregl.Marker({ element: mkPin(), draggable: !CONFIRM, anchor: 'bottom' }).setLngLat([initLng, initLat]).addTo(wmap);
          if (CONFIRM) m.getElement().classList.add('glow');
          m.on('dragend', function () { syncLoc(); announceMove(); });
          placed.push(m);
          syncLoc();
          if (CONFIRM && ro) ro.textContent = t('readout_confirm');
        });
      }

      if (wzReset) {
        wzReset.addEventListener('click', function () {
          pushHistory();
          placed.forEach(function (m) { m.remove(); });
          placed.length = 0;
          ctrls.forEach(function (c) { c.remove(); });
          ctrls.length = 0;
          legs = [];
          drawSeg();
          syncLoc();
        });
        wzReset.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); wzReset.click(); }
        });
      }
      if (undoCtl) {
        undoCtl.addEventListener('click', undo);
        undoCtl.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); undoCtl.click(); }
        });
      }
    }

    var searchEl = document.getElementById('wz-search');
    var resultsEl = document.getElementById('wz-results');
    var searchT = null;
    var parseLatLng = (window.Cc && window.Cc.parseLatLng) || null;

    function resultNote(text) {
      var el = document.createElement('div');
      el.className = 'res empty';
      el.textContent = text;
      return el;
    }

    /* Photon text is third-party; build as nodes (docs/specs/security-architecture.md §4.3). */
    function renderResults(list) {
      if (!resultsEl) return;
      RC.clear(resultsEl);
      if (!list) { resultsEl.hidden = true; return; }
      var shown = 0;
      list.forEach(function (f) {
        var p = f.properties || {};
        var c = (f.geometry && f.geometry.coordinates) || [];
        var lng = +c[0], lat = +c[1];
        if (!isFinite(lng) || !isFinite(lat)) return;
        var main = p.name || p.street || p.city || t('search_result');
        var sub = [p.name ? p.street : '', p.city, p.county, p.state, p.country].filter(Boolean).join(', ');

        var row = document.createElement('div');
        row.className = 'res';
        var b = document.createElement('b');
        b.textContent = main;
        var small = document.createElement('small');
        small.textContent = sub;
        row.appendChild(b);
        row.appendChild(small);
        row.addEventListener('click', function () {
          if (wmap) wmap.flyTo({ center: [lng, lat], zoom: 14 });
          if (searchEl) searchEl.value = main;
          var fPlace = fld('place');
          if (fPlace) fPlace.value = main;
          resultsEl.hidden = true;
        });
        resultsEl.appendChild(row);
        shown++;
      });
      if (!shown) resultsEl.appendChild(resultNote(t('search_no_matches')));
      resultsEl.hidden = false;
    }

    /* Pasted coords never reach Photon — the search box answers that itself. */
    function goCoord(pt) {
      if (!wmap) return;
      wmap.flyTo({ center: [pt.lng, pt.lat], zoom: 16 });
      // Point/segment: paste places the pin. Climb: fly-to only (foot/summit stay the editor's).
      if (placeAt) placeAt([pt.lng, pt.lat]);
      var fPlace = fld('place');
      if (fPlace && !fPlace.value) fPlace.value = window.Cc.formatLatLng(pt.lat, pt.lng);
      if (resultsEl) resultsEl.hidden = true;
    }

    function renderCoord(pt) {
      if (!resultsEl) return;
      RC.clear(resultsEl);
      var row = document.createElement('div');
      row.className = 'res';
      var b = document.createElement('b');
      b.textContent = window.Cc.formatLatLng(pt.lat, pt.lng);
      var small = document.createElement('small');
      small.textContent = placeAt ? t('search_coords_place') : t('search_coords_go');
      row.appendChild(b);
      row.appendChild(small);
      row.addEventListener('click', function () { goCoord(pt); });
      resultsEl.appendChild(row);
      resultsEl.hidden = false;
    }

    var searchSeq = 0;

    function geocode(q) {
      // Drop out-of-order responses (Enter bypasses debounce).
      var seq = ++searchSeq;
      fetch('https://photon.komoot.io/api/?q=' + encodeURIComponent(q) + '&limit=6')
        .then(function (r) { return r.json(); }).then(function (d) {
          if (seq !== searchSeq) return;
          renderResults(d.features || []);
        })
        .catch(function () {
          if (seq !== searchSeq) return;
          if (resultsEl) {
            RC.clear(resultsEl);
            resultsEl.appendChild(resultNote(t('search_unavailable')));
            resultsEl.hidden = false;
          }
        });
    }

    if (searchEl) {
      searchEl.addEventListener('input', function () {
        var q = searchEl.value.trim();
        clearTimeout(searchT);
        // Bump searchSeq so an in-flight geocode cannot overwrite the coordinate row.
        var pt = parseLatLng && parseLatLng(q);
        if (pt) { searchSeq++; renderCoord(pt); return; }
        if (q.length < 3) { renderResults(null); return; }
        searchT = setTimeout(function () { geocode(q); }, 320);
      });
      searchEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          clearTimeout(searchT);
          var q = searchEl.value.trim();
          var pt = parseLatLng && parseLatLng(q);
          if (pt) { searchSeq++; goCoord(pt); return; }
          if (q.length >= 2) geocode(q);
        }
      });
    }

    document.addEventListener('click', function (e) {
      if (resultsEl && !e.target.closest('.csearch')) resultsEl.hidden = true;
    });
  }

  function renderReview() {
    var rb = document.getElementById('reviewBody');
    if (!rb) return;

    // type 'none' is real (locate skipped). Check each shape so a missing loc does not blank the card.
    var locTxt = '';
    if (WZ.loc && WZ.loc.type === 'point' && isFinite(WZ.loc.lat) && isFinite(WZ.loc.lng)) {
      locTxt = '◎ ' + WZ.loc.lat.toFixed(4) + '°N ' + WZ.loc.lng.toFixed(4) + '°E';
    } else if (WZ.loc && WZ.loc.type === 'segment' && WZ.loc.a && WZ.loc.b) {
      locTxt = WZ.loc.a[1].toFixed(4) + '°N ' + WZ.loc.a[0].toFixed(4) + '°E → '
        + WZ.loc.b[1].toFixed(4) + '°N ' + WZ.loc.b[0].toFixed(4) + '°E';
    } else if (WZ.loc && WZ.loc.start && WZ.loc.summit) {
      locTxt = t('loc_climb', {
        '%foot%': WZ.loc.start[1].toFixed(4) + '°N ' + WZ.loc.start[0].toFixed(4) + '°E',
        '%summit%': WZ.loc.summit[1].toFixed(4) + '°N ' + WZ.loc.summit[0].toFixed(4) + '°E'
      });
      if (WZ.loc.lengthKm) {
        locTxt += ' · ' + t('climb_length', { '%km%': uKm(WZ.loc.lengthKm) });
      }
      // The measured numbers, so the review echoes what step 1 showed.
      if (WZ.loc.gain) locTxt += ' · △ ' + (window.ccElev ? window.ccElev(WZ.loc.gain) : Math.round(Number(WZ.loc.gain)) + ' m');
      if (WZ.loc.avg) locTxt += ' · ' + t('measured_avg', { '%pct%': WZ.loc.avg });
      if (WZ.loc.max) locTxt += ' · ' + t('measured_max', { '%pct%': String(WZ.loc.max).replace(/\s*%$/, '') });
    }

    var fieldRows = [];
    document.querySelectorAll('#w-details .field').forEach(function (fieldEl) {
      var ctrl = fieldEl.querySelector('input:not([type="hidden"]), select, textarea');
      if (!ctrl) return;
      var val;
      if (ctrl.tagName === 'SELECT') {
        var opt = ctrl.options[ctrl.selectedIndex];
        val = opt ? opt.text : ctrl.value;
      } else {
        val = ctrl.value;
      }
      val = (val || '').trim();
      // Placeholder em dash is not an answer; omit unanswered fields.
      if (!val || val === '—' || val === '-') return;
      var labelEl = fieldEl.querySelector('label');
      var label = labelEl ? labelEl.textContent.trim() : ctrl.name;
      fieldRows.push(RC.kvRow(label, val.length > 120 ? val.slice(0, 120) + '…' : val));
    });

    // No Location row when the pin is untouched; no category row (duplicates the step eyebrow).
    RC.clear(rb);
    if (locTxt) rb.appendChild(RC.kvRow(t('label_location'), locTxt));
    fieldRows.forEach(function (row) { rb.appendChild(row); });

    var note = document.getElementById('wz-nochange');
    if (note) {
      note.textContent = t('nothing_changed');
      // A curator sees the map pointer instead; the two notices never stack.
      note.hidden = !nothingChanged() || !!document.getElementById('wz-curator-decide');
    }

    var block = document.getElementById('reviewMedia');
    var list = document.getElementById('reviewMediaList');
    if (!block || !list) return;
    var all = WZ.media;
    block.hidden = !all.length;
    RC.clear(list);
    all.forEach(function (m) { list.appendChild(RC.mediaFigure(m)); });
  }

  function submitImprove() {
    var form = document.getElementById('improve-form');
    if (form) { form.submit(); return; }
  }

  function openModal() { var m = document.getElementById('modal'); if (m) m.classList.add('open'); }
  function closeModal() { var m = document.getElementById('modal'); if (m) m.classList.remove('open'); }

  var modalEl = document.getElementById('modal');
  if (modalEl) {
    modalEl.addEventListener('click', function (e) { if (e.target.id === 'modal') closeModal(); });
  }

  var _toastT = null;
  function toast(msg) {
    var t = document.getElementById('cc-toast');
    if (!t) { t = document.createElement('div'); t.id = 'cc-toast'; t.className = 'cc-toast'; document.body.appendChild(t); }
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(_toastT);
    _toastT = setTimeout(function () { t.classList.remove('show'); }, 2800);
  }

  // Real uploads (docs/specs/photo-uploads.md §4).
  var mediaField = fld('mediaIds');
  if (mediaField && window.Cc && window.Cc.mountMediaUploads) {
    window.Cc.mountMediaUploads({
      hidden: mediaField,
      hiddenAlts: fld('mediaAlts'),
      onChange: function (photos) { WZ.media = photos; }
    });
  }

  // Outbound-links editor (docs/specs/catalog-data-model.md §7).
  if (window.Cc && window.Cc.mountLinksEditor) {
    var linkFields = document.querySelectorAll('#wiz input[data-links-editor]');
    for (var li = 0; li < linkFields.length; li++) {
      window.Cc.mountLinksEditor(linkFields[li]);
    }
  }

  if (LOCATE === 'off') {
    WZ.loc = { type: 'none' };
    // "+ add" bridge: skip locate; start on step 2.
    var _step1Label = document.getElementById('step-label-1');
    if (_step1Label) _step1Label.style.display = 'none';
    step(2);
    // ?field=key: query details/extras; alphabetic token only.
    var _field = _q.get('field');
    if (_field && /^[a-zA-Z]+$/.test(_field)) {
      var _target = document.querySelector(
        '[name="improve[details][' + _field + ']"], [name="improve[extras][' + _field + ']"]'
      );
      if (_target) {
        _target.scrollIntoView({ block: 'center' });
        try { _target.focus({ preventScroll: true }); } catch (e) { _target.focus(); }
      }
    }
  }

  refreshGate();
})();
