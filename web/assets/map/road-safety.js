// SPDX-License-Identifier: AGPL-3.0-only
/* How safe did a road feel (docs/specs/traffic-measurements.md §10): the
   rider's road-safety mode. The road pieces of §2 are drawn over the map from
   zoom 14 (the tiles hold z14 only), a click picks one, a Ctrl+click (Cmd on
   a Mac) adds another or takes a picked one away (owner 2026-10-19), and with
   it the roads of the same type between the last road picked and this one
   (owner 2026-10-19: "on the second ctrl+click it also takes all the roads in
   between those 2 clicks of the same type"), and the
   card asks safe or unsafe and when: the part of the day, the day type and
   the quarter, starting from now on the rider's clock and the holiday file of
   the first road's country. Send marks every picked road with that answer.

   While the mode is on the map's clicks are claimed (picking.js), so no
   drawer opens, the scope does not change and the planner adds no stop.
   Escape and the close button leave it. */
import { map } from './map-init.js';
import { D, tpl } from './i18n.js';
import { familyConfigured, eachSourceInView, NO_VALIDATE } from './tile-sources.js';
import { claimMapClicks, releaseMapClicks } from './picking.js';
import { closeRailPanel } from './shell.js';
import { LABEL_COLOURS } from '../lib/traffic-colours.js';
import { PIECE_ZOOM, loadPieces } from '../lib/road-pieces.js';
import { fetchHolidays } from '../lib/traffic-ride.js';
import { FELT, MAX_ROADS, MAX_MARKS, quarterOptions, markDefaults, pieceOf, markPayload, markCount, pickPiece, chainBetween, tilePoints } from '../lib/road-safety.js';

const el = id => document.getElementById(id);
const t = (k, fallback) => (D && D[k]) || fallback;
/* Pixels around a click in which a road counts as hit: a thumb is wider than a line. */
const HIT_PX = 8;
const NONE = ['==', ['id'], -1];

let cfg = null;          // window.CC_ROAD_SAFETY: {url, token}
let on = false;
let picked = [];         // [{way, label, region, cc}], in the order picked
let last = null;         // the road picked last, with the point clicked: {way, label, at: [lng, lat]}
let fillReq = 0;         // the latest fill between two roads; an older one does not apply
let felt = null;
let touched = false;     // the rider changed the time: keep it for the next road
let sending = false;
const layers = [];       // {line, pick}

function paint(sourceId, sourceLayer) {
  const line = sourceId + '-safety';
  const pick = line + '-pick';
  if (map.getLayer(line)) return;
  const visibility = on ? 'visible' : 'none';
  map.addLayer({
    id: line, type: 'line', source: sourceId, 'source-layer': sourceLayer, minzoom: PIECE_ZOOM,
    layout: { 'line-cap': 'round', 'line-join': 'round', visibility },
    paint: {
      'line-color': ['match', ['get', 'l'], 'p', LABEL_COLOURS.p, 'l', LABEL_COLOURS.l, LABEL_COLOURS.r],
      'line-width': ['interpolate', ['linear'], ['zoom'], 14, 3, 18, 8],
      'line-opacity': 0.75,
    },
  });
  map.addLayer({
    id: pick, type: 'line', source: sourceId, 'source-layer': sourceLayer, minzoom: PIECE_ZOOM,
    filter: pickFilter(),
    layout: { 'line-cap': 'round', 'line-join': 'round', visibility },
    paint: { 'line-color': '#FF5A1F', 'line-width': ['interpolate', ['linear'], ['zoom'], 14, 7, 18, 14], 'line-opacity': 0.95 },
  });
  if (!layers.some(l => l.line === line)) layers.push({ line, pick });
}

function mount() {
  eachSourceInView(map, 'roadpieces', 'roadpieces', PIECE_ZOOM, (key, sourceId) => paint(sourceId, 'roadpieces_' + key));
}

function show(visible) {
  layers.forEach(({ line, pick }) => [line, pick].forEach(id => {
    if (map.getLayer(id)) map.setLayoutProperty(id, 'visibility', visible ? 'visible' : 'none');
  }));
}

function pickFilter() {
  return picked.length ? ['in', ['id'], ['literal', picked.map(p => p.way)]] : NONE;
}

