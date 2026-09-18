// SPDX-License-Identifier: AGPL-3.0-only
/* Scout ride review (docs/specs/moderation-and-contribution.md (Scout intake)):
   parse a FIT in the browser, put tags on the map, send one approved tag at a time.
   The ride file is never uploaded — ScoutIntakeController refuses a payload carrying a track. */
import { map } from './map-init.js';
import { D, tpl } from './i18n.js';
import { uSpeed } from './units.js';
import { parseFit, extractTags, buildSurfaceSegments, countVehicles, MESG, semiToDeg,
         fitToDate, POI_RESUPPLY, OSM_SURFACE, LEGACY_RESUPPLY, SURF_TYPE } from '../lib/scout-fit.js';
import { surfaceStyle } from './render.js';
import { DEVICE_CLASS, DEVICE_DECLARABLE, cutTrack, nearestTrackIndex, sliceTrack, endIndexFor,
         trackIndexAt, openBareTaps as openBare } from './scout-segments.js';
import { claimMapClicks, releaseMapClicks } from './picking.js';
import { mapToast } from './drawer.js';
import { enhanceSelect } from './select-box.js';
import { unzipSync } from '../lib/fflate-0.8.3.js';
import { readBundle, isZip, bundleKey } from './scout-bundle.js';

const RIDE_SRC = 'cc-scout-ride';
const RIDE_LINE = 'cc-scout-ride-line';
const SEG_SRC = 'cc-scout-seg';
const SEG_CASE = 'cc-scout-seg-case';
const SEG_CLS = ['paved', 'pave', 'gravel', 'dirt', 'rock'];
const RED = '#D92D20';
let passMarkers = [];

/** Tag markers, in file order. Each is {tag, lngLat, marker, at, approved}. */
let tags = [];
let track = [];
/** Surface stretches: view-models over buildSurfaceSegments(). */
let stretches = [];
/** Points and stretches in ride order - the numbering the map and list share. */
let order = [];
let segMarkers = [];
/** Surface taps with no type: grey pins, never sent. One may be a lost END. */
let bareTaps = [];
let bareMarkers = [];
/** The stretch whose end the next map click sets, or null. */
let endPick = null;

const el = id => document.getElementById(id);
const t = (k, fallback) => (D && D[k]) || fallback;

/* FIT only. No Scout app writes GPX, and a GPX path would drop sub-type/surface. */

/* Scout's poi_type → ScoutTag::TYPES. Legacy 7/8/9 were separate poi_types before 1.3. */
const POI_TO_TAG = {
  1: 'notice', 2: 'scenery', 3: 'resupply', 4: 'other',
  5: 'closure', 6: 'surface', 7: 'resupply', 8: 'resupply', 9: 'resupply',
};

/* Letters a tag may become, narrowed by the device sub-menu. Tables from
   App\Scout\ScoutTag so the panel never offers a letter the endpoint refuses. */
function lettersFor(tag, detail) {
  const byDetail = (window.CC_SCOUT_DETAILS || {})[tag] || {};
  const offered = detail != null ? byDetail[String(detail)] : byDetail[''];
  if (offered && offered.length) return offered;
  return (window.CC_SCOUT_TAGS || {})[tag] || ['B'];
}

function fitTagName(tag, detail) {
  if (tag === 'resupply') return POI_RESUPPLY[detail] || '';
  if (tag === 'surface') return OSM_SURFACE[detail] || '';
  return '';
}

/** Decode a Scout FIT: the ride line, the tags, and the surface stretches. */
function readFit(buffer) {
  const parsed = parseFit(buffer);
  const { tags: raw } = extractTags(parsed, 'poi_type', 'poi_detail');
  /* Bundle link (docs/specs/scout-bundle.md): the tag's second, plus its place
     within that second in FIT order. Every tag record counts, cancelled or not,
     because the phone numbers what it wrote. */
  const perSecond = new Map();
  raw.forEach(t => {
    const first = t.time ? bundleKey(t.time, 0) : null;
    if (!first) { t.bkey = null; return; }
    const n = perSecond.get(first) || 0;
    perSecond.set(first, n + 1);
    t.bkey = bundleKey(t.time, n);
  });

  // Ride line from RECORD messages. Drawn here; never sent.
  const track = [];
  for (const m of parsed.messages) {
    if (m.globalNum !== MESG.RECORD) continue;
    const lat = semiToDeg(m.fields[0]);
    const lng = semiToDeg(m.fields[1]);
    if (lat == null || lng == null) continue;
    track.push({ lat, lng, at: fitToDate(m.fields[253]) });
  }

  const rideEnd = track.length ? track[track.length - 1].at : null;
  const segments = buildSurfaceSegments(raw, rideEnd);
  /* A stretch is keyed on the tap that starts it. */
  segments.forEach(seg => {
    const t0 = seg.startTime ? seg.startTime.getTime() : NaN;
    const tap = raw.find(t => t.type === SURF_TYPE && t.time && t.time.getTime() === t0 && t.detail === seg.type);
    seg.bkey = tap ? tap.bkey : null;
  });

  /* Overtake count if the rider had a radar: shown, never sent
     (docs/specs/moderation-and-contribution.md (Scout intake)). */
  const radar = countVehicles(parsed);

  /* Tags with no GPS fix: counted and named, not dropped silently. */
  const unplaceable = raw.filter(t => !t.cancelled && (t.lat == null || t.lon == null)).length;

  /* Cancelled = rider retracted on the device (Scout undo). Hidden. */
  /* Bare surface tap (picker timed out): hidden from review, but counted. */
  const bare = raw.filter(t => !t.cancelled && t.type === SURF_TYPE && !(t.detail >= 1));
  const bareSurface = bare.length;
  const bareTaps = bare.filter(t => t.lat != null && t.lon != null).map(t => ({ lat: t.lat, lng: t.lon, at: t.time }));

  const tags = raw
    /* Surface tags never join the point list — they belong to stretches. */
    .filter(t => !t.cancelled && t.lat != null && t.lon != null && t.type !== SURF_TYPE)
    .map(t => {
      const legacy = LEGACY_RESUPPLY[t.type];
      const detail = legacy || t.detail;
      const tag = POI_TO_TAG[t.type] || 'other';
      const offered = lettersFor(tag, detail);
      /* 'other' starts unchosen — don't invent Water & food. */
      const letter = 'other' === tag ? '' : (offered[0] || 'B');
      return {
        tag,
        letter,
        lat: t.lat,
        lng: t.lon,
        at: t.time ? t.time.toISOString() : '',
        name: fitTagName(tag, detail),
        // Surface class the rider chose on the device.
        osmSurface: tag === 'surface' ? (OSM_SURFACE[detail] || '') : '',
        // Sub-menu number; the server maps it to fields.
        detail: detail || null,
        bkey: t.bkey,
      };
    });

  return { track, tags, segments, radar, unplaceable, bareSurface, bareTaps };
}

