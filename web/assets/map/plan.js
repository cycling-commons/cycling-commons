// SPDX-License-Identifier: AGPL-3.0-only
/* The ride planner (docs/specs/route-planner.md sections 3 to 6): the plan
   panel, the stops and legs on the map, the figures, the profile, the along
   list, Add to ride on the place drawer, the ride bar, saving and the GPX.
   The model is plan-model.js; this module owns the DOM and the map. The
   browser never talks to the routing engine or the forecast service: every
   leg is one POST /map/plan/leg, what is along the ride is POST /map/plan/along,
   and the day's forecast is POST /map/plan/weather. */
import { map, flyToPin } from './map-init.js';
import { I18N, D, tpl, trVal } from './i18n.js';
import { escPend, txtOn, gradColor, photonLang } from './util.js';
import { uKm, uM, uElev } from './units.js';
import { highlightAt, clearHighlight } from './drawer.js';
import { pinElFor } from './pin-fan.js';
import { liftInfoLayersAboveRoutes } from './render.js';
import { activePanel } from './shell.js';
import { isPicking } from './picking.js';
import { setPlanning } from './plan-state.js';
import { CATALOG } from './catalog.js';
import { layerGlyph, waterKind } from './icons.js';
import { rideScopeFor, scopeKey, scopeCovers } from './ride-scope.js';
import { keepCameraForNextScope } from './scope-ui.js';
import { MAX_STOPS, ALONG_MAX_POINTS, thinLine, CORRIDOR_M, workFresh, rideFromGpx, waterGapSpan, bestWaterStop, stopRows, legGoesRound, whyThisWay, sliceAlong, stopIndexAt, legKeys, insertIndex, figures, profileRuns, pointAt, gpxText, barText, createHistory, tickStepKm, rideTime, surfaceBars, gradientBars, roundedHm, tileSurfaceClass, mergeSurfaces, streetAt, stopStreet, roadAt, steepestKm, longestClimb, waterGap, sunTimes, windShares } from './plan-model.js';

const CC_PLAN = window.CC_PLAN || {};
const P = () => I18N.plan || {};
const ALONG_VISIBLE = 3;
const DRAFT_KEY = 'cc-plan-draft';
const HISTORY_KEY = 'cc-plan-history';
/* Whether the ride line shows (owner 2026-10-15): a per-browser convenience, the stops stay either way. */
const LINE_KEY = 'cc-plan-line';
let lineShown = (() => { try { return localStorage.getItem(LINE_KEY) !== 'off'; } catch(e){ return true; } })();
/* How far from the middle of a stretch without water the nearest water stop is looked for (route-planner.md section 4.4). */
const FILL_WATER_KM = 5;
const BIKES = ['Road', 'Gravel', 'MTB', 'E-bike', 'Handbike', 'Recumbent', 'Trike', 'Tandem'];

let ride = { name: '', bike: 'Road', stops: [], legs: [] };
const history = createHistory(30, 5);
const legCache = new Map();
let gen = 0, routing = false, alongTimer = null;
/* The day (route-planner.md section 4.4): the along rows once fetched (null until then), the forecast for the ride's date, and what was last asked. */
let alongRows = null, forecast = null, forecastKey = '', weatherTimer = null;
const weatherCache = new Map();
let markers = [], scrubMarker = null;
const $ = id => document.getElementById(id);
const fmtKm = m => uKm(m / 1000);
/* "Why this way?" answers, one per leg key (stops and bike), so a second click asks the engine nothing. */
const whyCache = new Map();

/* ---------- the engine, through our server ---------- */
/* `shortest` asks the shortest way the same bike may ride: the comparison of "Why this way?" (route-planner.md section 4.2a). */
async function routeLeg(a, b, bike, shortest){
  try {
    const r = await fetch(CC_PLAN.leg, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CC-Token': CC_PLAN.legToken || '' },
      body: JSON.stringify({ a: [a.lng, a.lat], b: [b.lng, b.lat], bike, ...(shortest ? { shortest: true } : {}) }) });
    if(r.status === 429) return { routed: false, limited: true };
    if(!r.ok) return { routed: false, down: true };
    const d = await r.json();
    if(!d.routed) return { routed: false };
    return { routed: true, line: d.coordinates, distM: d.distanceM, heights: d.heights || null, surfaces: d.surfaces || null, streets: d.streets || null };
  } catch(e){ return { routed: false, down: true }; }
}
async function fetchAlong(line){
  try {
    const r = await fetch(CC_PLAN.along, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CC-Token': CC_PLAN.alongToken || '' },
      body: JSON.stringify({ line: { type: 'LineString', coordinates: line } }) });
    return r.ok ? await r.json() : null;
  } catch(e){ return null; }
}

/* ---------- the ride ---------- */
function setStatus(text, busy){ const s = $('planStatus'); if(!s) return; s.textContent = text || ''; s.classList.toggle('is-busy', !!busy); }
/* A click is a stop, and the scope click stands aside, while the panel is open or a ride is on the map. */
function planning(){ return activePanel() === 'plan' || ride.stops.length > 0; }
/* Add to ride shows on the drawers from the moment the panel was opened in this session (route-planner.md section 4.3). */
let everOpened = false;
function syncPlanning(){
  if(activePanel() === 'plan') everOpened = true;
  setPlanning(planning());
  document.body.classList.toggle('cc-planning', everOpened || planning());
  /* The rail's dot on the Plan icon while a stop is on the map, as Layers wears one for a filter. */
  const b = document.getElementById('ib-plan');
  if(b) b.classList.toggle('riding', ride.stops.length > 0);
}

/* The name comes first (owner 2026-10-15): no first stop without it. */
function askName(){ openPanel(); const n = $('planRideName'); if(n) n.focus(); setStatus(P().nameFirst || 'Give your ride a name first.'); }
/* A stop into the ride at `at`, or at the end; true when it went in. */
function addStop(stop, at){
  if(!ride.stops.length && !(ride.name || '').trim()){ askName(); return false; }
  if(ride.stops.length >= MAX_STOPS){ setStatus(tpl(P().maxStops || 'A ride holds at most {n} checkpoints.', { n: MAX_STOPS })); return false; }
  if(stop.item && ride.stops.some(s => s.item === stop.item)){ setStatus(P().already || 'That place is already in your ride.'); return false; }
  if(at == null || at < 0 || at > ride.stops.length) at = ride.stops.length;
  remember();
  ride.stops.splice(at, 0, stop);
  afterChange();
  return true;
}
function removeStop(i){ remember(); ride.stops.splice(i, 1); afterChange(); }
function moveStop(i, d){ const j = i + d; if(j < 0 || j >= ride.stops.length) return; remember(); const s = ride.stops.splice(i, 1)[0]; ride.stops.splice(j, 0, s); afterChange(); }
let stopsOpen = false;
/* A stop's title: its place's name, or its street for a map point, or "Map point". */
function stopTitle(i){ const s = ride.stops[i]; if(!s) return ''; return s.item ? s.name : (stopStreet(ride.legs, i) || s.name); }
/* An unnamed road says what it is (owner 2026-10-15). */
function roadLabel(kind){ return kind ? ((P().roads || {})[kind] || null) : null; }
/* Opening the planner with a ride on the map takes the map to the ride (owner 2026-10-15), clear of the drawer and the strip. */
function fitRide(){
  // Before any leg is routed (a file just opened), the checkpoints frame the ride.
  const line = rideLine(), pts = line.length >= 2 ? line : ride.stops.map(s => [s.lng, s.lat]);
  if(pts.length === 1){ map.easeTo({ center: pts[0], duration: 600 }); return; }
  if(pts.length < 2) return;
  let w = 180, s = 90, e = -180, n = -90;
  for(const [x, y] of pts){ if(x < w) w = x; if(x > e) e = x; if(y < s) s = y; if(y > n) n = y; }
  const narrow = window.innerWidth < 820;
  map.fitBounds([[w, s], [e, n]], { padding: narrow ? { top: 40, bottom: 260, left: 30, right: 30 } : { top: 60, bottom: 200, left: 380, right: 80 }, maxZoom: 15, duration: 600 });
}
/* ---------- the start: a name, then a town or a street (route-planner.md section 4.1) ---------- */
let startTimer = null, startCtl = null;
function syncStart(){
  const n = $('planRideName'), box = $('planStartBox'); if(!n || !box) return;
  if(document.activeElement !== n) n.value = ride.name || '';
  box.hidden = !(ride.name || '').trim() || ride.stops.length > 0;
}
async function searchStart(qy){
  const ul = $('planStartResults'); if(!ul) return;
  if(startCtl) startCtl.abort();
  if(qy.length < 3){ ul.innerHTML = ''; return; }
  startCtl = new AbortController();
  const c = map.getCenter();
  const url = 'https://photon.komoot.io/api/?limit=6&layer=city&layer=district&layer=locality&layer=street'
    + '&q=' + encodeURIComponent(qy) + '&lat=' + c.lat.toFixed(3) + '&lon=' + c.lng.toFixed(3) + '&lang=' + photonLang(document.documentElement.lang);
  let feats = [];
  try { const r = await fetch(url, { signal: startCtl.signal }); feats = r.ok ? ((await r.json()).features || []) : []; } catch(e){ return; }
  // Towns first, then streets: a ride starts in a place, a street narrows it.
  const rows = feats.map(f => { const p = f.properties || {}, street = p.type === 'street' || p.osm_key === 'highway';
    return { name: p.name || '', where: [street ? (p.city || p.town || p.village || p.district) : null, p.state, p.country].filter(Boolean).join(', '), lat: f.geometry.coordinates[1], lng: f.geometry.coordinates[0], street }; })
    .filter(r => r.name).sort((a, b) => Number(a.street) - Number(b.street));
  ul.innerHTML = rows.length
    ? rows.map((r, i) => `<li><button type="button" class="cc-near" data-start="${i}"><span class="cc-near-nm">${escPend(r.name)}</span><em>${escPend(r.where)}</em></button></li>`).join('')
    : `<li class="cc-near-empty">${escPend(P().startNone || 'Nothing found. Try a nearby town.')}</li>`;
  ul.onclick = e => { const b = e.target.closest('[data-start]'); if(!b) return; const r = rows[Number(b.dataset.start)];
    map.flyTo({ center: [r.lng, r.lat], zoom: r.street ? 16 : 13 }); ul.innerHTML = ''; $('planStartSearch').value = r.name;
    setStatus(P().startClick || 'Click the map where you start.'); };
}
function setBike(b){ ride.bike = b; paintBikes(); afterChange(); }
/* A place from the drawer or the along list: in at its km along, or out again. */
function togglePlace(place){
  const i = ride.stops.findIndex(s => s.item === place.item);
  if(i >= 0){ removeStop(i); return; }
  const at = insertIndex(ride.stops, ride.legs, place);
  if(at < 0) return;
  addStop(place, at);
}
function clearRide(){
  if(ride.stops.length && !window.confirm(P().clearConfirm || 'Clear this ride? Ctrl+Z brings it back.')) return;
  remember(true);
  ride = { name: '', bike: ride.bike, stops: [], legs: [] };
  afterChange();
  restoreRideScope();
}
/* A ride outside the map's scope widens it to the regions the ride crosses, for as long as it is planned
   (route-planner.md section 5, owner 2026-10-16: a GPX of North Holland opened while the map looked at Wallonia
   showed no places), as the ride check does. Never stored as the rider's own scope; the chip says it comes from
   the ride. A scope that already shows the whole ride, its country or Everywhere, stays. */
