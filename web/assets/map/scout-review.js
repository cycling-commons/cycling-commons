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
import { DEVICE_CLASS, DEVICE_DECLARABLE, cutTrack, nearestTrackIndex, sliceTrack } from './scout-segments.js';

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

  /* Overtake count if the rider had a radar: shown, never sent
     (docs/specs/moderation-and-contribution.md (Scout intake)). */
  const radar = countVehicles(parsed);

  /* Tags with no GPS fix: counted and named, not dropped silently. */
  const unplaceable = raw.filter(t => !t.cancelled && (t.lat == null || t.lon == null)).length;

  /* Cancelled = rider retracted on the device (Scout undo). Hidden. */
  /* Bare surface tap (picker timed out): hidden from review, but counted. */
  const bareSurface = raw.filter(t => !t.cancelled && t.type === SURF_TYPE && !(t.detail >= 1)).length;

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
      };
    });

  return { track, tags, segments, radar, unplaceable, bareSurface };
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
  passMarkers.forEach(m => m.remove());
  passMarkers = [];
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
    photoBtnState(photo, entry.mediaIds ? 1 : 0);
    photo.addEventListener('click', () => requestPhotoFor(entry, photo));
    row.appendChild(photo);

    const approve = document.createElement('button');
    approve.type = 'button';
    approve.className = 'scout-approve';
    sendBtnState(approve, entry.approved ? 'sent' : 'idle');
    approve.addEventListener('click', () => sendOne(entry, approve, li));
    row.appendChild(approve);

    body.appendChild(row);

    li.appendChild(body);
    list.appendChild(li);
  };

  /* Stretch card: no letter dropdown — a stretch is road surface (letter A). */
  const surfChoices = (((window.CC_FIELD_SCHEMA || {}).A) || []).find(f => f['key'] === 'surface');
  const buildStretch = (entry) => {
    const li = document.createElement('li');
    li.className = 'scout-tag open' + (entry.approved ? ' done' : '');
    li.dataset.n = String(entry.n);
    li.appendChild(removeBtn(entry));

    const head = document.createElement('button');
    head.type = 'button';
    head.className = 'scout-tag-head';
    head.textContent = entry.n + ' · ' + t('scoutTag_surface', 'Surface');
    head.addEventListener('click', () => {
      if (!entry.geom) return;
      const b = new maplibregl.LngLatBounds(entry.geom.a, entry.geom.a);
      entry.geom.line.forEach(c => b.extend(c));
      map.fitBounds(b, { padding: 100 });
    });
    li.appendChild(head);

    const body = document.createElement('div');
    body.className = 'scout-tag-body';

    const sel = document.createElement('select');
    sel.className = 'scout-letter';
    const choices = surfChoices ? Object.keys(surfChoices.choices || {}) : [];
    (choices.length ? choices : [entry.surface].filter(Boolean)).forEach(label => {
      const o = document.createElement('option');
      o.value = label;
      o.textContent = label;
      if (label === entry.surface) o.selected = true;
      sel.appendChild(o);
    });
    sel.addEventListener('change', () => { entry.surface = sel.value; });
    body.appendChild(sel);

    const row = document.createElement('div');
    row.className = 'scout-row';

    const name = document.createElement('input');
    name.type = 'text';
    name.className = 'scout-name';
    name.placeholder = t('scoutNamePh', 'Name it');
    name.addEventListener('input', () => { entry.name = name.value; });
    row.appendChild(name);

    if (entry.seg.unterminated) {
      const warn = document.createElement('div');
      warn.className = 'scout-fact';
      warn.textContent = t('scoutStretchToEnd', 'No END tap: the stretch runs to the end of the ride. Check the line before sending.');
      body.appendChild(warn);
    }

    const photo = document.createElement('button');
    photo.type = 'button';
    photo.className = 'scout-photo';
    photoBtnState(photo, entry.mediaIds ? 1 : 0);
    photo.addEventListener('click', () => requestPhotoFor(entry, photo));
    row.appendChild(photo);

    const approve = document.createElement('button');
    approve.type = 'button';
    approve.className = 'scout-approve';
    sendBtnState(approve, entry.approved ? 'sent' : 'idle');
    if (!entry.geom) approve.disabled = true;
    approve.addEventListener('click', () => sendStretch(entry, approve, li));
    row.appendChild(approve);

    body.appendChild(row);

    li.appendChild(body);
    list.appendChild(li);
  };

  order.forEach(x => { if (!x.ref.dismissed) ('point' === x.kind ? buildPoint : buildStretch)(x.ref); });

  const live = [...tags, ...stretches].filter(x => !x.dismissed);
  const total = el('scoutTotal');
  const done = el('scoutDone');
  if (total) total.textContent = String(live.length);
  if (done) done.textContent = String(live.filter(x => x.approved).length);
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
function sendStretch(entry, button, li) {
  if (entry.approved || entry.sending) return Promise.resolve();
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
    const done = el('scoutDone');
    if (done) done.textContent = String(tags.filter(x => x.approved).length + stretches.filter(x => x.approved).length);
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
  const done = el('scoutDone');
  if (done) done.textContent = String(tags.filter(x => x.approved).length);
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
      });
      m.getElement().addEventListener('click', () => openCardFor(entry));
      segMarkers.push(m);
      (entry.markers = entry.markers || []).push(m);
    });
  });
}