function msg(text, isError) {
  const box = el('scoutMsg');
  if (!box) return;
  box.textContent = text;
  box.hidden = !text;
  box.classList.toggle('err', !!isError);
}

/* Keep scout layers on top — later style layers (catalog, surface, scope mask)
   would otherwise bury the ride. Guarded so styledata cannot loop. */
function raiseScoutLayers() {
  const ids = [RIDE_LINE, SEG_CASE, ...SEG_CLS.map(c => SEG_SRC + '-' + c)]
    .filter(id => map.getLayer(id));
  if (!ids.length) return;
  const style = map.getStyle().layers.map(l => l.id);
  const tail = style.slice(-ids.length);
  if (ids.length === tail.length && ids.every((id, i) => tail[i] === id)) return;
  ids.forEach(id => map.moveLayer(id));
}
let raiseHooked = false;
function hookRaise() {
  if (raiseHooked) return;
  raiseHooked = true;
  map.on('styledata', raiseScoutLayers);
}
function drawRide() {
  if (map.getLayer(RIDE_LINE)) map.removeLayer(RIDE_LINE);
  if (map.getSource(RIDE_SRC)) map.removeSource(RIDE_SRC);
  if (track.length < 2) return;
  map.addSource(RIDE_SRC, {
    type: 'geojson',
    data: { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: track.map(p => [p.lng, p.lat]) } },
  });
  map.addLayer({
    id: RIDE_LINE, type: 'line', source: RIDE_SRC,
    layout: { 'line-cap': 'round', 'line-join': 'round' },
    paint: { 'line-color': '#14160E', 'line-width': 5, 'line-opacity': 0.45 },
  });
  const b = new maplibregl.LngLatBounds([track[0].lng, track[0].lat], [track[0].lng, track[0].lat]);
  track.forEach(p => b.extend([p.lng, p.lat]));
  map.fitBounds(b, { padding: 80, duration: 0 });
}

/* Stretches use the same palette as the map surface legend (render.js SURFACE_STYLE). */
function stretchFeatures() {
  return stretches
    .filter(x => x.geom && !x.dismissed)
    .map((x, i) => ({
      type: 'Feature',
      properties: { cls: x.cls, idx: i },
      geometry: { type: 'LineString', coordinates: x.geom.line },
    }));
}

/* Dismissals thin the drawn set without rebuilding layers. */
function refreshStretches() {
  const src = map.getSource(SEG_SRC);
  if (src) src.setData({ type: 'FeatureCollection', features: stretchFeatures() });
}

function drawStretches() {
  if (!stretches.length) return;
  const feats = stretchFeatures();
  if (!feats.length) return;
  map.addSource(SEG_SRC, { type: 'geojson', data: { type: 'FeatureCollection', features: feats } });
  map.addLayer({
    id: SEG_CASE, type: 'line', source: SEG_SRC,
    layout: { 'line-cap': 'round', 'line-join': 'round' },
    paint: { 'line-color': '#FBF4E4', 'line-width': 9, 'line-opacity': 0.9 },
  });
  SEG_CLS.forEach(cls => {
    map.addLayer({
      id: SEG_SRC + '-' + cls, type: 'line', source: SEG_SRC,
      filter: ['==', ['get', 'cls'], cls],
      layout: { 'line-cap': 'round', 'line-join': 'round' },
      paint: { 'line-color': surfaceStyle(cls).color, 'line-width': 5, 'line-opacity': 1 },
    });
  });
}

/* Vehicle pass markers: ground speed (or "?"). Measured here, never sent. */
function passEl(pass) {
  const d = document.createElement('div');
  d.className = 'scout-pass';
  d.innerHTML = '<svg viewBox="0 0 24 12" width="17" height="9" aria-hidden="true">'
    + '<path fill="currentColor" d="M2 9h20a1 1 0 0 0 1-1V6.2a1.6 1.6 0 0 0-1.1-1.5l-4.2-1.3-2-1.9A2.4 2.4 0 0 0 14 1H8.3a2.4 2.4 0 0 0-2 1.1L4.6 4.6 2.1 5.3A1.5 1.5 0 0 0 1 6.8V8a1 1 0 0 0 1 1z"/>'
    + '<circle cx="6.5" cy="9.4" r="2.1" fill="currentColor"/><circle cx="17.5" cy="9.4" r="2.1" fill="currentColor"/>'
    + '</svg>';
  const sp = document.createElement('span');
  /* No speed → "?"; don't invent a number. */
  sp.textContent = pass.ground == null ? '?' : uSpeed(pass.ground);
  d.appendChild(sp);
  return d;
}

function placePasses(radar) {
  passMarkers.forEach(m => m.remove());
  passMarkers = [];
  if (!radar || !radar.passes) return;
  radar.passes.forEach(pass => {
    // Missing speed still places the marker with "?"; missing place does not.
    if (pass.lat == null || pass.lon == null) return;
    passMarkers.push(new maplibregl.Marker({ element: passEl(pass), anchor: 'bottom' })
      .setLngLat([pass.lon, pass.lat])
      .addTo(map));
  });
}

/* Close clears the ride off the map. Unsent tags stay in the ride file (we never had a copy). */
function clearRide() {
  stopEndPick();
  [...tags, ...stretches].forEach(x => (x.bundleUrls || new Map()).forEach(u => URL.revokeObjectURL(u)));
  passMarkers.forEach(m => m.remove());
  passMarkers = [];
  bareMarkers.forEach(m => m.remove());
  bareMarkers = [];
  bareTaps = [];
  tags.forEach(entry => { if (entry.marker) entry.marker.remove(); });
  segMarkers.forEach(m => m.remove());
  segMarkers = [];
  tags = [];
  track = [];
  stretches = [];
  order = [];
  if (map.getLayer(RIDE_LINE)) map.removeLayer(RIDE_LINE);
  if (map.getSource(RIDE_SRC)) map.removeSource(RIDE_SRC);
  SEG_CLS.forEach(cls => { if (map.getLayer(SEG_SRC + '-' + cls)) map.removeLayer(SEG_SRC + '-' + cls); });
  if (map.getLayer(SEG_CASE)) map.removeLayer(SEG_CASE);
  if (map.getSource(SEG_SRC)) map.removeSource(SEG_SRC);
  const list = el('scoutList');
  if (list) list.hidden = true;
  const tagList = el('scoutTags');
  if (tagList) tagList.textContent = '';
  const facts = el('scoutFacts');
  if (facts) { facts.textContent = ''; facts.hidden = true; }
  const media = el('scoutMedia');
  if (media) media.hidden = true;
  const file = el('scoutFile');
  if (file) file.value = '';   // the same ride can be opened again
  msg('');
}