function highlight() {
  layers.forEach(({ pick }) => { if (map.getLayer(pick)) map.setFilter(pick, pickFilter(), NO_VALIDATE); });
}

/* The line above the card's form: no road data, zoom in, or pick a road. */
function hint() {
  const h = el('roadSafetyHint');
  if (!h) return;
  if (!familyConfigured('roadpieces', 'roadpieces')) h.textContent = t('roadSafetyNoTiles', 'Road data is not available yet, so no road can be marked.');
  else if (map.getZoom() < PIECE_ZOOM) h.textContent = t('roadSafetyZoom', 'Zoom in to pick a road.');
  else h.textContent = t('roadSafetyPick', 'Pick a road on the map to say how safe it felt.');
}

function status(text) {
  const s = el('roadSafetyStatus');
  if (s) s.textContent = text || '';
}

/* The times ticked (owner 2026-10-19: "make all fields checkboxes"): the parts of the day, the day types and the
   quarters, each one or more. */
const checked = id => [...((el(id) && el(id).querySelectorAll('input:checked')) || [])].map(i => i.value);
function when() {
  return { bands: checked('roadSafetyBands').map(Number), dayTypes: checked('roadSafetyDays'), quarters: checked('roadSafetyQuarters') };
}
/* A ticked chip shows on; Send waits for a feeling, a tick in every field, and no more marks than one answer holds. */
function paintWhen() {
  document.querySelectorAll('#roadSafety label.chip').forEach(l => { const i = l.querySelector('input'); if (i) l.classList.toggle('on', i.checked); });
  const w = when();
  const tooMany = markCount(picked, w) > MAX_MARKS;
  const send = el('roadSafetySend');
  if (send) send.disabled = !felt || sending || !w.bands.length || !w.dayTypes.length || !w.quarters.length || tooMany;
  const s = el('roadSafetyStatus');
  if (s && tooMany) s.textContent = t('roadSafetyTooMany', 'That is more than one answer can hold: tick fewer times or pick fewer roads.');
  else if (s && s.dataset.tooMany) s.textContent = '';
  if (s) s.dataset.tooMany = tooMany ? '1' : '';
}

function setFelt(value) {
  felt = FELT.includes(value) ? value : null;
  document.querySelectorAll('#roadSafety [data-felt]').forEach(b => {
    const pressed = b.dataset.felt === felt;
    b.classList.toggle('on', pressed);
    b.setAttribute('aria-pressed', pressed ? 'true' : 'false');
  });
  paintWhen();
}

const quarterLabel = (q, now) => {
  const [y, n] = q.split('-Q');
  return tpl(now ? t('roadSafetyQuarterNow', 'Q{n} {y}, this quarter') : t('roadSafetyQuarter', 'Q{n} {y}'), { n, y });
};

/* The time starts on now, on the rider's clock and the road's holidays, until the rider changes it. */
async function fillWhen(cc) {
  if (touched) return;
  const holidays = cc ? await fetchHolidays(cc) : null;
  if (touched) return;
  const defaults = markDefaults(Math.floor(Date.now() / 1000), -new Date().getTimezoneOffset() * 60, holidays);
  document.querySelectorAll('#roadSafetyBands input').forEach(i => { i.checked = Number(i.value) === defaults.band; });
  document.querySelectorAll('#roadSafetyDays input').forEach(i => { i.checked = i.value === defaults.dayType; });
  const quarters = el('roadSafetyQuarters');
  if (quarters) {
    quarters.textContent = '';
    quarterOptions(defaults.quarter).forEach((q, i) => {
      const l = document.createElement('label');
      l.className = 'chip';
      const box = document.createElement('input');
      box.type = 'checkbox';
      box.name = 'quarter';
      box.value = q;
      box.checked = q === defaults.quarter;
      l.append(box, quarterLabel(q, i === 0));
      quarters.appendChild(l);
    });
  }
  paintWhen();
}

/* The picked roads on the card: one road by its label, several by their number. The answer stays chosen while
   roads are added or taken away; a new first pick starts it over. */
