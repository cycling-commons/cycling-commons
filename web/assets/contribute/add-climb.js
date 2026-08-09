// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The add-a-climb wizard. It mounts the SAME three-point editor as /improve
   (climb-editor.js), so it follows the same two rules that wizard settled on
   in docs/plans/2026-08-01-improve-js-i18n.md:

   - strings crossing into JS are TEXT; markup stays in Twig, where |rich
     sanitises it;
   - nothing with a value in it is built with innerHTML. The review card and
     the search list both mix rider-entered text with catalogue strings, so
     they are built with createElement + textContent via
     assets/contribute/review-card.js. textContent cannot produce an element,
     which is what makes a rider's `<img src=x onerror=…>` render as visible
     characters instead of firing. */
(function () {
  'use strict';

  // Only run when the wizard is present (GET page, not POST receipt)
  var wiz = document.getElementById('wiz');
  if (!wiz) return;

  /* The rider's units (account-and-auth.md §9). cc-units.js (base.html.twig)
     owns the conversion; these are the classic-script way to reach it, with a
     metric fallback for the case the global never loaded. Everything passed in
     is metric — nothing here converts on the way into a form field. */
  function uKm(km) { return window.ccKm ? window.ccKm(km) : Number(km).toFixed(1) + ' km'; }
  function uElev(m) { return window.ccElev ? window.ccElev(m) : Math.round(Number(m)) + ' m'; }
  // The length and gain FIELDS are written and read in the rider's unit; S and
  // everything downstream of it stay metric, and AddClimbType's transformers
  // convert the submitted values back before the payload is built.
  function dKm(km) { return window.ccKmValue ? window.ccKmValue(km, 1) : Number(km).toFixed(1); }
  function vKm(v) { return window.ccKmFromValue ? window.ccKmFromValue(v) : (Number(v) || 0); }
  function vElev(v) { return window.ccElevFromValue ? window.ccElevFromValue(v) : (Number(v) || 0); }

  // Helper: look up a Symfony form field by its name attribute
  function fld(sfName) {
    return document.querySelector('[name="add_climb[' + sfName + ']"]');
  }

  /* ---------- strings ----------
     The bag is emitted by add_climb.html.twig with all four JSON_HEX_* flags,
     so a catalogue string containing `</script>` cannot close the block it is
     printed in. A missing key resolves to empty rather than to its own name: a
     rider should never be shown `add_climb.step1.readout_climb_set`, and
     tools/check-translations.sh is what stops a key going missing at all. */
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

  // The review step's DOM builders (assets/contribute/review-card.js).
  var RC = (window.Cc && window.Cc.reviewCard) || null;

  var S = {
    start: null, summit: null, lengthKm: 0, name: '', gain: 0,
    maxGrad: '', surface: 'Asphalt', surfaceQ: 'Smooth',
    traffic: 'Traffic-free', note: '', osm: 'Unknown',
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

  /* ---------- step 1: draw the segment (shared three-point editor) ---------- */
  var cmap = new maplibregl.Map({
    container: 'cmap',
    style: 'https://tiles.openfreemap.org/styles/liberty',
    center: [5.74, 50.49], zoom: 11, attributionControl: false
  });
  cmap.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
  cmap.addControl(new maplibregl.AttributionControl({ customAttribution: '© OpenStreetMap contributors · ODbL' }), 'bottom-right');

  var editor = null;
  // Undo is only offered once there is something to take back — a control that
  // is always there and usually dead teaches a rider nothing.
  var undoBtn = document.getElementById('undo');
  cmap.on('load', function () {
    // Satellite + Mapillary: tracing a hairpin over a flat vector basemap
    // is guesswork, and /map has had both for a long time.
    if (window.Cc && window.Cc.mountEditorBase) {
      window.Cc.mountEditorBase(cmap, { token: window.MAPILLARY_TOKEN, labels: window.CC_BASE_LABELS });
    }
    editor = window.Cc.mountClimbEditor({
      map: cmap,
      hidden: { route: fld('route'), grad: fld('grad'), steep: fld('steep'), avg: fld('avg'), steepPoint: fld('steepPoint') },
      onHistory: function (depth) { if (undoBtn) undoBtn.hidden = 0 === depth; },
      onChange: function (st) {
        S.start = st.start; S.summit = st.summit; S.lengthKm = st.lengthKm;
        // Max gradient IS the steepest marker's reading — never a typed field.
        S.maxGrad = (st.steep && st.steep.pct) ? String(st.steep.pct) : '';
        // Average likewise: the editor measures it ascent-only from the drawn
        // line (climb-elevation.md 4). Kept separate from the gain/length
        // fallback below so a profile that fails does not blank the figure.
        S.avgMeasured = st.avg || '';
        var maxEl = document.getElementById('fMaxDisplay');
        if (maxEl) maxEl.value = S.maxGrad || '—';
        S.routing = st.routing; S.profiling = st.profiling;
        S.routeError = st.routeError; S.profileError = st.profileError;
        maybeRefreshLen();
        setReadout(); refreshGate();
      }
    });
    /* The Wallonia boundary mask that used to be drawn here is gone, and with
       it the codebase's LAST Nominatim call. It hardcoded 'Wallonia' on what
       is now a worldwide wizard (wrong mask for a Swiss climb), it re-fetched
       a polygon we serve ourselves (/map/region/{slug}/boundary — the same
       endpoint that retired the MAP's Nominatim fetch, for the same
       usage-policy reason), and one geocode per page load could never respect
       Nominatim's 1 req/s at scale (owner question, 2026-08-09). A wizard has
       no single correct region to mask; if framing ever returns, it draws
       from our own boundary endpoint per located region. */
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

  // A one-line note in the results list ("No matches", "Search unavailable").
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

  /* Photon's response is third-party text, so it is built as nodes like
     everything else here — a place name is a name, never markup. The
     coordinates ride along in the closure instead of in data- attributes,
     which also removes the last string-into-attribute path in this file. */
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
        // update place hidden field
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
          RC.clear(resultsEl);
          resultsEl.appendChild(resultNote(t('search_unavailable')));
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
    var lenKm = fLen ? vKm(parseFloat(fLen.value) || 0) : 0;
    return lenKm > 0 ? (S.gain / (lenKm * 1000)) * 100 : 0;
  }

  // Last length value WE wrote into fLen — a user-typed value always wins, but
  // our own stale autofill may be replaced when OSRM returns the snapped length.
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

    /* Prefer the measured average over the derived-from-typed-numbers one.

       avgGrad() below is gain over length, where the gain is a number the rider
       typed. That is net gain, so a climb with a dip in it reads low - and it
       disagrees with the ascent-only figure the drawer and the improve flow
       publish for the same road. One definition, measured from the line, in
       both flows. The fallback stays for the case the elevation profile never
       resolved, so the field is not simply blank. */
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

  /* ---------- step 3: details ----------
     The "Targeted audience" chips were retired on 2026-08-03 (see the note in
     add_climb.html.twig): they submitted nothing. The gradient guidance they
     used to drive is now one static line rendered by the template, so there is
     nothing left for this file to recalculate. */

  var fNoteEl = fld('fNote');
  if (fNoteEl) fNoteEl.addEventListener('input', function (e) { S.note = e.target.value; });
  var fOsmEl = fld('fOsm');
  if (fOsmEl) fOsmEl.addEventListener('input', function (e) { S.osm = e.target.value; });

  /* ---------- step 4: review ----------
     Built as nodes, not markup. The climb name and the rider's note are the
     hostile inputs here — a curator and later the public read this card back,
     so nothing on it may become an element. */
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
    // The class is derived from the select's own value, never from free text.
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

  /* ---------- step 5: submit (client-side wizard; also triggers real POST) ---------- */
  function submitClimb() {
    var doneName = document.getElementById('doneName');
    if (doneName) doneName.textContent = S.name || t('your_climb');

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

  refreshGate();
})();