function pinEl(entry) {
  const d = document.createElement('div');
  d.className = 'scout-pin' + (entry.approved ? ' done' : '');
  d.textContent = String(entry.n);
  d.title = entry.tag;
  return d;
}

/* Stretch number on both ends: green start, red end. */
function stretchPinEl(entry, cls) {
  const d = document.createElement('div');
  d.className = 'scout-pin ' + cls + (entry.approved ? ' done' : '');
  d.textContent = String(entry.n);
  d.title = t('scoutTag_surface', 'Surface');
  return d;
}

/* Grey "?" where a surface tap carried no type. Placed on the ride by time,
   so a ride that crosses itself still puts it on the right pass. A click
   opens the stretch it sits inside; in end-pick mode it sets the end there. */
function placeBareTaps(list) {
  bareTaps = (list || []).map(b => {
    let idx = trackIndexAt(track, b.at);
    if (idx < 0) idx = nearestTrackIndex(track, b);
    return { ...b, idx };
  });
  bareTaps.forEach(b => {
    const d = document.createElement('div');
    d.className = 'scout-pin bare';
    d.textContent = '?';
    d.title = t('scoutBareTitle', 'Surface tap with no type');
    d.addEventListener('click', () => {
      if (endPick) { setStretchEnd(endPick, b.idx); return; }
      const owner = stretches.find(x => bareInside(x) === b);
      if (owner) { openCardFor(owner); fitStretch(owner); }
    });
    bareMarkers.push(new maplibregl.Marker({ element: d, anchor: 'center' }).setLngLat([b.lng, b.lat]).addTo(map));
  });
}

/* The first no-type tap strictly inside an unsent stretch: likely its lost END. */
function bareInside(entry) {
  if (!entry.geom || entry.approved || entry.dismissed) return null;
  return bareTaps.find(b => b.idx > entry.startIdx && b.idx < entry.endIdx) || null;
}

/* Icon buttons: words in title/aria-label. SVG is static, never user content. */
const SVG_CAM = '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="currentColor" d="M9 3 7.2 5H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3.2L15 3H9zm3 5.5A4.5 4.5 0 1 1 7.5 13 4.5 4.5 0 0 1 12 8.5zm0 2A2.5 2.5 0 1 0 14.5 13 2.5 2.5 0 0 0 12 10.5z"/></svg>';
const SVG_SEND = '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="currentColor" d="M2.4 20.6 22 12 2.4 3.4l-.01 6.68L16 12 2.39 13.92z"/></svg>';
const SVG_SENT = '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="currentColor" d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>';

function sendBtnState(button, state) {
  button.disabled = 'idle' !== state;
  button.classList.toggle('sending', 'sending' === state);
  button.innerHTML = 'sent' === state ? SVG_SENT : SVG_SEND;
  button.title = 'sent' === state ? t('scoutSent', 'Sent')
    : 'sending' === state ? t('scoutSending', 'Sending…')
    : t('scoutApprove', 'Approve and send');
  button.setAttribute('aria-label', button.title);
}

function photoBtnState(button, n) {
  button.classList.toggle('has', n > 0);
  button.innerHTML = SVG_CAM;
  button.title = n > 1 ? tplCount(t('scoutPhotosAttached', '{n} photos attached'), n)
    : 1 === n ? t('scoutPhotoAttached', 'Photo attached')
    : t('scoutAddPhoto', 'Add a photo');
  button.setAttribute('aria-label', button.title);
}

function renderList() {
  const list = el('scoutTags');
  if (!list) return;
  list.textContent = '';

  const buildPoint = (entry) => {
    const li = document.createElement('li');
    li.className = 'scout-tag' + (entry.approved ? ' done' : '');
    li.dataset.n = String(entry.n);
    li.appendChild(removeBtn(entry));

    /* Cards stay open. Head still flies the map to the tag. */
    const head = document.createElement('button');
    head.type = 'button';
    head.className = 'scout-tag-head';
    head.textContent = entry.n + ' · ' + (t('scoutTag_' + entry.tag, entry.tag));
    head.addEventListener('click', () => map.flyTo({ center: [entry.lng, entry.lat], zoom: 16 }));
    li.appendChild(head);
    li.classList.add('open');

    const body = document.createElement('div');
    body.className = 'scout-tag-body';

    // Letters this tag type may become — same list the server validates.
    /* Letterless entry is free-text (server auto-files as F notice). */
    if (entry.letter) {
      const letters = lettersFor(entry.tag, entry.detail);
      const sel = document.createElement('select');
      sel.className = 'scout-letter';
      letters.forEach(L => {
        const o = document.createElement('option');
        o.value = L;
        o.textContent = t('scoutLetter_' + L, L);
        if (L === entry.letter) o.selected = true;
        sel.appendChild(o);
      });
      sel.addEventListener('change', () => { entry.letter = sel.value; });
      body.appendChild(sel);
    }

    const row = document.createElement('div');
    row.className = 'scout-row';

    const name = document.createElement('input');
    name.type = 'text';
    name.className = 'scout-name';
    name.placeholder = entry.letter
      ? t('scoutNamePh', 'Name it')
      : t('scoutDescribe', 'Describe what you saw…');
    name.value = entry.name || '';
    name.addEventListener('input', () => { entry.name = name.value; });
    row.appendChild(name);

    const photo = document.createElement('button');
    photo.type = 'button';
    photo.className = 'scout-photo';
    photoBtnState(photo, photoCount(entry));
    photo.addEventListener('click', () => requestPhotoFor(entry, photo));
    row.appendChild(photo);

    const approve = document.createElement('button');
    approve.type = 'button';
    approve.className = 'scout-approve';
    sendBtnState(approve, entry.approved ? 'sent' : 'idle');
    approve.addEventListener('click', () => sendOne(entry, approve, li));
    row.appendChild(approve);

    body.appendChild(row);
    const strip = bundleStrip(entry);
    if (strip) body.appendChild(strip);

    li.appendChild(body);
    list.appendChild(li);
  };

  order.forEach(x => {
    if (x.ref.dismissed) return;
    if ('point' === x.kind) buildPoint(x.ref);
    else list.appendChild(stretchCard(x.ref));
  });

  updateCounts();
}

/* One place for the "n / m sent" line and the bulk button's label:
   "Send all" before anything went, "Send the rest" after. */
function updateCounts() {
  refreshNotices();
  const live = [...tags, ...stretches].filter(x => !x.dismissed);
  const sent = live.filter(x => x.approved).length;
  const total = el('scoutTotal');
  const done = el('scoutDone');
  if (total) total.textContent = String(live.length);
  if (done) done.textContent = String(sent);
  const bulk = el('scoutSendAll');
  if (bulk && !bulk.dataset.busy) {
    bulk.textContent = 0 === sent ? t('scoutSendEverything', 'Send all') : t('scoutSendAll', 'Send the rest');
    bulk.disabled = sent === live.length;
  }
}