function choose(next) {
  const fresh = !picked.length;
  picked = next;
  highlight();
  if (fresh || !picked.length) setFelt(null);
  status('');
  const form = el('roadSafetyForm');
  if (form) form.hidden = !picked.length;
  if (!picked.length) return;
  const first = picked[0];
  const name = el('roadSafetyPiece');
  if (name) name.textContent = picked.length === 1
    ? t('roadSafetyLabel' + first.label.toUpperCase(), { p: 'Cycle path', l: 'Cycle lane', r: 'Shared road' }[first.label])
    : tpl(t('roadSafetyMany', '{n} roads'), { n: picked.length });
  const sw = el('roadSafetySw');
  if (sw) sw.style.setProperty('--c', picked.every(p => p.label === first.label) ? LABEL_COLOURS[first.label] : 'currentColor');
  const more = el('roadSafetyMore');
  if (more) more.textContent = t('roadSafetyMore', 'Ctrl+click another road to add it and the roads of the same type in between, or a picked road to take it away.');
  if (fresh) fillWhen(first.cc);
  paintWhen();
}

function onMapClick(e) {
  const ids = layers.map(l => l.line).filter(id => map.getLayer(id));
  if (!ids.length || map.getZoom() < PIECE_ZOOM) return;
  const { x, y } = e.point;
  const hit = map.queryRenderedFeatures([[x - HIT_PX, y - HIT_PX], [x + HIT_PX, y + HIT_PX]], { layers: ids });
  const next = hit.map(f => pieceOf(f, f.sourceLayer)).find(Boolean);
  if (!next) return;
  const ev = e.originalEvent || {};
  const add = !!(ev.ctrlKey || ev.metaKey);
  const after = pickPiece(picked, next, add);
  if (add && after === picked) { status(tpl(t('roadSafetyMax', 'One answer covers at most {n} roads.'), { n: MAX_ROADS })); return; }
  const from = last;
  const added = after.some(p => p.way === next.way);
  last = added ? { way: next.way, label: next.label, at: [e.lngLat.lng, e.lngLat.lat] } : last;
  fillReq++;
  choose(after);
  if (add && added && from && from.label === next.label && from.way !== next.way) fillBetween(from, last);
}

/* The roads of one type between two picked roads: the road-piece tiles around both clicks are read (loadPieces()),
   and the shortest run of touching ways of that label joins them (chainBetween()). Nothing is added when the run is
   too long, the clicks are too far apart, or the rider took either road away meanwhile. */
async function fillBetween(a, b) {
  const entries = window.CC_TILES && window.CC_TILES.roadpieces;
  const pts = entries ? tilePoints(a.at, b.at) : null;
  if (!pts) return;
  const req = fillReq;
  status(t('roadSafetyFilling', 'Adding the roads in between…'));
  let pieces = [];
  try { ({ pieces } = await loadPieces(pts, entries)); } catch (err) { pieces = []; }
  if (req !== fillReq || !on) return;
  status('');
  const have = new Set(picked.map(p => p.way));
  const ways = have.has(a.way) && have.has(b.way) ? chainBetween(pieces, a.way, b.way, b.label) : null;
  if (!ways) return;
  const more = [];
  for (const w of ways) {
    if (have.has(w)) continue;
    const p = pieces.find(x => x.id === w);
    if (p) more.push({ way: w, label: p.label, region: p.region, cc: p.cc });
  }
  if (!more.length) return;
  if (picked.length + more.length > MAX_ROADS) { status(tpl(t('roadSafetyMax', 'One answer covers at most {n} roads.'), { n: MAX_ROADS })); return; }
  choose([...picked, ...more]);
}

function onKey(e) {
  if (!on || e.key !== 'Escape' || (e.target && e.target.tagName === 'SELECT')) return;
  e.preventDefault();
  e.stopImmediatePropagation();
  stop();
}

function onMove() {
  if (!on) return;
  mount();
  hint();
}

async function send(e) {
  e.preventDefault();
  const body = markPayload(picked, {
    ...when(), felt,
  });
  if (!body || sending || !cfg) return;
  sending = true;
  el('roadSafetySend').disabled = true;
  status(t('roadSafetySending', 'Sending…'));
  try {
    const res = await fetch(cfg.url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CC-Token': cfg.token },
      body: JSON.stringify(body),
    });
    sending = false;
    if (res.ok) {
      setFelt(null);
      status(t('roadSafetySent', 'Thank you. Your mark waits until enough riders have marked this road for the same time; then curators see how it felt.'));
      return;
    }
    status(429 === res.status ? t('roadSafetyLimited', 'You have marked many roads in the last hour. Wait a while and send again.')
      : t('roadSafetyFailed', 'That did not go through. Try again in a moment.'));
  } catch (err) {
    sending = false;
    status(t('roadSafetyFailed', 'That did not go through. Try again in a moment.'));
  }
  setFelt(felt);
}

