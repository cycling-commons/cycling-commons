// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The contribute wizard (docs/plans/2026-08-01-improve-js-i18n.md).

   Two rules hold this file together, and both exist because the strings it
   renders are a mix of rider-entered text and catalogue copy that a curator
   later reads back:

   1. **No innerHTML.** Every node is built with createElement + textContent,
      or by review-card.js, which does the same. textContent cannot produce an
      element, so neither a rider's `<img src=x onerror=…>` nor a translation
      containing markup can become anything but visible characters. There is no
      escaping helper here on purpose — an escaper is something you can forget
      to call, and this file gives you nothing to forget.
   2. **Strings crossing into JS are text; markup stays in Twig.** Copy that
      genuinely needs a `<b>` is server-rendered in improve.html.twig, where
      |rich sanitises it. The one exception — the source note built from a
      pasted URL, which cannot be server-rendered — uses reviewCard.emphasised(),
      which splits the translated template on its placeholder and puts the value
      in its own element as text.

   The CSP has no 'unsafe-inline', but that is a backstop rather than the
   control: an injected onerror attribute needs no inline <script> tag. */
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

  // Read ?item= and ?mode= from URL (client-side only; controller does not process them)
  var _q = new URLSearchParams(location.search);
  var _id = _q.get('item') || '';
  var ADD = _q.get('mode') === 'add';

  // An existing item's coordinates, when we arrive from the map's "Edit this
  // item" link (?lat=&lng=). We show the map centred there so the contributor
  // can SEE and correct the location — not just when adding a new place.
  /* The item's own position, when the server knows it. `?lat=&lng=` still wins
     — the map's "◎ Fix location" bridge sends the exact pin a rider clicked —
     but a bare /improve?item=… now opens on the item instead of with no Locate
     step at all, which is what the desk's edit link produced. */
  var _itemPos = window.CC_ITEM || {};
  var initLat = parseFloat(_q.get('lat'));
  var initLng = parseFloat(_q.get('lng'));
  if (isNaN(initLat) && typeof _itemPos.lat === 'number') initLat = _itemPos.lat;
  if (isNaN(initLng) && typeof _itemPos.lng === 'number') initLng = _itemPos.lng;
  var hasCoords = !isNaN(initLat) && !isNaN(initLng);

  // A stretch we already know the ends of (?sa=lng,lat&sb=lng,lat). The map
  // drawer sends these when a rider corrects an OSM surface line: that way
  // already HAS a start and an end, so opening on a blank map and asking for
  // two taps throws away what we know and invites a worse answer. Both pins
  // are placed and draggable — adjusting beats placing.
  var _pair = function (raw) {
    var p = String(raw || '').split(',');
    if (p.length !== 2) return null;
    var lng = parseFloat(p[0]), lat = parseFloat(p[1]);
    return (isNaN(lng) || isNaN(lat) || lng < -180 || lng > 180 || lat < -90 || lat > 90) ? null : [lng, lat];
  };
  var segA = _pair(_q.get('sa'));
  var segB = _pair(_q.get('sb'));
  var hasSegment = !!(segA && segB);
  // "This is correct" (?confirm=1): the rider is agreeing with the location as
  // well as the surface, so the wizard opens on the details rather than making
  // them press Next past a map they have already accepted. Only meaningful with
  // a known stretch — without one there is nothing to have confirmed.
  var CONFIRM_SEGMENT = _q.get('confirm') === '1' && hasSegment;
  // The same shortcut for a POINT: confirming an OSM place means agreeing with
  // where it is, so the map step is a question already answered.
  var CONFIRM_POINT = _q.get('confirm') === '1' && !hasSegment;

  // "◎ Fix location" bridge from the drawer: open the LOCATE editor directly
  // in expanded (change) mode, because the intent is explicitly to move the pin.
  var RELOCATE = _q.get('fix') === 'location';

  // The catalog type + how to set its location come from the server (the
  // controller resolves ?type= into ItemType and renders these on #wiz).
  var _type = wiz.dataset.type || '';
  var _locMode = wiz.dataset.locationMode || 'point';

  // Registry defaults (item coords when editing, else a generic Wallonia centre)
  var DEFAULTS = {
    center: hasCoords ? [initLng, initLat] : [5.86, 50.49],
    icon: wiz.dataset.icon || '✎'
  };

  /* Locate mode: point (most), segment (road surface), none/track (ride).
     Show the map when adding a place, OR when editing one that has coordinates
     (so its location is visible and correctable); otherwise skip step 1.

     A CLIMB is the exception, and always gets its map. Its line is not a
     location it happens to sit at — it IS the item, and the only way to change
     where the climb ends is to drag the summit. The `hasCoords` gate depends
     on the caller putting lat/lng in the URL, which the map drawer's edit link
     does and the contributions list's does not: a rider answering a curator's
     question about their proposed ending arrived at a form with no map, unable
     to see or adjust the very thing they were being asked about
     (owner-reported 2026-08-03). CC_ITEM carries the stored route/grad/steep
     either way, so the editor has everything it needs to draw it. */
  var IS_CLIMB = !!(window.CC_ITEM && 'B' === window.CC_ITEM.letter);
  var LOCATE = (ADD || hasCoords || RELOCATE || (IS_CLIMB && !!(window.CC_ITEM || {}).route)) ? _locMode : 'off';

  // Wizard state
  // media = uploaded photos, owned wholesale by media-upload.js's onChange.
  // links = photo LINKS, which this file owns. Two arrays because onChange
  // replaces its list every time: a link pushed into the same array vanished
  // the moment the next photo finished uploading.
  var WZ = { cur: 1, last: 4, loc: null, media: [], links: [] };
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

  /* ---------- strings ----------
     The bag is emitted by improve.html.twig with all four JSON_HEX_* flags, so
     a catalogue string containing `</script>` cannot close the block it is
     printed in. A missing key resolves to empty rather than to its own name: a
     rider should never be shown `improve.step1.readout_initial`, and
     tools/check-translations.sh is what stops a key going missing at all. */
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

  // The review step's DOM builders (assets/contribute/review-card.js).
  var RC = (window.Cc && window.Cc.reviewCard) || null;

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

  /* ---------- "has anything actually changed?" ----------
     An edit that changes nothing is not a contribution: it costs a curator a
     queue row to read and applies nothing on approve, and the wizard walks
     straight from a prefilled form to Submit, so it is easy to send by
     accident. The server refuses it (CatalogContributionService); this is here
     so the rider is told at the review step rather than by a form error after
     pressing Submit.

     A photo, a photo link and a moved pin each count as a change on their own. */
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

  /* Geometry that is NOT a pin: a climb's route/steepest and a road surface's
     two endpoints. They live in their own hidden fields, so pinMoved() — which
     only ever compared lat/lng, and only for `type === 'point'` — could not see
     them. Moving a climb's summit therefore left the wizard convinced nothing
     had changed and Submit disabled, on an edit the SERVER would have accepted
     perfectly well (ClimbGeometry is merged into the change diff there)
     (owner-reported 2026-08-03).

     Compared against a snapshot taken once the editor has hydrated: it writes
     the item's stored shape into these fields synchronously at mount and does
     not re-snap or re-profile on its own, so anything that differs afterwards
     is the rider's doing. */
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
    if (WZ.media.length || WZ.links.length) return false;
    if (pinMoved() || geomChanged()) return false;
    return detailsSnapshot() === INITIAL_DETAILS;
  }

  /* A photo still going up. The wizard will not advance or submit while one
     is in flight: the hidden media-id field is only written when the upload
     lands, so a rider who presses Next while watching a thumbnail appear would
     submit without it — the photo is uploaded, unclaimed, and swept as an
     orphan seven days later (docs/specs/photo-uploads.md §6). Waiting for the
     thumbnail is the rider-visible signal that it is safe. */
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
    if (WZ.cur === 1 && LOCATE !== 'off') {
      nextBtn.disabled = !WZ.loc;
    } else if (WZ.cur === WZ.last) {
      nextBtn.disabled = nothingChanged();
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
    var glyph = document.createElement('span');
    glyph.textContent = '◎';
    el.appendChild(glyph);
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
    wmap.on('load', function () {
      if (window.Cc && window.Cc.mountEditorBase) {
        window.Cc.mountEditorBase(wmap, { token: window.MAPILLARY_TOKEN, labels: window.CC_BASE_LABELS });
      }
    });

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
    // Assigned by the single-pin/segment branch only; stays null for a climb,
    // whose foot/summit belong to the shared editor (see placeAt below).
    var placeAt = null;

    if (isClimb) {
      var initial = (window.CC_ITEM.route || window.CC_ITEM.grad || window.CC_ITEM.steep || window.CC_ITEM.steepPoint)
        ? { route: window.CC_ITEM.route, grad: window.CC_ITEM.grad, steep: window.CC_ITEM.steep,
            // The rider-placed steepest point, if this climb already has one.
            // Without it the editor would drop an existing contribution the
            // moment anyone edited anything else about the climb.
            steepPoint: window.CC_ITEM.steepPoint }
        : undefined;

      // Undo is only offered once there is something to take back — a control
      // that is always there and usually dead teaches a rider nothing.
      var undoBtn = document.getElementById('wzUndo');
      climbEditor = window.Cc.mountClimbEditor({
        map: wmap,
        hidden: { route: fld('route'), grad: fld('grad'), steep: fld('steep'), avg: fld('avg'), steepPoint: fld('steepPoint') },
        initial: initial,
        onHistory: function (depth) { if (undoBtn) undoBtn.hidden = 0 === depth; },
        onChange: function (st) {
          var ro = document.getElementById('wz-readout');
          if (st.start && st.summit) {
            // lengthKm rides along so the review can state the climb's
            // length: two coordinate pairs do not tell a rider whether they
            // drew the climb they meant to (owner request 2026-08-03).
            WZ.loc = { type: 'climb', start: st.start, summit: st.summit, lengthKm: st.lengthKm };
            if (ro) {
              var txt = t('readout_climb_set');
              if (st.lengthKm) txt += ' · ' + t('climb_length', { '%km%': uKm(st.lengthKm) });
              if (st.routing || st.profiling) txt += ' · ' + t('climb_measuring');
              else if (st.routeError) txt += ' — ' + t('climb_route_error');
              else if (st.profileError) txt += ' — ' + t('climb_profile_error');
              else txt += ' — ' + t('climb_drag_hint');
              ro.textContent = txt;
            }
          } else {
            WZ.loc = null;
            if (ro) ro.textContent = st.start ? t('readout_climb_summit') : t('readout_climb_foot');
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
      // The editor has written the item's stored shape into the hidden fields
      // by now (mountClimbEditor ends with writeHidden), so this is the
      // "unchanged" baseline the review-step gate compares against.
      INITIAL_GEOM = geomSnapshot();
      if (undoBtn) {
        undoBtn.addEventListener('click', function () { if (climbEditor) climbEditor.undo(); });
        undoBtn.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); undoBtn.click(); }
        });
      }
    } else {
      var placed = [];
      // Same baseline for the non-climb branch. A road surface's endpoints are
      // not prefilled (only points are pre-placed), so this snapshot is the
      // empty shape and drawing one registers as the change it is.
      INITIAL_GEOM = geomSnapshot();

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
        if (ro) ro.textContent = t('readout_expanded');
        if (wmap) wmap.resize();
      }

      var fmt = function (ll) {
        return ll.lat.toFixed(4) + '°N ' + ll.lng.toFixed(4) + '°E';
      };

      /* The road between the two pins, as the router drew it.

         A straight chord between two taps crosses fields, houses and the wrong
         bank of a river: on a map that reads as a mistake, because it is one.
         So the stretch is snapped with the SAME bicycle router the climb editor
         uses (/contribute/route → our Valhalla), and `snapped` holds its path.
         Null means we have not asked yet or the answer was "no road", and then
         the straight line stays and the rider is told — never a shape presented
         as the road that isn't. */
      var snapped = null;
      var snapSeq = 0;
      var snapCtl = null;
      var SNAP_TIMEOUT_MS = 8000;

      var drawSeg = function () {
        if (!wmap.isStyleLoaded()) { wmap.once('idle', drawSeg); return; }
        var id = 'seg';
        if (wmap.getLayer(id)) wmap.removeLayer(id);
        if (wmap.getSource(id)) wmap.removeSource(id);
        if (placed.length < 2) return;
        var coords = snapped || placed.map(function (m) { return m.getLngLat().toArray(); });
        wmap.addSource(id, { type: 'geojson', data: { type: 'Feature', geometry: { type: 'LineString', coordinates: coords } } });
        wmap.addLayer({ id: id, type: 'line', source: id, paint: { 'line-color': '#FF5A1F', 'line-width': 4 } });
      };

      /* Ask the router for the road between the pins. Sequence-guarded and
         abortable because dragging a pin fires this repeatedly, and a slow
         earlier answer arriving last would draw a stretch the rider has already
         moved away from. */
      var snapSeg = function () {
        var seq = ++snapSeq;
        if (snapCtl) { snapCtl.abort(); snapCtl = null; }
        if (placed.length < 2) { snapped = null; return; }
        var a = placed[0].getLngLat().toArray();
        var b = placed[1].getLngLat().toArray();
        var ctl = snapCtl = new AbortController();
        var timer = setTimeout(function () { ctl.abort(); }, SNAP_TIMEOUT_MS);
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
          if (seq !== snapSeq) return;   // superseded by a newer drag
          var line = d && d.code === 'Ok' && d.routes && d.routes[0]
            && d.routes[0].geometry && d.routes[0].geometry.coordinates;
          if (!line || line.length < 2) { snapped = null; toast(t('toast_segment_straight')); }
          else { snapped = line; }
          syncLoc();
          drawSeg();
        }).catch(function () {
          clearTimeout(timer);
          if (seq !== snapSeq) return;
          snapped = null;
          toast(t('toast_segment_straight'));
          syncLoc();
          drawSeg();
        });
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
            if (ro) ro.textContent = placed.length === 1 ? t('readout_segment_end') : t('readout_segment_start');
          } else {
            WZ.loc = { type: 'segment', a: placed[0].getLngLat().toArray(), b: placed[1].getLngLat().toArray() };
            // `line` only when the router answered: the server treats its
            // absence as "no road found", which is exactly what it means.
            var seg = { a: WZ.loc.a, b: WZ.loc.b };
            if (snapped && snapped.length > 1) seg.line = snapped;
            if (fSeg) fSeg.value = JSON.stringify(seg);
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
            if (ro) ro.textContent = t('readout_initial');
          } else {
            var ll = placed[0].getLngLat();
            WZ.loc = { type: 'point', lng: ll.lng, lat: ll.lat };
            // update hidden lat/lng fields
            if (fLat) fLat.value = ll.lat;
            if (fLng) fLng.value = ll.lng;
            if (ro) ro.textContent = t('readout_point_set', { '%coords%': fmt(ll) });
          }
        }
        refreshGate();
      };

      // Confirm on release that the corrected location was captured — syncLoc()
      // has already written it to the hidden lat/lng fields the form submits.
      var announceMove = function () {
        if (WZ.loc && WZ.loc.type === 'point') {
          toast(t('toast_pin_moved', {
            '%coords%': WZ.loc.lat.toFixed(4) + '°N ' + WZ.loc.lng.toFixed(4) + '°E'
          }));
        } else if (WZ.loc && WZ.loc.type === 'segment') {
          toast(t('toast_segment_moved'));
        }
      };

      // One way to drop a pin, two ways to ask for it: a tap on the map, or a
      // coordinate pasted into the search box (see the search section below).
      // `var` hoists the binding to this function's scope, so the search code —
      // which is shared with the climb branch, where no pin exists — can test
      // `if (placeAt)` and stay a fly-to there.
      /* ---------- undo ----------
         Reset without undo is a cliff: the only way back from a mis-drag was to
         throw both pins away and start over, on a stretch that took two taps and
         a router call to get right (owner-reported 2026-08-12). The climb editor
         has had undo since 2026-08-03 and the rule there is the same — every
         mutating act pushes ONE snapshot first, and the control appears only
         once there is something to take back. */
      var hist = [];
      var undoCtl = document.getElementById('wzUndo');
      var dragFrom = null;

      /* A drag has already moved the marker by the time `dragend` fires, so a
         snapshot taken then would record the state we are already in and undo
         would do nothing visible. `moved`/`from` put the pre-drag position back
         into the snapshot. */
      var pushHistory = function (moved, from) {
        hist.push({
          pts: placed.map(function (m) { return (moved && m === moved && from) ? from : m.getLngLat().toArray(); }),
          line: snapped
        });
        if (undoCtl) undoCtl.hidden = false;
      };

      /* Drop a marker WITHOUT touching history — the shared half of placing a
         pin, prefilling one and restoring one, so the three cannot drift. */
      var addMarker = function (lngLat) {
        var m = new maplibregl.Marker({ element: mkPin(), draggable: true, anchor: 'bottom' }).setLngLat(lngLat).addTo(wmap);
        m.on('dragstart', function () { dragFrom = m.getLngLat().toArray(); });
        m.on('dragend', function () {
          pushHistory(m, dragFrom);
          dragFrom = null;
          snapped = null; syncLoc(); drawSeg(); announceMove(); snapSeg();
        });
        placed.push(m);
        return m;
      };

      var undo = function () {
        var prev = hist.pop();
        if (!prev) return;
        placed.forEach(function (m) { m.remove(); });
        placed.length = 0;
        prev.pts.forEach(function (pt) { addMarker({ lng: pt[0], lat: pt[1] }); });
        snapped = prev.line;
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
        snapped = null;
        syncLoc();
        drawSeg();
        snapSeg();
      };

      wmap.on('click', function (e) { placeAt(e.lngLat); });

      // Pre-place a known stretch, then frame it. placeAt() handles the marker,
      // the drag handler, the readout and the drawn line, so the prefill is the
      // same code path a tap takes — no second way for a segment to exist.
      if (LOCATE === 'segment' && hasSegment) {
        wmap.on('load', function () {
          // addMarker, not placeAt: the stretch we opened on is the BASELINE, so
          // undo must not offer to take the rider back to a blank map they never
          // asked for.
          addMarker({ lng: segA[0], lat: segA[1] });
          addMarker({ lng: segB[0], lat: segB[1] });
          syncLoc();
          drawSeg();
          snapSeg();
          var b = new maplibregl.LngLatBounds(segA, segA);
          b.extend(segB);
          wmap.fitBounds(b, { padding: 60, maxZoom: 16, duration: 0 });
          // Skip AFTER the pins exist, never before: step 2 with an empty
          // hidden segment field would submit a located type with no location
          // and be refused at the very end, which is the worst place to find
          // out. The rider can still step back to the map.
          if (CONFIRM_SEGMENT) step(2);
        });
      }

      // Editing a located point: pre-place the pin at the item's coordinates so
      // the map opens on it. In CONFIRM mode it is a compact, glowing, view-only
      // reassurance; "Change location" expands to the full editor.
      if (hasCoords && LOCATE === 'point' && CONFIRM_POINT) {
        // Straight to the details, with the pin already placed below.
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
          if (CONFIRM && ro) ro.textContent = t('readout_confirm');
        });
      }

      if (wzReset) {
        wzReset.addEventListener('click', function () {
          // Reset is itself undoable — clearing a stretch by accident is exactly
          // the mistake undo exists for.
          pushHistory();
          placed.forEach(function (m) { m.remove(); });
          placed.length = 0;
          snapped = null;
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

    // Photon geocode
    var searchEl = document.getElementById('wz-search');
    var resultsEl = document.getElementById('wz-results');
    var searchT = null;
    var parseLatLng = (window.Cc && window.Cc.parseLatLng) || null;

    // A one-line note in the results list ("No matches", "Search unavailable").
    function resultNote(text) {
      var el = document.createElement('div');
      el.className = 'res empty';
      el.textContent = text;
      return el;
    }

    /* Photon's response is third-party text, so it is built as nodes like
       everything else here — a place name is a name, never markup. */
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

    /* A pasted coordinate is not a place name, so it never reaches Photon —
       it answers the question the search box is asking on its own. The row is
       rendered rather than applied silently so the rider sees WHAT was read
       out of what they pasted before the map moves (and so a mistyped pair is
       theirs to spot). Enter applies it directly, because someone who pastes
       coordinates and hits Enter has already decided. */
    function goCoord(pt) {
      if (!wmap) return;
      wmap.flyTo({ center: [pt.lng, pt.lat], zoom: 16 });
      // Point/segment: the coordinates ARE the location — placing the pin is
      // the whole reason to paste them, and re-tapping the map would only lose
      // the precision the rider just handed us. A climb's foot/summit stay the
      // editor's job, so there the paste is a fly-to and nothing more.
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
        var q = searchEl.value.trim();
        clearTimeout(searchT);
        // ++searchSeq drops any geocode already in flight: without it a slow
        // Photon reply for the half-typed query lands on top of the coordinate
        // row a moment later.
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

  /* ---------- step 4: review ---------- */
  function renderReview() {
    var rb = document.getElementById('reviewBody');
    if (!rb) return;

    // 'none' is a real state, not a missing one: editing an existing item skips
    // the locate step entirely (LOCATE === 'off' sets {type:'none'}). The old
    // ternary chain had no branch for it, fell through to the climb arm and
    // threw on WZ.loc.start — which left the ENTIRE review card blank on the
    // commonest contribution path there is. Each arm now checks the shape it
    // is about to read, so a malformed location costs its own row and nothing
    // else.
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
      // The length is the one number that says whether the drawn climb is the
      // intended one — coordinates alone do not.
      if (WZ.loc.lengthKm) {
        locTxt += ' · ' + t('climb_length', { '%km%': uKm(WZ.loc.lengthKm) });
      }
    }

    // Echo every detail/extra field the rider actually filled in (step 2), so the
    // review faithfully mirrors what will be submitted — not just Type/Location/Media.
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
      // An untouched select still reports its placeholder option's text, which
      // is an em dash — so the review used to list "Potable? —", "Cost —" and
      // friends as though the rider were submitting them. A field the rider
      // did not answer belongs nowhere on a page whose whole job is showing
      // what is about to be sent.
      if (!val || val === '—' || val === '-') return;
      var labelEl = fieldEl.querySelector('label');
      var label = labelEl ? labelEl.textContent.trim() : ctrl.name;
      fieldRows.push(RC.kvRow(label, val.length > 120 ? val.slice(0, 120) + '…' : val));
    });

    // No Location row when this submission does not touch the location: an edit
    // leaves the pin where it is, and "Location —" would read as a missing
    // answer rather than an untouched one.
    //
    // No category row either. It said the same thing as the eyebrow at the top
    // of every step ("C · Water & food"), and it printed the raw enum value
    // while doing it — but the real problem was that several types have a
    // "Type" detail field of their own, so the card showed two rows labelled
    // Type meaning different things, side by side once it went two-column.
    RC.clear(rb);
    if (locTxt) rb.appendChild(RC.kvRow(t('label_location'), locTxt));
    fieldRows.forEach(function (row) { rb.appendChild(row); });

    // Say why Submit is off, in the place the rider is already reading.
    var note = document.getElementById('wz-nochange');
    if (note) {
      note.textContent = t('nothing_changed');
      note.hidden = !nothingChanged();
    }

    // Photos are shown, not listed. A filename tells a rider nothing about
    // whether they picked the right shot; the thumbnail is the only version of
    // this row worth reading. Links have no thumbnail, so they keep their text.
    // The heading is server-rendered so it stays translated — this file has no
    // message bag of its own.
    var block = document.getElementById('reviewMedia');
    var list = document.getElementById('reviewMediaList');
    if (!block || !list) return;
    var all = WZ.media.concat(WZ.links);
    block.hidden = !all.length;
    RC.clear(list);
    all.forEach(function (m) { list.appendChild(RC.mediaFigure(m)); });
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
    // WZ.links carries the same text into the review strip, where
    // reviewCard.mediaFigure() renders it as a caption.
    var host = document.getElementById('q-photo');
    if (host) host.appendChild(chip);
    WZ.links.push('🔗 ' + label);
  }

  /* The hosts we can read rights from automatically. The site NAMES are proper
     nouns and stay here as data; the note explaining what we read from each is
     copy, so it lives in the catalogue (`improve.step3.link_note_*`) and
     arrives as text — the `&amp;` these notes used to carry was an artefact of
     being written straight into innerHTML. */
  var KNOWN_SOURCES = {
    'commons.wikimedia.org': { name: 'Wikimedia Commons', note: 'link_note_wikimedia' },
    'wikipedia.org': { name: 'Wikipedia', note: 'link_note_wikipedia' },
    'flickr.com': { name: 'Flickr', note: 'link_note_flickr' },
    'unsplash.com': { name: 'Unsplash', note: 'link_note_unsplash' },
    'youtube.com': { name: 'YouTube', note: 'link_note_youtube' },
    'vimeo.com': { name: 'Vimeo', note: 'link_note_vimeo' }
  };

  /* The ✓ / ⚠ that opens a source note. Its own element, deliberately: a glyph
     glued onto the front of a translated sentence is one more thing a
     catalogue edit can lose, and one more reason for a string to look like it
     may contain markup. */
  function srcMark(glyph) {
    var el = document.createElement('span');
    el.className = 'mark';
    el.textContent = glyph + ' ';
    return el;
  }

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
        if (note) {
          note.className = 'src-note ok';
          RC.clear(note);
          note.appendChild(srcMark('✓'));
          // The source name is emphasised without any string carrying markup:
          // the translated sentence is split on %source% and the name goes into
          // its own <b> as text.
          note.appendChild(RC.emphasised(
            t('link_recognised', { '%note%': t(s.note) }), '%source%', s.name
          ));
        }
        noteLink(t('chip_link', { '%host%': host }));
      } else {
        if (note) {
          note.className = 'src-note manual';
          RC.clear(note);
          note.appendChild(srcMark('⚠'));
          var warn = document.createElement('span');
          warn.textContent = t('link_unknown_note');
          note.appendChild(warn);
        }
        noteLink(t('chip_needs_licence', { '%host%': host || t('chip_host_fallback') }));
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
      onChange: function (photos) { WZ.media = photos; }
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