/* Stretch card: no letter dropdown - a stretch is road surface (letter A).
   Built alone so fixing an end redraws one card, not the list. */
function stretchCard(entry) {
  const surfChoices = (((window.CC_FIELD_SCHEMA || {}).A) || []).find(f => f['key'] === 'surface');
  const li = document.createElement('li');
  li.className = 'scout-tag open' + (entry.approved ? ' done' : '');
  li.dataset.n = String(entry.n);
  li.appendChild(removeBtn(entry));

  const head = document.createElement('button');
  head.type = 'button';
  head.className = 'scout-tag-head';
  head.textContent = entry.n + ' · ' + t('scoutTag_surface', 'Surface');
  head.addEventListener('click', () => fitStretch(entry));
  li.appendChild(head);

  const body = document.createElement('div');
  body.className = 'scout-tag-body';

  const choices = surfChoices ? Object.keys(surfChoices.choices || {}) : [];
  body.appendChild(surfacePicker(entry, choices.length ? choices : [entry.surface].filter(Boolean)));

  /* No END tap: the line runs to the ride's end, which is rarely true.
     Sending stays off until the rider sets the end or keeps it on purpose. */
  if (entry.needsEnd) {
    const warn = document.createElement('div');
    warn.className = 'scout-fact';
    warn.textContent = t('scoutStretchToEnd', 'No END tap. This stretch runs to the end of the ride. Set where it ends before you send it.');
    body.appendChild(warn);
  }

  const inside = bareInside(entry);
  if (inside) {
    const note = document.createElement('div');
    note.className = 'scout-fact';
    note.textContent = tpl(t('scoutBareInside', 'A tap with no type (grey ?) is on this stretch. Did the surface you started at {n} end there?'), { n: entry.n });
    body.appendChild(note);
  }

  /* Only an end in doubt gets the end controls: no END tap, or a no-type tap
     inside the line. A tapped end is trusted; the red circle still drags. */
  if (entry.geom && !entry.approved && (entry.needsEnd || inside || endPick === entry)) {
    const ends = document.createElement('div');
    ends.className = 'scout-ends';
    if (inside) {
      const atBare = document.createElement('button');
      atBare.type = 'button';
      atBare.className = 'scout-end-btn';
      atBare.textContent = t('scoutEndAtBare', 'End it at the ?');
      atBare.addEventListener('click', () => setStretchEnd(entry, inside.idx));
      ends.appendChild(atBare);
    }
    const setEnd = document.createElement('button');
    setEnd.type = 'button';
    setEnd.className = 'scout-end-btn' + (endPick === entry ? ' on' : '');
    setEnd.textContent = t('scoutSetEnd', 'Set the end on the map');
    setEnd.addEventListener('click', () => (endPick === entry ? stopEndPick() : startEndPick(entry)));
    ends.appendChild(setEnd);
    if (entry.needsEnd) {
      const keep = document.createElement('button');
      keep.type = 'button';
      keep.className = 'scout-end-btn';
      keep.textContent = t('scoutKeepEnd', 'It runs to the end');
      keep.addEventListener('click', () => {
        if (endPick === entry) stopEndPick();
        entry.needsEnd = false;
        redrawStretchCard(entry);
      });
      ends.appendChild(keep);
    }
    body.appendChild(ends);
  }

  const row = document.createElement('div');
  row.className = 'scout-row';

  const name = document.createElement('input');
  name.type = 'text';
  name.className = 'scout-name';
  name.placeholder = t('scoutNamePh', 'Name it');
  name.value = entry.name || '';
  name.addEventListener('input', () => { entry.name = name.value; });
  row.appendChild(name);

  const photo = document.createElement('button');
  photo.type = 'button';
  photo.className = 'scout-photo';
  photoBtnState(photo, photoCount(entry));
  photo.addEventListener('click', () => requestPhotoFor(entry, photo));
  row.appendChild(photo);

  const approve = document.createElement('button');
  approve.type = 'button';
  approve.className = 'scout-approve';
  sendBtnState(approve, entry.approved ? 'sent' : 'idle');
  if (!entry.geom) approve.disabled = true;
  if (entry.needsEnd) {
    approve.disabled = true;
    approve.title = t('scoutEndFirst', 'Set the end first');
    approve.setAttribute('aria-label', approve.title);
  }
  approve.addEventListener('click', () => sendStretch(entry, approve, li));
  row.appendChild(approve);

  body.appendChild(row);
  const strip = bundleStrip(entry);
  if (strip) body.appendChild(strip);
  li.appendChild(body);
  return li;
}

/* Surface picker: each choice with the line it draws on the map, on the
   map's cream casing. The shared dropdown (select-box.js) draws it; the
   native select underneath holds the value. */
function surfacePicker(entry, labels) {
  const classOf = label => (window.CC_SURFACE_CLASS || {})[label] || '';
  const sel = document.createElement('select');
  sel.className = 'scout-letter';
  sel.setAttribute('aria-label', t('scoutTag_surface', 'Surface'));
  labels.forEach(label => {
    const o = document.createElement('option');
    o.value = label;
    o.textContent = label;
    if (label === entry.surface) o.selected = true;
    sel.appendChild(o);
  });
  /* The line wears the chosen surface's map colour, not the device's. */
  sel.addEventListener('change', () => {
    entry.surface = sel.value;
    const cls = classOf(sel.value);
    if (SEG_CLS.includes(cls)) { entry.cls = cls; refreshStretches(); }
  });
  const holder = document.createElement('div');
  holder.appendChild(sel);
  enhanceSelect(sel, {
    decorate: o => {
      const cls = classOf(o.value);
      if (!cls) return [];
      const line = document.createElement('span');
      line.className = 'scout-swatch';
      line.style.background = surfaceStyle(cls).color;
      line.setAttribute('aria-hidden', 'true');
      return [line];
    },
  });
  return holder;
}

function redrawStretchCard(entry) {
  const li = document.querySelector('#scoutTags [data-n="' + entry.n + '"]');
  if (li) li.replaceWith(stretchCard(entry));
  refreshNotices();
}

function fitStretch(entry) {
  if (!entry.geom) return;
  const b = new maplibregl.LngLatBounds(entry.geom.a, entry.geom.a);
  entry.geom.line.forEach(c => b.extend(c));
  map.fitBounds(b, { padding: 100 });
}

/* End-pick mode: the next click on the ride sets this stretch's end. Map
   clicks are claimed, so a catalogue place under the click opens no drawer. */
function startEndPick(entry) {
  stopEndPick();
  endPick = entry;
  claimMapClicks();
  /* A class, not canvas.style.cursor: layer hover handlers reset that one. */
  map.getContainer().classList.add('scout-picking');
  map.on('click', endPickClick);
  document.addEventListener('keydown', onPickEsc);
  fitStretch(entry);
  pickBar(tpl(t('scoutPickEnd', 'Click the ride where stretch {n} ends. Press Esc to stop.'), { n: entry.n }));
  redrawStretchCard(entry);
}

