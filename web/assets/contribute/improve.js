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
  var queues = { photo: [], video: [] };
  var _pendingDone = null;

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
    if (backBtn) backBtn.style.visibility = n > 1 ? 'visible' : 'hidden';
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
  if (backBtn) backBtn.addEventListener('click', function () { step(WZ.cur - 1); });
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
      var confirmView = CONFIRM;
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
          var m = new maplibregl.Marker({ element: mkPin(), draggable: true, anchor: 'bottom' }).setLngLat([initLng, initLat]).addTo(wmap);
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

  /* ---------- media: consent + queue ---------- */
  var RULES = {
    photo: { noun: 'photo', rule: 'Only photos you took, or are licensed to share — never images scraped or re-hosted from another site.' },
    video: { noun: 'video', rule: 'Only clips you filmed yourself — no copyrighted music or footage.' }
  };

  var consented = function (k) { return localStorage.getItem('cc_consent_' + k) === '1'; };

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

  function queueAdd(kind, name) {
    queues[kind].push(name);
    var qEl = document.getElementById('q-' + kind);
    if (qEl) {
      qEl.innerHTML = queues[kind].map(function (n) {
        return '<span class="chip"><span class="dot"></span>' + escHtml(n) + ' · queued</span>';
      }).join('');
    }
    toast(name + ' → added to your upload queue · demo, nothing is actually uploaded');
    WZ.media = queues.photo.map(function (n) { return '📷 ' + n; }).concat(queues.video.map(function (n) { return '🎬 ' + n; }));
  }

  function openConsent(kind, onDone) {
    _pendingDone = onDone;
    var r = RULES[kind];
    var box = document.getElementById('modal-box');
    if (!box) return;
    box.innerHTML =
      '<h3>Before you add a ' + r.noun + '</h3>' +
      '<p>Your ' + r.noun + ' joins the <b>open Commons</b> so every rider can use it. That only works if it\'s genuinely free to share.</p>' +
      '<div class="rules"><b>The rules.</b> ' + r.rule + ' You keep ownership — the Commons just gets a licence to share it.</div>' +
      '<label class="ok-check"><input type="checkbox" id="ok-check" /><span>I took / own this ' + r.noun + ' and I\'m <b>donating</b> it to the Commons under <b>CC BY-SA 4.0</b>.</span></label>' +
      '<div class="mrow"><button class="b-cancel" id="modal-cancel">Cancel</button><button class="b-ok" id="ok-btn" disabled>Donate it</button></div>';
    var okCheck = document.getElementById('ok-check');
    var okBtn = document.getElementById('ok-btn');
    var cancelBtn = document.getElementById('modal-cancel');
    if (okCheck && okBtn) okCheck.onchange = function (e) { okBtn.disabled = !e.target.checked; };
    if (okBtn) okBtn.onclick = function () { acceptConsent(kind); };
    if (cancelBtn) cancelBtn.onclick = closeModal;
    openModal();
  }

  function acceptConsent(kind) {
    localStorage.setItem('cc_consent_' + kind, '1');
    var box = document.getElementById('modal-box');
    if (!box) return;
    box.innerHTML =
      '<div class="thanks-big"><div class="ic">🙏</div><h3>Thank you!</h3>' +
      '<p>You just made the map better for everyone who rides here.</p>' +
      '<div class="demo-note">This is a demo — nothing is actually uploaded.</div>' +
      '<div class="mrow" style="justify-content:center;margin-top:1.1rem"><button class="b-ok" id="finish-consent">Nice</button></div></div>';
    var finBtn = document.getElementById('finish-consent');
    if (finBtn) finBtn.onclick = function () { closeModal(); var d = _pendingDone; _pendingDone = null; if (d) d(); };
  }

  function upload(kind) {
    var run = function () {
      queueAdd(kind, kind === 'photo' ? 'IMG_' + (1000 + queues.photo.length) + '.jpg' : 'CLIP_' + (1 + queues.video.length) + '.mp4');
    };
    consented(kind) ? run() : openConsent(kind, run);
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
        queueAdd(kind, host + ' link');
      } else {
        if (note) { note.className = 'src-note manual'; note.innerHTML = '⚠ Unknown source — you\'ll need to confirm the rights-holder and licence yourself before this can go public.'; }
        queueAdd(kind, (host || 'link') + ' · needs licence');
      }
      inputEl.value = '';
      inputEl.focus();
    };
    consented(kind) ? run() : openConsent(kind, run);
  }

  // Wire up media drop zones
  var dropPhoto = document.getElementById('drop-photo');
  if (dropPhoto) dropPhoto.addEventListener('click', function () { upload('photo'); });
  var dropVideo = document.getElementById('drop-video');
  if (dropVideo) dropVideo.addEventListener('click', function () { upload('video'); });

  // Wire up link buttons
  var btnLinkPhoto = document.getElementById('btn-link-photo');
  if (btnLinkPhoto) btnLinkPhoto.addEventListener('click', function () { linkMedia('photo'); });
  var btnLinkVideo = document.getElementById('btn-link-video');
  if (btnLinkVideo) btnLinkVideo.addEventListener('click', function () { linkMedia('video'); });

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
