// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Scout ride review: read a ride IN THE BROWSER, put its tags on the map, let
   the rider fix each one, and send them to the Commons one approved tag at a
   time (Dated/2026-08-09-scout-cc-tagger-plan.md, tasks 5 and 7).

   The load-bearing property, and the one every change here has to preserve:
   **the ride never leaves this file**. It is parsed with FileReader, drawn from
   memory, and the only thing that ever crosses the network is one tag the rider
   has looked at and approved. There is no upload, no draft on a server, nothing
   to expire, and nothing to delete — the promise on /scout and /privacy is a
   description of this module rather than a policy about a stored file.
   ScoutIntakeController REFUSES a payload carrying a track, so this cannot
   quietly change without a test going red.

   Why it lives on the real map rather than its own: a rider fixing a mis-tapped
   tag needs to see what is already mapped around it — the water point twenty
   metres away that makes theirs a duplicate, the surface line they are about to
   contradict. That judgement is the whole task, and a bare basemap cannot
   support it (owner, 2026-08-12).

   Tags render RED because they are unresolved, not because they are wrong: red
   is the one colour the map does not otherwise use for a place, and a rider
   scanning a ride needs to find what still needs them. */
import { map } from './map-init.js';
import { D, tpl } from './i18n.js';
import { uSpeed } from './units.js';
import { parseFit, extractTags, buildSurfaceSegments, countVehicles, MESG, semiToDeg,
         fitToDate, POI_RESUPPLY, OSM_SURFACE, LEGACY_RESUPPLY, SURF_TYPE } from '../lib/scout-fit.js';
import { surfaceStyle } from './render.js';
import { DEVICE_CLASS, DEVICE_DECLARABLE, cutTrack } from './scout-segments.js';

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
/** Surface stretches (plan task 6): view-models over buildSurfaceSegments(). */
let stretches = [];
/** Points and stretches in ride order - the numbering the map and list share. */
let order = [];
let segMarkers = [];

const el = id => document.getElementById(id);
const t = (k, fallback) => (D && D[k]) || fallback;

/* ── reading a ride ────────────────────────────────────────────────────────
   FIT, and only FIT. No Scout app writes GPX (owner, 2026-08-12), so a GPX
   reader here would be a path no real ride can take — and worse, one that
   quietly produces weaker tags: `<wpt>` names carry no sub-type and no surface
   value, so a ride routed through it would arrive stripped of half of what the
   rider recorded, with nothing to say so.

   The decoder is Scout's own, vendored verbatim (assets/lib/scout-fit.js,
   MIT). */

/* Scout's poi_type → our tag vocabulary (ScoutTag::TYPES, PHP). The legacy
   spellings matter: before Scout 1.3, water/food/repair were three separate
   poi_types rather than one RESUPPLY with a kind, and rides recorded then are
   still on people's devices. Dropping them would silently discard real tags. */
const POI_TO_TAG = {
  1: 'notice', 2: 'scenery', 3: 'resupply', 4: 'other',
  5: 'closure', 6: 'surface', 7: 'resupply', 8: 'resupply', 9: 'resupply',
};

/* Which letters a tag may become, narrowed by the device's SUB-MENU when that
   says more than the tag type does.

   SCENERY · HISTORY is history & culture, not a scenic view; RESUPPLY · REPAIR
   is a bike service, not water. Offering only the coarse answer made the rider
   re-file their own tag (owner-reported 2026-08-12). The tables come from the
   server (App\Scout\ScoutTag), so the panel can never offer a letter the
   endpoint refuses. */