let scopeBefore = null, scopeSet = null;
function followRideScope(regions){
  const S = window.CCScope;
  if(!S || !Array.isArray(regions) || !regions.length) return;
  const cur = S.get();
  if(scopeCovers(cur, regions)) return;
  const next = rideScopeFor(regions); if(!next) return;
  if(scopeBefore == null) scopeBefore = cur;
  // The ride's own framing stays the last word; the scope change would otherwise move the camera.
  keepCameraForNextScope();
  S.set(next, { persist: false });
  scopeSet = next;
  if(window.CCScopeHeader) window.CCScopeHeader.paint(window.CC_I18N || {});
  const line = document.getElementById('regionLine');
  if(line) line.textContent = line.textContent + ' · ' + (I18N.rcScopeFromRide || 'from your ride');
}
/* The rider's own scope back when the ride is cleared, unless they picked another since. */
function restoreRideScope(){
  const S = window.CCScope, prev = scopeBefore;
  scopeBefore = null;
  if(S && prev && scopeSet && scopeKey(S.get()) === scopeKey(scopeSet)) S.set(prev, { persist: false });
  scopeSet = null;
}
/* The stops as they are now, kept for the undo key (route-planner.md section 4.1). */
/* Every change to the stops is kept for undo, as a full copy of the stops before it; the legs are routed
   again from them. The change also takes the keyboard off a text field: a drag or a right-click on the map
   leaves the focus where it was, and Ctrl+Z in the ride's name would undo the name, not the ride. */
function remember(whole){
  const a = document.activeElement; if(a && a.blur && a.matches && a.matches('input, textarea, select, [contenteditable]')) a.blur();
  history.push(step(whole));
}
/* A step for undo: the stops, or the whole ride (its name and bike too) for a change that replaces it, opening a
   file or clearing; a stop change keeps the stops alone, so undo never takes back a bike the rider picked. */
function step(whole){ return whole ? { stops: ride.stops, name: ride.name, bike: ride.bike, whole: true } : { stops: ride.stops }; }
function applyStep(st){
  if(Array.isArray(st)) st = { stops: st };
  ride.stops = Array.isArray(st.stops) ? st.stops : [];
  if(st.whole){
    ride.name = st.name || '';
    if(BIKES.includes(st.bike)){ ride.bike = st.bike; if(!document.querySelector(`#planBikes .chip[data-bike="${ride.bike}"]`)) showAllBikes(); paintBikes(); }
  }
  afterChange();
}
/* Ctrl+Z, or the command key with Z on a Mac: the ride before the last change. */
function undo(){ const prev = history.undo(step(true)); if(prev) applyStep(prev); }
function redo(){ const next = history.redo(step(true)); if(next) applyStep(next); }
function afterChange(){ pendingFill = null; if(altPinned && !legKeys(ride.stops, ride.bike).includes(altPinned)){ altPinned = null; paintAlt(null); } alongRows = null; syncStart(); renderStops(); drawRide(); routeAll(); saveDraft(); syncPlanning(); paintDrawerButtons(); }

async function routeAll(){
  const myGen = ++gen;
  const keys = legKeys(ride.stops, ride.bike);
  const needed = [];
  ride.legs = keys.map((key, i) => { const c = legCache.get(key); if(!c) needed.push([i, key]); return c || null; });
  if(!needed.length){ drawRide(); renderStops(); finishRouting(); return; }
  routing = true;
  setStatus(tpl(P().routing || 'Routing the legs for a {bike} bike…', { bike: ride.bike }), true);
  for(const [i, key] of needed){
    const leg = await routeLeg(ride.stops[i], ride.stops[i + 1], ride.bike, ride.stops[i + 1].way === 'shortest');
    if(myGen !== gen) return;
    leg.key = key;
    if(!leg.limited && !leg.down) legCache.set(key, leg);
    ride.legs[i] = leg;
    drawRide(); renderStops();
    if(leg.limited || leg.down) break;
  }
  if(myGen !== gen) return;
  routing = false;
  finishRouting();
}
function finishRouting(){
  const legs = ride.legs.filter(Boolean);
  if(legs.some(l => l.limited)) setStatus(P().rateLimited || 'Too many legs in a short time. Wait a minute, then try again.');
  else if(legs.some(l => l.down)) setStatus(P().engineDown || 'The routing engine did not answer. Try again in a moment.');
  else if(legs.some(l => !l.routed)) setStatus(P().noRoad || 'No road found between these checkpoints');
  else setStatus(ride.stops.length < 2 ? '' : (P().routed || 'Routed.'));
  renderFigures(); renderProfile(); scheduleAlong(); scheduleWeather(); scheduleTileRead(); renderBar(); saveDraft();
}

/* ---------- panel: bikes ---------- */
function bikeLabel(b){ const el = document.querySelector(`#planBikes .chip[data-bike="${b}"]`); return el ? el.textContent : b; }
function paintBikes(){
  document.querySelectorAll('#planBikes .chip').forEach(c => { const on = c.dataset.bike === ride.bike; c.classList.toggle('on', on); c.setAttribute('aria-pressed', on ? 'true' : 'false'); });
  const why = $('planWhyText'); if(why) why.textContent = (P().why || {})[ride.bike] || '';
}
/* Every bike type, or only the rider's own (owner 2026-10-15): the switch stays, both ways. */
let allBikes = false;
function showAllBikes(){
  const box = $('planBikes'); if(!box) return;
  const have = new Set([...box.querySelectorAll('.chip')].map(c => c.dataset.bike));
  BIKES.filter(b => !have.has(b)).forEach(b => { const c = document.createElement('button'); c.type = 'button'; c.className = 'chip'; c.dataset.bike = b; c.dataset.extra = '1'; c.textContent = b; box.appendChild(c); });
  allBikes = true; paintBikeSwitch(); paintBikes();
}
function showFewerBikes(){
  const box = $('planBikes'); if(!box) return;
  // The bike the ride is planned for stays, even when it is not one of the rider's own.
  box.querySelectorAll('.chip[data-extra]').forEach(c => { if(c.dataset.bike !== ride.bike) c.remove(); });
  allBikes = false; paintBikeSwitch(); paintBikes();
}
function toggleAllBikes(){ if(allBikes) showFewerBikes(); else showAllBikes(); }
function paintBikeSwitch(){
  const all = $('planBikesAll'); if(!all) return;
  all.textContent = allBikes ? (P().bikeFewer || 'Show fewer bike types') : (P().bikeAll || 'Show every bike type');
}

/* ---------- panel: stops ---------- */
function metaFor(letter){ const m = CATALOG.find(x => x.letter === letter); return m ? { color: m.color, label: m.label, glyph: layerGlyph(m) } : null; }
function renderStops(){
  const ul = $('planStops'); if(!ul) return;
  const n = ride.stops.length;
  const instr = $('planInstr'); if(instr) instr.hidden = n >= 2;
  // Folded by default once the ride has a start and an end (owner 2026-10-15): one line says what it holds.
  const tgl = $('planStopsToggle'), sum = $('planStopsSum'), folded = n >= 2 && !stopsOpen;
  if(tgl){ tgl.hidden = n < 2; tgl.setAttribute('aria-expanded', String(!folded)); tgl.textContent = folded ? (P().stopsShow || 'show') : (P().stopsHide || 'hide'); }
  if(sum){ sum.hidden = !folded; if(folded) sum.textContent = tpl(P().stopsSum || '{n} checkpoints, {from} to {to}', { n, from: stopTitle(0), to: stopTitle(n - 1) }); }
  ul.hidden = folded;
  // With the stops folded, the legs that go round stay findable (owner 2026-10-15): one line opens the list and the first answer.
  const round = ride.legs.map((leg, i) => i < n - 1 && legGoesRound(leg, ride.stops[i], ride.stops[i + 1]) ? i : -1).filter(i => i >= 0);
  const rs = $('planRoundSum');
  if(rs){ rs.hidden = !folded || !round.length; if(round.length){ rs.textContent = tpl(round.length === 1 ? (P().wayRoundOne || '1 leg goes round: see why') : (P().wayRoundMany || '{n} legs go round: see why'), { n: round.length }); rs.dataset.first = String(round[0]); } }
  if(!n){ ul.innerHTML = `<li class="cc-near-empty">${escPend(P().noStops || 'No checkpoints yet.')}</li>`; return; }
  let html = '';
  const keys = legKeys(ride.stops, ride.bike);
  ride.stops.forEach((s, i) => {
    const cls = i === 0 ? 'start' : (i === n - 1 && n > 1 ? 'end' : '');
    const lbl = i === 0 ? 'S' : (i === n - 1 && n > 1 ? FINISH_FLAG : String(i));
    const meta = s.letter ? metaFor(s.letter) : null;
    const kind = meta ? `<span class="k" style="background:${meta.color}" title="${escPend(meta.label)}"></span>` : '';
    // A map point says its street (owner 2026-10-15), from the engine's names along its leg; a place keeps its kind.
    const street = s.item ? null : stopStreet(ride.legs, i);
    const sub = meta ? meta.label : (street || (s.lat.toFixed(4) + ', ' + s.lng.toFixed(4)));
    // The name takes the map to the stop, a map point as much as a place (owner 2026-10-14).
    html += `<li class="cc-plan-stop ${cls}" data-i="${i}"><span class="n">${lbl}</span>${kind}<span class="nm"><b class="lnk" data-fly="1">${escPend(s.name)}</b><small>${escPend(sub)}</small></span>`
      + `<span class="act"><button type="button" data-up title="${escPend(P().up || 'Move up')}" ${i === 0 ? 'disabled' : ''}>↑</button><button type="button" data-down title="${escPend(P().down || 'Move down')}" ${i === n - 1 ? 'disabled' : ''}>↓</button><button type="button" data-rm title="${escPend(P().remove || 'Remove')}">✕</button></span></li>`;
    if(i < n - 1){
      const leg = ride.legs[i];
      if(leg && !leg.routed && !leg.limited && !leg.down) html += `<li class="cc-plan-leg bad">${escPend(P().noRoad || 'No road found between these checkpoints')}</li>`;
      else if(leg && leg.routed){
        // A leg that clearly goes round offers "Why this way?" (owner 2026-10-15); its answer sits under it, with the
        // shorter way to see on the map and to ride. A leg ridden the shortest way says so and offers the way back.
        const way = ride.stops[i + 1].way === 'shortest';
        const why = way ? null : whyCache.get(keys[i]);
        const ask = !way && !why && legGoesRound(leg, ride.stops[i], ride.stops[i + 1]) ? ` <button type="button" class="dl-all" data-why="${i}">${escPend(P().wayAsk || 'Why this way?')}</button>` : '';
        const back = way ? ` · ${escPend(P().wayTaken || 'shortest way')} <button type="button" class="dl-all" data-way-back="${i}">${escPend(P().wayBack || "Back to your bike's way")}</button>` : '';
        html += leg.heights
          ? `<li class="cc-plan-leg">${escPend(fmtKm(leg.distM))}${back}${ask}</li>`
          : `<li class="cc-plan-leg bad">${escPend(fmtKm(leg.distM))} · ${escPend(P().noHeights || 'no heights here')}${back}${ask}</li>`;
        if(why){
          const acts = why.alt ? ` <button type="button" class="dl-all" data-way-show="${i}">${escPend(altPinned === keys[i] ? (P().wayHide || 'Hide from map') : (P().wayShow || 'Show on map'))}</button> <button type="button" class="dl-all" data-way-take="${i}">${escPend(P().wayTake || 'Ride the shortest way')}</button>` : '';
          html += `<li class="cc-plan-leg why" data-way-leg="${i}">${escPend(why.text)}${acts}</li>`;
        }
      }
    }
  });
  ul.innerHTML = html;
}