/* Pick-mode words live on the map, where the rider is looking. A mistake
   shows in the bar for a moment, then the instruction comes back. */
let pickBarTimer = 0;
function pickBar(text, isErr) {
  let bar = el('scoutPickbar');
  if (!text) { if (bar) bar.hidden = true; clearTimeout(pickBarTimer); return; }
  if (!bar) {
    bar = document.createElement('div');
    bar.id = 'scoutPickbar';
    bar.className = 'cc-pickbar scout-pickbar';
    bar.setAttribute('role', 'status');
    const span = document.createElement('span');
    const stop = document.createElement('button');
    stop.type = 'button';
    stop.textContent = t('scoutPickStop', 'Stop');
    stop.addEventListener('click', () => stopEndPick());
    bar.append(span, stop);
    document.body.appendChild(bar);
  }
  const span = bar.firstChild;
  clearTimeout(pickBarTimer);
  if (isErr) {
    const back = bar.dataset.hint || '';
    span.textContent = text;
    bar.classList.add('err');
    pickBarTimer = setTimeout(() => { span.textContent = back; bar.classList.remove('err'); }, 3200);
  } else {
    bar.dataset.hint = text;
    span.textContent = text;
    bar.classList.remove('err');
  }
  bar.hidden = false;
}

function stopEndPick() {
  if (!endPick) return;
  const entry = endPick;
  endPick = null;
  map.off('click', endPickClick);
  document.removeEventListener('keydown', onPickEsc);
  map.getContainer().classList.remove('scout-picking');
  releaseMapClicks();
  pickBar('');
  redrawStretchCard(entry);
}

function onPickEsc(e) {
  if ('Escape' === e.key) stopEndPick();
}

const PICK_REACH_PX = 30;

function endPickClick(e) {
  const entry = endPick;
  if (!entry) return;
  const px = e.point;
  const near = nearestTrackIndex(track, e.lngLat);
  const q = near < 0 ? null : map.project([track[near].lng, track[near].lat]);
  if (!q || Math.hypot(q.x - px.x, q.y - px.y) > PICK_REACH_PX) {
    pickBar(t('scoutPickOnRide', 'Click on the ride line.'), true);
    return;
  }
  setStretchEnd(entry, endIndexFor(track, entry.startIdx, e.lngLat));
}

/* Move a stretch's end to a ride index: pick click, grey pin, or card button. */
function setStretchEnd(entry, idx) {
  if (idx <= entry.startIdx || idx < 0) {
    const say = t('scoutPickAfterStart', 'The end must come after the green start. Click further along the ride.');
    if (endPick) pickBar(say, true); else mapToast(say);
    return;
  }
  const geom = sliceTrack(track, entry.startIdx, idx);
  if (!geom) return;
  entry.endIdx = idx;
  entry.geom = geom;
  entry.needsEnd = false;
  if (entry.endMarker) entry.endMarker.setLngLat([track[idx].lng, track[idx].lat]);
  /* The map stays put: the rider is looking at the end they just set. */
  refreshStretches();
  if (endPick) stopEndPick();
  else redrawStretchCard(entry);
}

/* Remove from this review only. The tag stays in the ride file. */
function removeBtn(entry) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'scout-remove';
  b.textContent = '\u00d7';
  b.title = t('scoutRemove', 'Remove this tag from the review');
  b.setAttribute('aria-label', b.title);
  b.addEventListener('click', () => {
    if (endPick === entry) stopEndPick();
    entry.dismissed = true;
    if (entry.marker) entry.marker.remove();
    if (entry.markers) entry.markers.forEach(m => m.remove());
    refreshStretches();
    renderList();
  });
  return b;
}

/* Fallback name from tag type (and surface detail), so unnamed spots stay sendable. */
function fallbackName(entry) {
  const kind = t('scoutTag_' + entry.tag, entry.tag);
  const detail = (entry.osmSurface || '').trim();
  return detail ? kind + ' · ' + detail : kind;
}

/* Stretch sent on the same wire as a point tag, plus `segment` and surface. */
async function sendStretch(entry, button, li) {
  if (entry.approved || entry.sending || entry.needsEnd || !entry.geom) return;
  if (!(await sendBundlePhotos(entry, button, li))) return;
  entry.sending = true;
  const wire = {
    tag: 'surface',
    letter: 'A',
    lat: entry.geom.a[1],
    lng: entry.geom.a[0],
    at: entry.seg.startTime ? entry.seg.startTime.toISOString() : '',
    name: (entry.name || '').trim(),
    osmSurface: entry.osmSurface,
    surface: entry.surface,
    segment: entry.geom,
    mediaIds: entry.mediaIds,
    marker: null,
  };
  if (!wire.name) wire.name = fallbackName(wire);
  return sendOne(wire, button, li).then(() => {
    entry.sending = false;
    entry.approved = wire.approved || entry.approved;
    entry.name = wire.name;
    if (entry.approved && entry.markers) {
      entry.markers.forEach(m => { m.getElement().classList.add('done'); m.setDraggable(false); });
    }
    updateCounts();
  });
}