function lettersFor(tag, detail) {
  const byDetail = (window.CC_SCOUT_DETAILS || {})[tag] || {};
  const offered = detail != null ? byDetail[String(detail)] : byDetail[''];
  if (offered && offered.length) return offered;
  return (window.CC_SCOUT_TAGS || {})[tag] || ['C'];
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

  // The ride line, from the RECORD messages. Used to draw the ride and to clamp
  // a dragged tag to it — and, like everything else here, never sent anywhere.
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

  /* The overtake count, if the rider was carrying a radar. Read and SHOWN,
     because it is their ride and their number — but not sent anywhere and not
     stored, because nothing on the server can hold it yet: the plan puts
     measurements in their own table behind a five-rider gate, visible only to
     moderators (Dated/2026-08-09-scout-cc-tagger-plan.md §2/D2), and none of
     that is built. Showing it while saying so is honest; showing it as though
     it had been recorded would not be. */
  const radar = countVehicles(parsed);

  /* Tags with no GPS fix. They were dropped silently, which is the failure this
     project keeps finding: a rider counts thirteen taps on the bars, sees
     eleven here, and has no way to learn that two were recorded in a tunnel
     with no lock. Counted and named instead. */
  const unplaceable = raw.filter(t => !t.cancelled && (t.lat == null || t.lon == null)).length;

  /* A cancelled tag is one the rider retracted on the device by tapping the
     same tile twice — Scout's own undo rule, applied by the vendored parser.
     It is not ours to second-guess, and showing it would ask them to decide
     again about something they already un-decided. */
  const tags = raw
    /* Surface TRANSITIONS (detail 1-8) and END (9) belong to the stretches
       below, not the point list - showing them twice made every stretch also
       a bogus point card defaulting to Water & food (owner, 2026-08-18). A
       bare surface tap (no detail) stays a point: it marks a spot, not a
       stretch. */
    .filter(t => !t.cancelled && t.lat != null && t.lon != null
      && !(t.type === SURF_TYPE && t.detail >= 1))
    .map(t => {
      const legacy = LEGACY_RESUPPLY[t.type];
      const detail = legacy || t.detail;
      const tag = POI_TO_TAG[t.type] || 'other';
      const offered = lettersFor(tag, detail);
      /* 'other' starts UNCHOSEN: the device recorded "something", and
         defaulting it to Water & food would invent an answer the rider never
         gave (owner, 2026-08-18). Every other tag type has a meaningful best
         guess, which stays preselected. */
      const letter = 'other' === tag ? '' : (offered[0] || 'C');
      return {
        tag,
        letter,
        lat: t.lat,
        lng: t.lon,
        at: t.time ? t.time.toISOString() : '',
        name: fitTagName(tag, detail),
        // Kept so a surface tag can carry the class the rider actually chose on
        // the device rather than making them pick it again.
        osmSurface: tag === 'surface' ? (OSM_SURFACE[detail] || '') : '',
        // The sub-menu number itself. The server maps it to the fields it
        // answers — one mapping, in the language that validates it.
        detail: detail || null,
      };
    });

  return { track, tags, segments, radar, unplaceable };
}

function msg(text, isError) {
  const box = el('scoutMsg');
  if (!box) return;
  box.textContent = text;
  box.hidden = !text;
  box.classList.toggle('err', !!isError);
}

/* ── drawing ─────────────────────────────────────────────────────────────── */
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

/* The stretches, in the SAME palette the map's surface legend uses
   (render.js SURFACE_STYLE): a gravel stretch draws ochre here because it
   will draw ochre on the map once approved. Pale casing under class-coloured
   lines, exactly like the curated A layer. */
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

/* Where a vehicle passed you.

   The overtake count was a number in a box: true, and impossible to act on. On
   the map it is a place - this corner, that bridge - which is the whole reason
   a radar reading is worth anything to a rider looking back at a ride.

   Ground speed, not closing speed: what the car was doing, not the difference
   between it and you. The parser gives closing speed plus the rider's own, so
   ground is the sum; when the rider's speed is missing there is no honest
   ground figure and the marker shows the car alone rather than a number that
   would read as the vehicle's speed and be 20 km/h short of it.

   The speed is all a marker says - a "?" when the radar gave none. There is no
   tooltip: closing speed and nearest range are the radar's working, not the
   fact a rider wants off a map, and hover is not a thing on the bike computer
   this data came from.

   Measured here, never sent - like the count line, and for the same reason:
   nothing on the server can hold a measurement yet. */
function passEl(pass) {
  const d = document.createElement('div');
  d.className = 'scout-pass';
  // A car from the side, small enough to sit under a tag pin without fighting
  // it: this is context for the ride, not a thing to click through.
  d.innerHTML = '<svg viewBox="0 0 24 12" width="17" height="9" aria-hidden="true">'
    + '<path fill="currentColor" d="M2 9h20a1 1 0 0 0 1-1V6.2a1.6 1.6 0 0 0-1.1-1.5l-4.2-1.3-2-1.9A2.4 2.4 0 0 0 14 1H8.3a2.4 2.4 0 0 0-2 1.1L4.6 4.6 2.1 5.3A1.5 1.5 0 0 0 1 6.8V8a1 1 0 0 0 1 1z"/>'
    + '<circle cx="6.5" cy="9.4" r="2.1" fill="currentColor"/><circle cx="17.5" cy="9.4" r="2.1" fill="currentColor"/>'
    + '</svg>';
  const sp = document.createElement('span');
  /* No speed reading, no invented number: a "?" says the car passed here and
     that how fast is unknown, which is the honest pair (owner 2026-08-12). The
     unit goes with it - "? km/h" would read like a measurement that failed to
     render rather than one that was never taken. */
  sp.textContent = pass.ground == null ? '?' : uSpeed(pass.ground);
  d.appendChild(sp);
  return d;
}

