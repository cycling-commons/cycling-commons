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
import { D } from './i18n.js';
import { parseFit, extractTags, buildSurfaceSegments, MESG, semiToDeg, fitToDate,
         POI_RESUPPLY, OSM_SURFACE, LEGACY_RESUPPLY } from '../lib/scout-fit.js';

const RIDE_SRC = 'cc-scout-ride';
const RIDE_LINE = 'cc-scout-ride-line';
const RED = '#D92D20';

/** Tag markers, in file order. Each is {tag, lngLat, marker, at, approved}. */
let tags = [];
let track = [];

const el = id => document.getElementById(id);
const t = (k, fallback) => (D && D[k]) || fallback;

/* ── reading a ride ────────────────────────────────────────────────────────
   FIT is the real format: it is what Scout writes into the activity a bike
   computer was already recording, and it is the only one that carries the tag
   TYPE, its sub-type and the moment it was dropped as structured fields. The
   decoder is Scout's own, vendored verbatim (assets/lib/scout-fit.js, MIT).

   GPX is kept as the second door, because a rider who has already exported
   their ride somewhere else should not have to go back for the original. Its
   tags come from `<wpt>` names, which is a weaker channel — no sub-type, no
   surface value — so it is the fallback, not the contract. */

/* Scout's poi_type → our tag vocabulary (ScoutTag::TYPES, PHP). The legacy
   spellings matter: before Scout 1.3, water/food/repair were three separate
   poi_types rather than one RESUPPLY with a kind, and rides recorded then are
   still on people's devices. Dropping them would silently discard real tags. */
const POI_TO_TAG = {
  1: 'notice', 2: 'scenery', 3: 'resupply', 4: 'other',
  5: 'closure', 6: 'surface', 7: 'resupply', 8: 'resupply', 9: 'resupply',
};

/* Which letter a resupply tag lands on. Water and food are one letter here (C);
   a repair stop is another (D). The rider can still change it — this only
   decides which is offered first. */
const RESUPPLY_LETTER = { 1: 'C', 2: 'C', 3: 'D' };

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

  /* A cancelled tag is one the rider retracted on the device by tapping the
     same tile twice — Scout's own undo rule, applied by the vendored parser.
     It is not ours to second-guess, and showing it would ask them to decide
     again about something they already un-decided. */
  const tags = raw
    .filter(t => !t.cancelled && t.lat != null && t.lon != null)
    .map(t => {
      const legacy = LEGACY_RESUPPLY[t.type];
      const detail = legacy || t.detail;
      const tag = POI_TO_TAG[t.type] || 'other';
      const letter = tag === 'resupply'
        ? (RESUPPLY_LETTER[detail] || 'C')
        : ((window.CC_SCOUT_TAGS || {})[tag] || ['C'])[0];
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
      };
    });

  return { track, tags, segments };
}

