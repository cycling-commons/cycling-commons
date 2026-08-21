// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Add-a-climb wizard (docs/specs/edit-items/B-climbs.md). Same three-point
   editor as /improve. No innerHTML: nodes are createElement + textContent
   via review-card.js. Markup stays in Twig. (docs/specs/security-architecture.md §4.3) */
(function () {
  'use strict';

  var wiz = document.getElementById('wiz');
  if (!wiz) return;

  /* Rider units (docs/specs/account-and-auth.md §9). Inputs are metric. */
  function uKm(km) { return window.ccKm ? window.ccKm(km) : Number(km).toFixed(1) + ' km'; }
  function uElev(m) { return window.ccElev ? window.ccElev(m) : Math.round(Number(m)) + ' m'; }
  // Length/gain fields are the rider's unit; S and the payload stay metric.
  function dKm(km) { return window.ccKmValue ? window.ccKmValue(km, 1) : Number(km).toFixed(1); }
  function vKm(v) { return window.ccKmFromValue ? window.ccKmFromValue(v) : (Number(v) || 0); }
  function vElev(v) { return window.ccElevFromValue ? window.ccElevFromValue(v) : (Number(v) || 0); }

  function fld(sfName) {
    return document.querySelector('[name="add_climb[' + sfName + ']"]');
  }

  var BAG = window.CC_ADD_CLIMB_I18N || {};

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

  var S = {
    start: null, summit: null, lengthKm: 0, name: '', gain: 0,
    maxGrad: '', surface: 'Asphalt', surfaceQ: 'Smooth',
    traffic: 'Traffic-free', note: '', osm: 'Unknown',
    routing: false, profiling: false, routeError: false, profileError: false
  };
  var cur = 1;

  function step(n) {
    if (n < 1 || n > 5) return;
    cur = n;
    document.querySelectorAll('.pane').forEach(function (p) {
      p.classList.toggle('on', +p.dataset.s === n);
    });
    document.querySelectorAll('#stepper li').forEach(function (li) {
      li.classList.toggle('on', +li.dataset.s === n);
      li.classList.toggle('done', +li.dataset.s < n);
    });
    var backBtn = document.getElementById('backBtn');
    if (backBtn) backBtn.style.visibility = (n > 1 && n < 5) ? 'visible' : 'hidden';
    var next = document.getElementById('nextBtn');
    if (next) {
      next.textContent = (n === 4 ? t('nav_submit') : t('nav_next')) + ' →';
      next.style.display = n === 5 ? 'none' : 'inline-flex';
    }
    if (n === 2) onEnterProfile();
    if (n === 4) renderReview();
    refreshGate();
    window.scrollTo(0, 0);
  }

  function onNext() {
    if (cur === 4) { submitClimb(); } else { step(cur + 1); }
  }

  function refreshGate() {
    var next = document.getElementById('nextBtn');
    if (!next) return;
    var pending = S.routing || S.profiling;
    if (cur === 1) next.disabled = !(S.start && S.summit) || pending;
    else if (cur === 2) next.disabled = !(S.name && S.gain > 0);
    else if (cur === 4) next.disabled = pending; // never submit a mid-flight placeholder
    else next.disabled = false;
  }

  var backBtn = document.getElementById('backBtn');
  var nextBtn = document.getElementById('nextBtn');
  if (backBtn) backBtn.addEventListener('click', function () { step(cur - 1); });
  if (nextBtn) nextBtn.addEventListener('click', onNext);

  var seed = window.CC_CLIMB_VIEW;
  var cmap = new maplibregl.Map({
    container: 'cmap',
    style: 'https://tiles.openfreemap.org/styles/liberty',
    center: seed ? [seed.lng, seed.lat] : [5.74, 50.49],
    zoom: seed ? seed.zoom : 11,
    attributionControl: false
  });
  cmap.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
  cmap.addControl(new maplibregl.AttributionControl({ customAttribution: '© OpenStreetMap contributors · ODbL' }), 'bottom-right');

  var editor = null;
  var undoBtn = document.getElementById('undo');
  cmap.on('load', function () {
    if (window.Cc && window.Cc.mountEditorBase) {
      window.Cc.mountEditorBase(cmap, { token: window.MAPILLARY_TOKEN, labels: window.CC_BASE_LABELS });
    }
    editor = window.Cc.mountClimbEditor({
      map: cmap,
      hidden: { route: fld('route'), grad: fld('grad'), steep: fld('steep'), avg: fld('avg'), steepPoint: fld('steepPoint') },
      onHistory: function (depth) { if (undoBtn) undoBtn.hidden = 0 === depth; },
      onChange: function (st) {
        S.start = st.start; S.summit = st.summit; S.lengthKm = st.lengthKm;
        S.maxGrad = (st.steep && st.steep.pct) ? String(st.steep.pct) : '';
        // Average is measured ascent-only from the line (docs/specs/climb-elevation.md).
        S.avgMeasured = st.avg || '';
        var maxEl = document.getElementById('fMaxDisplay');
        if (maxEl) maxEl.value = S.maxGrad || '—';
        S.routing = st.routing; S.profiling = st.profiling;
        S.routeError = st.routeError; S.profileError = st.profileError;
        maybeRefreshLen();
        setReadout(); refreshGate();
      }
    });
  });

  function fmt(ll) { return ll ? ll[1].toFixed(3) + '°N ' + ll[0].toFixed(3) + '°E' : '…'; }

  function setReadout() {
    var el = document.getElementById('readout');
    if (!el) return;
    if (!S.start) { el.textContent = t('readout_initial'); return; }
    if (!S.summit) { el.textContent = t('readout_climb_summit'); return; }
    var txt = t('readout_climb_set');
    if (S.lengthKm) txt += ' · ' + t('climb_length', { '%km%': uKm(S.lengthKm) });
    if (S.routing || S.profiling || !S.lengthKm) txt += ' · ' + t('climb_measuring');
    else if (S.routeError) txt += ' — ' + t('climb_route_error');
    else if (S.profileError) txt += ' — ' + t('climb_profile_error');
    el.textContent = txt;
  }

  var resetBtn = document.getElementById('reset');
  if (resetBtn) {
    resetBtn.addEventListener('click', function () { if (editor) editor.reset(); });
    resetBtn.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); if (editor) editor.reset(); }
    });
  }
  if (undoBtn) {
    undoBtn.addEventListener('click', function () { if (editor) editor.undo(); });
    undoBtn.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); undoBtn.click(); }
    });
  }

  var searchEl = document.getElementById('placeSearch');
  var resultsEl = document.getElementById('searchResults');
  var searchT = null;
  var parseLatLng = (window.Cc && window.Cc.parseLatLng) || null;

  /* Pasted coords only fly the map — one pair cannot say which end of the climb. */
  function goCoord(pt) {
    if (!cmap) return;
    cmap.flyTo({ center: [pt.lng, pt.lat], zoom: 16 });
    if (resultsEl) resultsEl.hidden = true;
  }

  function resultNote(text) {
    var el = document.createElement('div');
    el.className = 'res empty';
    el.textContent = text;
    return el;
  }

  function renderCoord(pt) {
    if (!resultsEl) return;
    RC.clear(resultsEl);
    var row = document.createElement('div');
    row.className = 'res';
    var b = document.createElement('b');
    b.textContent = pt.lat.toFixed(6) + ', ' + pt.lng.toFixed(6);
    var small = document.createElement('small');
    small.textContent = t('search_coords_go');
    row.appendChild(b);
    row.appendChild(small);
    row.addEventListener('click', function () { goCoord(pt); });
    resultsEl.appendChild(row);
    resultsEl.hidden = false;
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
        cmap.flyTo({ center: [lng, lat], zoom: 14 });
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

  var searchSeq = 0;

  function geocode(q) {
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
      var q = searchEl.value.trim(); clearTimeout(searchT);
      var pt = parseLatLng && parseLatLng(q);
      if (pt) { searchSeq++; renderCoord(pt); return; }
      if (q.length < 3) { renderResults(null); return; }
      searchT = setTimeout(function () { geocode(q); }, 320);
    });
    searchEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault(); clearTimeout(searchT);
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

  function avgGrad() {
    var fLen = fld('fLen');
    var lenKm = fLen ? vKm(parseFloat(fLen.value) || 0) : 0;
    return lenKm > 0 ? (S.gain / (lenKm * 1000)) * 100 : 0;
  }

  var lenAutofill = null;

  function maybeRefreshLen() {
    var fLen = fld('fLen');
    if (!fLen || !S.lengthKm || S.routing) return;
    if (!fLen.value || fLen.value === lenAutofill) {
      lenAutofill = fLen.value = String(dKm(S.lengthKm));
      syncProfile();
    }
  }

  function onEnterProfile() {
    var fLen = fld('fLen');
    if (fLen && !fLen.value && S.lengthKm) lenAutofill = fLen.value = String(dKm(S.lengthKm));
    syncProfile();
  }

  function syncProfile() {
    var fName = fld('fName');
    var fGain = fld('fGain');
    var fSurface = fld('fSurface');
    var fSurfaceQ = fld('fSurfaceQ');
    var fTraffic = fld('fTraffic');
    var fAvg = fld('fAvg');
    var fAvgDisplay = document.getElementById('fAvgDisplay');

    S.name = fName ? fName.value.trim() : '';
    S.gain = fGain ? vElev(parseFloat(fGain.value) || 0) : 0;
    S.surface = fSurface ? fSurface.value : 'Asphalt';
    S.surfaceQ = fSurfaceQ ? fSurfaceQ.value : 'Smooth';
    S.traffic = fTraffic ? fTraffic.value : 'Traffic-free';

    var np = document.getElementById('namePreview');
    if (np) { np.textContent = S.name || t('name_placeholder'); np.classList.toggle('empty', !S.name); }

    /* Prefer measured average over gain/length (docs/specs/climb-elevation.md). */
    var measured = S.avgMeasured ? parseFloat(S.avgMeasured) : 0;
    var avg = measured || avgGrad();
    var avgStr = avg ? avg.toFixed(1) + ' %' : '—';
    if (fAvgDisplay) fAvgDisplay.value = avgStr;
    if (fAvg) fAvg.value = avg ? avg.toFixed(1) : '';

    refreshGate();
  }

  ['fName', 'fLen', 'fGain', 'fSurface', 'fSurfaceQ', 'fTraffic'].forEach(function (id) {
    var el = fld(id);
    if (el) el.addEventListener('input', syncProfile);
  });

  var fNoteEl = fld('fNote');
  if (fNoteEl) fNoteEl.addEventListener('input', function (e) { S.note = e.target.value; });
  var fOsmEl = fld('fOsm');
  if (fOsmEl) fOsmEl.addEventListener('input', function (e) { S.osm = e.target.value; });

  function renderReview() {
    var fLen = fld('fLen');
    var lenKm = (fLen ? vKm(parseFloat(fLen.value) || 0) : 0) || S.lengthKm || 0;
    var rb = document.getElementById('reviewBody');
    if (!rb) return;
    RC.clear(rb);

    function span(cls, text) {
      var el = document.createElement('span');
      if (cls) el.className = cls;
      el.textContent = text;
      return el;
    }

    rb.appendChild(span('co', t('review_loc', { '%foot%': fmt(S.start), '%summit%': fmt(S.summit) })));

    var h3 = document.createElement('h3');
    h3.textContent = S.name || t('name_placeholder');
    rb.appendChild(h3);

    var tags = document.createElement('div');
    tags.className = 'rtags';
    tags.appendChild(span('t-surf', S.surface + ' · ' + S.surfaceQ));
    tags.appendChild(span('t-traf t-' + S.traffic.toLowerCase().replace(/[^a-z]/g, ''), S.traffic));
    rb.appendChild(tags);

    rb.appendChild(RC.kvRow(t('label_length'), uKm(lenKm)));
    rb.appendChild(RC.kvRow(t('label_gain'), '△ ' + uElev(S.gain)));
    rb.appendChild(RC.kvRow(t('label_avg'), avgGrad().toFixed(1) + ' %'));
    rb.appendChild(RC.kvRow(t('label_max'), S.maxGrad ? S.maxGrad + ' %' : '—'));
    rb.appendChild(RC.kvRow(t('label_surface'), S.surface + ' · ' + S.surfaceQ));
    rb.appendChild(RC.kvRow(t('label_traffic'), S.traffic));
    rb.appendChild(RC.kvRow(t('label_osm'), S.osm));

    if (S.note) {
      var p = document.createElement('p');
      p.className = 'rnote';
      p.textContent = S.note;
      rb.appendChild(p);
    }
  }

  function submitClimb() {
    var doneName = document.getElementById('doneName');
    if (doneName) doneName.textContent = S.name || t('your_climb');

    var fLat = fld('lat');
    var fLng = fld('lng');
    if (fLat && S.start) fLat.value = S.start[1];
    if (fLng && S.start) fLng.value = S.start[0];

    var form = document.getElementById('add-climb-form');
    if (form) { form.submit(); return; }

    step(5);
    var yah = document.getElementById('youAreHere');
    if (yah) yah.classList.add('here');
  }

  refreshGate();
})();