function placePasses(radar) {
  passMarkers.forEach(m => m.remove());
  passMarkers = [];
  if (!radar || !radar.passes) return;
  radar.passes.forEach(pass => {
    // No place, no marker - there is nowhere to put it. A missing SPEED is a
    // different thing: the pass still happened here, and the marker says so
    // with a "?" rather than disappearing.
    if (pass.lat == null || pass.lon == null) return;
    passMarkers.push(new maplibregl.Marker({ element: passEl(pass), anchor: 'bottom' })
      .setLngLat([pass.lon, pass.lat])
      .addTo(map));
  });
}

/* Closing the panel clears the ride off the map with it (owner 2026-08-12):
   the line, the numbered tag pins and the car markers all go. They belong to a
   review that is over - leaving them would put pins on a map with nothing to
   press them for, and a rider looking at the ordinary map has no way to tell
   they are not real places.

   Unsent tags are worth pausing for, so a review with work left in it asks
   first - and says the reassuring half out loud: the tags live in the RIDE
   FILE, which we never had a copy of and never changed, so opening it here
   again another day brings all of them back. Only what was already sent is
   gone from the list, because it is on the server now. */
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

/* A stretch is two places, not one: its number rides on BOTH ends - green
   where it starts, red where it ends (owner, 2026-08-18) - so "4" on the map
   reads as "stretch 4 runs from here to here". */
function stretchPinEl(entry, cls) {
  const d = document.createElement('div');
  d.className = 'scout-pin ' + cls + (entry.approved ? ' done' : '');
  d.textContent = String(entry.n);
  d.title = t('scoutTag_surface', 'Surface');
  return d;
}

/* Icon buttons (owner, 2026-08-18): the card's controls are one row - a
   small name field with a camera and a send button beside it. Static inline
   SVG, never user content; the words move into title/aria-label so nothing
   is lost to a screen reader. */
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

    /* Open, all of them. A collapsed list hides exactly the thing a rider came
       to check — that thirteen tags are the right thirteen things — behind
       thirteen clicks (owner, 2026-08-12). The head stays a button because it
       still flies the map to the tag. */
    const head = document.createElement('button');
    head.type = 'button';
    head.className = 'scout-tag-head';
    head.textContent = entry.n + ' · ' + (t('scoutTag_' + entry.tag, entry.tag));
    head.addEventListener('click', () => map.flyTo({ center: [entry.lng, entry.lat], zoom: 16 }));
    li.appendChild(head);
    li.classList.add('open');

    const body = document.createElement('div');
    body.className = 'scout-tag-body';

    // What it is: the letters this tag type may become, and nothing else — the
    // same list the server validates against.
    /* 'Other' has no category on the device and none here either (owner,
       2026-08-18): it is a free-text observation. The server auto-files it as
       an F notice and the curator's read of the description is the filing
       decision - so this card is just the text field. Every other tag type
       keeps its category dropdown. */
    if ('other' !== entry.tag) {
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
    name.placeholder = 'other' === entry.tag
      ? t('scoutDescribe', 'Describe what you saw…')
      : t('scoutNamePh', 'Name it');
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

  /* A stretch card (plan task 6). No letter dropdown: a stretch IS road
     surface - the one segment-located letter - so the card states it instead
     of asking. The surface dropdown reuses the A form's own vocabulary
     (CC_FIELD_SCHEMA.A), preselected with what the rider chose on the device. */
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

/* Waving a tag away (owner, 2026-08-18): it leaves THIS review - card,
   pin(s) and line - and nothing else. The tag still lives in the rider's own
   ride file, which we never had a copy of, so opening the file again brings
   it back. Nothing is sent, nothing is deleted anywhere. */
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

/* A name we can stand behind when the rider gives none.

   Not "Untitled": the tag already says what kind of thing it is, and the type
   the device recorded says a little more, so "Scenery" beats an empty field and
   beats a placeholder that means nothing to the curator who reads it next. The
   rider can always type over it — this only stops an unnamed spot from being
   unsendable (owner, 2026-08-12). */
function fallbackName(entry) {
  const kind = t('scoutTag_' + entry.tag, entry.tag);
  const detail = (entry.osmSurface || '').trim();
  return detail ? kind + ' · ' + detail : kind;
}

/* A stretch is sent through the same wire as a point tag, plus the
   `segment` excerpt and the surface the rider settled on. The name falls
   back like a point tag's: "Surface · gravel" beats an empty refusal. */
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
    if (entry.approved && entry.markers) entry.markers.forEach(m => m.getElement().classList.add('done'));
    const done = el('scoutDone');
    if (done) done.textContent = String(tags.filter(x => x.approved).length + stretches.filter(x => x.approved).length);
  });
}