function parseGpx(text) {
  const doc = new DOMParser().parseFromString(text, 'application/xml');
  if (doc.querySelector('parsererror')) throw new Error('parse');

  const pts = [...doc.querySelectorAll('trkpt, rtept')].map(p => ({
    lng: parseFloat(p.getAttribute('lon')),
    lat: parseFloat(p.getAttribute('lat')),
    at: (p.querySelector('time') || {}).textContent || '',
  })).filter(p => isFinite(p.lng) && isFinite(p.lat));

  /* Waypoints are the tags. Scout's own file writes them as FIT course points;
     a GPX exported from the same ride carries them as <wpt>, whose <sym>/<type>
     names the tag. An unrecognised name is kept as `other` rather than dropped:
     a tag the rider deliberately dropped, silently discarded because we did not
     know the word, is exactly the failure this project keeps finding. */
  const wpts = [...doc.querySelectorAll('wpt')].map(w => {
    const name = (w.querySelector('name') || {}).textContent || '';
    const sym = ((w.querySelector('sym') || {}).textContent || '').toLowerCase();
    const type = ((w.querySelector('type') || {}).textContent || '').toLowerCase();
    const raw = sym || type || name.toLowerCase();
    const known = Object.keys(window.CC_SCOUT_TAGS || {});
    const tag = known.find(k => raw.includes(k)) || 'other';
    return {
      tag,
      lng: parseFloat(w.getAttribute('lon')),
      lat: parseFloat(w.getAttribute('lat')),
      at: (w.querySelector('time') || {}).textContent || '',
      name: name.trim(),
    };
  }).filter(w => isFinite(w.lng) && isFinite(w.lat));

  return { track: pts, tags: wpts };
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

function pinEl(entry, index) {
  const d = document.createElement('div');
  d.className = 'scout-pin' + (entry.approved ? ' done' : '');
  d.textContent = String(index + 1);
  d.title = entry.tag;
  return d;
}

/** A tag is a point ON A RIDE: dragging is clamped to the track, never free.
    Letting one drift into the field beside the road is how a water point ends
    up in a hedge (plan task 5). */
function nearestOnTrack(lngLat) {
  if (!track.length) return lngLat;
  let best = track[0];
  let bestD = Infinity;
  for (const p of track) {
    const dx = (p.lng - lngLat.lng) * Math.cos(p.lat * Math.PI / 180);
    const dy = p.lat - lngLat.lat;
    const d = dx * dx + dy * dy;
    if (d < bestD) { bestD = d; best = p; }
  }
  return { lng: best.lng, lat: best.lat };
}

function renderList() {
  const list = el('scoutTags');
  if (!list) return;
  list.textContent = '';
  const vocab = window.CC_SCOUT_TAGS || {};
  tags.forEach((entry, i) => {
    const li = document.createElement('li');
    li.className = 'scout-tag' + (entry.approved ? ' done' : '');

    const head = document.createElement('button');
    head.type = 'button';
    head.className = 'scout-tag-head';
    head.textContent = (i + 1) + ' · ' + (t('scoutTag_' + entry.tag, entry.tag));
    head.addEventListener('click', () => {
      map.flyTo({ center: [entry.lng, entry.lat], zoom: 16 });
      li.classList.toggle('open');
    });
    li.appendChild(head);

    const body = document.createElement('div');
    body.className = 'scout-tag-body';

    // What it is: the letters this tag type may become, and nothing else — the
    // same list the server validates against.
    const letters = vocab[entry.tag] || [];
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

    const name = document.createElement('input');
    name.type = 'text';
    name.className = 'scout-name';
    name.placeholder = t('scoutNamePh', 'Name it');
    name.value = entry.name || '';
    name.addEventListener('input', () => { entry.name = name.value; });
    body.appendChild(name);

    const approve = document.createElement('button');
    approve.type = 'button';
    approve.className = 'scout-approve';
    approve.textContent = entry.approved ? t('scoutSent', 'Sent') : t('scoutApprove', 'Approve and send');
    approve.disabled = !!entry.approved;
    approve.addEventListener('click', () => sendOne(entry, approve, li));
    body.appendChild(approve);

    li.appendChild(body);
    list.appendChild(li);
  });
  const total = el('scoutTotal');
  const done = el('scoutDone');
  if (total) total.textContent = String(tags.length);
  if (done) done.textContent = String(tags.filter(x => x.approved).length);
}

/** One tag, one request — see ScoutIntakeController for why not a batch. */
async function sendOne(entry, button, li) {
  if (!entry.name || !entry.name.trim()) {
    msg(t('scoutNeedName', 'Give the tag a name before sending it.'), true);
    return;
  }
  button.disabled = true;
  button.textContent = t('scoutSending', 'Sending…');
  try {
    const res = await fetch('/scout/tags', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      // Exactly the wire contract: no track, no polyline, no device id.
      body: JSON.stringify({
        tag: entry.tag,
        letter: entry.letter,
        lat: entry.lat,
        lng: entry.lng,
        observedAt: entry.at || '',
        details: { name: entry.name.trim() },
        // The surface the rider picked on the device, in OSM's own vocabulary.
        // The server maps it to the declarable label; an unknown value is
        // dropped there rather than trusted.
        osmSurface: entry.osmSurface || undefined,
      }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'failed');
    entry.approved = true;
    if (li) li.classList.add('done');
    if (entry.marker) entry.marker.getElement().classList.add('done');
    button.textContent = t('scoutSent', 'Sent');
    msg('');
  } catch (e) {
    button.disabled = false;
    button.textContent = t('scoutApprove', 'Approve and send');
    msg(t('scoutSendFailed', 'That tag could not be sent — try again.'), true);
  }
  const done = el('scoutDone');
  if (done) done.textContent = String(tags.filter(x => x.approved).length);
}

function placeTags() {
  tags.forEach((entry, i) => {
    const m = new maplibregl.Marker({ element: pinEl(entry, i), draggable: true, anchor: 'center' })
      .setLngLat([entry.lng, entry.lat]).addTo(map);
    m.on('dragend', () => {
      const snapped = nearestOnTrack(m.getLngLat());
      m.setLngLat([snapped.lng, snapped.lat]);
      entry.lng = snapped.lng;
      entry.lat = snapped.lat;
    });
    m.getElement().addEventListener('click', () => {
      const li = el('scoutTags') && el('scoutTags').children[i];
      if (li) { li.classList.add('open'); li.scrollIntoView({ block: 'nearest' }); }
    });
    entry.marker = m;
  });
}

function show(parsed) {
  track = parsed.track;
  tags = parsed.tags.map(w => ({
    ...w,
    letter: w.letter || ((window.CC_SCOUT_TAGS || {})[w.tag] || ['C'])[0],
    approved: false,
  }));
  drawRide();
  placeTags();
  renderList();
  const list = el('scoutList');
  if (list) list.hidden = false;
  msg(tags.length ? '' : t('scoutNoTags', 'That ride has no tags in it — nothing to review.'), !tags.length);
}

function loadFile(file) {
  if (!file) return;
  const isFit = /\.fit$/i.test(file.name);
  const reader = new FileReader();
  reader.onload = () => {
    let parsed;
    try {
      parsed = isFit ? readFit(reader.result) : parseGpx(String(reader.result));
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
  if (isFit) reader.readAsArrayBuffer(file);
  else reader.readAsText(file);
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
