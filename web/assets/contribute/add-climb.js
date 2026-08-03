// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
(function () {
  'use strict';

  // Only run when the wizard is present (GET page, not POST receipt)
  var wiz = document.getElementById('wiz');
  if (!wiz) return;

  // Helper: look up a Symfony form field by its name attribute
  function fld(sfName) {
    return document.querySelector('[name="add_climb[' + sfName + ']"]');
  }

  var S = {
    start: null, summit: null, lengthKm: 0, name: '', gain: 0,
    maxGrad: '', surface: 'Asphalt', surfaceQ: 'Smooth',
    traffic: 'Traffic-free', disciplines: ['Road'], note: '', osm: 'Unknown',
    // Editor in-flight/failure signals (climb-editor.js onChange): the wizard
    // must not advance/submit a 2-point placeholder while OSRM/elevation is pending.
    routing: false, profiling: false, routeError: false, profileError: false
  };
  var cur = 1;

  /* ---------- step navigation ---------- */
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
      next.textContent = n === 4 ? 'Submit for review →' : 'Next →';
      next.style.display = n === 5 ? 'none' : 'inline-flex';
    }
    if (n === 2) onEnterProfile();
    if (n === 3) updateAudienceHint();
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

  /* ---------- step 1: draw the segment (shared three-point editor) ---------- */
  var cmap = new maplibregl.Map({
    container: 'cmap',
    style: 'https://tiles.openfreemap.org/styles/liberty',
    center: [5.74, 50.49], zoom: 11, attributionControl: false
  });
  cmap.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
  cmap.addControl(new maplibregl.AttributionControl({ customAttribution: '© OpenStreetMap contributors · ODbL' }), 'bottom-right');

  var editor = null;
  cmap.on('load', function () {
    editor = window.Cc.mountClimbEditor({
      map: cmap,
      hidden: { route: fld('route'), grad: fld('grad'), steep: fld('steep') },
      onChange: function (st) {
        S.start = st.start; S.summit = st.summit; S.lengthKm = st.lengthKm;
        S.routing = st.routing; S.profiling = st.profiling;
        S.routeError = st.routeError; S.profileError = st.profileError;
        maybeRefreshLen();
        setReadout(); refreshGate();
      }
    });
    addRegionBoundary('Wallonia');
  });

  function addRegionBoundary(name) {
    fetch('https://nominatim.openstreetmap.org/search?q=' + encodeURIComponent(name) + '&format=jsonv2&polygon_geojson=1&limit=1')
      .then(function (r) { return r.json(); }).then(function (d) {
        if (!d[0] || !d[0].geojson || cmap.getSource('region')) return;
        var g = d[0].geojson;
        var polys = g.type === 'MultiPolygon' ? g.coordinates : [g.coordinates];
        var world = [[-180, -85], [180, -85], [180, 85], [-180, 85], [-180, -85]];
        var mask = { type: 'Feature', geometry: { type: 'Polygon', coordinates: [world].concat(polys.map(function (p) { return p[0]; })) } };
        cmap.addSource('region-mask', { type: 'geojson', data: mask });
        cmap.addSource('region', { type: 'geojson', data: { type: 'Feature', geometry: g } });
        // Insert below the editor's drawn-route layer so the climb line stays on top.
        var beforeId = cmap.getLayer('cc-climb-editor-route') ? 'cc-climb-editor-route' : undefined;
        cmap.addLayer({ id: 'region-mask', type: 'fill', source: 'region-mask', paint: { 'fill-color': '#101E16', 'fill-opacity': 0.2 } }, beforeId);
        cmap.addLayer({ id: 'region-line', type: 'line', source: 'region', paint: { 'line-color': '#C8923A', 'line-width': 2.5, 'line-dasharray': [2, 1.4], 'line-opacity': 0.9 } }, beforeId);
      }).catch(function () {});
  }

  function fmt(ll) { return ll ? ll[1].toFixed(3) + '°N ' + ll[0].toFixed(3) + '°E' : '…'; }

  function setReadout() {
    var el = document.getElementById('readout');
    if (!el) return;
    if (!S.start) { el.textContent = 'Tap the map to set the foot of the climb.'; return; }
    if (!S.summit) { el.textContent = 'Foot set — now tap the summit.'; return; }
    var txt = 'Climb set' + (S.lengthKm ? ' · ' + S.lengthKm.toFixed(1) + ' km' : '');
    if (S.routing || S.profiling || !S.lengthKm) txt += ' · measuring…';
    else if (S.routeError) txt += ' — could not snap to the road network, showing a straight line';
    else if (S.profileError) txt += ' — gradient profile unavailable';
    el.textContent = txt;
  }

  var resetBtn = document.getElementById('reset');
  if (resetBtn) {
    resetBtn.addEventListener('click', function () { if (editor) editor.reset(); });
    resetBtn.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); if (editor) editor.reset(); }
    });
  }

  // place search — geocode with Photon (keyless, OSM-based)
  var searchEl = document.getElementById('placeSearch');
  var resultsEl = document.getElementById('searchResults');
  var searchT = null;
  var parseLatLng = (window.Cc && window.Cc.parseLatLng) || null;

  /* A coordinate copied off the map (right-click there) is an answer, not a
     query — Photon would return "No matches" for it and leave the map on its
     default centre. Here the paste only moves the view: the foot and summit
     stay the three-point editor's to place, since one pair of coordinates
     cannot say which end of the climb it is. */
  function goCoord(pt) {
    if (!cmap) return;
    cmap.flyTo({ center: [pt.lng, pt.lat], zoom: 16 });
    if (resultsEl) resultsEl.hidden = true;
  }

  function renderCoord(pt) {
    if (!resultsEl) return;
    resultsEl.innerHTML = '';
    var row = document.createElement('div');
    row.className = 'res';
    var b = document.createElement('b');
    b.textContent = pt.lat.toFixed(6) + ', ' + pt.lng.toFixed(6);
    var small = document.createElement('small');
    small.textContent = 'Coordinates — tap to jump here';
    row.appendChild(b);
    row.appendChild(small);
    row.addEventListener('click', function () { goCoord(pt); });
    resultsEl.appendChild(row);
    resultsEl.hidden = false;
  }

  function renderResults(list) {
    if (!resultsEl) return;
    if (!list) { resultsEl.hidden = true; resultsEl.innerHTML = ''; return; }
    if (!list.length) { resultsEl.innerHTML = '<div class="res empty">No matches</div>'; resultsEl.hidden = false; return; }
    resultsEl.innerHTML = list.map(function (f) {
      var p = f.properties || {}, c = f.geometry.coordinates;
      // Coerce before interpolating into the attribute — API strings never reach the markup raw.
      var lng = +c[0], lat = +c[1];
      if (!isFinite(lng) || !isFinite(lat)) return '';
      var main = p.name || p.street || p.city || 'Result';
      var sub = [p.name ? p.street : '', p.city, p.county, p.state, p.country].filter(Boolean).join(', ');
      return '<div class="res" data-lng="' + lng + '" data-lat="' + lat + '"><b>' + escHtml(main) + '</b><small>' + escHtml(sub) + '</small></div>';
    }).join('');
    resultsEl.hidden = false;
    resultsEl.querySelectorAll('.res[data-lat]').forEach(function (el) {
      el.addEventListener('click', function () {
        cmap.flyTo({ center: [+el.dataset.lng, +el.dataset.lat], zoom: 14 });
        if (searchEl) searchEl.value = el.querySelector('b').textContent;
        // update place hidden field
        var fPlace = fld('place');
        if (fPlace) fPlace.value = el.querySelector('b').textContent;
        resultsEl.hidden = true;
      });
    });
  }

  var searchSeq = 0;

  function geocode(q) {
    // Drop out-of-order responses (Enter bypasses the debounce, so a slow
    // earlier request can otherwise overwrite a fresher result list).
    var seq = ++searchSeq;
    fetch('https://photon.komoot.io/api/?q=' + encodeURIComponent(q) + '&limit=6')
      .then(function (r) { return r.json(); }).then(function (d) {
        if (seq !== searchSeq) return;
        renderResults(d.features || []);
      })
      .catch(function () {
        if (seq !== searchSeq) return;
        if (resultsEl) {
          resultsEl.innerHTML = '<div class="res empty">Search unavailable — click the map instead</div>';
          resultsEl.hidden = false;
        }
      });
  }

  if (searchEl) {
    searchEl.addEventListener('input', function () {
      var q = searchEl.value.trim(); clearTimeout(searchT);
      // ++searchSeq drops any geocode still in flight, so a slow reply for the
      // half-typed query cannot land on top of the coordinate row.
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

  /* ---------- step 2: profile ---------- */
  function avgGrad() {
    var fLen = fld('fLen');
    var len = fLen ? (parseFloat(fLen.value) || 0) : 0;
    return len > 0 ? (S.gain / (len * 1000)) * 100 : 0;
  }

  // Last length value WE wrote into fLen — a user-typed value always wins, but
  // our own stale autofill may be replaced when OSRM returns the snapped length.
  var lenAutofill = null;

  function maybeRefreshLen() {
    var fLen = fld('fLen');
    if (!fLen || !S.lengthKm || S.routing) return;
    if (!fLen.value || fLen.value === lenAutofill) {
      lenAutofill = fLen.value = S.lengthKm.toFixed(1);
      syncProfile();
    }
  }

  function onEnterProfile() {
    var fLen = fld('fLen');
    if (fLen && !fLen.value && S.lengthKm) lenAutofill = fLen.value = S.lengthKm.toFixed(1);
    syncProfile();
  }

  function syncProfile() {
    var fName = fld('fName');
    var fGain = fld('fGain');
    var fMax = fld('fMax');
    var fSurface = fld('fSurface');
    var fSurfaceQ = fld('fSurfaceQ');
    var fTraffic = fld('fTraffic');
    var fAvg = fld('fAvg');
    var fAvgDisplay = document.getElementById('fAvgDisplay');

    S.name = fName ? fName.value.trim() : '';
    S.gain = fGain ? (parseFloat(fGain.value) || 0) : 0;
    S.maxGrad = fMax ? fMax.value : '';
    S.surface = fSurface ? fSurface.value : 'Asphalt';
    S.surfaceQ = fSurfaceQ ? fSurfaceQ.value : 'Smooth';
    S.traffic = fTraffic ? fTraffic.value : 'Traffic-free';

    var np = document.getElementById('namePreview');
    if (np) { np.textContent = S.name || 'Unnamed climb'; np.classList.toggle('empty', !S.name); }

    var avg = avgGrad();
    var avgStr = avg ? avg.toFixed(1) + ' %' : '—';
    if (fAvgDisplay) fAvgDisplay.value = avgStr;
    if (fAvg) fAvg.value = avg ? avg.toFixed(1) : '';

    refreshGate();
  }

  ['fName', 'fLen', 'fGain', 'fMax', 'fSurface', 'fSurfaceQ', 'fTraffic'].forEach(function (id) {
    var el = fld(id);
    if (el) el.addEventListener('input', syncProfile);
  });

  /* ---------- step 3: details ---------- */
  function updateAudienceHint() {
    var hint = document.getElementById('audienceHint'); if (!hint) return;
    var avg = avgGrad();
    var hasHandbike = S.disciplines.indexOf('Handbike') >= 0;
    if (hasHandbike) {
      if (avg > 6) {
        hint.innerHTML = '⚠ ' + avg.toFixed(1) + '% avg is steep for handbikes — most handcyclists sustain ~6% (≈10% on short ramps). Note an accessible alternative if there is one.';
        hint.style.color = 'var(--clay)';
      } else if (avg > 0) {
        hint.textContent = '~' + avg.toFixed(1) + '% avg — within reach for many handcyclists (≈6% sustained is a common comfortable max).';
        hint.style.color = '';
      } else {
        hint.textContent = 'Handbike: most handcyclists sustain ~6% (≈10% on short ramps).';
        hint.style.color = '';
      }
    } else {
      hint.textContent = 'Rough gradient ceilings — road & MTB: 15%+ · gravel ~12% (traction) · loaded touring ~10% · handbike ~6%.';
      hint.style.color = '';
    }
  }

  document.querySelectorAll('#disc .chip').forEach(function (c) {
    c.setAttribute('role', 'button'); c.setAttribute('tabindex', '0');
    c.setAttribute('aria-pressed', c.classList.contains('on') ? 'true' : 'false');
    function toggleChip() {
      c.classList.toggle('on');
      var on = c.classList.contains('on');
      c.setAttribute('aria-pressed', on ? 'true' : 'false');
      var k = c.textContent.trim();
      var i = S.disciplines.indexOf(k);
      if (on && i < 0) S.disciplines.push(k);
      else if (!on && i >= 0) S.disciplines.splice(i, 1);
      updateAudienceHint();
    }
    c.addEventListener('click', toggleChip);
    c.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleChip(); }
    });
  });

  var fNoteEl = fld('fNote');
  if (fNoteEl) fNoteEl.addEventListener('input', function (e) { S.note = e.target.value; });
  var fOsmEl = fld('fOsm');
  if (fOsmEl) fOsmEl.addEventListener('input', function (e) { S.osm = e.target.value; });

  /* ---------- step 4: review ---------- */
  function renderReview() {
    var fLen = fld('fLen');
    var len = ((fLen ? parseFloat(fLen.value) : 0) || S.lengthKm || 0).toFixed(1);
    var rb = document.getElementById('reviewBody');
    if (!rb) return;
    rb.innerHTML =
      '<span class="co">Foot ' + fmt(S.start) + ' → Summit ' + fmt(S.summit) + '</span>' +
      '<h3>' + escHtml(S.name || 'Unnamed climb') + '</h3>' +
      '<div class="rtags">' + S.disciplines.map(function (d) { return '<span>' + escHtml(d) + '</span>'; }).join('') +
      '<span class="t-surf">' + escHtml(S.surface) + ' · ' + escHtml(S.surfaceQ) + '</span>' +
      '<span class="t-traf t-' + S.traffic.toLowerCase().replace(/[^a-z]/g, '') + '">' + escHtml(S.traffic) + '</span></div>' +
      '<div class="kv"><span>Length</span><span>' + len + ' km</span></div>' +
      '<div class="kv"><span>Elevation gain</span><span>△ ' + S.gain + ' m</span></div>' +
      '<div class="kv"><span>Avg gradient</span><span>' + avgGrad().toFixed(1) + ' %</span></div>' +
      '<div class="kv"><span>Max gradient</span><span>' + (S.maxGrad ? escHtml(S.maxGrad) + ' %' : '—') + '</span></div>' +
      '<div class="kv"><span>Surface</span><span>' + escHtml(S.surface) + ' · ' + escHtml(S.surfaceQ) + '</span></div>' +
      '<div class="kv"><span>Traffic</span><span>' + escHtml(S.traffic) + '</span></div>' +
      '<div class="kv"><span>Already in OSM?</span><span>' + escHtml(S.osm) + '</span></div>' +
      (S.note ? '<p style="margin-top:.7rem;font-size:.9rem">' + escHtml(S.note) + '</p>' : '');
  }

  /* ---------- step 5: submit (client-side wizard; also triggers real POST) ---------- */
  function submitClimb() {
    var doneName = document.getElementById('doneName');
    if (doneName) doneName.textContent = S.name || 'your climb';

    // Sync geocoded coords to hidden form fields before submit
    var fLat = fld('lat');
    var fLng = fld('lng');
    if (fLat && S.start) fLat.value = S.start[1];
    if (fLng && S.start) fLng.value = S.start[0];

    // Submit the real form so server records the contribution
    var form = document.getElementById('add-climb-form');
    if (form) { form.submit(); return; }

    // Fallback: just advance client-side wizard
    step(5);
    var yah = document.getElementById('youAreHere');
    if (yah) yah.classList.add('here');
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  refreshGate();
})();