function start() {
  if (on || !cfg) return;
  on = true;
  closeRailPanel();
  claimMapClicks();
  map.getContainer().classList.add('cc-rs-on');
  map.on('click', onMapClick);
  document.addEventListener('keydown', onKey, true);
  const card = el('roadSafety');
  if (card) card.hidden = false;
  el('roadSafetyX')?.focus({ preventScroll: true });
  const go = el('roadSafetyGo');
  if (go) go.setAttribute('aria-expanded', 'true');
  choose([]);
  if (familyConfigured('roadpieces', 'roadpieces')) {
    mount();
    show(true);
  }
  hint();
}

function stop() {
  if (!on) return;
  on = false;
  map.off('click', onMapClick);
  document.removeEventListener('keydown', onKey, true);
  map.getContainer().classList.remove('cc-rs-on');
  releaseMapClicks();
  choose([]);
  show(false);
  const card = el('roadSafety');
  if (card) card.hidden = true;
  const go = el('roadSafetyGo');
  if (go) { go.setAttribute('aria-expanded', 'false'); go.focus({ preventScroll: true }); }
}

/* A road's card asks "How safe did it feel?" (owner 2026-10-19, option A): the mode opens on that road, read from
   the road-piece tile under it, as a click on it would pick it. A way the road pieces leave out (a motorway, a
   footway without bikes) cannot be marked, and the card says so. */
export async function markWay(way, lngLat) {
  if (!cfg || !Number.isInteger(way) || !lngLat || !Number.isFinite(lngLat.lng) || !Number.isFinite(lngLat.lat)) return;
  start();
  if (map.getZoom() < PIECE_ZOOM) map.easeTo({ center: [lngLat.lng, lngLat.lat], zoom: PIECE_ZOOM });
  const entries = (window.CC_TILES && window.CC_TILES.roadpieces) || null;
  let found = null;
  if (entries) {
    try {
      const { pieces } = await loadPieces([[lngLat.lng, lngLat.lat]], entries);
      found = pieces.find(p => p.id === way) || null;
    } catch (e) { found = null; }
  }
  if (!on) return;
  if (!found) { status(t('roadSafetyNotPiece', 'This road is not in the road data, so it cannot be marked.')); return; }
  last = { way, label: found.label, at: [lngLat.lng, lngLat.lat] };
  choose([{ way, label: found.label, region: found.region, cc: found.cc }]);
}

export function initRoadSafety() {
  cfg = window.CC_ROAD_SAFETY || null;
  const go = el('roadSafetyGo');
  if (!cfg || !go) return;
  go.addEventListener('click', () => (on ? stop() : start()));
  el('roadSafetyX')?.addEventListener('click', stop);
  document.addEventListener('click', e => {
    const b = e.target && e.target.closest && e.target.closest('[data-rs-way]');
    if (!b) return;
    e.preventDefault();
    markWay(Number(b.dataset.rsWay), { lat: Number(b.dataset.rsLat), lng: Number(b.dataset.rsLng) });
  });
  el('roadSafetyForm')?.addEventListener('submit', send);
  document.querySelectorAll('#roadSafety [data-felt]').forEach(b => b.addEventListener('click', () => setFelt(b.dataset.felt)));
  ['roadSafetyBands', 'roadSafetyDays', 'roadSafetyQuarters'].forEach(id => el(id)?.addEventListener('change', () => { touched = true; paintWhen(); }));
  map.on('moveend', onMove);
  // Back from signing in (the guest's link in the tools panel): the mode opens at once.
  const params = new URLSearchParams(window.location.search);
  if (params.get('road-safety') === '1') {
    params.delete('road-safety');
    const rest = params.toString();
    history.replaceState(history.state, '', window.location.pathname + (rest ? '?' + rest : '') + window.location.hash);
    if (map.isStyleLoaded()) start(); else map.once('load', start);
  }
}