/** One tag, one request — see ScoutIntakeController for why not a batch. */
async function sendOne(entry, button, li) {
  /* One tag, ONE submission. The guard lives on the entry, not the button:
     the button disables itself, but "Send the rest" walking the list while a
     single send is still in flight would post the same tag twice - and the
     server mints a fresh submission for every POST, so a double send is a
     duplicate a curator has to reject (owner, 2026-08-18). */
  if (entry.approved || entry.sending) return;
  /* An Other tag IS its description - "Other" as a name tells the curator
     nothing, so an empty field refuses instead of falling back. */
  if ('other' === entry.tag && !(entry.name || '').trim()) {
    msg(t('scoutNeedName', 'Give the tag a name before sending it.'), true);
    return;
  }
  entry.sending = true;
  if (!entry.name || !entry.name.trim()) entry.name = fallbackName(entry);
  sendBtnState(button, 'sending');
  try {
    const res = await fetch('/scout/tags', {
      method: 'POST',
      // X-CC-Token: the stateless 'scout-tags' CSRF token the map template
      // mints (server refuses without it - ScoutIntakeController).
      headers: { 'Content-Type': 'application/json', 'X-CC-Token': window.CC_SCOUT_TOKEN || '' },
      // Exactly the wire contract: no track, no polyline, no device id.
      body: JSON.stringify({
        tag: entry.tag,
        letter: entry.letter,
        lat: entry.lat,
        lng: entry.lng,
        observedAt: entry.at || '',
        details: Object.assign(
          { name: entry.name.trim() },
          // The surface the rider settled on in the stretch card - the A
          // form's own vocabulary. The server prefers this over osmSurface.
          entry.surface ? { surface: entry.surface } : {},
        ),
        /* The rider-approved excerpt between the two taps: {a, b, line} in
           [lng, lat] pairs, coordinates only - the deliberate exception to
           "no track keys" above, validated end-to-end by the same
           decodeSegment the map wizard uses. */
        segment: entry.segment ? JSON.stringify(entry.segment) : undefined,
        // The surface the rider picked on the device, in OSM's own vocabulary.
        // The server maps it to the declarable label; an unknown value is
        // dropped there rather than trusted.
        osmSurface: entry.osmSurface || undefined,
        // A photo taken at the spot, for a camera that writes no GPS: the tag
        // knows where it was even when the picture does not (owner,
        // 2026-08-12). Claimed by the same MediaClaimService every other
        // submission uses.
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
      /* Free placement (owner, 2026-08-18, reversing the 2026-08-12 clamp):
         the ride is where the rider WAS, not where the thing IS - a castle
         tagged from the road stands beside it, and clamping the pin to the
         track made the correct position unreachable. The rider reviewing
         their own ride is the authority on where it belongs. */
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

/* The stretch's number on both of its ends. Not draggable: the endpoints are
   the rider's taps, and the line between them is the ride itself. */
function placeStretchMarkers() {
  stretches.forEach(entry => {
    if (!entry.geom) return;
    [['seg-start', entry.geom.a], ['seg-end', entry.geom.b]].forEach(([cls, at]) => {
      const m = new maplibregl.Marker({ element: stretchPinEl(entry, cls), anchor: 'center' })
        .setLngLat(at).addTo(map);
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
    /* How many of them we could not place. A rider who counts nine cars on the
       map and reads fifteen in the line above deserves the difference named,
       not left to look like a drawing bug. */
    const placed = parsed.radar.passes.filter(x => x.lat != null && x.lon != null).length;
    if (placed < parsed.radar.total) {
      lines.push(tplCount(t('scoutPassNoFix', '{n} of them had no GPS fix, so they are not on the map'), parsed.radar.total - placed));
    }
  }
  if (parsed.unplaceable > 0) {
    lines.push(tplCount(t('scoutNoFix', '{n} tag(s) had no GPS fix and cannot be placed'), parsed.unplaceable));
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
  // A second ride never draws over the first.
  clearRide();
  renderRideFacts(parsed);
  placePasses(parsed.radar);
  track = parsed.track;
  tags = parsed.tags.map(w => ({
    ...w,
    letter: 'other' === w.tag ? (w.letter || '')
      : (w.letter || ((window.CC_SCOUT_TAGS || {})[w.tag] || ['C'])[0]),
    approved: false,
  }));
  /* Each stretch becomes a card + a coloured line. The geometry is cut from
     the ride strictly between the two taps - coordinates only, no times -
     and is the ONE deliberate exception to "the ride never leaves the
     browser": a rider-approved excerpt, sent only when they press send. */
  stretches = (parsed.segments || []).map(seg => ({
    seg,
    cls: DEVICE_CLASS[seg.type] || 'gravel',
    surface: DEVICE_DECLARABLE[seg.type] || '',
    osmSurface: OSM_SURFACE[seg.type] || '',
    geom: cutTrack(parsed.track, seg),
    name: '',
    approved: false,
  }));
  /* One chronological list: a stretch sits at its start tap, a point at its
     tap, and the numbering walks the ride in order - so "4" on the map is the
     fourth thing that happened, whether it is a spot or a stretch. */
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
    // Named rather than silently attempted: handing this a GPX and watching it
    // fail deep in a binary parser tells a rider nothing about what to do next.
    msg(t('scoutNeedFit', 'Scout writes .fit files — that is the ride to open here.'), true);
    return;
  }
  const reader = new FileReader();
  reader.onload = () => {
    let parsed;
    try {
      parsed = readFit(reader.result);
    } catch (e) {
      /* Loudly, with the decoder's own words. A corrupt FIT and a ride with no
         tags look identical when a parser fails quietly, and that is the one
         failure this screen must never have: a rider would conclude their tags
         had not recorded. */
      msg((e && e.message) ? e.message : t('scoutBadFile', 'That file could not be read as a ride.'), true);
      return;
    }
    show(parsed);
  };
  reader.onerror = () => msg(t('scoutBadFile', 'That file could not be read as a ride.'), true);
  reader.readAsArrayBuffer(file);
}

/* ── photos ────────────────────────────────────────────────────────────────
   ONE uploader for the whole panel, not one per row. media-upload.js owns the
   licence-consent gate, and consent has to fail closed in a single place
   (docs/specs/photo-uploads.md) — duplicating that machinery per tag is how a
   second copy ends up defaulting to "yes". So the module is mounted once, and
   the row that asked for a photo is the row the next completed upload lands
   on. */
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
      /* The hidden field carries every id the panel's queue holds, as a JSON
         ARRAY — media-upload.js writes JSON.stringify(ids), and MediaClaimService
         parses JSON on the way in. Reading it as a comma-separated list built a
         string of nested fragments that would have failed on submit. Anything
         NOT yet attributed belongs to whoever asked last, which is how one spot
         carries several photos and a ride carries photos from many spots, all
         in one queue. */
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
  // Revealed rather than always shown: the consent notice and the queue are
  // meaningful only once somebody has asked to add a picture.
  media.hidden = false;
  input.click();
  /* And bring it into view. The uploader sits below a list that can be twenty
     rows long, so on a phone a rider tapped "add a photo", chose one, and saw
     nothing happen — the picture landed off-screen (owner-reported
     2026-08-12). */
  setTimeout(() => media.scrollIntoView({ block: 'nearest', behavior: 'smooth' }), 250);
}

/** Send everything still outstanding, in ride order. Cards are found by
    their number, never by list position: dismissals and interleaved
    stretches make positions lie. */
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
  /* Close hides the card and leaves nothing in its place. The ride, the pins
     and every tag's state live in module state and on the map, so a tag pin
     brings the card back exactly as it was — re-reading the file would throw
     away edits the rider has already made. */
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

/* A handle for the browser smoke harness (web/tests/browser/map-smoke.js's
   idiom). Dragging a MapLibre marker cannot be driven by synthetic mouse events
   in the probe browser — a trap this codebase has hit before — so the drag path
   is exercised through the markers themselves instead of pretending a
   page.mouse drag proves anything. */
if (typeof window !== 'undefined') window.__ccScoutTags = () => tags;