function renderRideFacts(parsed) {
  const box = el('scoutFacts');
  if (!box) return;
  box.textContent = '';
  const lines = [];
  if (parsed.radar && parsed.radar.total > 0) {
    lines.push(tplCount(t('scoutRadar', '{n} vehicles passed you on this ride — measured, not sent'), parsed.radar.total));
    /* Passes we could not place. */
    const placed = parsed.radar.passes.filter(x => x.lat != null && x.lon != null).length;
    if (placed < parsed.radar.total) {
      lines.push(tplCount(t('scoutPassNoFix', '{n} of them had no GPS fix, so they are not on the map'), parsed.radar.total - placed));
    }
  }
  if (parsed.unplaceable > 0) {
    lines.push(tplCount(t('scoutNoFix', '{n} tag(s) had no GPS fix and cannot be placed'), parsed.unplaceable));
  }
  if (parsed.bareSurface > 0) {
    lines.push(tplCount(t('scoutBareSurface', 'Hidden: {n} surface tap(s) carried no type (the picker timed out)'), parsed.bareSurface));
  }
  lines.forEach(text => {
    const p = document.createElement('p');
    p.className = 'scout-fact';
    p.textContent = text;
    box.appendChild(p);
  });
  box.hidden = !lines.length;
}

function show(parsed) {
  const panel = el('scoutPanel');
  if (panel) panel.hidden = false;
  clearRide();
  renderRideFacts(parsed);
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

  drawRide();
  drawStretches();
  raiseScoutLayers();
  hookRaise();
  placeTags();
  placeStretchMarkers();
  renderList();
  const list = el('scoutList');
  if (list) list.hidden = false;
  const empty = !tags.length && !stretches.length;
  msg(empty ? t('scoutNoTags', 'That ride has no tags in it — nothing to review.') : '', empty);
}

function loadFile(file) {
  if (!file) return;
  if (!/\.fit$/i.test(file.name)) {
    msg(t('scoutNeedFit', 'Scout writes .fit files — that is the ride to open here.'), true);
    return;
  }
  const reader = new FileReader();
  reader.onload = () => {
    let parsed;
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

function mountPhotos() {
  const hidden = el('scoutMediaIds');
  if (!hidden || !window.Cc || !window.Cc.mountMediaUploads) return;
  window.Cc.mountMediaUploads({
    hidden,
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
  button.textContent = t('scoutSending', 'Sending…');
  for (const x of order) {
    const entry = x.ref;
    if (entry.approved || entry.dismissed) continue;
    const li = list && list.querySelector('[data-n="' + entry.n + '"]');
    const rowBtn = li && li.querySelector('.scout-approve');
    if (!rowBtn) continue;
    if ('stretch' === x.kind) await sendStretch(entry, rowBtn, li);
    else await sendOne(entry, rowBtn, li);
  }
  button.disabled = false;
  button.textContent = t('scoutSendAll', 'Send the rest');
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