/* Why this way? (route-planner.md section 4.2a): the shortest way the same bike may ride, asked once per leg on a click,
   and one line on why the leg goes round. A failed ask is not kept, so the question comes back. */
async function explainLeg(i){
  const a = ride.stops[i], b = ride.stops[i + 1], cur = ride.legs[i]; if(!a || !b || !cur || !cur.routed) return;
  const key = legKeys(ride.stops, ride.bike)[i];
  if(whyCache.has(key)) return;
  whyCache.set(key, { text: P().wayAsking || 'Comparing with the shortest way…', alt: null }); renderStops();
  const alt = await routeLeg(a, b, ride.bike, true);
  const w = whyThisWay(cur, alt);
  if(!w){ whyCache.delete(key); renderStops(); setStatus(P().wayFailed || 'Could not compare this leg now. Try again in a moment.'); return; }
  whyCache.set(key, { text: wayText(w), alt: w.kind === 'shortest' ? null : alt }); renderStops();
}
/* The shorter way on the map: drawn while its answer is hovered, kept while pinned by Show on map (owner 2026-10-15). */
let altPinned = null;
function paintAlt(key){
  const src = map.getSource('plan-alt'); if(!src) return;
  const w = key ? whyCache.get(key) : null, line = w && w.alt && w.alt.line;
  src.setData({ type: 'FeatureCollection', features: line && line.length > 1 ? [{ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: line } }] : [] });
}
function toggleAltPin(i){
  const key = legKeys(ride.stops, ride.bike)[i];
  altPinned = altPinned === key ? null : key;
  paintAlt(altPinned);
  const w = altPinned ? whyCache.get(altPinned) : null, leg = ride.legs[i];
  if(w && w.alt && w.alt.line && leg && leg.line){
    const pts = w.alt.line.concat(leg.line), b = new maplibregl.LngLatBounds(pts[0], pts[0]);
    pts.forEach(p => b.extend(p)); map.fitBounds(b, { padding: 80, duration: 600 });
  }
  renderStops();
}
/* Ride the shortest way on this leg (owner 2026-10-15): undoable, kept in the ride file, the line already fetched reused. */
function takeShortest(i){
  const w = whyCache.get(legKeys(ride.stops, ride.bike)[i]); if(!ride.stops[i + 1]) return;
  remember();
  ride.stops[i + 1] = { ...ride.stops[i + 1], way: 'shortest' };
  const key = legKeys(ride.stops, ride.bike)[i];
  if(w && w.alt && w.alt.routed) legCache.set(key, { ...w.alt, key });
  altPinned = null; paintAlt(null);
  afterChange();
}
function backToBikeWay(i){
  if(!ride.stops[i + 1]) return;
  remember();
  const s = { ...ride.stops[i + 1] }; delete s.way;
  ride.stops[i + 1] = s;
  afterChange();
}
function wayText(w){
  const d = { cur: fmtKm(w.curM), alt: fmtKm(w.altM) };
  if(w.kind === 'shortest') return P().wayShortest || 'This is about the shortest way your bike may ride here.';
  if(w.kind === 'surface') return tpl(P().waySurface || 'The shortest way is {alt}, with {unpaved} of gravel or dirt. Your bike avoids that, so this way is {cur}.', { ...d, unpaved: fmtKm(w.unpavedM) });
  if(w.kind === 'climb') return tpl(P().wayClimb || 'The shortest way is {alt}, but climbs {m} more. This way is {cur}.', { ...d, m: uElev(w.upM) });
  if(w.kind === 'busy') return tpl(P().wayBusy || 'The shortest way is {alt}, with {busy} on busy roads without a cycle lane. This way is {cur}.', { ...d, busy: fmtKm(w.busyM) });
  if(w.kind === 'cycle') return tpl(P().wayCycle || 'The shortest way is {alt}, only {alt_cycle} of it on cycle paths. This way is {cur}, {cur_cycle} of it on cycle paths, away from cars.', { ...d, alt_cycle: fmtKm(w.altCycleM), cur_cycle: fmtKm(w.curCycleM) });
  if(w.kind === 'signals') return tpl(P().waySignals || 'The shortest way is {alt}, past {alt_n} traffic lights. This way is {cur}, past {cur_n}.', { ...d, alt_n: w.altSignals, cur_n: w.curSignals });
  // Nothing measured stands out: say what the planner weighs, never a reason it did not find.
  return tpl(P().wayOther || 'The shortest way is {alt}; this way is {cur}. The planner weighs the kind of road, not only the distance, and no single reason stands out here. Show both on the map to compare.', d);
}

/* ---------- panel: figures, profile ---------- */
function unrouted(){ return ride.legs.some(l => !l || !l.routed); }
function renderFigures(){
  const has = ride.stops.length >= 2;
  const figs = $('planFigsGrp'), acts = $('planActsGrp');
  if(figs) figs.hidden = !has;
  if(acts) acts.hidden = !has;
  if(!has) return;
  const f = figures(ride.legs);
  $('planDist').textContent = fmtKm(f.distM);
  $('planUp').textContent = uElev(f.upM);
  $('planDown').textContent = uElev(f.downM);
  // The day (route-planner.md section 4.4): the reference rider's time, the flat equivalent, the bar, what makes it tougher.
  const day = rideTime(dayLegs(), ride.bike);
  const tEl = $('planTime'), tSub = $('planTimeSub');
  if(tEl) tEl.textContent = day ? hmText(day.seconds) : '';
  if(tSub) tSub.textContent = day ? tpl(P().feelsLike || 'feels like {d} flat', { d: uKm(day.flatKm, 0) }) : '';
  renderDayGraphic(day);
  renderTougher(day, f);
  const wait = unrouted() || routing;
  const gpx = $('planGpx');
  if(gpx) gpx.disabled = wait;
  const hint = $('planSaveHint'); if(hint && (unrouted() && !routing)) hint.textContent = P().saveWait || 'Save and GPX wait until every leg has a road.';
}
/* Seconds as "4 h 45", to five minutes; under an hour as minutes. */
function hmText(seconds){ const { h, m } = roundedHm(seconds); return h ? tpl(P().hm || '{h} h {m}', { h, m: String(m).padStart(2, '0') }) : tpl(P().min || '{m} min', { m }); }
/* A part of the day, to the minute. */
function partText(seconds){ if(seconds > 0 && seconds < 30) return P().underMin || 'under 1 min'; const h = Math.floor(seconds / 3600), m = Math.round((seconds % 3600) / 60); return h ? tpl(P().hm || '{h} h {m}', { h, m: String(m).padStart(2, '0') }) : tpl(P().min || '{m} min', { m }); }
/* The day graphic (route-planner.md section 4.4): two bars of the same time, by surface and by gradient,
   each with a legend of its parts and their minutes. */
/* The finish: a chequered flag, not an E (owner 2026-10-15). */
const FINISH_FLAG = '<svg viewBox="0 0 16 16" width="13" height="13" aria-hidden="true"><path d="M3 1.5v13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><path d="M3.8 2h10v7h-10z" fill="#fff"/><path d="M3.8 2h2.5v2.33H3.8zM8.8 2h2.5v2.33H8.8zM6.3 4.33h2.5v2.33H6.3zM11.3 4.33h2.5v2.33h-2.5zM3.8 6.66h2.5V9H3.8zM8.8 6.66h2.5V9H8.8z" fill="#101E16"/></svg>';
/* ---------- the map's surface lines along the ride (route-planner.md section 4.4a) ----------
   The engine names a surface for every road; where the map's surface lines are drawn, they say
   which roads have none recorded. Each sample point a leg has been seen at keeps its class, so the
   knowledge grows as the rider looks along the ride. */
