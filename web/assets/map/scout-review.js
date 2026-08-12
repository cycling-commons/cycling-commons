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
import { uSpeed, uM } from './units.js';
import { parseFit, extractTags, buildSurfaceSegments, countVehicles, MESG, semiToDeg,
         fitToDate, POI_RESUPPLY, OSM_SURFACE, LEGACY_RESUPPLY } from '../lib/scout-fit.js';

const RIDE_SRC = 'cc-scout-ride';
const RIDE_LINE = 'cc-scout-ride-line';
const RED = '#D92D20';
let passMarkers = [];

/** Tag markers, in file order. Each is {tag, lngLat, marker, at, approved}. */
let tags = [];
let track = [];

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
    .filter(t => !t.cancelled && t.lat != null && t.lon != null)
    .map(t => {
      const legacy = LEGACY_RESUPPLY[t.type];
      const detail = legacy || t.detail;
      const tag = POI_TO_TAG[t.type] || 'other';
      const offered = lettersFor(tag, detail);
      const letter = offered[0] || 'C';
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

/* Where a vehicle passed you.

   The overtake count was a number in a box: true, and impossible to act on. On
   the map it is a place - this corner, that bridge - which is the whole reason
   a radar reading is worth anything to a rider looking back at a ride.

   Ground speed, not closing speed: what the car was doing, not the difference
   between it and you. The parser gives closing speed plus the rider's own, so
   ground is the sum; when the rider's speed is missing there is no honest
   ground figure and the marker shows the car alone rather than a number that
   would read as the vehicle's speed and be 20 km/h short of it.

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
  if (pass.ground != null) {
    const sp = document.createElement('span');
    sp.textContent = uSpeed(pass.ground);
    d.appendChild(sp);
  }
  const bits = [];
  if (pass.ground != null) bits.push(tpl(t('scoutPassSpeed', 'Passed at {s}'), { s: uSpeed(pass.ground) }));
  if (pass.speed != null) bits.push(tpl(t('scoutPassClosing', 'closing {s} faster than you'), { s: uSpeed(pass.speed) }));
  if (pass.range != null) bits.push(tpl(t('scoutPassRange', 'nearest {d}'), { d: uM(pass.range) }));
  d.title = bits.join(' \u00b7 ');
  return d;
}

function placePasses(radar) {
  passMarkers.forEach(m => m.remove());
  passMarkers = [];
  if (!radar || !radar.passes) return;
  radar.passes.forEach(pass => {
    if (pass.lat == null || pass.lon == null) return;
    passMarkers.push(new maplibregl.Marker({ element: passEl(pass), anchor: 'bottom' })
      .setLngLat([pass.lon, pass.lat])
      .addTo(map));
  });
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
  tags.forEach((entry, i) => {
    const li = document.createElement('li');
    li.className = 'scout-tag' + (entry.approved ? ' done' : '');

    /* Open, all of them. A collapsed list hides exactly the thing a rider came
       to check — that thirteen tags are the right thirteen things — behind
       thirteen clicks (owner, 2026-08-12). The head stays a button because it
       still flies the map to the tag. */
    const head = document.createElement('button');
    head.type = 'button';
    head.className = 'scout-tag-head';
    head.textContent = (i + 1) + ' · ' + (t('scoutTag_' + entry.tag, entry.tag));
    head.addEventListener('click', () => map.flyTo({ center: [entry.lng, entry.lat], zoom: 16 }));
    li.appendChild(head);
    li.classList.add('open');

    const body = document.createElement('div');
    body.className = 'scout-tag-body';

    // What it is: the letters this tag type may become, and nothing else — the
    // same list the server validates against.
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

    const name = document.createElement('input');
    name.type = 'text';
    name.className = 'scout-name';
    name.placeholder = t('scoutNamePh', 'Name it');
    name.value = entry.name || '';
    name.addEventListener('input', () => { entry.name = name.value; });
    body.appendChild(name);

    const photo = document.createElement('button');
    photo.type = 'button';
    photo.className = 'scout-photo';
    photo.textContent = entry.mediaIds
      ? t('scoutPhotoAttached', 'Photo attached')
      : t('scoutAddPhoto', 'Add a photo');
    photo.addEventListener('click', () => requestPhotoFor(entry, photo));
    body.appendChild(photo);

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

/** One tag, one request — see ScoutIntakeController for why not a batch. */
async function sendOne(entry, button, li) {
  if (!entry.name || !entry.name.trim()) entry.name = fallbackName(entry);
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
      // A pin is the way back in after the panel was closed: the ride is still
      // here, so clicking its tag reopens the card at that tag.
      const p = el('scoutPanel');
      if (p) p.hidden = false;
      const li = el('scoutTags') && el('scoutTags').children[i];
      if (li) { li.classList.add('open'); li.scrollIntoView({ block: 'nearest' }); }
    });
    entry.marker = m;
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
  if (parsed.segments && parsed.segments.length) {
    lines.push(tplCount(t('scoutStretches', '{n} surface stretch(es) recorded — send these from the map for now'), parsed.segments.length));
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
  renderRideFacts(parsed);
  placePasses(parsed.radar);
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
      if (photoButton) {
        photoButton.textContent = n > 1
          ? tplCount(t('scoutPhotosAttached', '{n} photos attached'), n)
          : t('scoutPhotoAttached', 'Photo attached');
      }
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
  photoIndex = tags.indexOf(entry) + 1;
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

/** Send everything still outstanding, in order. */
async function sendAll(button) {
  const rows = [...document.querySelectorAll('.scout-tag')];
  button.disabled = true;
  button.textContent = t('scoutSending', 'Sending…');
  for (let i = 0; i < tags.length; i++) {
    const entry = tags[i];
    if (entry.approved) continue;
    const li = rows[i];
    const rowBtn = li && li.querySelector('.scout-approve');
    if (rowBtn) await sendOne(entry, rowBtn, li);
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
  if (closeBtn) closeBtn.addEventListener('click', () => { panel.hidden = true; });

  /* Keep the mark square against the text beside it. Its height is whatever the
     title and lead wrap to - which changes with the locale, the panel width and
     the mobile layout - and CSS cannot transfer that back into a width here,
     so it is measured. Cheap: one observer, one custom property. */
  const markbox = panel.querySelector('.scout-markbox');
  const headtext = panel.querySelector('.scout-headtext');
  if (markbox && headtext && typeof ResizeObserver !== 'undefined') {
    const square = () => markbox.style.setProperty('--scout-mark', headtext.offsetHeight + 'px');
    new ResizeObserver(square).observe(headtext);
    square();
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