/** One tag, one request — see ScoutIntakeController for why not a batch. */
async function sendOne(entry, button, li) {
  /* Guard on the entry, not the button — "Send the rest" must not double-post. */
  if (entry.approved || entry.sending) return;
  /* Letterless tag is its description — empty field refuses instead of falling back. */
  if ('' === entry.letter && !(entry.name || '').trim()) {
    msg(t('scoutNeedName', 'Give the tag a name before sending it.'), true);
    return;
  }
  if (!(await sendBundlePhotos(entry, button, li))) return;
  entry.sending = true;
  if (!entry.name || !entry.name.trim()) entry.name = fallbackName(entry);
  sendBtnState(button, 'sending');
  try {
    const res = await fetch('/scout/tags', {
      method: 'POST',
      // X-CC-Token: stateless 'scout-tags' CSRF (ScoutIntakeController).
      headers: { 'Content-Type': 'application/json', 'X-CC-Token': window.CC_SCOUT_TOKEN || '' },
      // Wire contract: no track, no polyline, no device id.
      body: JSON.stringify({
        tag: entry.tag,
        letter: entry.letter,
        lat: entry.lat,
        lng: entry.lng,
        observedAt: entry.at || '',
        details: Object.assign(
          { name: entry.name.trim() },
          entry.surface ? { surface: entry.surface } : {},
        ),
        /* Rider-approved excerpt {a, b, line} — the exception to "no track keys". */
        segment: entry.segment ? JSON.stringify(entry.segment) : undefined,
        osmSurface: entry.osmSurface || undefined,
        mediaIds: entry.mediaIds || undefined,
      }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'failed');
    entry.approved = true;
    if (li) li.classList.add('done');
    if (entry.marker) entry.marker.getElement().classList.add('done');
    sendBtnState(button, 'sent');
    msg('');
  } catch (e) {
    sendBtnState(button, 'idle');
    msg(t('scoutSendFailed', 'That tag could not be sent — try again.'), true);
  }
  entry.sending = false;
  updateCounts();
}

function placeTags() {
  tags.forEach(entry => {
    const m = new maplibregl.Marker({ element: pinEl(entry), draggable: true, anchor: 'center' })
      .setLngLat([entry.lng, entry.lat]).addTo(map);
    m.on('dragend', () => {
      /* Free placement: the ride is where the rider was, not where the thing is. */
      const p = m.getLngLat();
      entry.lng = p.lng;
      entry.lat = p.lat;
    });
    m.getElement().addEventListener('click', () => openCardFor(entry));
    entry.marker = m;
  });
}

function openCardFor(entry) {
  const list = el('scoutTags');
  const li = list && list.querySelector('[data-n="' + entry.n + '"]');
  if (li) { li.classList.add('open'); li.scrollIntoView({ block: 'nearest' }); }
}

/* Stretch ends drag along the ride only. Start cannot pass end; a sent stretch locks. */
function placeStretchMarkers() {
  stretches.forEach(entry => {
    if (!entry.geom) return;
    [['seg-start', entry.geom.a], ['seg-end', entry.geom.b]].forEach(([cls, at]) => {
      const m = new maplibregl.Marker({ element: stretchPinEl(entry, cls), anchor: 'center', draggable: !entry.approved })
        .setLngLat(at).addTo(map);
      m.on('dragend', () => {
        const isStart = 'seg-start' === cls;
        let idx = nearestTrackIndex(track, m.getLngLat());
        idx = isStart ? Math.min(idx, entry.endIdx - 1) : Math.max(idx, entry.startIdx + 1);
        if (isStart) entry.startIdx = idx; else entry.endIdx = idx;
        const geom = sliceTrack(track, entry.startIdx, entry.endIdx);
        if (geom) entry.geom = geom;
        m.setLngLat([track[idx].lng, track[idx].lat]);
        refreshStretches();
        if (!isStart) entry.needsEnd = false;
        redrawStretchCard(entry);
      });
      m.getElement().addEventListener('click', () => openCardFor(entry));
      segMarkers.push(m);
      (entry.markers = entry.markers || []).push(m);
      if ('seg-end' === cls) entry.endMarker = m;
    });
  });
}

function renderRideFacts(parsed) {
  const box = el('scoutFacts');
  if (!box) return;
  box.textContent = '';
  const lines = [];
  if (parsed.radar && parsed.radar.total > 0) {
    lines.push(tplCount(t('scoutRadar', '{n} vehicles passed you on this ride'), parsed.radar.total));
    /* Passes we could not place. */
    const placed = parsed.radar.passes.filter(x => x.lat != null && x.lon != null).length;
    if (placed < parsed.radar.total) {
      lines.push(tplCount(t('scoutPassNoFix', '{n} of them had no GPS fix, so they are not on the map'), parsed.radar.total - placed));
    }
  }
  if (parsed.unplaceable > 0) {
    lines.push(tplCount(t('scoutNoFix', '{n} tag(s) had no GPS fix and cannot be placed'), parsed.unplaceable));
  }
  if (parsed.bundleUnmatched > 0) {
    lines.push(tplCount(t('scoutBundleUnmatched', '{n} note(s) or photo(s) in the bundle match no tag, so they are not used'), parsed.bundleUnmatched));
  }
  lines.forEach(text => {
    const p = document.createElement('p');
    p.className = 'scout-fact';
    p.textContent = text;
    box.appendChild(p);
  });
  /* No-type taps: a live line, filled by refreshNotices() once the pins are
     on the ride. The grey pins are small and sit among the red ones, so the
     link takes the rider to the ones still open. */
  if ((parsed.bareTaps || []).length) {
    const p = document.createElement('p');
    p.className = 'scout-fact';
    p.id = 'scoutBareFact';
    p.hidden = true;
    const text = document.createElement('span');
    const go = document.createElement('button');
    go.type = 'button';
    go.className = 'scout-fact-go';
    go.textContent = t('scoutShowBare', 'Show on the map');
    go.addEventListener('click', () => {
      const open = openBareTaps();
      if (!open.length) return;
      if (1 === open.length) { map.flyTo({ center: [open[0].lng, open[0].lat], zoom: 15 }); return; }
      const b = new maplibregl.LngLatBounds([open[0].lng, open[0].lat], [open[0].lng, open[0].lat]);
      open.forEach(x => b.extend([x.lng, x.lat]));
      map.fitBounds(b, { padding: 120, maxZoom: 15 });
    });
    p.append(text, go);
    box.appendChild(p);
  }
  box.hidden = !lines.length;
}

function openBareTaps() {
  return openBare(bareTaps, stretches);
}

/* Notices shrink as the rider fixes things and vanish with the last fix. */
let noEndText = '';
function refreshNotices() {
  const box = el('scoutFacts');
  const bareLine = el('scoutBareFact');
  if (bareLine) {
    const n = openBareTaps().length;
    bareLine.firstChild.textContent = tplCount(t('scoutBareSurface', 'Surface taps with no type (grey ?): {n}'), n);
    bareLine.hidden = 0 === n;
  }
  if (box) box.hidden = ![...box.children].some(c => !c.hidden);

  const out = el('scoutMsg');
  if (noEndText && out && out.textContent === noEndText) {
    const n = stretches.filter(x => x.needsEnd && !x.dismissed && !x.approved).length;
    noEndText = n ? tplCount(t('scoutNeedEnd', '{n} stretch(es) have no end yet and were not sent. Set the end, then send them.'), n) : '';
    msg(noEndText, true);
  } else {
    noEndText = '';
  }
}

function show(parsed) {
  const panel = el('scoutPanel');
  if (panel) panel.hidden = false;
  clearRide();
  placePasses(parsed.radar);
  track = parsed.track;
  tags = parsed.tags.map(w => ({
    ...w,
    letter: 'other' === w.tag ? (w.letter || '')
      : (w.letter || ((window.CC_SCOUT_TAGS || {})[w.tag] || ['B'])[0]),
    approved: false,
  }));
  /* Each stretch: geometry cut from the ride between the two taps — coordinates
     only. The one exception to "the ride never leaves the browser". */
  stretches = (parsed.segments || []).map(seg => {
    const geom = cutTrack(parsed.track, seg);
    return {
      seg,
      cls: DEVICE_CLASS[seg.type] || 'gravel',
      surface: DEVICE_DECLARABLE[seg.type] || '',
      osmSurface: OSM_SURFACE[seg.type] || '',
      geom,
      /* Endpoints as track indices so dragging is a number comparison. */
      startIdx: geom ? nearestTrackIndex(parsed.track, { lng: geom.a[0], lat: geom.a[1] }) : -1,
      endIdx: geom ? nearestTrackIndex(parsed.track, { lng: geom.b[0], lat: geom.b[1] }) : -1,
      name: '',
      approved: false,
      /* No END tap: blocked until the rider sets the end or keeps it. */
      needsEnd: !!(seg.unterminated && geom),
    };
  });
  /* One chronological list: stretch at start tap, point at tap. */
  const when = x => 'stretch' === x.kind
    ? (x.ref.seg.startTime ? x.ref.seg.startTime.getTime() : 0)
    : (x.ref.at ? Date.parse(x.ref.at) : 0);
  order = [
    ...tags.map(ref => ({ kind: 'point', ref })),
    ...stretches.map(ref => ({ kind: 'stretch', ref })),
  ].sort((a, b) => when(a) - when(b));
  order.forEach((x, i) => { x.ref.n = i + 1; });

  /* A phone bundle: notes into the name fields, photos onto the cards. */
  let unmatched = 0;
  if (parsed.bundle) {
    const byKey = new Map();
    tags.forEach(x => { if (x.bkey) byKey.set(x.bkey, x); });
    stretches.forEach(x => { if (x.seg.bkey) byKey.set(x.seg.bkey, x); });
    parsed.bundle.entries.forEach((e, key) => {
      const ref = byKey.get(key);
      if (!ref) { unmatched += (e.note ? 1 : 0) + e.photos.length; return; }
      if (e.note && !ref.name) ref.name = e.note;
      if (e.photos.length) ref.bundlePhotos = e.photos.map(p => new File([p.bytes], p.name, { type: p.type }));
    });
    unmatched += parsed.bundle.skipped;
  }
  parsed.bundleUnmatched = unmatched;
  renderRideFacts(parsed);

  drawRide();
  drawStretches();
  raiseScoutLayers();
  hookRaise();
  placeTags();
  placeStretchMarkers();
  placeBareTaps(parsed.bareTaps);
  renderList();
  const list = el('scoutList');
  if (list) list.hidden = false;
  const empty = !tags.length && !stretches.length;
  msg(empty ? t('scoutNoTags', 'That ride has no tags in it — nothing to review.') : '', empty);
}

function loadFile(file) {
  if (!file) return;
  if (!/\.(fit|zip)$/i.test(file.name)) {
    msg(t('scoutNeedFit', 'Open the .fit file of your ride, or the .zip your Scout phone app exported.'), true);
    return;
  }
  const reader = new FileReader();
  reader.onload = () => {
    let parsed;
    const bytes = new Uint8Array(reader.result);
    /* A phone's bundle (docs/specs/scout-bundle.md): unpacked here, in memory. */
    if (isZip(bytes)) {
      let bundle;
      try {
        bundle = readBundle(bytes, unzipSync);
      } catch (e) {
        const code = e && e.code;
        msg('version' === code ? t('scoutBundleVersion', 'This bundle is from a newer Scout app. Reload the page and try again.')
          : 'too-large' === code ? t('scoutBundleTooLarge', 'This bundle is too large to open here.')
          : t('scoutBundleBad', 'That .zip is not a Scout ride export.'), true);
        return;
      }
      try {
        parsed = readFit(bundle.fit.buffer.slice(bundle.fit.byteOffset, bundle.fit.byteOffset + bundle.fit.byteLength));
      } catch (e) {
        msg(t('scoutBadFile', 'That file could not be read as a ride.'), true);
        return;
      }
      parsed.bundle = bundle;
      show(parsed);
      return;
    }
    try {
      parsed = readFit(reader.result);
    } catch (e) {
      msg((e && e.message) ? e.message : t('scoutBadFile', 'That file could not be read as a ride.'), true);
      return;
    }
    show(parsed);
  };
  reader.onerror = () => msg(t('scoutBadFile', 'That file could not be read as a ride.'), true);
  reader.readAsArrayBuffer(file);
}

/* Photos: one uploader for the panel (docs/specs/photo-uploads.md) — consent
   must fail closed in a single place. */
let photoTarget = null;
let photoButton = null;
let photoIndex = 0;          // the tag number the queue is currently filling
const attributed = new Set(); // ids already assigned to a tag
let uploader = null;
let mediaBusy = false;
if (typeof document !== 'undefined') {
  document.addEventListener('cc:media-busy', e => { mediaBusy = !!(e.detail && e.detail.busy); });
}

function idCount(entry) {
  try { const a = JSON.parse(entry.mediaIds || '[]'); return Array.isArray(a) ? a.length : 0; } catch (e) { return 0; }
}
function photoCount(entry) {
  return idCount(entry) + ((entry.bundlePhotos || []).length);
}

/* The phone's photos for one card: thumbnails, each removable. Nothing has
   uploaded yet; they go when the card is sent. */
function bundleStrip(entry) {
  const files = entry.bundlePhotos || [];
  if (!files.length || entry.approved) return null;
  const wrap = document.createElement('div');
  wrap.className = 'scout-bphotos';
  const label = document.createElement('span');
  label.className = 'scout-bphotos-l';
  label.textContent = tplCount(t('scoutBundlePhotos', '{n} photo(s) from your phone, sent with this tag'), files.length);
  wrap.appendChild(label);
  entry.bundleUrls = entry.bundleUrls || new Map();
  files.forEach(f => {
    if (!entry.bundleUrls.has(f)) entry.bundleUrls.set(f, URL.createObjectURL(f));
    const fig = document.createElement('span');
    fig.className = 'scout-bphoto';
    const img = document.createElement('img');
    img.src = entry.bundleUrls.get(f);
    img.alt = '';
    const x = document.createElement('button');
    x.type = 'button';
    x.textContent = '\u00d7';
    x.title = t('scoutBundlePhotoRemove', 'Do not send this photo');
    x.setAttribute('aria-label', x.title);
    x.addEventListener('click', () => {
      entry.bundlePhotos = entry.bundlePhotos.filter(g => g !== f);
      URL.revokeObjectURL(entry.bundleUrls.get(f));
      entry.bundleUrls.delete(f);
      const card = wrap.closest('li');
      const btn = card && card.querySelector('.scout-photo');
      if (btn) photoBtnState(btn, photoCount(entry));
      const next = bundleStrip(entry);
      if (next) wrap.replaceWith(next); else wrap.remove();
    });
    fig.append(img, x);
    wrap.appendChild(fig);
  });
  return wrap;
}

/* Before a card sends, its phone photos go through the one uploader, consent
   gate and all. The card waits for their ids; a photo that fails stops the
   send so the rider can decide, and is not tried again. */
async function sendBundlePhotos(entry, button, li) {
  const files = entry.bundlePhotos || [];
  if (!files.length) return true;
  if (!uploader || !uploader.accept) return false;
  const want = idCount(entry) + files.length;
  photoTarget = entry;
  photoButton = li ? li.querySelector('.scout-photo') : null;
  photoIndex = entry.n || 0;
  const media = el('scoutMedia');
  if (media) media.hidden = false;
  sendBtnState(button, 'sending');
  entry.sending = true;
  uploader.accept(files);
  const started = Date.now();
  const ok = await new Promise(resolve => {
    const tick = () => {
      if (idCount(entry) >= want && !mediaBusy) { resolve(true); return; }
      const modal = document.getElementById('modal');
      const asking = modal && modal.classList.contains('open');
      if (!asking && !mediaBusy && Date.now() - started > 800) { resolve(idCount(entry) >= want); return; }
      if (Date.now() - started > 180000) { resolve(false); return; }
      setTimeout(tick, 300);
    };
    tick();
  });
  entry.sending = false;
  (entry.bundleUrls || new Map()).forEach(u => URL.revokeObjectURL(u));
  entry.bundleUrls = new Map();
  entry.bundlePhotos = [];
  const strip = li && li.querySelector('.scout-bphotos');
  if (strip) strip.remove();
  if (photoButton) photoBtnState(photoButton, photoCount(entry));
  if (!ok) {
    sendBtnState(button, 'idle');
    msg(t('scoutBundlePhotosFailed', 'Not every photo could be added. Check the photo list below, then send the tag again.'), true);
  }
  return ok;
}

function mountPhotos() {
  const hidden = el('scoutMediaIds');
  if (!hidden || !window.Cc || !window.Cc.mountMediaUploads) return;
  uploader = window.Cc.mountMediaUploads({
    hidden,
    /* Where the photo is for (the upload refuses one without a place): the
       tag being filled, or the start of the stretch. */
    pin: () => {
      const e = photoTarget;
      if (!e) return null;
      if (e.geom && e.geom.a) return { lat: e.geom.a[1], lng: e.geom.a[0] };
      return e.lat != null && e.lng != null ? { lat: e.lat, lng: e.lng } : null;
    },
    onChange: () => {
      /* Hidden field is a JSON array of ids; unattributed ids go to whoever asked last. */
      let ids = [];
      try { ids = JSON.parse(hidden.value || '[]'); } catch (e) { ids = []; }
      if (!Array.isArray(ids)) ids = [];
      const fresh = ids.filter(id => typeof id === 'string' && !attributed.has(id));
      if (!fresh.length || !photoTarget) return;
      fresh.forEach(id => attributed.add(id));
      let have = [];
      try { have = JSON.parse(photoTarget.mediaIds || '[]'); } catch (e) { have = []; }
      if (!Array.isArray(have)) have = [];
      const all = have.concat(fresh);
      photoTarget.mediaIds = JSON.stringify(all);
      const n = all.length;
      if (photoButton) photoBtnState(photoButton, n);
      numberQueue();
    },
  });
}

/** Stamp each queue chip with the tag it belongs to. */
function numberQueue() {
  const rows = [...document.querySelectorAll('#q-photo .chip')];
  const owners = [];
  tags.forEach((entry, i) => {
    let own = [];
    try { own = JSON.parse(entry.mediaIds || '[]'); } catch (e) { own = []; }
    (Array.isArray(own) ? own : []).forEach(() => owners.push(i + 1));
  });
  rows.forEach((row, i) => {
    if (owners[i]) row.setAttribute('data-tag-no', String(owners[i]));
  });
}

function tplCount(text, n) {
  return String(text).replace('{n}', String(n));
}

function requestPhotoFor(entry, button) {
  const media = el('scoutMedia');
  const input = el('file-photo');
  if (!media || !input) return;
  photoTarget = entry;
  photoButton = button;
  photoIndex = entry.n || (tags.indexOf(entry) + 1);
  media.hidden = false;
  input.click();
  setTimeout(() => media.scrollIntoView({ block: 'nearest', behavior: 'smooth' }), 250);
}

/** Send everything still outstanding, in ride order. Find cards by number, not list position. */
async function sendAll(button) {
  const list = el('scoutTags');
  button.disabled = true;
  button.dataset.busy = '1';
  button.textContent = t('scoutSending', 'Sending…');
  if (endPick) stopEndPick();
  let noEnd = 0;
  for (const x of order) {
    const entry = x.ref;
    if (entry.approved || entry.dismissed) continue;
    if ('stretch' === x.kind && entry.needsEnd) { noEnd++; continue; }
    const li = list && list.querySelector('[data-n="' + entry.n + '"]');
    const rowBtn = li && li.querySelector('.scout-approve');
    if (!rowBtn) continue;
    if ('stretch' === x.kind) await sendStretch(entry, rowBtn, li);
    else await sendOne(entry, rowBtn, li);
  }
  delete button.dataset.busy;
  updateCounts();
  if (noEnd) {
    noEndText = tplCount(t('scoutNeedEnd', '{n} stretch(es) have no end yet and were not sent. Set the end, then send them.'), noEnd);
    msg(noEndText, true);
  }
}

/** Mount the panel. A no-op everywhere except /scout/review. */
export function initScoutReview() {
  const panel = el('scoutPanel');
  if (!panel) return;
  const input = el('scoutFile');
  const pick = el('scoutPick');
  if (pick && input) {
    pick.addEventListener('click', () => input.click());
    input.addEventListener('change', () => loadFile(input.files && input.files[0]));
  }
  /* Close hides the card. State lives in module + map, so a pin brings it back. */
  const closeBtn = el('scoutClose');
  if (closeBtn) {
    closeBtn.addEventListener('click', () => {
      const unsent = [...tags, ...stretches].filter(x => !x.approved && !x.dismissed).length;
      if (unsent > 0 && !window.confirm(tpl(t('scoutCloseUnsent', 'Close the review? {n} tag(s) have not been sent — they stay in your ride file, so you can open it here again later.'), { n: unsent }))) return;
      clearRide();
      panel.hidden = true;
    });
  }

  mountPhotos();
  const sendAllBtn = el('scoutSendAll');
  if (sendAllBtn) sendAllBtn.addEventListener('click', () => sendAll(sendAllBtn));
  const drop = el('scoutDrop');
  if (drop) {
    drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('over'); });
    drop.addEventListener('dragleave', () => drop.classList.remove('over'));
    drop.addEventListener('drop', e => {
      e.preventDefault();
      drop.classList.remove('over');
      loadFile(e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]);
    });
  }
}

export const scoutRed = RED;

/* Smoke-harness handle: drag via markers, not synthetic mouse events. */
if (typeof window !== 'undefined') window.__ccScoutTags = () => tags;