const SAMPLE_M = 50;
const tileSeen = new Map();   // leg key -> Map(sample index -> planner class)
function legSamples(leg){
  const out = []; let next = 0, run = 0;
  for(let i = 1; i < leg.line.length; i++){
    const [x1, y1] = leg.line[i - 1], [x2, y2] = leg.line[i];
    const len = Math.hypot((x2 - x1) * 111320 * Math.cos(y1 * Math.PI / 180), (y2 - y1) * 110540);
    while(next <= run + len){ const f = len > 0 ? (next - run) / len : 0; out.push([x1 + (x2 - x1) * f, y1 + (y2 - y1) * f]); next += SAMPLE_M; }
    run += len;
  }
  return out;
}
function readTileSurfaces(){
  const surfLayers = (map.getStyle()?.layers || []).map(l => l.id).filter(id => (id.startsWith('surftile-') || id.startsWith('surftodo-')) && map.getLayoutProperty(id, 'visibility') !== 'none');
  if(!surfLayers.length) return false;
  const w = map.getCanvas().clientWidth, h = map.getCanvas().clientHeight;
  let changed = false;
  for(const leg of ride.legs){
    if(!leg || !leg.routed || !Array.isArray(leg.line)) continue;
    let seen = tileSeen.get(leg.key); if(!seen){ seen = new Map(); tileSeen.set(leg.key, seen); }
    legSamples(leg).forEach((p, i) => {
      const pt = map.project(p); if(pt.x < 0 || pt.y < 0 || pt.x > w || pt.y > h) return;
      const f = map.queryRenderedFeatures([[pt.x - 4, pt.y - 4], [pt.x + 4, pt.y + 4]], { layers: surfLayers })[0];
      if(!f) return;
      const cls = f.layer.id.startsWith('surftodo-') ? 'unknown' : tileSurfaceClass(f.properties && f.properties.cls);
      if(cls && seen.get(i) !== cls){ seen.set(i, cls); changed = true; }
    });
  }
  return changed;
}
function tileMetres(leg){
  const seen = tileSeen.get(leg.key), out = {}; if(!seen) return out;
  for(const cls of seen.values()) out[cls] = (out[cls] || 0) + SAMPLE_M;
  return out;
}
/* The legs the day's figures read: each with its surfaces corrected by the map's lines where seen. */
function dayLegs(){ return ride.legs.map(leg => leg && leg.routed ? { ...leg, surfaces: mergeSurfaces(leg.surfaces, tileMetres(leg), leg.distM) } : leg); }
let tileTimer = null;
function scheduleTileRead(){ clearTimeout(tileTimer); tileTimer = setTimeout(() => { if(ride.stops.length >= 2 && readTileSurfaces()){ renderFigures(); renderBar(); } }, 400); }
const PART_COLOUR = { paved: '#577A71', gravel: '#C8923A', rough: '#6E5849', unknown: '#C2402F', climb: '#7B4F9D', flat: '#C9BFA8', descend: '#9FB8C9' };
function partLabel(cls){ const d = { paved: P().partPaved || 'on asphalt', gravel: P().partGravel || 'on gravel', rough: P().partRough || 'on rough road', unknown: P().partUnknown || 'surface not recorded', climb: P().partClimb || 'climbing', flat: P().partFlat || 'on the flat', descend: P().partDescend || 'descending' }; return d[cls] || cls; }
let dayUnit = 'time';
function renderDayGraphic(day){
  const box = $('planDayFx'), host = $('planDaySvg'); if(!box || !host) return;
  const byDist = dayUnit === 'dist';
  const src = day ? (byDist ? day.metres : day.parts) : null;
  const rows = src ? [surfaceBars(src), gradientBars(src)].filter(r => r.length) : [];
  if(!rows.length){ box.hidden = true; return; }
  box.hidden = false;
  const value = b => byDist ? fmtKm(b.seconds) : partText(b.seconds);   // barsOf calls the amount `seconds` whatever its unit
  host.innerHTML = rows.map(bars => {
    let x = 0;
    const rects = bars.map(b => { const w = 100 * b.share, r = `<rect x="${x.toFixed(2)}" y="0" width="${Math.max(w, 0.2).toFixed(2)}" height="10" fill="${PART_COLOUR[b.cls]}"><title>${escPend(partLabel(b.cls))} · ${escPend(value(b))}</title></rect>`; x += w; return r; }).join('');
    const legend = bars.map(b => `<li><i style="background:${PART_COLOUR[b.cls]}"></i>${escPend(partLabel(b.cls))} · ${escPend(value(b))}</li>`).join('');
    return `<svg viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">${rects}</svg><ul class="cc-plan-daylegend">${legend}</ul>`;
  }).join('');
}
/* The day of the ride: the date field, today by default, kept with the draft only. */
function todayIso(){ const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
function rideDate(){ const i = $('planDate'); const v = i ? i.value : ''; return /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : todayIso(); }
function clockText(d){ return d.toLocaleTimeString(document.documentElement.lang || 'en', { hour: '2-digit', minute: '2-digit', hour12: false }); }
const DIRS = ['n', 'ne', 'e', 'se', 's', 'sw', 'w', 'nw'];
function dirWord(deg){ const k = DIRS[Math.round(((deg % 360) + 360) % 360 / 45) % 8]; return (P().dirs || {})[k] || k.toUpperCase(); }
/* What makes it tougher (route-planner.md section 4.4): one line each, only when it applies. */
function renderTougher(day, f){
  const grp = $('planTougherGrp'), ul = $('planTougher'); if(!grp || !ul) return;
  const lines = [];
  const steep = steepestKm(ride.legs);
  if(steep) lines.push(tpl(P().steepest || 'Steepest km: {g} % at km {at}', { g: steep.grad.toFixed(0), at: steep.atKm.toFixed(0) }));
  const climb = longestClimb(ride.legs);
  if(climb) lines.push(tpl(P().longestClimb || 'Longest climb: {d}, {m} up, {g} %', { d: uKm(climb.km, 1), m: uElev(climb.upM), g: climb.grad.toFixed(1) }));
  let waterAt = -1;
  if(alongRows){ const gap = waterGap(alongRows.concat(stopRows(ride.stops, ride.legs)), f.distM); if(gap != null && gap >= 15){ waterAt = lines.length; lines.push(tpl(P().waterGap || '{d} without water or food along the way', { d: uKm(gap, 0) })); } }
  const busyM = ride.legs.reduce((t, l) => t + (l && l.routed && l.surfaces ? (l.surfaces.busy || 0) : 0), 0);
  if(busyM >= 1000) lines.push(tpl(P().busy || '{d} on busy roads without a cycle lane', { d: fmtKm(busyM) }));
  const start = ride.stops[0];
  const sun = start ? sunTimes(start.lat, start.lng, rideDate()) : null;
  if(sun){
    const h = Math.floor(sun.daylightS / 3600), m = Math.round((sun.daylightS % 3600) / 60);
    lines.push(tpl(P().daylight || 'Daylight {h} h {m}, {from} to {to}', { h, m: String(m).padStart(2, '0'), from: clockText(sun.sunrise), to: clockText(sun.sunset) }));
    if(day && day.seconds > sun.daylightS) lines.push(P().daylightShort || 'The riding time is longer than the daylight.');
  }
  let credit = '';
  if(forecast && forecast.date === rideDate()){
    if(forecast.windKmh >= 15){ const w = windShares(ride.legs, forecast.windFromDeg); lines.push(tpl(P().wind || 'Wind {s} km/h from the {dir}: {head} into it, {tail} with it', { s: Math.round(forecast.windKmh), dir: dirWord(forecast.windFromDeg), head: fmtKm(w.headM), tail: fmtKm(w.tailM) })); }
    if(forecast.tempMaxC >= 28) lines.push(tpl(P().heat || 'Up to {t} °C', { t: Math.round(forecast.tempMaxC) }));
    if(forecast.rainMm >= 2) lines.push(tpl(P().rain || '{r} mm of rain', { r: Math.round(forecast.rainMm) }));
    credit = `<li class="cc-plan-credit">${escPend(P().forecastBy || 'Forecast: Open-Meteo')}</li>`;
  }
  // The water-gap line offers the nearest water stop in one click (owner 2026-10-15).
  // The water-gap line (owner 2026-10-15): hovered or focused it shows its stretch on the map; its icon adds the nearest place for water or food.
  const label = P().fillWater || 'Add the nearest place for water or food';
  ul.innerHTML = lines.map((l, i) => i === waterAt
    ? `<li class="cc-plan-gap" tabindex="0">${escPend(l)} <button type="button" class="cc-info cc-plan-fillwater" data-plan-fill-water title="${escPend(label)}" aria-label="${escPend(label)}"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5c3 3.6 5.5 6.6 5.5 9.5a5.5 5.5 0 0 1-11 0c0-2.9 2.5-5.9 5.5-9.5z"/><path d="M12 10.5v5M9.5 13h5"/></svg></button></li>`
    : `<li>${escPend(l)}</li>`).join('') + credit;
  grp.hidden = !lines.length;
}
/* The water stop nearest the middle of the longest stretch without water or food (route-planner.md section 4.4), from our
   own data through /map/coverage/nearby, in at its km along as Add to ride puts a place. */
/* The longest stretch without water or food on the map while its line in the panel is hovered or focused (owner 2026-10-15). */
function showGap(on){
  const src = map.getSource('plan-gap'); if(!src) return;
  const span = on && alongRows ? waterGapSpan(alongRows.concat(stopRows(ride.stops, ride.legs)), figures(ride.legs).distM) : null;
  const coords = span ? sliceAlong(ride.legs, span.fromKm * 1000, span.toKm * 1000) : [];
  src.setData({ type: 'FeatureCollection', features: coords.length > 1 ? [{ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: coords } }] : [] });
}
/* The water stop just added, checked once the new route and its along list are in (owner 2026-10-15): reaching a place off
   the line can change the route itself, so the dry stretch may not shrink. Shorter: say so. Not shorter: take it out. */
let pendingFill = null;
/* Something the planner could not do, said in the middle of the screen (#planNotice, owner 2026-10-16); without
   the dialog, the status line. */
let noticePlace = null;
function notice(title, text, place){
  const dlg = $('planNotice');
  if(!dlg || typeof dlg.showModal !== 'function'){ setStatus(text); return; }
  $('planNoticeH').textContent = title;
  $('planNoticeText').textContent = text;
  // A place the notice names can be shown on the map ({lat, lng}).
  noticePlace = place || null;
  $('planNoticeShow').hidden = !place;
  if(!dlg.open) dlg.showModal();
}
/* What a water stop is and where it lies, for the notice (owner 2026-10-16: "Who is Koning?"): its name, its kind in
   the rider's words, how far it lies off the ride and level with which distance along it. */
