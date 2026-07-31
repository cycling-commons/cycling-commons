// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
(function () {
  'use strict';

  // Only run when the wizard is present (GET page, not POST receipt)
  var wiz = document.getElementById('wiz');
  if (!wiz) return;

  // Read ?item= and ?mode= from URL (client-side only; controller does not process them)
  var _q = new URLSearchParams(location.search);
  var _id = _q.get('item') || '';
  var ADD = _q.get('mode') === 'add';

  // An existing item's coordinates, when we arrive from the map's "Edit this
  // item" link (?lat=&lng=). We show the map centred there so the contributor
  // can SEE and correct the location — not just when adding a new place.
  var initLat = parseFloat(_q.get('lat'));
  var initLng = parseFloat(_q.get('lng'));
  var hasCoords = !isNaN(initLat) && !isNaN(initLng);

  // "◎ Fix location" bridge from the drawer: open the LOCATE editor directly
  // in expanded (change) mode, because the intent is explicitly to move the pin.
  var RELOCATE = _q.get('fix') === 'location';

  // The catalog type + how to set its location come from the server (the
  // controller resolves ?type= into ItemType and renders these on #wiz).
  var _type = wiz.dataset.type || '';
  var _locMode = wiz.dataset.locationMode || 'point';
  var typeName = wiz.dataset.type ? wiz.dataset.type.replace(/-/g, ' ') : 'place';

  // Registry defaults (item coords when editing, else a generic Wallonia centre)
  var DEFAULTS = {
    center: hasCoords ? [initLng, initLat] : [5.86, 50.49],
    icon: wiz.dataset.icon || '✎'
  };

  // Locate mode: point (most), segment (road surface), none/track (ride).
  // Show the map when adding a place, OR when editing one that has coordinates
  // (so its location is visible and correctable); otherwise skip step 1.
  var LOCATE = (ADD || hasCoords || RELOCATE) ? _locMode : 'off';

  // Wizard state
  var WZ = { cur: 1, last: 4, loc: null, media: [] };
  // Uploads are owned by media-upload.js; nothing here queues anything.

  // Update subject hidden field with the item id
  var subjectEl = document.querySelector('[name="improve[subject]"]');
  if (subjectEl && _id) subjectEl.value = _id;

  // Update mode hidden field
  var modeEl = document.querySelector('[name="improve[mode]"]');
  if (modeEl && ADD) modeEl.value = 'add';

  // Helper: look up a Symfony form field by its name attribute
  function fld(sfName) {
    return document.querySelector('[name="improve[' + sfName + ']"]');
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* ---------- step navigation ---------- */
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
      nextBtn.textContent = n === WZ.last ? 'Submit for review →' : 'Next →';
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

  function refreshGate() {
    var nextBtn = document.getElementById('nextBtn');
    if (!nextBtn) return;
    if (WZ.cur === 1 && LOCATE !== 'off') {
      nextBtn.disabled = !WZ.loc;
    } else {
      nextBtn.disabled = false;
    }
  }

  var backBtn = document.getElementById('backBtn');
  var nextBtn = document.getElementById('nextBtn');
  if (backBtn) backBtn.addEventListener('click', function () { step(Math.max(WZ.cur - 1, LOCATE === 'off' ? 2 : 1)); });
  if (nextBtn) nextBtn.addEventListener('click', onNext);

  /* ---------- step 1: locate ---------- */
  function mkPin() {
    var el = document.createElement('div');
    el.className = 'cc-pin';
    el.innerHTML = '<span>◎</span>';
    return el;
  }

  var wmap = null;

  if (LOCATE !== 'off') {
    // point or segment — show map
    wmap = new maplibregl.Map({
      container: 'wmap',
      style: 'https://tiles.openfreemap.org/styles/liberty',
      center: DEFAULTS.center,
      zoom: hasCoords ? 14 : 12,
      attributionControl: false
    });
    wmap.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    wmap.addControl(new maplibregl.AttributionControl({ customAttribution: '© OpenStreetMap contributors · ODbL' }), 'bottom-right');

    /* ── Known-places overlay (ADD mode only) ────────────────────────────
       Coverage POIs near the view — the same OSM-derived reference layer
       as /map — so a rider placing a pin sees what the Commons already
       knows about and doesn't submit a duplicate. Same-letter only,
       zoom-gated, and the dots are non-interactive so pin taps pass
       straight through them. Best-effort context: any fetch failure just
       leaves the map bare, exactly as before. */
    var covLetter = window.CC_ITEM && window.CC_ITEM.letter;
    if (ADD && covLetter && 'B' !== covLetter) {
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
        // Skip refetching when the view barely moved — /map/coverage/nearby
        // is per-IP rate-limited (coverage-provider.md §5).
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

    // Editing a climb (letter B): the map hosts the shared three-point
    // editor (foot/summit/steepest) instead of the generic single-pin
    // Locate — route/grad/steep flow through moderation + change history
    // just like every other edited field (Task 5). Every other catalog
    // type keeps the single-pin/segment Locate exactly as before.
    var isClimb = !!(window.CC_ITEM && 'B' === window.CC_ITEM.letter);
    var climbEditor = null;
    var wzReset = document.getElementById('wzReset');

    if (isClimb) {
      var initial = (window.CC_ITEM.route || window.CC_ITEM.grad || window.CC_ITEM.steep)
        ? { route: window.CC_ITEM.route, grad: window.CC_ITEM.grad, steep: window.CC_ITEM.steep }
        : undefined;

      climbEditor = window.Cc.mountClimbEditor({
        map: wmap,
        hidden: { route: fld('route'), grad: fld('grad'), steep: fld('steep') },
        initial: initial,
        onChange: function (st) {
          var ro = document.getElementById('wz-readout');
          if (st.start && st.summit) {
            WZ.loc = { type: 'climb', start: st.start, summit: st.summit };
            if (ro) {
              var txt = '✓ Climb set' + (st.lengthKm ? ' · ' + st.lengthKm.toFixed(1) + ' km' : '');
              if (st.routing || st.profiling) txt += ' · measuring…';
              else if (st.routeError) txt += ' — could not snap to the road network, showing a straight line';
              else if (st.profileError) txt += ' — gradient profile unavailable';
              else txt += ' — drag a marker to correct it';
              ro.textContent = txt;
            }
          } else {
            WZ.loc = null;
            if (ro) ro.textContent = st.start ? '◎ Foot set — now tap the summit' : '◎ Tap the map to set the foot of the climb';
          }
          refreshGate();
        }
      });

      if (wzReset) {
        wzReset.addEventListener('click', function () { if (climbEditor) climbEditor.reset(); });
        wzReset.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); wzReset.click(); }
        });
      }
    } else {
      var placed = [];

      // Part C: an "Edit this item" bridge (known coords, not add, not fix=location)
      // opens a COMPACT, view-only confirm-map. confirmView gates click-to-reposition
      // until the contributor expands the editor.
      var CONFIRM = hasCoords && !ADD && !RELOCATE;
      var confirmView = CONFIRM && _locMode === 'point';
      var mapEl = document.getElementById('wmap');
      var searchWrap = document.querySelector('#w-locate .csearch');
      var changeBtn = document.getElementById('wzChange');
      var locHelp = document.querySelector('#w-locate .help');
      var locSection = document.getElementById('w-locate');
      var origHelp = locHelp ? locHelp.textContent : '';
      // Note: unlike `wzReset` (declared with `var` at this same outer function
      // scope above), `ro` only ever exists as a *local* inside nested callbacks
      // (syncLoc, the climb editor's onChange) — it does not hoist out to here.
      // Declare our own outer-scope handle so expandEditor()/the pre-place block
      // below can safely read the readout without a ReferenceError.
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
        if (ro) ro.textContent = '✓ ◎ location set — tap the map or drag the pin to move it';
        if (wmap) wmap.resize();
      }

      var fmt = function (ll) {
        return ll.lat.toFixed(4) + '°N ' + ll.lng.toFixed(4) + '°E';
      };

      var drawSeg = function () {
        if (!wmap.isStyleLoaded()) { wmap.once('idle', drawSeg); return; }
        var id = 'seg';
        if (wmap.getLayer(id)) wmap.removeLayer(id);
        if (wmap.getSource(id)) wmap.removeSource(id);
        if (placed.length < 2) return;
        wmap.addSource(id, { type: 'geojson', data: { type: 'Feature', geometry: { type: 'LineString', coordinates: placed.map(function (m) { return m.getLngLat().toArray(); }) } } });
        wmap.addLayer({ id: id, type: 'line', source: id, paint: { 'line-color': '#FF5A1F', 'line-width': 4 } });
      };

      var syncLoc = function () {
        var ro = document.getElementById('wz-readout');
        if (LOCATE === 'segment') {
          // Frontend review 2026-07-12 C6: the endpoints must reach the form,
          // not just WZ.loc — otherwise the POST silently drops the segment
          // while announceMove() claims it will be recorded.
          var fSeg = fld('segment');
          if (placed.length < 2) {
            WZ.loc = null;
            if (fSeg) fSeg.value = '';
            if (ro) ro.textContent = placed.length === 1 ? '◎ Now tap the end of the segment' : '◎ Tap the start of the segment';
          } else {
            WZ.loc = { type: 'segment', a: placed[0].getLngLat().toArray(), b: placed[1].getLngLat().toArray() };
            if (fSeg) fSeg.value = JSON.stringify({ a: WZ.loc.a, b: WZ.loc.b });
            if (ro) ro.textContent = '✓ ' + fmt(placed[0].getLngLat()) + ' → ' + fmt(placed[1].getLngLat());
          }
        } else {
          var fLat = fld('lat');
          var fLng = fld('lng');
          if (!placed.length) {
            WZ.loc = null;
            // Mirror the segment branch: a reset must clear the hidden fields
            // too, or the POST would still carry the previously placed pin.
            if (fLat) fLat.value = '';
            if (fLng) fLng.value = '';
            var fPlace = fld('place');
            if (fPlace) fPlace.value = '';
            if (ro) ro.textContent = '◎ Tap the map to set the location';
          } else {
            var ll = placed[0].getLngLat();
            WZ.loc = { type: 'point', lng: ll.lng, lat: ll.lat };
            // update hidden lat/lng fields
            if (fLat) fLat.value = ll.lat;
            if (fLng) fLng.value = ll.lng;
            if (ro) ro.textContent = '✓ ◎ ' + fmt(ll) + ' — drag the pin or tap again to move it';
          }
        }
        refreshGate();
      };

      // Confirm on release that the corrected location was captured — syncLoc()
      // has already written it to the hidden lat/lng fields the form submits.
      var announceMove = function () {
        if (WZ.loc && WZ.loc.type === 'point') {
          toast('Pin moved to ' + WZ.loc.lat.toFixed(4) + '°N ' + WZ.loc.lng.toFixed(4) + '°E — submit to record it');
        } else if (WZ.loc && WZ.loc.type === 'segment') {
          toast('Segment updated — submit to record it');
        }
      };

      wmap.on('click', function (e) {
        if (confirmView) return;   // compact confirm-map is view-only until expanded
        var need = LOCATE === 'segment' ? 2 : 1;
        if (placed.length >= need) { placed.forEach(function (m) { m.remove(); }); placed.length = 0; }
        var m = new maplibregl.Marker({ element: mkPin(), draggable: true, anchor: 'bottom' }).setLngLat(e.lngLat).addTo(wmap);
        m.on('dragend', function () { syncLoc(); drawSeg(); announceMove(); });
        placed.push(m);
        syncLoc();
        drawSeg();
      });

      // Editing a located point: pre-place the pin at the item's coordinates so
      // the map opens on it. In CONFIRM mode it is a compact, glowing, view-only
      // reassurance; "Change location" expands to the full editor.
      if (hasCoords && LOCATE === 'point') {
        if (CONFIRM) {
          if (mapEl) mapEl.classList.add('confirm');
          if (searchWrap) searchWrap.hidden = true;
          if (wzReset) wzReset.style.display = 'none';
          if (locSection && locSection.dataset.confirmHelp && locHelp) locHelp.textContent = locSection.dataset.confirmHelp;
          if (changeBtn) { changeBtn.hidden = false; changeBtn.addEventListener('click', expandEditor); }
        }
        wmap.on('load', function () {
          // CONFIRM shrinks #wmap to 190px AFTER the map was built at its full
          // height, so MapLibre's canvas is stale (tall) and the centred pin
          // renders below the visible 190px window — invisible. Resize to the
          // compact height and re-centre on the item so the glowing pin sits
          // dead-centre. (Non-CONFIRM opens are already full-height at build.)
          if (CONFIRM) { wmap.resize(); wmap.setCenter([initLng, initLat]); }
          var m = new maplibregl.Marker({ element: mkPin(), draggable: !CONFIRM, anchor: 'bottom' }).setLngLat([initLng, initLat]).addTo(wmap);
          if (CONFIRM) m.getElement().classList.add('glow');
          m.on('dragend', function () { syncLoc(); announceMove(); });
          placed.push(m);
          syncLoc();
          if (CONFIRM && ro) ro.textContent = '✓ ◎ location set — check it looks right';
        });
      }

      if (wzReset) {
        wzReset.addEventListener('click', function () {
          placed.forEach(function (m) { m.remove(); });
          placed.length = 0;
          drawSeg();
          syncLoc();
        });
        wzReset.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); wzReset.click(); }
        });
      }
    }

    // Photon geocode
    var searchEl = document.getElementById('wz-search');
    var resultsEl = document.getElementById('wz-results');
    var searchT = null;

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
          if (wmap) wmap.flyTo({ center: [+el.dataset.lng, +el.dataset.lat], zoom: 14 });
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
            resultsEl.innerHTML = '<div class="res empty">Search unavailable — tap the map instead</div>';
            resultsEl.hidden = false;
          }
        });
    }

    if (searchEl) {
      searchEl.addEventListener('input', function () {
        var q = searchEl.value.trim();
        clearTimeout(searchT);
        if (q.length < 3) { renderResults(null); return; }
        searchT = setTimeout(function () { geocode(q); }, 320);
      });
      searchEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          clearTimeout(searchT);
          var q = searchEl.value.trim();
          if (q.length >= 2) geocode(q);
        }
      });
    }

    document.addEventListener('click', function (e) {
      if (resultsEl && !e.target.closest('.csearch')) resultsEl.hidden = true;
    });
  }

  /* ---------- step 4: review ---------- */
  function renderReview() {
    var rb = document.getElementById('reviewBody');
    if (!rb) return;

    var locTxt = !WZ.loc ? '—'
      : WZ.loc.type === 'point' ? '◎ ' + WZ.loc.lat.toFixed(4) + '°N ' + WZ.loc.lng.toFixed(4) + '°E'
      : WZ.loc.type === 'segment' ? WZ.loc.a[1].toFixed(4) + '°N ' + WZ.loc.a[0].toFixed(4) + '°E → ' + WZ.loc.b[1].toFixed(4) + '°N ' + WZ.loc.b[0].toFixed(4) + '°E'
      : '◎ foot ' + WZ.loc.start[1].toFixed(4) + '°N ' + WZ.loc.start[0].toFixed(4) + '°E → summit ' + WZ.loc.summit[1].toFixed(4) + '°N ' + WZ.loc.summit[0].toFixed(4) + '°E';

    // Echo every detail/extra field the rider actually filled in (step 2), so the
    // review faithfully mirrors what will be submitted — not just Type/Location/Media.
    var fieldRows = '';
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
      if (!val) return;
      var labelEl = fieldEl.querySelector('label');
      var label = labelEl ? labelEl.textContent.trim() : ctrl.name;
      fieldRows += '<div class="kv"><span>' + escHtml(label) + '</span><span>'
        + escHtml(val.length > 120 ? val.slice(0, 120) + '…' : val) + '</span></div>';
    });

    var media = WZ.media.length ? WZ.media.join(' · ') : 'none added';

    rb.innerHTML =
      '<div class="kv"><span>Type</span><span>' + escHtml(typeName) + '</span></div>' +
      '<div class="kv"><span>Location</span><span>' + escHtml(locTxt) + '</span></div>' +
      fieldRows +
      '<div class="kv"><span>Media</span><span>' + escHtml(media) + '</span></div>';
  }

  /* ---------- submit (real form POST) ---------- */
  function submitImprove() {
    var form = document.getElementById('improve-form');
    if (form) { form.submit(); return; }
  }

  /* ---------- media ----------
     Real uploads live in media-upload.js (docs/specs/photo-uploads.md §4),
     which owns the drop zone, the file input, the server-backed consent gate
     and the per-file progress bars. What stays here is the photo *link* row:
     a link is reviewer context, never an upload, and is not gated by the
     upload consent. */

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

  /* A linked photo is not an upload and must not look like one: no progress
     bar, no thumbnail, a distinct 🔗 mark. */
  function noteLink(label) {
    var chip = document.createElement('span');
    chip.className = 'chip';
    chip.textContent = '🔗 ' + label;
    var host = document.getElementById('q-photo');
    if (host) host.appendChild(chip);
    WZ.media.push('🔗 ' + label);
  }

  var KNOWN_SOURCES = {
    'commons.wikimedia.org': { name: 'Wikimedia Commons', note: 'author &amp; licence read from the Commons file page and validated automatically' },
    'wikipedia.org': { name: 'Wikipedia', note: 'author &amp; licence read from Wikimedia and validated automatically' },
    'flickr.com': { name: 'Flickr', note: 'rights-holder &amp; licence read from Flickr and validated automatically' },
    'unsplash.com': { name: 'Unsplash', note: 'Unsplash licence detected automatically' },
    'youtube.com': { name: 'YouTube', note: 'channel &amp; licence read from YouTube — Creative Commons clips only' },
    'vimeo.com': { name: 'Vimeo', note: 'creator &amp; licence read from Vimeo — Creative Commons clips only' }
  };

  function linkMedia(kind) {
    var inputEl = document.getElementById('lnk-' + kind);
    if (!inputEl) return;
    var url = inputEl.value.trim();
    if (!url) return;
    var run = function () {
      var note = document.getElementById('srcn-' + kind);
      var host = '';
      try { host = new URL(url).hostname.replace(/^www\./, ''); } catch (e) { host = ''; }
      // Exact host or a true subdomain — a bare endsWith would match e.g. "notflickr.com".
      var key = Object.keys(KNOWN_SOURCES).find(function (k) { return host === k || host.endsWith('.' + k); });
      if (key) {
        var s = KNOWN_SOURCES[key];
        if (note) { note.className = 'src-note ok'; note.innerHTML = '✓ <b>' + s.name + '</b> recognised — ' + s.note + '.'; }
        noteLink(host + ' link');
      } else {
        if (note) { note.className = 'src-note manual'; note.innerHTML = '⚠ Unknown source — you\'ll need to confirm the rights-holder and licence yourself before this can go public.'; }
        noteLink((host || 'link') + ' · needs licence');
      }
      inputEl.value = '';
      inputEl.focus();
    };
    run();
  }

  // Real uploads (docs/specs/photo-uploads.md §4). The module owns the drop
  // zone, the file input, the consent gate and the per-file progress bars; the
  // wizard only needs the names for its review step.
  var mediaField = fld('mediaIds');
  if (mediaField && window.Cc && window.Cc.mountMediaUploads) {
    window.Cc.mountMediaUploads({
      hidden: mediaField,
      onChange: function (names) {
        WZ.media = names.map(function (n) { return '📷 ' + n; });
      }
    });
  }

  // Wire up the photo link button
  var btnLinkPhoto = document.getElementById('btn-link-photo');
  if (btnLinkPhoto) btnLinkPhoto.addEventListener('click', function () { linkMedia('photo'); });

  // If not in add mode, step 1 has no map gate — allow immediate Next.
  if (LOCATE === 'off') {
    WZ.loc = { type: 'none' };
    // Add-a-field bridge (a drawer "+ add" prompt): there is no location to
    // set, so SKIP the LOCATE step entirely rather than showing a dead, empty
    // map pane. Start on step 2 and drop the step-1 chip from the stepper.
    var _step1Label = document.getElementById('step-label-1');
    if (_step1Label) _step1Label.style.display = 'none';
    step(2);
    // Deep-link straight to the field the "+ add" prompt targeted (?field=key).
    // Catalog attribute fields are nested under the `details`/`extras` sub-forms
    // (ImproveType), not flat improve[<key>] — so query both. Guard the key to
    // an alphabetic token so it can't break the selector.
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
