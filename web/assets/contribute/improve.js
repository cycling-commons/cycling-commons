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
  var LOCATE = (ADD || hasCoords) ? _locMode : 'off';

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
    // Update step 1 label for ride/track
    if (LOCATE === 'none') {
      var sl1 = document.getElementById('step-label-1');
      if (sl1) sl1.textContent = 'Track';
      var locateEl = document.getElementById('w-locate');
      if (locateEl) {
        locateEl.innerHTML =
          '<h2>Add your track</h2>' +
          '<p class="help">Your GPX/FIT recording sets the whole route. Drop it here — we\'ll derive distance and elevation.</p>' +
          '<div class="drop" id="wz-track"><span class="ic">▲</span>Drop a GPX or FIT file, or click to choose<br/><small>.gpx / .fit · your own recording only</small></div>' +
          '<div class="ulq" id="wz-trackq"></div>' +
          '<div class="notice"><b>Tracks join the Commons as ODbL 1.0.</b> Upload only rides you recorded yourself.</div>';
        var trackDrop = document.getElementById('wz-track');
        if (trackDrop) {
          trackDrop.addEventListener('click', function () {
            WZ.loc = { type: 'track', name: 'RIDE_' + (Math.floor(performance.now()) % 9000 + 1000) + '.gpx' };
            var tq = document.getElementById('wz-trackq');
            if (tq) tq.innerHTML = '<span class="chip"><span class="dot"></span>' + escHtml(WZ.loc.name) + ' · queued</span>';
            refreshGate();
          });
        }
      }
    } else {
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

      var placed = [];

      function fmt(ll) {
        return ll.lat.toFixed(4) + '°N ' + ll.lng.toFixed(4) + '°E';
      }

      function drawSeg() {
        if (!wmap.isStyleLoaded()) { wmap.once('idle', drawSeg); return; }
        var id = 'seg';
        if (wmap.getLayer(id)) wmap.removeLayer(id);
        if (wmap.getSource(id)) wmap.removeSource(id);
        if (placed.length < 2) return;
        wmap.addSource(id, { type: 'geojson', data: { type: 'Feature', geometry: { type: 'LineString', coordinates: placed.map(function (m) { return m.getLngLat().toArray(); }) } } });
        wmap.addLayer({ id: id, type: 'line', source: id, paint: { 'line-color': '#FF5A1F', 'line-width': 4 } });
      }

      function syncLoc() {
        var ro = document.getElementById('wz-readout');
        if (LOCATE === 'segment') {
          if (placed.length < 2) {
            WZ.loc = null;
            if (ro) ro.textContent = placed.length === 1 ? '◎ Now tap the end of the segment' : '◎ Tap the start of the segment';
          } else {
            WZ.loc = { type: 'segment', a: placed[0].getLngLat().toArray(), b: placed[1].getLngLat().toArray() };
            if (ro) ro.textContent = '✓ ' + fmt(placed[0].getLngLat()) + ' → ' + fmt(placed[1].getLngLat());
          }
        } else {
          if (!placed.length) {
            WZ.loc = null;
            if (ro) ro.textContent = '◎ Tap the map to set the location';
          } else {
            var ll = placed[0].getLngLat();
            WZ.loc = { type: 'point', lng: ll.lng, lat: ll.lat };
            // update hidden lat/lng fields
            var fLat = fld('lat');
            var fLng = fld('lng');
            if (fLat) fLat.value = ll.lat;
            if (fLng) fLng.value = ll.lng;
            if (ro) ro.textContent = '✓ ◎ ' + fmt(ll) + ' — drag the pin or tap again to move it';
          }
        }
        refreshGate();
      }

      // Confirm on release that the corrected location was captured — syncLoc()
      // has already written it to the hidden lat/lng fields the form submits.
      function announceMove() {
        if (WZ.loc && WZ.loc.type === 'point') {
          toast('Pin moved to ' + WZ.loc.lat.toFixed(4) + '°N ' + WZ.loc.lng.toFixed(4) + '°E — submit to record it');
        } else if (WZ.loc && WZ.loc.type === 'segment') {
          toast('Segment updated — submit to record it');
        }
      }

      wmap.on('click', function (e) {
        var need = LOCATE === 'segment' ? 2 : 1;
        if (placed.length >= need) { placed.forEach(function (m) { m.remove(); }); placed.length = 0; }
        var m = new maplibregl.Marker({ element: mkPin(), draggable: true, anchor: 'bottom' }).setLngLat(e.lngLat).addTo(wmap);
        m.on('dragend', function () { syncLoc(); drawSeg(); announceMove(); });
        placed.push(m);
        syncLoc();
        drawSeg();
      });

      // Editing a located point: pre-place the pin at the item's coordinates so
      // the map opens on it and the contributor can drag to correct it.
      if (hasCoords && LOCATE === 'point') {
        wmap.on('load', function () {
          var m = new maplibregl.Marker({ element: mkPin(), draggable: true, anchor: 'bottom' }).setLngLat([initLng, initLat]).addTo(wmap);
          m.on('dragend', function () { syncLoc(); announceMove(); });
          placed.push(m);
          syncLoc();
        });
      }

      var wzReset = document.getElementById('wzReset');
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
          var main = p.name || p.street || p.city || 'Result';
          var sub = [p.name ? p.street : '', p.city, p.county, p.state, p.country].filter(Boolean).join(', ');
          return '<div class="res" data-lng="' + c[0] + '" data-lat="' + c[1] + '"><b>' + escHtml(main) + '</b><small>' + escHtml(sub) + '</small></div>';
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

      function geocode(q) {
        fetch('https://photon.komoot.io/api/?q=' + encodeURIComponent(q) + '&limit=6')
          .then(function (r) { return r.json(); }).then(function (d) { renderResults(d.features || []); })
          .catch(function () {
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
  }

  /* ---------- step 4: review ---------- */
  function renderReview() {
    var rb = document.getElementById('reviewBody');
    if (!rb) return;

    var locTxt = !WZ.loc ? '—'
      : WZ.loc.type === 'point' ? '◎ ' + WZ.loc.lat.toFixed(4) + '°N ' + WZ.loc.lng.toFixed(4) + '°E'
      : WZ.loc.type === 'segment' ? WZ.loc.a[1].toFixed(4) + '°N ' + WZ.loc.a[0].toFixed(4) + '°E → ' + WZ.loc.b[1].toFixed(4) + '°N ' + WZ.loc.b[0].toFixed(4) + '°E'
      : 'Track · ' + WZ.loc.name;

    var whatChangedEl = fld('whatChanged');
    var noteEl = fld('note');
    var whatChanged = whatChangedEl ? whatChangedEl.value.trim() : '';
    var note = noteEl ? noteEl.value.trim() : '';
    var media = WZ.media.length ? WZ.media.join(' · ') : 'none added';

    rb.innerHTML =
      '<div class="kv"><span>Type</span><span>' + escHtml(typeName) + '</span></div>' +
      '<div class="kv"><span>Location</span><span>' + escHtml(locTxt) + '</span></div>' +
      (whatChanged ? '<div class="kv"><span>What changed</span><span>' + escHtml(whatChanged.slice(0, 120)) + (whatChanged.length > 120 ? '…' : '') + '</span></div>' : '') +
      (note ? '<div class="kv"><span>Note</span><span>' + escHtml(note.slice(0, 120)) + (note.length > 120 ? '…' : '') + '</span></div>' : '') +
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
      var key = Object.keys(KNOWN_SOURCES).find(function (k) { return host.endsWith(k); });
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

  // If not in add mode, step 1 has no map gate — allow immediate Next
  if (LOCATE === 'off') {
    WZ.loc = { type: 'none' };
  }

  refreshGate();
})();