function fillWhere(s){
  return { name: s.name || P().fillWaterName || 'Water', kind: (P().waterKinds || {})[waterKind({ food: s.food, potable: s.potable })] || P().fillWaterName || 'Water', off: uKm(s.distM / 1000, 1), at: uKm(s.alongM / 1000, 0) };
}
/* Whether the water stop the fill added shortened the dry stretch, once its legs are routed and the along rows read.
   Only the fill's own stop, while it is still the newest change, is ever taken back (owner 2026-10-16: a check left
   behind by a stop that never went in undid the rider's own last change), and without a redo step. */
function checkFill(){
  const p = pendingFill; pendingFill = null;
  if(!p || history.size() !== p.steps || !ride.stops.some(s => s.item === p.item)) return;
  const f = figures(ride.legs), gap = waterGap(alongRows.concat(stopRows(ride.stops, ride.legs)), f.distM);
  if(gap != null && gap < p.beforeKm - 0.5){ setStatus(tpl(P().fillWaterDone || 'Added {name}. The longest stretch without water or food is now {d}.', { name: p.name, d: uKm(gap, 0) })); return; }
  const prev = history.discard(); if(prev) applyStep(prev);
  notice(P().fillWaterH || 'No water stop added', tpl(P().fillWaterUndone || '{name}, {kind}, is {off} from your ride, level with {at} along it. The way there changes the route, so the dry stretch would not get shorter. It is not added.', fillWhere(p.stop)), p.stop);
}
async function fillWater(){
  if(!alongRows || !CC_PLAN.nearby) return;
  const f = figures(ride.legs), span = waterGapSpan(alongRows.concat(stopRows(ride.stops, ride.legs)), f.distM); if(!span) return;
  const fromM = span.fromKm * 1000, toM = span.toKm * 1000;
  const mid = pointAt(ride.legs, (fromM + toM) / 2); if(!mid) return;
  // The stretch every 200 m: the place that leaves the shortest dry part wins, and goes into the ride where its nearest
  // point of the stretch is (owner 2026-10-15).
  const points = [];
  for(let d = fromM; d <= toM; d += 200){ const p = pointAt(ride.legs, d); if(p) points.push({ lat: p[1], lng: p[0], alongM: d }); }
  const none = () => notice(P().fillWaterH || 'No water stop added', tpl(P().fillWaterNone || 'No water or food within {d} of the middle of this stretch.', { d: uKm(FILL_WATER_KM, 0) }));
  try {
    const r = await fetch(`${CC_PLAN.nearby}?lat=${mid[1]}&lng=${mid[0]}&km=${FILL_WATER_KM}`, { headers: { 'Accept': 'application/json' } });
    if(!r.ok){ none(); return; }
    const d = await r.json();
    const stop = bestWaterStop(d.groups, points, fromM, toM);
    if(!stop){ none(); return; }
    // A place that would not make the dry part shorter is not added: its detour costs more than it gives (owner 2026-10-15).
    if(stop.leftM >= toM - fromM){ notice(P().fillWaterH || 'No water stop added', P().fillWaterUseless || 'No water or food near this stretch would make it shorter.'); return; }
    const place = { item: stop.item, letter: 'B', name: stop.name || P().fillWaterName || 'Water', lat: stop.lat, lng: stop.lng };
    if(ride.stops.some(s => s.item === place.item)){ notice(P().fillWaterH || 'No water stop added', tpl(P().fillWaterAlready || '{name}, {kind}, is already in your ride, {off} from it at {at}.', fillWhere(stop)), stop); return; }
    const at = stopIndexAt(ride.legs, stop.alongM); if(at == null){ none(); return; }
    if(ride.stops.length >= MAX_STOPS){ notice(P().fillWaterH || 'No water stop added', tpl(P().fillWaterFull || 'Your ride has {n} checkpoints, the most it holds. Remove one, then try again.', { n: MAX_STOPS })); return; }
    if(!addStop(place, at)) return;
    pendingFill = { beforeKm: span.km, name: place.name, item: place.item, steps: history.size(), stop };
  } catch(e){ none(); }
}
/* The forecast for the ride's date at the ride's middle, through our server, once the legs settle; one ask per 0.1° cell and date. */
function scheduleWeather(){ clearTimeout(weatherTimer); weatherTimer = setTimeout(fetchWeather, 1200); }
async function fetchWeather(){
  if(!CC_PLAN.weather || ride.stops.length < 2 || unrouted()) return;
  const f = figures(ride.legs); const mid = pointAt(ride.legs, f.distM / 2); if(!mid) return;
  const date = rideDate(), key = `${mid[1].toFixed(1)},${mid[0].toFixed(1)},${date}`;
  if(forecastKey === key) return;
  let got = weatherCache.get(key);
  if(got === undefined){
    try {
      const r = await fetch(CC_PLAN.weather, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CC-Token': CC_PLAN.weatherToken }, body: JSON.stringify({ lat: mid[1], lng: mid[0], date }) });
      got = r.ok ? ((await r.json()).forecast || null) : null;
    } catch(e){ got = null; }
    weatherCache.set(key, got);
  }
  forecast = got; forecastKey = key;
  renderFigures();
}

/* The profile in the panel and, the map's width wide, as a strip along the
   foot of the map (route-planner.md section 4.5); one drawing, two sizes. */
let stripClosed = false;
/* The height profile is the strip along the foot of the map (route-planner.md section 4.5); a closed
   strip comes back from the panel's Show the height profile. */
/* The strip slides rather than snaps (owner 2026-10-15): open it, then let it rise; fold it, then hide it once down. */
let stripShown = false, stripFoldTimer = null;
function showStrip(on){
  const strip = $('planStrip'), wrap = document.querySelector('.map-wrap'); if(!strip) return;
  if(on === stripShown) return;
  stripShown = on; clearTimeout(stripFoldTimer);
  wrap && wrap.classList.toggle('cc-strip-on', on);
  if(on){ strip.hidden = false; strip.classList.add('folded'); void strip.offsetHeight; strip.classList.remove('folded'); }
  else { strip.classList.add('folded'); stripFoldTimer = setTimeout(() => { if(!stripShown) strip.hidden = true; }, 320); }
}
function renderProfile(){
  const strip = $('planStrip'), reopen = $('planStripOpen'); if(!strip) return;
  const f = figures(ride.legs);
  const runs = ride.stops.length >= 2 ? profileRuns(ride.legs, Math.max(100, Math.ceil(f.distM / 120 / 100) * 100)) : [];
  const bins = runs.flat();
  const wrap = document.querySelector('.map-wrap');
  const tab = $('planStripTab'); if(tab) tab.hidden = !stripClosed;
  if(bins.length < 2){ showStrip(false); if(reopen) reopen.hidden = true; if(tab) tab.hidden = true; return; }
  if(reopen) reopen.hidden = !stripClosed;
  {
    showStrip(!stripClosed);
    if(!stripClosed){
      const w = Math.max(320, strip.clientWidth || 800);
      $('planStripSvg').setAttribute('viewBox', `0 0 ${w} 140`);
      drawProfile(strip, $('planStripSvg'), $('planStripCur'), $('planStripTip'), w, 140, f, bins);
    }
  }
}
function drawProfile(prof, svgEl, cur, tip, W, H, f, bins){
  const L = 26, R = 6, T = 14, B = 16, total = f.distM;
  let minH = Infinity, maxH = -Infinity;
  for(const b of bins){ minH = Math.min(minH, b.h0, b.h1); maxH = Math.max(maxH, b.h0, b.h1); }
  const span = Math.max(maxH - minH, 40);
  const y = h => T + (H - T - B) * (1 - (h - minH) / span), x = d => L + (W - L - R) * d / total, base = H - B;
  let svg = '';
  for(const b of bins){
    svg += `<polygon points="${x(b.d0).toFixed(1)},${base} ${x(b.d0).toFixed(1)},${y(b.h0).toFixed(1)} ${x(b.d1).toFixed(1)},${y(b.h1).toFixed(1)} ${x(b.d1).toFixed(1)},${base}" fill="${gradColor(b.grad)}" fill-opacity=".92" stroke="#fff" stroke-width=".4"><title>${uKm(b.d0 / 1000)} to ${uKm(b.d1 / 1000)} · ${b.grad.toFixed(0)}%</title></polygon>`;
  }
  svg += `<text x="2" y="${(T + 4).toFixed(1)}">${escPend(uElev(maxH))}</text><text x="2" y="${(H - B - 1).toFixed(1)}">${escPend(uElev(minH))}</text>`;
  const tick = tickStepKm(total / 1000) * 1000;
  for(let d = tick; d < total; d += tick) svg += `<line x1="${x(d).toFixed(1)}" x2="${x(d).toFixed(1)}" y1="${H - B}" y2="${H - B + 3}" stroke="rgb(var(--chrome-fg))" stroke-opacity=".5"/><text x="${x(d).toFixed(1)}" y="${H - 3}" text-anchor="middle">${d / 1000}</text>`;
  svg += `<text x="${W - R}" y="${H - 3}" text-anchor="end">${escPend(uKm(1).replace(/[\d.,\s]+/, ''))}</text>`;
  let off = 0;
  ride.stops.forEach((st, i) => {
    if(i > 0 && ride.legs[i - 1] && ride.legs[i - 1].routed) off += ride.legs[i - 1].distM;
    if(i === 0 || i === ride.stops.length - 1) return;
    svg += `<circle class="stopmark" cx="${x(off).toFixed(1)}" cy="${H - B}" r="2.4"><title>${escPend(st.name)}</title></circle>`;
  });
  svgEl.innerHTML = svg;
  prof.onmousemove = e => {
    const r = prof.getBoundingClientRect();
    const fx = (e.clientX - r.left) / r.width * W;
    if(fx < L || fx > W - R){ prof.onmouseleave(); return; }
    const d = (fx - L) / (W - L - R) * total;
    scrubTo(d);
    cur.style.display = 'block'; cur.style.left = (fx / W * 100) + '%';
    const near = bins.reduce((b, p) => Math.abs(p.d0 - d) < Math.abs(b.d0 - d) ? p : b, bins[0]);
    const st = streetAt(ride.legs, d) || roadLabel(roadAt(ride.legs, d));
    tip.style.display = 'block'; tip.textContent = `${uKm(d / 1000)} · ${uElev(near.h0)}` + (st ? ` · ${st}` : ''); tip.style.left = Math.min(fx / W * 100, 70) + '%';
  };
  prof.onmouseleave = () => { cur.style.display = 'none'; tip.style.display = 'none'; if(scrubMarker){ scrubMarker.remove(); scrubMarker = null; } };
}
function scrubTo(d){
  const p = pointAt(ride.legs, d); if(!p) return;
  if(!scrubMarker){ const el = document.createElement('div'); el.className = 'cc-plan-mk scrub'; scrubMarker = new maplibregl.Marker({ element: el }).setLngLat(p).addTo(map); }
  else scrubMarker.setLngLat(p);
}

/* ---------- panel: along the ride ---------- */
function rideLine(){ const out = []; for(const leg of ride.legs){ if(!leg || !leg.routed) continue; for(const p of leg.line) out.push(p); } return out; }
function scheduleAlong(){ clearTimeout(alongTimer); alongTimer = setTimeout(renderAlong, 1200); }
async function renderAlong(){
  const g = $('planAlongGrp'), ul = $('planAlong'); if(!g || !ul) return;
  const line = rideLine();
  if(line.length < 2 || unrouted()){ g.hidden = true; return; }
  const myGen = gen;
  const d = await fetchAlong(thinLine(line, ALONG_MAX_POINTS));
  if(myGen !== gen || !d) return;
  followRideScope(d.regions);
  const rows = [];
  (d.climbs || []).forEach(c => rows.push({ letter: 'N', ridden: true, item: 'N:' + c.id, name: c.name, ll: c.ll, alongKm: c.alongKm, distM: c.distM || 0 }));
  (d.groups || []).forEach(gr => gr.items.forEach(it => rows.push({ letter: gr.letter, item: gr.letter + ':' + it.id, name: it.name, ll: it.ll, alongKm: it.alongKm, distM: it.distM, truncated: gr.truncated })));
  (d.coverage || []).forEach(gr => gr.items.forEach(it => rows.push({ letter: gr.letter, item: 'osm:' + it.ref, name: it.name, ll: it.ll, alongKm: it.alongKm, distM: it.distM, coverage: true })));
  const order = ['N', 'B', 'C', 'D', 'G', 'F', 'O', 'P', 'Q', 'E'];
  const groups = {};
  rows.forEach(r => (groups[r.letter] = groups[r.letter] || []).push(r));
  let html = '', seq = 0;
  for(const L of order){
    const items = groups[L]; if(!items) continue;
    items.sort((a, b) => a.alongKm - b.alongKm);
    const meta = metaFor(L) || { color: '#6b6f5e', label: L, glyph: '•' };
    const id = 'planAlongRest' + (++seq);
    const toggle = items.length > ALONG_VISIBLE ? `<button type="button" class="cc-near-more" aria-expanded="false" aria-controls="${id}" data-more="${escPend(tpl(D.showMore || 'Show {n} more', { n: items.length - ALONG_VISIBLE }))}" data-fewer="${escPend(D.showFewer || 'Show fewer')}">${escPend(tpl(D.showMore || 'Show {n} more', { n: items.length - ALONG_VISIBLE }))}</button>` : '';
    html += `<li class="cc-near-grp"><span class="cc-near-k" style="background:${meta.color};color:${txtOn(meta.color)}">${meta.glyph || '•'}</span>${escPend(meta.label)} · ${items.length}${items[0].truncated ? ` ${escPend(D.capped || '(capped)')}` : ''}${toggle}</li>`;
    const row = r => {
      const inRide = ride.stops.some(s => s.item === r.item);
      const place = JSON.stringify({ item: r.item, letter: r.letter, name: r.name || meta.label, lat: r.ll[0], lng: r.ll[1] });
      return `<li class="cc-plan-row"><button type="button" class="cc-near" data-plan-ll="${r.ll[0]},${r.ll[1]}" data-plan-key="${escPend(r.item)}"><span class="cc-near-nm">${escPend(r.name || meta.label)}</span><em>${escPend(tpl(D.kmOff || '{a} along · {b} off', { a: uKm(r.alongKm), b: uM(r.distM) }))}</em></button><button type="button" class="cc-plan-add${inRide ? ' in' : ''}" data-plan-place="${escPend(place)}">${escPend(inRide ? (P().inRide || 'In your ride') : (P().addStop || 'Add as checkpoint'))}</button></li>`;
    };
    html += items.slice(0, ALONG_VISIBLE).map(row).join('');
    if(items.length > ALONG_VISIBLE) html += `<li class="cc-near-rest" id="${id}" hidden><ul class="cc-near-list">${items.slice(ALONG_VISIBLE).map(row).join('')}</ul></li>`;
  }
  if(!html) html = `<li class="cc-near-empty">${escPend(tpl(P().alongEmpty || 'Nothing the Commons knows within {d} of this ride yet.', { d: uM(CORRIDOR_M) }))}</li>`;
  ul.innerHTML = html;
  alongRows = rows; renderFigures();   // the water gap reads the rows
  if(pendingFill) checkFill();
  ul.querySelectorAll('.cc-near-more[aria-controls]').forEach(b => {
    const rest = ul.querySelector('#' + b.getAttribute('aria-controls')); if(!rest) return;
    b.onclick = () => { const open = b.getAttribute('aria-expanded') !== 'true'; rest.hidden = !open; b.setAttribute('aria-expanded', String(open)); b.textContent = open ? b.dataset.fewer : b.dataset.more; };
  });
  g.hidden = false;
}

/* ---------- the map ---------- */
function drawRide(){
  if(!map.getSource('plan')) return;
  const routed = [], straight = [];
  ride.legs.forEach((leg, i) => {
    const a = ride.stops[i], b = ride.stops[i + 1]; if(!a || !b) return;
    if(leg && leg.routed) routed.push({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: leg.line } });
    else straight.push({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: [[a.lng, a.lat], [b.lng, b.lat]] } });
  });
  map.getSource('plan').setData({ type: 'FeatureCollection', features: routed });
  map.getSource('plan-straight').setData({ type: 'FeatureCollection', features: straight });
  markers.forEach(m => m.remove()); markers = [];
  const n = ride.stops.length;
  ride.stops.forEach((s, i) => {
    const el = document.createElement('div');
    el.className = 'cc-plan-mk ' + (i === 0 ? 'start' : (i === n - 1 && n > 1 ? 'end' : ''));
    if(i === n - 1 && n > 1) el.innerHTML = FINISH_FLAG; else el.textContent = i === 0 ? 'S' : String(i);
    el.title = s.name + '\n' + (P().removeHint || '');
    // Right-click, or a click with the command key on a Mac (owner 2026-10-15); a Mac's Ctrl+click is a right-click already.
    el.addEventListener('contextmenu', e => { e.preventDefault(); e.stopPropagation(); removeStop(i); });
    el.addEventListener('click', e => { if(!e.metaKey) return; e.preventDefault(); e.stopPropagation(); removeStop(i); });
    const m = new maplibregl.Marker({ element: el, draggable: true }).setLngLat([s.lng, s.lat]).addTo(map);
    m.on('dragend', () => { remember(); const ll = m.getLngLat(); const st = ride.stops[i]; st.lng = ll.lng; st.lat = ll.lat; if(st.item){ st.item = null; st.letter = null; st.name = P().mapPoint || 'Map point'; } afterChange(); });
    markers.push(m);
  });
}
function applyLineShown(){
  ['plan-casing', 'plan-line', 'plan-straight', 'plan-arrows'].forEach(id => { if(map.getLayer(id)) map.setLayoutProperty(id, 'visibility', lineShown ? 'visible' : 'none'); });
  const b = $('planLineToggle'); if(!b) return;
  const label = lineShown ? (P().lineHide || 'Hide the ride line') : (P().lineShow || 'Show the ride line');
  b.setAttribute('aria-pressed', lineShown ? 'true' : 'false'); b.title = label; b.setAttribute('aria-label', label);
  b.classList.toggle('off', !lineShown);
}
/* A chevron for the ride's direction, orange on a cream edge as the line is, pointing along the line; drawn once per style. */
function addArrowImage(){
  if(map.hasImage && map.hasImage('plan-arrow')) return;
  const S = 2, D = 14, c = document.createElement('canvas'); c.width = c.height = D * S;
  const x = c.getContext('2d'); if(!x) return;
  x.scale(S, S); x.lineCap = 'round'; x.lineJoin = 'round';
  const path = () => { x.beginPath(); x.moveTo(4.5, 3); x.lineTo(10, 7); x.lineTo(4.5, 11); };
  x.strokeStyle = '#F3EBD8'; x.lineWidth = 4; path(); x.stroke();
  x.strokeStyle = '#FF5A1F'; x.lineWidth = 2; path(); x.stroke();
  map.addImage('plan-arrow', { width: D * S, height: D * S, data: new Uint8Array(x.getImageData(0, 0, D * S, D * S).data.buffer) }, { pixelRatio: S });
}
function addLayers(){
  if(map.getSource('plan')) return;
  map.addSource('plan', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
  map.addSource('plan-straight', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
  // The longest stretch without water or food, while its line in the panel is hovered (owner 2026-10-15), under the ride;
  // a yellow highlighter, because blue next to a river read as the river.
  map.addSource('plan-gap', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
  // The shortest way of a leg, from "Why this way?" (owner 2026-10-15): dark and dashed, so it reads as a choice, not the ride.
  map.addSource('plan-alt', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
  map.addLayer({ id: 'plan-alt', type: 'line', source: 'plan-alt', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#1F2A44', 'line-width': ['interpolate', ['linear'], ['zoom'], 10, 2.5, 18, 5], 'line-dasharray': [1.6, 1.4] } });
  map.addLayer({ id: 'plan-gap', type: 'line', source: 'plan-gap', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#F5C518', 'line-opacity': 0.55, 'line-width': ['interpolate', ['linear'], ['zoom'], 10, 9, 18, 18] } });
  // Thin, so the surface band under the ride stays readable on both sides of it (owner 2026-10-15).
  map.addLayer({ id: 'plan-casing', type: 'line', source: 'plan', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#F3EBD8', 'line-width': ['interpolate', ['linear'], ['zoom'], 10, 3, 18, 4.5], 'line-opacity': .7 } });
  map.addLayer({ id: 'plan-line', type: 'line', source: 'plan', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#FF5A1F', 'line-width': ['interpolate', ['linear'], ['zoom'], 10, 1.8, 18, 3] } });
  map.addLayer({ id: 'plan-straight', type: 'line', source: 'plan-straight', paint: { 'line-color': '#E8A33D', 'line-width': 3, 'line-dasharray': [1.5, 1.5] } });
  // Which way the ride goes (owner 2026-10-15): chevrons along the routed line, from zoom 11.
  addArrowImage();
  map.addLayer({ id: 'plan-arrows', type: 'symbol', source: 'plan', minzoom: 11, layout: { 'symbol-placement': 'line', 'symbol-spacing': 90, 'icon-image': 'plan-arrow', 'icon-size': ['interpolate', ['linear'], ['zoom'], 11, 0.75, 16, 1], 'icon-rotation-alignment': 'map', 'icon-allow-overlap': true, 'icon-ignore-placement': true } });
  // Into the one stacking order (map-and-search.md section 4.1): over every other line, under the icons.
  liftInfoLayersAboveRoutes();
  applyLineShown();
  drawRide();
}
/* An empty map click is a stop. A click on anything of ours (a pin, a coverage
   point, a route, a surface stretch) is not: the basemap's own sources are
   remembered when the style loads (catalog-load.js), and everything else is a feature. */
function hitsFeature(e){
  const baseSources = window.__ccBaseSources;
  if(!baseSources) return false;
  return map.queryRenderedFeatures(e.point).some(f => f.source && !baseSources.has(f.source) && f.source !== 'plan' && f.source !== 'plan-straight');
}
function renderBar(){
  const bar = $('planBar'); if(!bar) return;
  const show = activePanel() !== 'plan' && ride.stops.length >= 2;
  bar.hidden = !show;
  if(show){ const day = rideTime(dayLegs(), ride.bike); $('planBarText').textContent = barText(figures(ride.legs), ride.stops.length, { km: fmtKm, m: uElev, stops: n => tpl(P().barStops || '{n} checkpoints', { n }) }, day ? hmText(day.seconds) : ''); }
}
function openPanel(){ const b = document.getElementById('ib-plan'); if(b && activePanel() !== 'plan') b.click(); }

/* ---------- the drawer's Add to ride ---------- */
/* Runs from the observer on the drawer, so it writes only what changed: a
   write is itself a drawer mutation, and an unconditional one would call this
   again without end. */
function paintDrawerButtons(){
  document.querySelectorAll('[data-plan-add]').forEach(b => {
    const inRide = ride.stops.some(s => s.item === b.dataset.planItem);
    const label = inRide ? (D.planInRide || '✓ In your ride · remove') : (D.planAdd || '+ Add to ride');
    if(b.classList.contains('in') !== inRide) b.classList.toggle('in', inRide);
    if(b.textContent !== label) b.textContent = label;
  });
}

/* ---------- saving, the draft, the GPX ---------- */
/* The ride's work lives in this browser only, seven days after the last change (route-planner.md section 6):
   the draft, and the undo and redo steps beside it, so a closed tab loses nothing; a cleared ride keeps its
   steps, so undo still brings it back. */
function saveDraft(){
  try {
    if(ride.stops.length) localStorage.setItem(DRAFT_KEY, JSON.stringify({ bike: ride.bike, stops: ride.stops, name: ride.name, date: rideDate(), at: Date.now() }));
    else localStorage.removeItem(DRAFT_KEY);
    if(history.size() || history.redoSize()) localStorage.setItem(HISTORY_KEY, JSON.stringify(Object.assign({ at: Date.now() }, history.state())));
    else localStorage.removeItem(HISTORY_KEY);
  } catch(e){}
}
function loadDraft(){
  try {
    const d = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null');
    if(d && d.at !== undefined && !workFresh(d.at, Date.now())){ localStorage.removeItem(DRAFT_KEY); return null; }
    return d;
  } catch(e){ return null; }
}
function loadHistory(){
  try {
    const h = JSON.parse(localStorage.getItem(HISTORY_KEY) || 'null');
    if(!h) return null;
    if(!workFresh(h.at, Date.now())){ localStorage.removeItem(HISTORY_KEY); return null; }
    return h;
  } catch(e){ return null; }
}
function downloadGpx(){
  const text = gpxText({ name: ride.name, bike: ride.bike, stops: ride.stops, legs: ride.legs }, { itemUrl: item => item.startsWith('osm:') ? null : (location.origin + (CC_PLAN.item || '/map?item=') + item) });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([text], { type: 'application/gpx+xml' }));
  a.download = (ride.name || 'Ride') + '.gpx';
  document.body.appendChild(a); a.click(); a.remove();
}
/* Open a GPX (route-planner.md section 6.1): the planner's own ride file, read in this browser and never sent.
   A file that cannot open says why; over a ride being planned the rider is asked first, because the file
   replaces that ride (owner 2026-10-16); an empty planner opens it straight away. */
function openRideFile(file){
  if(!file) return;
  const refused = P().openRefused || {};
  // A long track from a GPS device runs to several megabytes; past 25 it is not a one-day ride.
  if(file.size > 25 * 1024 * 1024){ setStatus(refused.not_gpx || 'This file holds no ride we can open.'); return; }
  const reader = new FileReader();
  reader.onload = () => {
    const r = rideFromGpx(String(reader.result || ''));
    if(r.error){ setStatus(tpl(refused[r.error] || refused.not_gpx || 'This file holds no ride we can open.', { n: MAX_STOPS })); return; }
    if(!ride.stops.length){ applyRideFile(r, file.name); return; }
    askReplace(file.name).then(ok => { if(ok) applyRideFile(r, file.name); });
  };
  reader.onerror = () => setStatus(refused.not_gpx || 'This file holds no ride we can open.');
  reader.readAsText(file);
}
/* The file's name, bike and stops go on the map and the legs are routed again; undo goes back to the ride before. */
function applyRideFile(r, fileName){
  remember(true);
  if(r.bike && BIKES.includes(r.bike)){ ride.bike = r.bike; if(!document.querySelector(`#planBikes .chip[data-bike="${ride.bike}"]`)) showAllBikes(); paintBikes(); }
  ride.name = r.name || ride.name;
  // A point converted from another app's track is a map point, as a click on the map makes one.
  ride.stops = r.stops.map(s => s.name ? s : Object.assign({}, s, { name: P().mapPoint || 'Map point' }));
  ride.legs = [];
  afterChange();
  openPanel();
  fitRide();
  const name = ride.name || fileName;
  setStatus(r.converted
    ? tpl(P().converted || 'Opened {name}, made in another app, as {n} checkpoints. The planner routes between them for your bike, so a road can differ from the file.', { name, n: r.stops.length })
    : tpl(P().opened || 'Opened {name}.', { name }));
}
/* The question before a file replaces the ride being planned (#planReplace): true for "Open the file". The
   dialog's close value is the answer, so Escape, the backdrop and "Keep my ride" all keep the ride. Download
   GPX keeps the dialog open; it waits, as the panel's own does, until every leg has a road. */
function askReplace(fileName){
  const dlg = $('planReplace');
  if(!dlg || typeof dlg.showModal !== 'function') return Promise.resolve(window.confirm(($('planReplaceH') || {}).textContent || 'Open this file instead of your ride?'));
  $('planReplaceFile').textContent = fileName;
  const gpx = $('planReplaceGpx'); if(gpx) gpx.disabled = routing || unrouted();
  return new Promise(resolve => {
    dlg.returnValue = '';
    dlg.addEventListener('close', () => resolve(dlg.returnValue === 'open'), { once: true });
    dlg.showModal();
  });
}

/* ---------- the guide (route-planner.md section 3.1) ---------- */
/* Shown when the planner opens, once a page, until the rider unticks "Show this guide"; How it works opens it at any
   time (owner 2026-10-15). Signed in, the account holds the choice (a closed map hint), so it holds on every device;
   a visitor's browser holds it. */
const GUIDE_KEY = 'cc-plan-guide';
let guideShownOnce = false;
function guideOff(){
  const h = window.CC_HINTS || {};
  if(h.url) return (h.closed || []).includes('plan_guide');
  try { return localStorage.getItem(GUIDE_KEY) === 'off'; } catch(e){ return false; }
}
function showGuide(show){
  const g = $('planGuide'); if(!g) return;
  g.hidden = !show;
  const c = $('planGuideShow'); if(c) c.checked = !guideOff();
  const o = $('planGuideOpen'); if(o) o.hidden = show;
  // In the middle of the map: the close button takes the keyboard, and Escape closes it.
  if(show){ const x = $('planGuideClose'); if(x) x.focus(); }
}
function setGuideShown(show){
  try { if(show) localStorage.removeItem(GUIDE_KEY); else localStorage.setItem(GUIDE_KEY, 'off'); } catch(e){}
  const h = window.CC_HINTS || {};
  h.closed = show ? (h.closed || []).filter(x => x !== 'plan_guide') : [...new Set([...(h.closed || []), 'plan_guide'])];
  if(h.url && h.open && h.token) fetch((show ? h.open : h.url).replace('HINT', 'plan_guide'), { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': h.token } }).catch(() => {});
}

/* ---------- wiring ---------- */
export function initPlan(){
  const panel = $('p-plan'); if(!panel || !CC_PLAN.leg) return;
  const chips = $('planBikes');
  const first = chips && chips.querySelector('.chip.on'); if(first) ride.bike = first.dataset.bike;
  chips && chips.addEventListener('click', e => { const c = e.target.closest('.chip[data-bike]'); if(c) setBike(c.dataset.bike); });
  const all = $('planBikesAll'); if(all) all.addEventListener('click', e => { e.preventDefault(); toggleAllBikes(); });
  paintBikes();
  $('planStops').addEventListener('click', e => {
    const why = e.target.closest('[data-why]'); if(why){ explainLeg(Number(why.dataset.why)); return; }
    const show = e.target.closest('[data-way-show]'); if(show){ toggleAltPin(Number(show.dataset.wayShow)); return; }
    const take = e.target.closest('[data-way-take]'); if(take){ takeShortest(Number(take.dataset.wayTake)); return; }
    const back = e.target.closest('[data-way-back]'); if(back){ backToBikeWay(Number(back.dataset.wayBack)); return; }
    const li = e.target.closest('.cc-plan-stop'); if(!li) return;
    const i = Number(li.dataset.i);
    if(e.target.closest('[data-up]')) moveStop(i, -1);
    else if(e.target.closest('[data-down]')) moveStop(i, 1);
    else if(e.target.closest('[data-rm]')) removeStop(i);
    else if(e.target.closest('[data-fly]')){ const s = ride.stops[i]; flyToPin([s.lng, s.lat]); }
  });
  $('planStops').addEventListener('mouseover', e => { const li = e.target.closest('.cc-plan-stop'); if(!li) return; const s = ride.stops[Number(li.dataset.i)]; if(s) highlightAt([s.lat, s.lng], [0, 0], null); });
  $('planStops').addEventListener('mouseleave', clearHighlight);
  $('planStops').addEventListener('mouseover', e => { const r = e.target.closest('[data-way-leg]'); if(r) paintAlt(legKeys(ride.stops, ride.bike)[Number(r.dataset.wayLeg)]); });
  $('planStops').addEventListener('mouseout', e => { const r = e.target.closest('[data-way-leg]'); if(r && !r.contains(e.relatedTarget)) paintAlt(altPinned); });
  const along = $('planAlong');
  along.addEventListener('click', e => {
    const add = e.target.closest('[data-plan-place]'); if(add){ togglePlace(JSON.parse(add.dataset.planPlace)); renderAlong(); return; }
    const row = e.target.closest('.cc-near[data-plan-ll]'); if(row){ const [lat, lng] = row.dataset.planLl.split(',').map(Number); flyToPin([lng, lat]); }
  });
  along.addEventListener('mouseover', e => { const row = e.target.closest('.cc-near[data-plan-ll]'); if(!row) return; const [lat, lng] = row.dataset.planLl.split(',').map(Number); /* A drawn pin stands on its tip: the ring goes onto its body (owner 2026-10-15). */ highlightAt([lat, lng], pinElFor(row.dataset.planKey) ? [0, -16] : [0, 0], row.dataset.planKey); });
  along.addEventListener('mouseleave', clearHighlight);
  { const t = $('planStopsToggle'); if(t) t.addEventListener('click', () => { stopsOpen = !stopsOpen; renderStops(); }); }
  { const sm = $('planStopsSum'); if(sm) sm.addEventListener('click', () => { stopsOpen = true; renderStops(); }); }
  { const rs = $('planRoundSum'); if(rs) rs.addEventListener('click', () => { stopsOpen = true; renderStops(); const first = Number(rs.dataset.first); if(Number.isFinite(first)) explainLeg(first); }); }
  // Every info box of the panel: hover shows it, a click keeps it, the next closes it (owner 2026-10-15).
  document.querySelectorAll('#p-plan .cc-info-wrap').forEach(w => { const b = w.querySelector('.cc-info'); if(b) b.addEventListener('click', () => { const open = !w.classList.contains('open'); w.classList.toggle('open', open); b.setAttribute('aria-expanded', String(open)); }); });
  { const u = $('planDayUnit'); if(u) u.addEventListener('click', e => { const b = e.target.closest('button[data-u]'); if(!b) return; dayUnit = b.dataset.u; u.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b)); renderFigures(); }); }
  $('planGpx').addEventListener('click', downloadGpx);
  { const t = $('planTougher'); if(t){
    t.addEventListener('click', e => { if(e.target.closest('[data-plan-fill-water]')) fillWater(); });
    const leave = e => { const g = e.target.closest('.cc-plan-gap'); if(g && !g.contains(e.relatedTarget)) showGap(false); };
    t.addEventListener('mouseover', e => { if(e.target.closest('.cc-plan-gap')) showGap(true); });
    t.addEventListener('focusin', e => { if(e.target.closest('.cc-plan-gap')) showGap(true); });
    t.addEventListener('mouseout', leave);
    t.addEventListener('focusout', leave);
  } }
  { const o = $('planOpen'), f = $('planOpenFile'); if(o && f){ o.addEventListener('click', () => f.click()); f.addEventListener('change', () => { openRideFile(f.files && f.files[0]); f.value = ''; }); } }
  { const g = $('planReplaceGpx'); if(g) g.addEventListener('click', downloadGpx); }
  { const b = $('planNoticeShow'); if(b) b.addEventListener('click', () => { const d = $('planNotice'); if(d && d.open) d.close('ok'); if(noticePlace){ flyToPin([noticePlace.lng, noticePlace.lat]); highlightAt([noticePlace.lat, noticePlace.lng], [0, 0], null); } }); }
  // The planner's dialogs: a click beside one closes it, and the replace question then keeps the ride.
  for(const id of ['planReplace', 'planNotice']){ const d = $(id); if(d) d.addEventListener('click', e => { if(e.target === d) d.close('keep'); }); }
  // Escape closes the open dialog only: caught first, so the panel's own Escape does not close the planner.
  document.addEventListener('keydown', e => { if(e.key !== 'Escape') return; const open = ['planReplace', 'planNotice'].map($).find(d => d && d.open); if(open){ e.preventDefault(); e.stopImmediatePropagation(); open.close('keep'); } }, true);
  { const n = $('planRideName'); if(n) n.addEventListener('input', () => { ride.name = n.value.slice(0, 80); saveDraft(); syncStart(); if((ride.name || '').trim()) setStatus(''); }); }
  { const q = $('planStartSearch'); if(q) q.addEventListener('input', () => { clearTimeout(startTimer); startTimer = setTimeout(() => searchStart(q.value.trim()), 350); }); }
  $('planClear').addEventListener('click', clearRide);
  { const lt = $('planLineToggle'); if(lt) lt.addEventListener('click', () => { lineShown = !lineShown; try { localStorage.setItem(LINE_KEY, lineShown ? 'on' : 'off'); } catch(e){} applyLineShown(); }); applyLineShown(); }
  $('planBarOpen').addEventListener('click', openPanel);
  $('planBarClear').addEventListener('click', clearRide);
  const stripX = $('planStripX'); if(stripX) stripX.addEventListener('click', () => { stripClosed = true; renderProfile(); });
  ['planStripOpen', 'planStripTab'].forEach(id => { const b = $(id); if(b) b.addEventListener('click', () => { stripClosed = false; renderProfile(); }); });
  let resizeT = null;
  window.addEventListener('resize', () => { clearTimeout(resizeT); resizeT = setTimeout(renderProfile, 150); });
  // Add to ride on any place drawer (drawer.js writes the button).
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-plan-add]'); if(!b) return;
    togglePlace({ item: b.dataset.planItem, letter: b.dataset.planLetter || null, name: b.dataset.planName || b.dataset.planLetter || (P().mapPoint || 'Map point'), lat: Number(b.dataset.planLat), lng: Number(b.dataset.planLng) });
    paintDrawerButtons();
    openPanel();
  });
  // A right-click or a command-click on a place's pin (util.js offerToRide): in at its km along, or out
  // again, while a ride is planned; the rider stays on the map, the numbered stop is the answer.
  document.addEventListener('cc:place-menu', e => {
    if(!planning() || !e.detail.place) return;
    if(!e.detail.place.name) e.detail.place.name = e.detail.place.letter || (P().mapPoint || 'Map point');
    togglePlace(e.detail.place);
    paintDrawerButtons();
    e.detail.handled = true;
  });
  const drawer = document.getElementById('drawer');
  if(drawer) new MutationObserver(paintDrawerButtons).observe(drawer, { childList: true, subtree: true });
  document.addEventListener('cc:panel', e => { renderBar(); syncPlanning(); const key = e.detail && e.detail.key; if(key === 'plan' && ride.stops.length >= 2) fitRide(); if(key === 'plan' && !guideShownOnce && !guideOff()){ guideShownOnce = true; showGuide(true); } });
  { const o = $('planGuideOpen'), c = $('planGuideClose'), s = $('planGuideShow');
    if(o) o.addEventListener('click', () => showGuide(true));
    if(c) c.addEventListener('click', () => showGuide(false));
    if(s) s.addEventListener('change', () => setGuideShown(s.checked));
    // Escape closes the guide and nothing else: caught first, so the panel's own Escape does not close the planner.
    document.addEventListener('keydown', e => { const g = $('planGuide'); if(e.key === 'Escape' && g && !g.hidden){ e.preventDefault(); e.stopImmediatePropagation(); showGuide(false); } }, true); }
  // The map: layers once the style is there.
  const onLoad = () => { addLayers(); };
  if(map.loaded && map.loaded()) onLoad(); else map.once('load', onLoad);
  map.on('style.load', () => { if(map.loaded()) addLayers(); });
  map.on('click', e => {
    if(isPicking() || !planning()) return;
    if(hitsFeature(e)) return;
    const t = e.originalEvent && e.originalEvent.target;
    if(t && t.closest && t.closest('.cc-plan-mk')) return;   // a click on a stop is about that stop
    openPanel();
    addStop({ lat: e.lngLat.lat, lng: e.lngLat.lng, name: P().mapPoint || 'Map point' });
  });
  map.on('moveend', () => { if(ride.stops.length >= 2){ scheduleAlong(); scheduleTileRead(); } });
  map.on('idle', () => { if(ride.stops.length >= 2) scheduleTileRead(); });
  document.addEventListener('keydown', e => {
    if(!(e.ctrlKey || e.metaKey) || e.altKey) return;
    // Undo: Ctrl+Z, or the command key with Z. Redo: Ctrl+Shift+Z, the command key with shift and Z, or Ctrl+Y.
    const k = String(e.key).toLowerCase();
    const isUndo = k === 'z' && !e.shiftKey, isRedo = (k === 'z' && e.shiftKey) || (k === 'y' && !e.shiftKey && !e.metaKey);
    if(!isUndo && !isRedo) return;
    // Not under the replace question: the ride behind it would change while the rider is asked about it.
    if(['planReplace', 'planNotice'].some(id => { const d = $(id); return d && d.open; })) return;
    const t = e.target;
    if(t && t.closest && t.closest('input, textarea, select, [contenteditable]')) return;   // a field keeps its own undo
    if(!planning() && !history.size() && !history.redoSize()) return;
    e.preventDefault();
    if(isRedo) redo(); else undo();
  });
  // The day of the ride: today unless the draft holds a later date; a change redraws the daylight and asks the forecast.
  const dateIn = $('planDate'), draft = loadDraft();
  if(dateIn){
    dateIn.min = todayIso();
    dateIn.value = draft && typeof draft.date === 'string' && draft.date >= todayIso() ? draft.date : todayIso();
    dateIn.addEventListener('change', () => { saveDraft(); renderFigures(); scheduleWeather(); });
  }
  // The draft from the last visit with its undo and redo steps. The steps are read first: restoring the
  // draft saves, and an empty history would overwrite the kept one.
  const keptHistory = loadHistory();
  const d = draft; if(d && Array.isArray(d.stops) && d.stops.length){ ride = { name: d.name || '', bike: d.bike || ride.bike, stops: d.stops, legs: [] }; if(!document.querySelector(`#planBikes .chip[data-bike="${ride.bike}"]`)) showAllBikes(); paintBikes(); afterChange(); }
  if(keptHistory){ history.restore(keptHistory); saveDraft(); }
  syncStart();
  syncPlanning();
}
