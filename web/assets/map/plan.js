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
import { uKm, uM, uElev, uElevValue, uElevUnit } from './units.js';
import { highlightAt, clearHighlight } from './drawer.js';
import { pinElFor } from './pin-fan.js';
import { liftInfoLayersAboveRoutes } from './render.js';
import { initLegendDock } from './legend-dock.js';
import { initDatePick } from './date-pick.js';
import { storedLegs, keepLegs } from './leg-store.js';
import { activePanel } from './shell.js';
import { isPicking } from './picking.js';
import { setPlanning, isLineOrArea } from './plan-state.js';
import { CATALOG } from './catalog.js';
import { layerGlyph, waterKind } from './icons.js';
import { scopeKey, planScopeFor } from './ride-scope.js';
import { keepCameraForNextScope } from './scope-ui.js';
import { MAX_STOPS, ALONG_MAX_POINTS, thinLine, CORRIDOR_M, workFresh, rideFromGpx, waterGapSpan, bestWaterStop, stopRows, legGoesRound, whyThisWay, sliceAlong, stopIndexAt, legKeys, insertIndex, figures, profileRuns, pointAt, gpxText, barText, createHistory, coverPadding, tickStepKm, gradientSpans, surfaceSpans, rideTime, surfaceBars, gradientBars, durationText, tougherLines, rideFeel, rideHours, defaultStartMin, forecastPageUrl, forecastPoint, heightTicks, tileSurfaceClass, mergeSurfaces, streetAt, stopStreet, roadAt, steepestKm, longestClimb, waterGap, sunTimes, windShares, windSpans, hourSpan } from './plan-model.js';

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
const REPEAT_ROUNDS = 3;   // requests the repeat check sends at most for one routing
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
    if(r.status === 429) return { routed: false, ...await spent(r) };
    if(!r.ok) return { routed: false, down: true };
    const d = await r.json();
    showLegsLeft(d.budget);
    if(!d.routed) return { routed: false };
    return { routed: true, line: d.coordinates, distM: d.distanceM, heights: d.heights || null, surfaces: d.surfaces || null, streets: d.streets || null, runs: d.runs || null, busyRuns: d.busyRuns || null, recorded: !!d.recorded, quality: d.quality || null, speeds: d.speeds || null, detailRuns: d.detailRuns || null };
  } catch(e){ return { routed: false, down: true }; }
}
/* How far the along list looks (route-planner.md section 5): 250 m unless the rider picks more, kept with the draft.
   The water gap reads only the places within CORRIDOR_M: water 5 km off the ride is not water along it. */
const ALONG_STEPS = [250, 500, 1000, 2000, 5000];
let alongM = CORRIDOR_M;
const nearRows = () => (alongRows || []).filter(r => r.distM <= CORRIDOR_M);
const reachText = m => m >= 1000 ? uKm(m / 1000, 0) : uM(m);
function fillAlongReach(){
  const sel = $('planAlongR'); if(!sel) return;
  sel.innerHTML = ALONG_STEPS.map(m => `<option value="${m}">${escPend(tpl(P().within || 'within {d}', { d: reachText(m) }))}</option>`).join('');
  sel.value = String(alongM);
}
/* The legs of a stretch of the ride in one request (route-planner.md section 8, owner 2026-10-18: a reopened ride
   asked its legs one after another). Null when the server routes the stretch not whole: the planner then asks leg by
   leg; `{ limited: true }` when the day's legs are spent. */
async function routeLegs(stops, bike){
  if(!CC_PLAN.legs) return null;
  try {
    const r = await fetch(CC_PLAN.legs, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CC-Token': CC_PLAN.legToken || '' },
      body: JSON.stringify({ stops: stops.map(s => [s.lng, s.lat]), bike }) });
    if(r.status === 429) return await spent(r);
    if(!r.ok) return null;
    const d = await r.json();
    showLegsLeft(d.budget);
    if(!Array.isArray(d.legs) || d.legs.length !== stops.length - 1) return null;
    return d.legs.map(legFrom);
  } catch(e){ return null; }
}
/* Legs asked alone, each with its own roads to keep off, in one request (the repeat check, route-planner.md section
   4.2). One answer per ask, in order; null when the request fails, `{ limited: true }` when the legs are spent. */
async function routeDetours(asks, bike){
  if(!CC_PLAN.detours) return null;
  try {
    const r = await fetch(CC_PLAN.detours, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CC-Token': CC_PLAN.legToken || '' },
      body: JSON.stringify({ bike, legs: asks.map(q => ({ a: [q.a.lng, q.a.lat], b: [q.b.lng, q.b.lat], avoid: q.avoid })) }) });
    if(r.status === 429) return await spent(r);
    if(!r.ok) return null;
    const d = await r.json();
    showLegsLeft(d.budget);
    if(!Array.isArray(d.legs) || d.legs.length !== asks.length) return null;
    return d.legs.map(l => l && l.routed ? legFrom(l) : { routed: false });
  } catch(e){ return null; }
}
function legFrom(l){
  return { routed: true, line: l.coordinates, distM: l.distanceM, heights: l.heights || null, surfaces: l.surfaces || null, streets: l.streets || null, runs: l.runs || null, busyRuns: l.busyRuns || null, recorded: !!l.recorded, quality: l.quality || null, speeds: l.speeds || null, detailRuns: l.detailRuns || null, ...(l.fromFile ? { fromFile: true, matched: !!l.matched } : {}) };
}
/* A ride file's legs on the file's own roads (route-planner.md section 6.1, owner 2026-10-18): its track cut at the
   checkpoints, one piece per leg, matched to the roads in one request. One leg per piece, in order; null when the
   request fails, and the planner then routes the legs; `{ limited: true }` when the legs are spent. */
async function routeMatch(pieces, bike){
  if(!CC_PLAN.match) return null;
  try {
    const r = await fetch(CC_PLAN.match, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CC-Token': CC_PLAN.legToken || '' },
      body: JSON.stringify({ bike, legs: pieces }) });
    if(r.status === 429) return await spent(r);
    if(!r.ok) return null;
    const d = await r.json();
    showLegsLeft(d.budget);
    if(!Array.isArray(d.legs) || d.legs.length !== pieces.length) return null;
    return d.legs.map(l => l && l.routed ? legFrom(l) : null);
  } catch(e){ return null; }
}
/* The legs a ride file gave, by their two stops whatever the bike (stopKey|stopKey): a bike chosen after the file
   opened keeps the file's roads, as the file says where the rider goes. */
const fileLegs = new Map();
const fileLegKey = (a, b) => stopKey(a) + '|' + stopKey(b);
/* The legs' budget is spent: a visitor's for the day, a rider's for a few minutes (the server's `until`). */
async function spent(r){
  let until = '';
  try { const d = await r.json(); until = d.until || ''; showLegsLeft(d.budget); } catch(e){ /* no body: the short wait */ }
  return { limited: true, tomorrow: until === 'tomorrow' };
}
/* The legs left to plan, under the status line, always (owner 2026-10-18): from every leg answer, and asked when the
   panel opens, which spends none. A visitor's are the day's, a rider's those of the next ten minutes. */
function showLegsLeft(b){
  const el = $('planLegsLeft');
  if(!el || !b || !Number.isFinite(b.left) || !Number.isFinite(b.limit)) return;
  el.textContent = tpl(b.per === 'day' ? (P().legsLeftDay || '{n} of {max} legs left today') : (P().legsLeftWindow || '{n} of {max} legs left for the next 10 minutes'), { n: b.left, max: b.limit });
  el.hidden = false;
}
let budgetAskedAt = 0;
async function askLegsLeft(){
  if(!CC_PLAN.budget || Date.now() - budgetAskedAt < 60000) return;
  budgetAskedAt = Date.now();
  try {
    const r = await fetch(CC_PLAN.budget, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CC-Token': CC_PLAN.legToken || '' }, body: '{}' });
    if(r.ok) showLegsLeft((await r.json()).budget);
  } catch(e){ /* the line waits for the next leg */ }
}
/* The legs to route as runs of neighbours, [first, last] leg index, none ridden the shortest way (that leg asks alone). */
function legRuns(idx){
  const runs = [];
  for(const i of idx){
    if(ride.stops[i + 1] && ride.stops[i + 1].way === 'shortest') continue;
    const last = runs[runs.length - 1];
    if(last && last[1] === i - 1) last[1] = i; else runs.push([i, i]);
  }
  return runs.filter(([a, b]) => b > a);
}
/* A map point onto the road a bike would take there (route-planner.md section 4.1, owner 2026-10-18: a checkpoint in a
   field routed up a farm's driveway). The point as it was when the server cannot say. */
async function snapPoint(lat, lng){
  if(!CC_PLAN.snap) return { lat, lng };
  try {
    const r = await fetch(CC_PLAN.snap, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CC-Token': CC_PLAN.legToken || '' },
      body: JSON.stringify({ point: [lng, lat], bike: ride.bike }) });
    const d = r.ok ? await r.json() : null;
    return d && d.snapped && Array.isArray(d.point) ? { lat: d.point[1], lng: d.point[0] } : { lat, lng };
  } catch(e){ return { lat, lng }; }
}
async function fetchAlong(line){
  try {
    const r = await fetch(CC_PLAN.along, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CC-Token': CC_PLAN.alongToken || '' },
      body: JSON.stringify({ line: { type: 'LineString', coordinates: line }, radius: alongM }) });
    return r.ok ? await r.json() : null;
  } catch(e){ return null; }
}

/* ---------- the ride ---------- */
/* `done` is the quiet all-well line ("Routed."): grey, as the orange stays for the ride and what needs the rider. */
function setStatus(text, busy, done){ const s = $('planStatus'); if(!s) return; s.textContent = text || ''; s.classList.toggle('is-busy', !!busy); s.classList.toggle('is-done', !!done); }
/* A click is a stop, and the scope click stands aside, while the panel is open or a ride is on the map. */
function planning(){ return activePanel() === 'plan' || (!asleep && ride.stops.length > 0); }
/* The ride kept from the last visit sleeps until the planner is opened (route-planner.md section 6.0, owner 2026-10-17):
   the map page opens as the map, with no line, strip, ride bar or captured click, and no leg is asked of the engine. */
let asleep = false;
/* Add to ride shows on the drawers from the moment the panel was opened in this session (route-planner.md section 4.3). */
let everOpened = false;
function syncPlanning(){
  if(activePanel() === 'plan'){ everOpened = true; askLegsLeft(); if(asleep){ asleep = false; afterChange(); return; } }
  setPlanning(planning());
  document.body.classList.toggle('cc-planning', everOpened || planning());
  // While a ride is planned the map is the rider's: Contribute steps aside (owner 2026-10-17).
  document.body.classList.toggle('cc-plan-on', planning());
  syncSide();
  /* The rail's dot on the Plan icon while a stop is on the map, as Layers wears one for a filter. */
  const b = document.getElementById('ib-plan');
  if(b) b.classList.toggle('riding', ride.stops.length > 0);
}

/* Search & region puts the ride to sleep at once (route-planner.md section 6.0, owner 2026-10-18: "when user clicks on
   search & region you immediately should stop showing any planner related stuff"): the map is the plain map again,
   as at page load. No line, checkpoints, strip, ride bar, details or Add to ride; nothing asked of our server; the
   rider's own scope back. Opening the planner wakes the ride from the legs kept (syncPlanning()), and its scope
   follows the ride again. */
function sleepRide(){
  if(asleep || !ride.stops.length) return;
  asleep = true; everOpened = false; gen++; routing = false;
  clearTimeout(alongTimer); clearTimeout(weatherTimer); clearTimeout(tileTimer);
  const empty = { type: 'FeatureCollection', features: [] };
  for(const id of ['plan', 'plan-straight', 'plan-alt', 'plan-gap', 'plan-hl', 'plan-rain', 'plan-wind']){ const s = map.getSource(id); if(s) s.setData(empty); }
  markers.forEach(m => m.remove()); markers = [];
  if(scrubMarker){ scrubMarker.remove(); scrubMarker = null; }
  focusMap(false);
  showStrip(false);
  restoreRideScope();
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
/* What covers the map as it is now: the drawer where it lies over the map, the area card, the key, the strip, the ride bar. */
const MAP_COVERS = ['#drawer', '.map-top', '.legend', '#planStrip', '#planBar', '#planSide'];
function ridePadding(){
  const box = map.getContainer().getBoundingClientRect();
  const covers = MAP_COVERS.map(q => document.querySelector(q)).filter(el => el && !el.hidden && el.offsetParent !== null).map(el => el.getBoundingClientRect());
  return coverPadding(box, covers, 30);
}
/* Opening the planner with a ride on the map takes the map to the ride (owner 2026-10-15), clear of whatever covers the map. */
function fitRide(){
  // Before any leg is routed (a file just opened), the checkpoints frame the ride.
  const line = rideLine(), pts = line.length >= 2 ? line : ride.stops.map(s => [s.lng, s.lat]);
  if(pts.length === 1){ map.easeTo({ center: pts[0], duration: 600 }); return; }
  if(pts.length < 2) return;
  let w = 180, s = 90, e = -180, n = -90;
  for(const [x, y] of pts){ if(x < w) w = x; if(x > e) e = x; if(y < s) s = y; if(y > n) n = y; }
  map.fitBounds([[w, s], [e, n]], { padding: ridePadding(), maxZoom: 15, duration: 600 });
}
/* ---------- the start: a name, then a town or a street (route-planner.md section 4.1) ---------- */
let startTimer = null, startCtl = null;
function syncStart(){
  const n = $('planRideName'), box = $('planStartBox'); if(!n || !box) return;
  if(document.activeElement !== n) n.value = ride.name || '';
  box.hidden = !(ride.name || '').trim();
  const grp = $('planSearchGrp'); if(grp) grp.hidden = box.hidden;   // the card goes with its search
  // With checkpoints it is a way to a place on the map, no longer the start (owner 2026-10-18: "the search field is missing").
  const lbl = $('planStartLbl'); if(lbl) lbl.textContent = ride.stops.length ? lbl.dataset.goto : lbl.dataset.start;
}
async function searchStart(qy){
  const ul = $('planStartResults'); if(!ul) return;
  if(startCtl) startCtl.abort();
  if(qy.length < 3){ ul.innerHTML = ''; return; }
  startCtl = new AbortController();
  // Photon ranks towns and streets near the middle of the map first; rounded to 0.1° (about 10 km), all it needs,
  // as the privacy notice says (bs_photon_sees).
  const c = map.getCenter();
  const url = 'https://photon.komoot.io/api/?limit=6&layer=city&layer=district&layer=locality&layer=street'
    + '&q=' + encodeURIComponent(qy) + '&lat=' + c.lat.toFixed(1) + '&lon=' + c.lng.toFixed(1) + '&lang=' + photonLang(document.documentElement.lang);
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
    if(!ride.stops.length) setStatus(P().startClick || 'Click the map where you start.');
    // The scope follows the start as it follows a ride (owner 2026-10-17: Hoorn picked, the chip still said Near Spa).
    const S = window.CCScope;
    if(S && S.regionOfPointPrecise) S.regionOfPointPrecise(r.lng, r.lat).then(reg => { if(reg) followRideScope(reg); }).catch(() => {}); };
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
/* While a ride is planned the map's scope is the region of its start (route-planner.md section 5, owner 2026-10-19:
   "just load the region our ride is in (startpoint of the ride if it spans multiple). Do not show the lighter
   circle"), whatever the rider's own scope was. Never stored as the rider's own scope; the chip says it comes from
   the ride. */
let scopeBefore = null, scopeSet = null;
function followRideScope(region){
  const S = window.CCScope;
  if(!S || !region) return;
  const cur = S.get();
  const next = planScopeFor(cur, region); if(!next) return;
  if(scopeBefore == null) scopeBefore = cur;
  // The ride's own framing stays the last word; the scope change would otherwise move the camera.
  keepCameraForNextScope();
  S.set(next, { persist: false });
  scopeSet = next;
  if(window.CCScopeHeader) window.CCScopeHeader.paint(window.CC_I18N || {});
  const line = document.getElementById('regionLine');
  if(line) line.textContent = line.textContent + ' · ' + (I18N.rcScopeFromRide || 'from your ride');
}
/* The scope follows the start as soon as it is on the map (owner 2026-10-17: a map point clicked near Hoorn left
   the scope near Spa), before any leg is routed; a stop clicked in the Checkpoints list takes it to that stop's
   region. A stop outside every region asks nothing. */
function followStopScope(stop){
  const S = window.CCScope;
  if(!S || !S.regionOfPointPrecise || !stop) return;
  const myGen = gen;
  S.regionOfPointPrecise(stop.lng, stop.lat).then(r => { if(myGen === gen && r) followRideScope(r); }).catch(() => {});
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
function afterChange(){ pendingFill = null; if(altPinned && !legKeys(ride.stops, ride.bike).includes(altPinned)){ altPinned = null; paintAlt(null); } alongRows = null; syncStart(); renderStops(); drawRide(); routeAll(); followStopScope(ride.stops[0]); saveDraft(); syncPlanning(); paintDrawerButtons(); }

async function routeAll(){
  const myGen = ++gen;
  const keys = legKeys(ride.stops, ride.bike);
  // The legs this browser kept from an earlier visit first (leg-store.js): a reopened ride asks our server nothing.
  const unknown = keys.filter(k => !legCache.has(k));
  if(unknown.length){
    const kept = await storedLegs(unknown);
    if(myGen !== gen) return;
    kept.forEach((leg, k) => legCache.set(k, leg));
  }
  // A leg a ride file gave keeps the file's roads under any bike.
  keys.forEach((k, i) => {
    if(legCache.has(k) || ride.stops[i + 1].way === 'shortest') return;
    const f = fileLegs.get(fileLegKey(ride.stops[i], ride.stops[i + 1]));
    if(f) legCache.set(k, Object.assign({}, f, { key: k }));
  });
  const needed = [];
  ride.legs = keys.map((key, i) => { const c = legCache.get(key); if(!c) needed.push([i, key]); return c || null; });
  if(!needed.length){ drawRide(); renderStops(); if(await avoidRepeats(myGen)) finishRouting(); return; }
  routing = true;
  setStatus(tpl(P().routing || 'Routing the legs for a {bike} bike…', { bike: ride.bike }), true);
  // Neighbouring legs in one request each; what is left, or a run the server could not route whole, leg by leg.
  for(const [a, b] of legRuns(needed.map(([i]) => i))){
    const got = await routeLegs(ride.stops.slice(a, b + 2), ride.bike);
    if(myGen !== gen) return;
    if(got && got.limited){ ride.legs[a] = { routed: false, ...got, key: keys[a] }; drawRide(); renderStops(); break; }
    if(!got) continue;
    got.forEach((leg, k) => { leg.key = keys[a + k]; legCache.set(leg.key, leg); ride.legs[a + k] = leg; });
    drawRide(); renderStops();
  }
  for(const [i, key] of needed){
    if(ride.legs.some(l => l && l.limited)) break;   // the day's legs are spent
    if(ride.legs[i]) continue;   // routed with its neighbours
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
  if(await avoidRepeats(myGen)) finishRouting();
}
/* ---------- never the same road twice (route-planner.md section 4.2, owner 2026-10-18) ----------
   A leg that rides a stretch an earlier leg already rides is asked once more, keeping off that stretch; the other way
   is taken when it repeats less and is at most REPEAT.maxGrowth times as long, else the shared road is the only way.
   A leg the rider set to the shortest way keeps it. Asked once per set of earlier legs: the leg remembers them. */
async function avoidRepeats(myGen){
  const keys = legKeys(ride.stops, ride.bike), before = i => keys.slice(0, i).join(';');
  const asked = new Set();
  // All legs that repeat go in one request; a leg that repeats only once an earlier leg took its other way goes in the next.
  for(let round = 0; round < REPEAT_ROUNDS; round++){
    const asks = [];
    for(let i = 1; i < ride.legs.length; i++){
      const leg = ride.legs[i];
      if(!leg || !leg.routed || leg.fromFile || asked.has(i) || leg.repeatChecked === before(i) || ride.stops[i + 1].way === 'shortest') continue;
      const runs = repeatRuns(ride.legs, i);
      if(repeatedM(runs)) asks.push({ i, a: ride.stops[i], b: ride.stops[i + 1], avoid: avoidPoints(leg, runs) });
    }
    if(!asks.length) break;
    asks.forEach(q => asked.add(q.i));
    const alts = await routeDetours(asks, ride.bike);
    if(myGen !== gen) return false;
    if(!alts || alts.limited) break;
    // In ride order: an earlier leg's other way may already have taken a later leg off the shared road.
    asks.forEach((q, k) => {
      const leg = ride.legs[q.i], alt = alts[k], was = repeatedM(repeatRuns(ride.legs, q.i));
      if(!was || !alt.routed || !(alt.distM <= leg.distM * REPEAT.maxGrowth)) return;
      const trial = ride.legs.slice(); trial[q.i] = alt;
      if(repeatedM(repeatRuns(trial, q.i)) >= was) return;
      alt.key = leg.key; alt.otherWay = true;
      legCache.set(leg.key, alt); ride.legs[q.i] = alt;
    });
    drawRide(); renderStops();
  }
  ride.legs.forEach((leg, i) => { if(leg && leg.routed) leg.repeatChecked = before(i); });
  return true;
}
function finishRouting(){
  const legs = ride.legs.filter(Boolean);
  const spentLeg = legs.find(l => l.limited);
  if(spentLeg) setStatus(spentLeg.tomorrow ? (P().rateLimitedDay || 'This connection has planned many legs today. Try again tomorrow, or sign in to plan more.') : (P().rateLimited || 'Too many legs in a short time. Wait a few minutes, then try again.'));
  else if(legs.some(l => l.down)) setStatus(P().engineDown || 'The routing engine did not answer. Try again in a moment.');
  else if(legs.some(l => !l.routed)) setStatus(P().noRoad || 'No road found between these checkpoints');
  else setStatus(ride.stops.length < 2 ? '' : (P().routed || 'Routed.'), false, true);
  renderFigures(); renderProfile(); scheduleAlong(); scheduleWeather(); scheduleTileRead(); renderBar(); saveDraft();
  keepLegs(ride.legs);
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
  if(rs){ rs.hidden = !folded || !round.length; if(round.length){ rs.textContent = tpl(round.length === 1 ? (P().wayRoundOne || '1 leg takes a detour: see why') : (P().wayRoundMany || '{n} legs take a detour: see why'), { n: round.length }); rs.dataset.first = String(round[0]); } }
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
  const figs = $('planFigsGrp'), acts = $('planActs');
  if(figs) figs.hidden = !has;
  if(acts) acts.hidden = !has;
  if(!has) return;
  const f = figures(ride.legs);
  $('planDist').textContent = fmtKm(f.distM);
  $('planUp').textContent = uElev(f.upM);
  $('planDown').textContent = uElev(f.downM);
  // The day (route-planner.md section 4.4): the reference rider's time, the flat equivalent, the bar, what makes it tough.
  const day = rideTime(dayLegs(), ride.bike);
  const tEl = $('planTime'), tSub = $('planTimeSub');
  if(tEl) tEl.textContent = day ? hmText(day.seconds) : '';
  // A flat ride feels like itself: the flat equivalent shows only when it is more than 5 % longer (owner 2026-10-17).
  const feels = day && day.flatKm > f.distM / 1000 * 1.05;
  if(tSub){ tSub.textContent = feels ? tpl(P().feelsLike || 'feels like {d} flat', { d: uKm(day.flatKm, 0) }) : ''; tSub.hidden = !feels; }
  const arr = $('planArrive');
  // The finish: the riding time plus 5 % for lights, crossings and traffic, to five minutes; stops are the rider's (owner 2026-10-17).
  const at = $('planArriveT');
  if(arr){ arr.hidden = !day; if(day && at) at.textContent = '~ ' + clockText(new Date(startAt().getTime() + roundedS(day.seconds * FINISH_SLACK) * 1000)); }
  renderDayGraphic(day);
  renderTougher(day, f);
  const wait = unrouted() || routing;
  const gpx = $('planGpx');
  if(gpx) gpx.disabled = wait;
  const hint = $('planSaveHint'); if(hint && (unrouted() && !routing)) hint.textContent = P().saveWait || 'Save and GPX wait until every leg has a road.';
  syncSide();
}
/* The page's duration words: "3h 50m", "3h", "50m" in English. */
const durWords = () => ({ hm: P().hm, h: P().h, min: P().min });
/* The finish time's allowance for lights, crossings and traffic over the riding time (route-planner.md section 4.4). */
const FINISH_SLACK = 1.05;
/* How far ahead the forecast reaches, days (Forecast::DAYS): the calendar marks those days. */
const FORECAST_DAYS = 16;
/* The riding time as the panel says it, to five minutes, in seconds: the finish time adds what the rider reads. */
const roundedS = s => Math.round(s / 300) * 300;
/* The riding time, "3h 50m", to five minutes. */
function hmText(seconds){ return durationText(seconds, durWords(), 5); }
/* A part of the day in distance: a stretch under 50 m reads "under 0.1 km", never "0 km", as time reads "under 1 min"
   (owner 2026-10-18). */
function partDist(m){ if(m > 0 && m < 50) return tpl(P().underDist || 'under {d}', { d: uKm(0.1) }); return fmtKm(m); }
/* A part of the day, to the minute. */
function partText(seconds){ if(seconds > 0 && seconds < 30) return P().underMin || 'under 1 min'; return durationText(seconds, durWords(), 1); }
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
/* The legs the day's figures read: a leg the server read from the recorded surfaces as it is (section 4.4a), any other
   with its surfaces corrected by the map's lines where seen. */
function dayLegs(){ return ride.legs.map(leg => leg && leg.routed && !leg.recorded ? { ...leg, surfaces: mergeSurfaces(leg.surfaces, tileMetres(leg), leg.distM) } : leg); }
let tileTimer = null;
function scheduleTileRead(){ clearTimeout(tileTimer); tileTimer = setTimeout(() => { if(!asleep && ride.stops.length >= 2 && readTileSurfaces()){ renderFigures(); renderBar(); } }, 400); }
/* The surface parts wear the map's own line colours (SurfaceVocabulary::LINE_COLOUR, the one palette), so "surface not
   recorded" is the map's red, dashed as the map draws it (owner 2026-10-17). */
const SURF = window.CC_SURFACE_COLOURS || {};
const PART_COLOUR = { paved: SURF.paved || '#577A71', gravel: SURF.gravel || '#C8923A', rough: SURF.dirt || '#6E5849', unknown: SURF.unverified || '#D92D20', climb: '#7B4F9D', flat: '#C9BFA8', descend: '#9FB8C9' };
const DASHED = new Set(['unknown']);
const partFill = cls => DASHED.has(cls) ? 'url(#planPartDash)' : PART_COLOUR[cls];
const partSwatch = cls => DASHED.has(cls) ? `background:repeating-linear-gradient(90deg,${PART_COLOUR[cls]} 0 3px,transparent 3px 5px)` : `background:${PART_COLOUR[cls]}`;
const DASH_DEFS = `<defs><pattern id="planPartDash" width="2" height="10" patternUnits="userSpaceOnUse"><rect width="1.2" height="10" fill="${PART_COLOUR.unknown}"/></pattern></defs>`;
function partLabel(cls){ const d = { paved: P().partPaved || 'on asphalt', gravel: P().partGravel || 'on gravel', rough: P().partRough || 'on rough road', unknown: P().partUnknown || 'surface not recorded', climb: P().partClimb || 'climbing', flat: P().partFlat || 'on the flat', descend: P().partDescend || 'descending' }; return d[cls] || cls; }
/* ---------- the stats light up their stretches (route-planner.md section 4.4, owner 2026-10-17) ----------
   Pointing at a figure or a part of the day graphic draws its stretches over the ride: the distance the whole ride,
   climbed and descended their gradient class, a surface its runs. The pale parts of the bars get a deeper tone on the map. */
const SURF_PARTS = new Set(['paved', 'gravel', 'rough', 'unknown']);
const MAP_PART = { ...PART_COLOUR, all: '#FF5A1F', flat: '#8C8167', descend: '#5D8FB0', 'wind-into': '#B8501A', 'wind-side': '#8C8167', 'wind-with': '#2E78C2',
  steep: '#7B4F9D', climbrun: '#7B4F9D', busy: '#B8501A', rain: '#3A6FC4', dark: '#1F2A44', 'hour-hot': '#C0561E', 'hour-cold': '#3A6FC4', 'hour-ice': '#3A6FC4' };
/* What each line of what makes it tougher lights on the map (owner 2026-10-18: "then also the veil trick"). */
const TOUGH_PART = { long: 'all', steep: 'steep', climb: 'climbrun', rough: 'rough', busy: 'busy', water: 'water', daylight: 'dark', wind: 'wind-into', rain: 'rain', heat: 'hour-hot', cold: 'hour-cold', ice: 'hour-ice' };
/* The day as the panel reads it now: riding time, total, the forecast of the hours ridden and how they feel. */
function dayNow(){
  const day = rideTime(dayLegs(), ride.bike), total = figures(ride.legs).distM;
  const fc = day && forecast ? rideHours(forecast, startMin(), day.seconds) : null;
  const feel = rideFeel(fc, day && day.seconds > 0 ? total / 1000 / (day.seconds / 3600) : 0);
  return { day, total, fc, feel };
}
/* Seconds after the start, as metres along the ride at the average pace. */
const secToM = (s, n) => Math.max(0, Math.min(n.total, s / n.day.seconds * n.total));
function toughSpans(cls){
  const n = dayNow(); if(!n.day) return [];
  if(cls === 'steep'){ const s = steepestKm(ride.legs); return s ? [[s.atKm * 1000, s.atKm * 1000 + 1000]] : []; }
  if(cls === 'climbrun'){ const c = longestClimb(ride.legs); return c && Number.isFinite(c.fromM) ? [[c.fromM, c.fromM + c.km * 1000]] : []; }
  if(cls === 'busy'){ const out = []; let off = 0; for(const l of ride.legs){ if(!l || !l.routed) continue; (l.busyRuns || []).forEach(([a, b]) => out.push([off + a, off + b])); off += l.distM || 0; } return out; }
  if(cls === 'water') return [];   // the yellow stretch of the water gap is its own layer
  if(cls === 'rain'){ if(!n.fc || !Array.isArray(n.fc.hours)) return []; const out = []; for(let h = n.fc.fromH; h <= n.fc.toH; h++){ if(n.fc.hours[h] && n.fc.hours[h].rainMm >= 0.1){ const s = hourSpan(startMin(), n.day.seconds, n.total, h); if(s) out.push(s); } } return out; }
  if(cls === 'dark'){
    const st = ride.stops[0], sun = st ? sunTimes(st.lat, st.lng, rideDate()) : null; if(!sun) return [];
    const t0 = startAt().getTime(), rise = (sun.sunrise.getTime() - t0) / 1000, set = (sun.sunset.getTime() - t0) / 1000, out = [];
    if(rise > 0) out.push([0, secToM(rise, n)]);
    if(set < n.day.seconds) out.push([secToM(set, n), n.total]);
    if(!out.length && set - 3600 < n.day.seconds) out.push([secToM(set - 3600, n), n.total]);   // the last hour before sunset
    return out.filter(s => s[1] > s[0]);
  }
  const at = { 'hour-hot': n.feel.hot, 'hour-cold': n.feel.cold, 'hour-ice': n.feel.ice }[cls];
  if(at){ const s = hourSpan(startMin(), n.day.seconds, n.total, at.hour); return s ? [cls === 'hour-ice' ? [s[0], n.total] : s] : []; }
  return null;
}
function partSpans(cls){
  if(cls === 'all') return [[0, figures(ride.legs).distM]];
  const tough = toughSpans(cls); if(tough) return tough;
  if(cls.startsWith('wind-')){ const fc = forecast && rideHours(forecast, startMin(), (rideTime(dayLegs(), ride.bike) || {}).seconds); return fc ? windSpans(ride.legs, fc.windFromDeg, cls.slice(5)) : []; }
  if(/^(sm|tt|sp):/.test(cls)) return detailSpans(ride.legs, cls.slice(0, 2), cls.slice(3));
  if(SURF_PARTS.has(cls)) return surfaceSpans(ride.legs, cls, leg => leg.recorded ? null : tileSeen.get(leg.key), SAMPLE_M);
  return gradientSpans(ride.legs, cls);
}
/* The wind's direction drawn along the ride (owner 2026-10-17): arrows where the wind blows, about every 1.5 km, from
   the hour pointed at in the chart or the hours ridden; null clears them. */
function windArrows(fromDeg, kmh){
  const src = map.getSource('plan-wind'); if(!src) return;
  const total = figures(ride.legs).distM, feats = [];
  if(Number.isFinite(fromDeg) && total > 0){
    const n = Math.max(6, Math.min(40, Math.round(total / 1500)));
    for(let i = 0; i < n; i++){ const p = pointAt(ride.legs, (i + 0.5) * total / n); if(p) feats.push({ type: 'Feature', properties: { rot: (fromDeg + 180) % 360, kmh: Number.isFinite(kmh) ? kmh : 15 }, geometry: { type: 'Point', coordinates: p } }); }
  }
  src.setData({ type: 'FeatureCollection', features: feats });
}
function ridingWind(){ const fc = forecast && rideHours(forecast, startMin(), (rideTime(dayLegs(), ride.bike) || {}).seconds); return fc ? [fc.windFromDeg, fc.windKmh] : [null, null]; }
/* The map steps back while a stat or the weather is pointed at (owner 2026-10-18: "else it will be too much info"):
   a veil over everything on the canvas, the ride and what is lit over it. The checkpoints are page elements and stay
   on top. The one stacking order comes back when the pointer leaves. */
const FOCUS_LAYERS = ['plan-gap', 'plan-casing', 'plan-line', 'plan-straight', 'plan-hl-casing', 'plan-hl', 'plan-rain', 'plan-wind', 'plan-arrows'];
/* It fades in and out (owner 2026-10-18: "so flashy"): the veil's opacity eases over VEIL_FADE_MS, a pointer that moves
   from one stat to the next keeps it up (VEIL_HOLD_MS), and the stacking order comes back only once it has faded out. */
const VEIL_OPACITY = 0.62, VEIL_FADE_MS = 300, VEIL_HOLD_MS = 150;
/* Reduced motion keeps a short soft fade, not a jump (owner 2026-10-18: a tiny smooth change over a flicker). */
const VEIL_FADE_STILL_MS = 120;
let focused = false, veilOffTimer = null, veilLiftTimer = null;
function focusMap(on){
  if(!map.getLayer('plan-veil')) return;
  clearTimeout(veilOffTimer);
  if(on){
    clearTimeout(veilLiftTimer);
    if(focused) return;
    focused = true;
    map.moveLayer('plan-veil'); FOCUS_LAYERS.forEach(id => { if(map.getLayer(id)) map.moveLayer(id); });
    map.setPaintProperty('plan-veil', 'background-opacity', VEIL_OPACITY);
    return;
  }
  if(!focused) return;
  veilOffTimer = setTimeout(() => {
    focused = false;
    map.setPaintProperty('plan-veil', 'background-opacity', 0);
    veilLiftTimer = setTimeout(() => { if(!focused) liftInfoLayersAboveRoutes(); }, VEIL_FADE_MS + 20);   // the longer of the two fades
  }, VEIL_HOLD_MS);
}
/* Rain drops along the stretch ridden during a rainy hour (owner 2026-10-18), bigger as it rains harder; null clears. */
function rainDrops(spans, mm){
  const src = map.getSource('plan-rain'); if(!src) return;
  const feats = [];
  if(Array.isArray(spans) && mm >= 0.1) for(const span of spans){
    const n = Math.max(2, Math.min(24, Math.round((span[1] - span[0]) / 800)));
    for(let i = 0; i < n; i++){ const p = pointAt(ride.legs, span[0] + (i + 0.5) * (span[1] - span[0]) / n); if(p) feats.push({ type: 'Feature', properties: { mm }, geometry: { type: 'Point', coordinates: p } }); }
  }
  src.setData({ type: 'FeatureCollection', features: feats });
}
/* One hour of the chart on the map: where the rider is then, lit; its wind as arrows; its rain as drops. */
function lightHour(hr){
  const h = forecast && Array.isArray(forecast.hours) ? forecast.hours[hr] : null;
  const day = rideTime(dayLegs(), ride.bike), total = figures(ride.legs).distM;
  const span = h && day ? hourSpan(startMin(), day.seconds, total, hr) : null;
  const src = map.getSource('plan-hl');
  if(src) src.setData({ type: 'FeatureCollection', features: span ? [{ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: sliceAlong(ride.legs, span[0], span[1]) } }] : [] });
  if(span && map.getLayer('plan-hl')) map.setPaintProperty('plan-hl', 'line-color', '#FF5A1F');
  windArrows(h ? h.fromDeg : null, h ? h.windKmh : null);
  rainDrops(span ? [span] : null, h ? h.rainMm : 0);
  focusMap(!!h);
}
function lightPart(cls){
  focusMap(!!cls);
  windArrows(...(cls && cls.startsWith('wind-') ? ridingWind() : [null, null]));
  if(cls === 'rain'){ const n = dayNow(); rainDrops(partSpans('rain'), n.fc ? n.fc.rainMm : 0); } else rainDrops(null, 0);
  const src = map.getSource('plan-hl'); if(!src) return;
  const lines = cls ? partSpans(cls).map(([a, b]) => sliceAlong(ride.legs, a, b)).filter(l => l.length >= 2) : [];
  src.setData({ type: 'FeatureCollection', features: lines.map(c => ({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: c } })) });
  if(cls && map.getLayer('plan-hl')) map.setPaintProperty('plan-hl', 'line-color', MAP_PART[cls] || '#FF5A1F');
}
/* One listener for every stat: an element with data-part lights its stretches while pointed at or focused. */
function wirePartHover(root){
  if(!root) return;
  const on = e => { const el = e.target.closest && e.target.closest('[data-part]'); if(el && root.contains(el)) lightPart(el.dataset.part); };
  const off = e => { const to = e.relatedTarget; if(!(to && to.closest && to.closest('[data-part]') && root.contains(to))) lightPart(null); };
  root.addEventListener('mouseover', on); root.addEventListener('focusin', on);
  root.addEventListener('mouseout', off); root.addEventListener('focusout', off);
}
let dayUnit = 'time';
/* What OpenStreetMap records along the ride (route-planner.md section 4.4a, owner 2026-10-19): the smoothness, the
   tracks by grade and the speed limits, in distance, each line only where something is recorded. Each line lights
   its stretches on the map when pointed at or focused, as the surface lines do (`sm:good`, `tt:grade3`, `sp:50`). */
function speedText(k){
  const t = P().detail || {};
  if(k === 'unset') return t.unset || 'no limit recorded';
  return uDistUnit() === 'mi' ? Math.round(Number(k) / 1.609344 / 5) * 5 + ' mph' : k + ' km/h';
}
function renderDetail(){
  const host = $('planDetail'); if(!host) return;
  const t = P().detail || {}, d = roadDetail(ride.legs);
  const row = (kind, head, rows, label) => rows.length ? `<h5>${escPend(head)}</h5><ul class="cc-plan-daylegend">${rows.map(([k, m]) => `<li data-part="${kind}:${k}" tabindex="0">${escPend(label(k))} · ${escPend(partDist(m))}</li>`).join('')}</ul>` : '';
  const speeds = d.speeds.some(([k]) => k !== 'unset') ? d.speeds : [];
  const html = row('sm', t.smooth || 'Smoothness', d.smoothness, k => (t.sm || {})[k] || k)
    + row('tt', t.track || 'Tracks', d.tracktype, k => (t.tt || {})[k] || k)
    + row('sp', t.speed || 'Speed limit', speeds, speedText);
  host.innerHTML = html ? html + `<p class="cc-plan-detailnote">${escPend(t.h || 'From OpenStreetMap, where it is recorded')}</p>` : '';
  host.hidden = !html;
}
function renderDayGraphic(day){
  const box = $('planDayFx'), host = $('planDaySvg'); if(!box || !host) return;
  renderDetail();
  const byDist = dayUnit === 'dist';
  const src = day ? (byDist ? day.metres : day.parts) : null;
  const rows = src ? [surfaceBars(src), gradientBars(src)].filter(r => r.length) : [];
  if(!rows.length){ box.hidden = true; return; }
  box.hidden = false;
  const value = b => byDist ? partDist(b.seconds) : partText(b.seconds);   // barsOf calls the amount `seconds` whatever its unit
  host.innerHTML = rows.map(bars => {
    let x = 0;
    const rects = bars.map(b => { const w = 100 * b.share, r = `<rect x="${x.toFixed(2)}" y="0" width="${Math.max(w, 0.2).toFixed(2)}" height="10" fill="${partFill(b.cls)}" data-part="${b.cls}"><title>${escPend(partLabel(b.cls))} · ${escPend(value(b))}</title></rect>`; x += w; return r; }).join('');
    const legend = bars.map(b => `<li data-part="${b.cls}" tabindex="0"><i style="${partSwatch(b.cls)}"></i>${escPend(partLabel(b.cls))} · ${escPend(value(b))}</li>`).join('');
    return `<svg viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">${bars.some(b => DASHED.has(b.cls)) ? DASH_DEFS : ''}${rects}</svg><ul class="cc-plan-daylegend">${legend}</ul>`;
  }).join('');
}
/* The day of the ride: the date field, today by default, kept with the draft only. */
function todayIso(){ const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
/* The day on its button in the page's language, "Sat 17 Oct 2026", as the field writes the browser's order. */
function paintDate(){
  const b = $('planDateShow'); if(!b) return;
  const d = new Date(rideDate() + 'T12:00:00');
  b.textContent = d.toLocaleDateString(document.documentElement.lang || 'en', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
}
function rideDate(){ const i = $('planDate'); const v = i ? i.value : ''; return /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : todayIso(); }
/* The start time, minutes after midnight on the ride's day; a ride's start and finish read in the browser's clock, as the
   sunrise and sunset do. */
const hhmm = m => String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
function startMin(){ const v = Number($('planStartTime') && $('planStartTime').value); return Number.isFinite(v) && v >= 0 && v < 1440 ? v : defaultStartMin(null); }
function startAt(){ const m = startMin(); return new Date(rideDate() + 'T' + hhmm(m) + ':00'); }
function nowMinToday(){ const d = new Date(); return d.getHours() * 60 + d.getMinutes(); }
/* The half hours from 04:00 to 22:00, and the kept time when it is not one of them. */
function fillStart(keep){
  const sel = $('planStartTime'); if(!sel) return;
  const opts = []; for(let m = 4 * 60; m <= 22 * 60; m += 30) opts.push(m);
  if(Number.isFinite(keep) && !opts.includes(keep)){ opts.push(keep); opts.sort((a, b) => a - b); }
  sel.innerHTML = opts.map(m => `<option value="${m}">${hhmm(m)}</option>`).join('');
  sel.value = String(Number.isFinite(keep) ? keep : defaultStartMin(rideDate() === todayIso() ? nowMinToday() : null));
}
function clockText(d){ return d.toLocaleTimeString(document.documentElement.lang || 'en', { hour: '2-digit', minute: '2-digit', hour12: false }); }
const DIRS = ['n', 'ne', 'e', 'se', 's', 'sw', 'w', 'nw'];
function dirWord(deg){ const k = DIRS[Math.round(((deg % 360) + 360) % 360 / 45) % 8]; return (P().dirs || {})[k] || k.toUpperCase(); }
/* What makes it tough (route-planner.md section 4.4): one line per factor past its baseline (TOUGHER in plan-model.js),
   "Nothing out of the ordinary" when none is. */
function renderTougher(day, f){
  const grp = $('planTougherGrp'), ul = $('planTougher'); if(!grp || !ul) return;
  if(!day){ grp.hidden = true; renderWeather(null); return; }
  const start = ride.stops[0];
  const fc = forecast && forecast.date === rideDate() && forecastPoint(ride.legs) ? rideHours(forecast, startMin(), day.seconds) : null;
  // The rider's average pace: the wind chill on the bike counts the air the rider rides into (route-planner.md section 4.4).
  const feel = rideFeel(fc, day.seconds > 0 ? f.distM / 1000 / (day.seconds / 3600) : 0);
  const waterKm = alongRows ? waterGap(nearRows().concat(stopRows(ride.stops, ride.legs)), f.distM) : null;
  const busyM = ride.legs.reduce((t, l) => t + (l && l.routed && l.surfaces ? (l.surfaces.busy || 0) : 0), 0);
  const found = tougherLines({
    distM: f.distM, steep: steepestKm(ride.legs), climb: longestClimb(ride.legs), roughM: day.metres.rough, waterKm, busyM,
    sun: start ? sunTimes(start.lat, start.lng, rideDate()) : null, rideS: day.seconds, startAt: startAt(), forecast: fc, feel,
  });
  const lines = [];
  let waterAt = -1;
  const parts = [];
  for(const x of found){
    const before = lines.length;
    if(x.kind === 'long') lines.push(tpl(P().longDay || '{d}: a long day', { d: uKm(x.distM / 1000, 0) }));
    else if(x.kind === 'steep') lines.push(tpl(P().steepest || 'Steepest km: {g} % at km {at}', { g: x.grad.toFixed(0), at: x.atKm.toFixed(0) }));
    else if(x.kind === 'climb') lines.push(tpl(P().longestClimb || 'Longest climb: {d}, {m} up, {g} %', { d: uKm(x.km, 1), m: uElev(x.upM), g: x.grad.toFixed(1) }));
    else if(x.kind === 'rough') lines.push(tpl(P().rough || '{d} of rough surface, cobbles and the like', { d: uKm(x.roughM / 1000, 0) }));
    else if(x.kind === 'water'){ waterAt = lines.length; lines.push(tpl(P().waterGap || '{d} without water or food along the way', { d: uKm(x.km, 0) })); }
    else if(x.kind === 'busy') lines.push(tpl(P().busy || '{d} on busy roads without a cycle lane', { d: fmtKm(x.busyM) }));
    else if(x.kind === 'daylight'){
      lines.push(tpl(P().daylight || 'Daylight {d}, {from} to {to}', { d: durationText(x.sun.daylightS, durWords(), 1), from: clockText(x.sun.sunrise), to: clockText(x.sun.sunset) }));
      if(x.over === 'over') lines.push(P().daylightShort || 'The riding time is longer than the daylight.');
      else if(x.over === 'darkStart') lines.push(tpl(P().darkStart || 'Sunrise is at {t}: you start before it.', { t: clockText(x.sun.sunrise) }));
      else if(x.over === 'darkEnd') lines.push(tpl(P().darkEnd || 'Sunset is at {t}: you finish after it.', { t: clockText(x.sun.sunset) }));
      else if(x.over === 'tight' && x.arrive) lines.push(tpl(P().darkTight || 'Sunset is at {t}: you finish less than an hour before it.', { t: clockText(x.sun.sunset) }));
      else if(x.over === 'tight') lines.push(P().daylightTight || 'The riding time leaves less than an hour of daylight to spare.');
    }
    else if(x.kind === 'wind') lines.push(windText(x.windKmh, x.gustKmh, x.fromDeg));
    else if(x.kind === 'heat') lines.push(x.feelC != null ? humidText(x.tempC, x.humidity, x.feelC) : heatText(x.tempC));
    else if(x.kind === 'cold') lines.push(coldText(x.feelC));
    else if(x.kind === 'ice') lines.push(iceText(x.hour, x.why));
    else if(x.kind === 'rain') lines.push(rainText(x.mm));
    for(let i = before; i < lines.length; i++) parts[i] = TOUGH_PART[x.kind] || null;
  }
  renderWeather(fc, new Set(found.map(x => x.kind)), feel);
  // The credit names Open-Meteo, a link to its own page for the same rounded point and day; it closes the weather list.
  let credit = '';
  if(fc){
    const href = forecastPageUrl(fc);
    const source = href ? `<a href="${escPend(href)}" target="_blank" rel="noopener noreferrer">Open-Meteo</a>` : 'Open-Meteo';
    // With the hours, the credit says which hours the figures are for: the hours ridden.
    const words = (Number.isFinite(fc.fromH) ? tpl(P().forecastHours || 'Forecast for {from} to {to}: {source}', { from: hhmm(fc.fromH * 60), to: hhmm(Math.min(24, fc.toH + 1) * 60), source: '{source}' }) : (P().forecastBy || 'Forecast: {source}')) + ' · ' + leadText(fc.date);
    credit = `<li class="cc-plan-credit">${escPend(words).replace('{source}', source)}</li>`;
  }
  // The water-gap line (owner 2026-10-15): hovered or focused it shows its stretch on the map; its icon adds the nearest place for water or food.
  const label = P().fillWater || 'Add the nearest place for water or food';
  const body = lines.length ? lines.map((l, i) => i === waterAt
    ? `<li class="cc-plan-gap" tabindex="0" data-part="water">${escPend(l)} <button type="button" class="cc-info cc-plan-fillwater" data-plan-fill-water title="${escPend(label)}" aria-label="${escPend(label)}"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5c3 3.6 5.5 6.6 5.5 9.5a5.5 5.5 0 0 1-11 0c0-2.9 2.5-5.9 5.5-9.5z"/><path d="M12 10.5v5M9.5 13h5"/></svg></button></li>`
    : parts[i] ? `<li data-part="${parts[i]}" tabindex="0">${escPend(l)}</li>` : `<li>${escPend(l)}</li>`).join('') : `<li>${escPend(P().tougherNone || 'Nothing out of the ordinary')}</li>`;
  ul.innerHTML = body;
  const wx = $('planWeather'); if(wx) wx.insertAdjacentHTML('beforeend', credit);
  grp.hidden = false;
}
/* The forecast lines (route-planner.md section 4.4), worded once for both lists. */
function windText(kmh, gust, fromDeg){
  const w = windShares(ride.legs, fromDeg), v = { s: Math.round(kmh), g: Math.round(gust || 0), dir: dirWord(fromDeg), head: fmtKm(w.headM), tail: fmtKm(w.tailM) };
  return Number.isFinite(gust) ? tpl(P().windGusts || 'Wind {s} km/h from the {dir}, gusts to {g} km/h: {head} into it, {tail} with it', v) : tpl(P().wind || 'Wind {s} km/h from the {dir}: {head} into it, {tail} with it', v);
}
/* How far ahead the day is, in plain facts (route-planner.md section 4.4): today, tomorrow, in 9 days; from a week ahead
   the credit adds that forecasts that far ahead often change. No judgement of its own on nearer days. */
const LEAD_FAR_DAYS = 7;
function leadText(date){
  const days = Math.round((new Date(date + 'T12:00:00') - new Date(todayIso() + 'T12:00:00')) / 86400000);
  const when = days <= 0 ? (P().leadToday || 'today') : days === 1 ? (P().leadTomorrow || 'tomorrow') : tpl(P().leadDays || 'in {n} days', { n: days });
  return days >= LEAD_FAR_DAYS ? when + ', ' + (P().leadFar || 'forecasts this far ahead often change') : when;
}
function heatText(c){ return tpl(P().heat || 'Up to {t} °C', { t: Math.round(c) }); }
function humidText(t, h, f){ return tpl(P().heatHumid || 'Up to {t} °C with {h} % humidity: feels like {f} °C', { t: Math.round(t), h: Math.round(h), f: Math.round(f) }); }
function coldText(f){ return tpl(P().coldFeel || 'Feels like {t} °C on the bike, riding into the wind', { t: Math.round(f) }); }
function iceText(hour, why){ const w = { freezing: P().iceFreezing || 'freezing rain', snow: P().iceSnow || 'snow', frost: P().iceFrost || 'frost on damp roads' }; return tpl(P().ice || 'Chance of ice from {h}: {why}', { h: String(hour).padStart(2, '0') + ':00', why: w[why] || why }); }
function rainText(mm){ return tpl(P().rain || '{r} mm of rain', { r: mm < 1 ? mm.toFixed(1) : Math.round(mm) }); }
/* The weather of the hours ridden that is not already a line of what makes it tough, so each fact shows once:
   the temperature, the wind and the rain, "No rain" on a dry ride. Hidden without a forecast. */
function renderWeather(fc, tough, feel){
  const grp = $('planWeatherGrp'), ul = $('planWeather'); if(!grp || !ul) return;
  if(!fc){ grp.hidden = true; ul.innerHTML = ''; const w = $('planWatch'); if(w) w.innerHTML = ''; return; }
  renderWatch(fc, feel || {});
  // The words stay for a screen reader; the eye reads the chart (owner 2026-10-17: "graphs instead of dry text").
  const lines = [];
  if(!tough.has('heat')) lines.push(heatText(fc.tempMaxC));
  if(!tough.has('wind')) lines.push(windText(fc.windKmh, fc.gustKmh, fc.windFromDeg));
  if(!tough.has('rain')) lines.push(fc.rainMm >= 0.1 ? rainText(fc.rainMm) : (P().dry || 'No rain'));
  const words = lines.map(l => `<li class="cc-wx-words">${escPend(l)}</li>`).join('');
  // How it feels, in words the eye reads too, when the cold or the humidity matters and it is not a line above already.
  const f = feel || {}, felt = [];
  if(f.cold && !tough.has('cold') && Math.round(f.cold.feelC) < Math.round(f.cold.tempC)) felt.push(coldText(f.cold.feelC));
  if(f.hot && !tough.has('heat') && Math.round(f.hot.feelC) > Math.round(f.hot.tempC)) felt.push(humidText(f.hot.tempC, f.hot.humidity, f.hot.feelC));
  const chart = Array.isArray(fc.hours) && fc.hours.length === 24 ? `<li class="cc-wx">${weatherSvg(fc, f.ice)}<div class="cc-wx-tip" hidden></div></li>` : '';
  ul.innerHTML = chart + felt.map(l => `<li class="cc-wx-words">${escPend(l)}</li>`).join('') + windShareHtml(fc.windFromDeg) + (chart ? words : lines.map(l => `<li>${escPend(l)}</li>`).join(''));
  grp.hidden = false;
}
/* What the planner watches in the hours ridden (route-planner.md section 4.4, owner 2026-10-17): the wind chill, the
   humidity in the heat and a chance of ice, each an icon that is struck through while there is nothing to say, so a rider
   knows it would warn; lit, it says its figure, and its title the whole line. */
const WATCH_ICON = {
  chill: '<path d="M9 14.5V5a2 2 0 0 1 4 0v9.5a3.5 3.5 0 1 1-4 0z"/><path d="M15.5 7.5h5M15.5 11h3.5"/>',
  humid: '<path d="M12 3.5c3 3.6 5.5 6.6 5.5 9.5a5.5 5.5 0 0 1-11 0c0-2.9 2.5-5.9 5.5-9.5z"/><path d="M10 16.2l4-5"/><circle cx="10.2" cy="11.6" r=".8"/><circle cx="13.8" cy="15.6" r=".8"/>',
  ice: '<path d="M12 3v18M4.2 7.5l15.6 9M4.2 16.5l15.6-9"/><path d="M9.5 4.5 12 6.5l2.5-2M9.5 19.5 12 17.5l2.5 2"/>',
};
function renderWatch(fc, feel){
  const box = $('planWatch'); if(!box) return;
  const hours = Array.isArray(fc.hours) && fc.hours.length === 24;
  const item = (k, label, on, value, title) => `<span class="cc-wx-w${on ? ' on' : ''}" title="${escPend(title)}"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${WATCH_ICON[k]}${on ? '' : '<path class="cc-wx-strike" d="M4 20 20 4"/>'}</svg><span>${escPend(label)}${on ? ` <b>${escPend(value)}</b>` : ''}</span></span>`;
  const none = P().watchNoHours || 'Needs the forecast by the hour';
  const cold = feel.cold && Math.round(feel.cold.feelC) < Math.round(feel.cold.tempC) ? feel.cold : null;
  const hot = feel.hot && Math.round(feel.hot.feelC) > Math.round(feel.hot.tempC) ? feel.hot : null;
  box.innerHTML = item('chill', P().watchChill || 'Wind chill', !!cold, cold ? `${Math.round(cold.feelC)} °C` : '', cold ? coldText(cold.feelC) : (hours ? (P().watchChillOff || 'Shown at 10 °C or colder: not on this ride') : none))
    + item('humid', P().watchHumid || 'Humidity', !!hot, hot ? `${Math.round(hot.feelC)} °C` : '', hot ? humidText(hot.tempC, hot.humidity, hot.feelC) : (hours ? (P().watchHumidOff || 'Shown at 27 °C or warmer: not on this ride') : none))
    + item('ice', P().watchIce || 'Ice', !!feel.ice, feel.ice ? String(feel.ice.hour).padStart(2, '0') + ':00' : '', feel.ice ? iceText(feel.ice.hour, feel.ice.why) : (hours ? (P().watchIceOff || 'No chance of ice on this ride') : none));
}
/* The day by the hour (route-planner.md section 4.4): three small charts on one hour axis, never two scales on one,
   temperature as a line, wind as columns with their gusts and an arrow where it blows, rain as columns; the hours
   ridden are a band across all three. From 06:00 to 21:00, wider when the ride is. */
function weatherSvg(fc, ice){
  const hs = fc.hours, rideFrom = Number.isFinite(fc.fromH) ? fc.fromH : null, rideTo = Number.isFinite(fc.toH) ? fc.toH : null;
  const lo = Math.min(6, rideFrom == null ? 6 : rideFrom), hi = Math.max(21, rideTo == null ? 20 : rideTo + 1), n = hi - lo;
  const W = 300, L = 34, R = 6, slot = (W - L - R) / n, bw = Math.max(3, Math.min(14, slot - 2));
  const cx = h => L + (h - lo + 0.5) * slot, x0 = h => L + (h - lo) * slot;
  const win = hs.slice(lo, hi);
  const f1 = v => Math.round(v), up = P().wxKmh || 'km/h';
  // Temperature, 16 to 52.
  const tMin = Math.min(...win.map(h => h.tempC)), tMax = Math.max(...win.map(h => h.tempC)), tSpan = Math.max(tMax - tMin, 4);
  const ty = t => 52 - (t - tMin) / tSpan * 34;
  const tPath = win.map((h, i) => `${i ? 'L' : 'M'}${cx(lo + i).toFixed(1)},${ty(h.tempC).toFixed(1)}`).join('');
  const tHi = win.reduce((b, h, i) => h.tempC > win[b].tempC ? i : b, 0);
  // Wind, columns from 104 up to 70; gusts a tick; the arrow points where the wind blows.
  const wMax = Math.max(10, ...win.map(h => Math.max(h.windKmh, h.gustKmh || 0)));
  const wy = v => 104 - v / wMax * 34;
  const col = (x, base, top, cls) => { const hgt = base - top; if(hgt < 0.5) return ''; const r = Math.min(3, bw / 2, hgt); return `<path class="${cls}" d="M${x.toFixed(1)},${base}V${(top + r).toFixed(1)}Q${x.toFixed(1)},${top.toFixed(1)} ${(x + r).toFixed(1)},${top.toFixed(1)}H${(x + bw - r).toFixed(1)}Q${(x + bw).toFixed(1)},${top.toFixed(1)} ${(x + bw).toFixed(1)},${(top + r).toFixed(1)}V${base}Z"/>`; };
  const every = slot >= 14 ? 1 : 2;
  let wind = '', rain = '', arrows = '';
  const wHi = win.reduce((b, h, i) => h.windKmh > win[b].windKmh ? i : b, 0);
  // Rain, columns from 140 up to 116.
  const rMax = Math.max(2, ...win.map(h => h.rainMm)), ry = v => 140 - v / rMax * 24;
  const rHi = win.reduce((b, h, i) => h.rainMm > win[b].rainMm ? i : b, 0);
  win.forEach((h, i) => {
    const hr = lo + i, x = cx(hr) - bw / 2;
    wind += col(x, 104, wy(h.windKmh), 'wx-wind');
    if(Number.isFinite(h.gustKmh) && h.gustKmh > h.windKmh) wind += `<line class="wx-gust" x1="${x.toFixed(1)}" x2="${(x + bw).toFixed(1)}" y1="${wy(h.gustKmh).toFixed(1)}" y2="${wy(h.gustKmh).toFixed(1)}"/>`;
    if(i % every === 0) arrows += `<path class="wx-arrow" transform="translate(${cx(hr).toFixed(1)},62) rotate(${(h.fromDeg + 180) % 360})" d="M0,-4.5L3,2.5L0,1L-3,2.5Z"/>`;
    rain += col(x, 140, ry(h.rainMm), 'wx-rain');
  });
  let band = '';
  if(rideFrom != null && rideTo != null){
    const bx = x0(rideFrom), bwid = (rideTo + 1 - rideFrom) * slot;
    band = `<rect class="wx-band" x="${bx.toFixed(1)}" y="12" width="${bwid.toFixed(1)}" height="132" rx="3"/><text class="wx-band-t" x="${(bx + bwid / 2).toFixed(1)}" y="9" text-anchor="middle">${escPend(P().wxRide || 'your ride')}</text>`;
  }
  let ticks = '';
  for(let hr = lo; hr <= hi; hr++) if(hr % 3 === 0) ticks += `<text class="wx-ax" x="${x0(hr).toFixed(1)}" y="157" text-anchor="middle">${String(hr % 24).padStart(2, '0')}:00</text>`;
  const hits = win.map((h, i) => `<rect class="wx-hit" data-h="${lo + i}" x="${x0(lo + i).toFixed(1)}" y="12" width="${slot.toFixed(1)}" height="132"/>`).join('');
  const lab = (x, y, t, a) => `<text class="wx-v" x="${x.toFixed(1)}" y="${y.toFixed(1)}" text-anchor="${a || 'middle'}">${t}</text>`;
  const rainLabel = win[rHi].rainMm >= 0.1 ? lab(cx(lo + rHi), ry(win[rHi].rainMm) - 3, win[rHi].rainMm < 1 ? win[rHi].rainMm.toFixed(1) : f1(win[rHi].rainMm)) : '';
  return `<svg class="cc-wx-svg" viewBox="0 0 ${W} 162" role="img" aria-label="${escPend(P().wxAria || 'Weather by the hour: temperature, wind and rain')}">${band}`
    + `<line class="wx-base" x1="${L}" x2="${W - R}" y1="104" y2="104"/><line class="wx-base" x1="${L}" x2="${W - R}" y1="140" y2="140"/>`
    + `<text class="wx-ax" x="2" y="38">°C</text><text class="wx-ax" x="2" y="92">${escPend(up)}</text><text class="wx-ax" x="2" y="132">mm</text>`
    + `<path class="wx-temp" d="${tPath}"/><circle class="wx-dot" cx="${cx(lo + tHi).toFixed(1)}" cy="${ty(win[tHi].tempC).toFixed(1)}" r="4"/>`
    + lab(cx(lo + tHi), ty(win[tHi].tempC) - 7, `${f1(win[tHi].tempC)}°`)
    + (ice && ice.hour >= lo && ice.hour < hi ? `<g class="wx-ice" transform="translate(${cx(ice.hour).toFixed(1)},${(ty(hs[ice.hour].tempC) + 9).toFixed(1)})"><path d="M0,-5V5M-4.3,-2.5L4.3,2.5M-4.3,2.5L4.3,-2.5"/></g>` : '')
    + wind + lab(cx(lo + wHi), Math.min(wy(win[wHi].windKmh), Number.isFinite(win[wHi].gustKmh) ? wy(win[wHi].gustKmh) : 999) - 3, f1(win[wHi].windKmh)) + arrows + rain + rainLabel + ticks
    + `<line class="wx-x" x1="0" x2="0" y1="12" y2="144" visibility="hidden"/>${hits}</svg>`;
}
/* Into the wind, across it and with it, as one bar along the ride; each part lights its stretches on the map. */
function windShareHtml(fromDeg){
  const w = windShares(ride.legs, fromDeg), side = Math.max(0, w.totalM - w.headM - w.tailM);
  if(!(w.totalM > 0)) return '';
  const parts = [['into', w.headM, P().wxInto || 'into the wind'], ['side', side, P().wxSide || 'crosswind'], ['with', w.tailM, P().wxWith || 'with the wind']].filter(p => p[1] > 0);
  let x = 0;
  const rects = parts.map(([k, m]) => { const wd = 100 * m / w.totalM, r = `<rect class="wx-share-${k}" x="${x.toFixed(2)}" y="0" width="${Math.max(wd - 0.6, 0.2).toFixed(2)}" height="8" data-part="wind-${k}"/>`; x += wd; return r; }).join('');
  const legend = parts.map(([k, m, t]) => `<span data-part="wind-${k}" tabindex="0"><i class="wx-share-${k}"></i>${escPend(t)} · ${escPend(fmtKm(m))}</span>`).join('');
  return `<li class="cc-wx-share"><svg viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true">${rects}</svg><div class="cc-wx-share-l">${legend}</div></li>`;
}
/* The chart's hover: a line through the hour and its figures in words. */
function wireWeatherHover(root){
  if(!root) return;
  root.addEventListener('mousemove', e => {
    const hit = e.target.closest && e.target.closest('.wx-hit'); const box = root.querySelector('.cc-wx'); if(!box) return;
    const tip = box.querySelector('.cc-wx-tip'), cross = box.querySelector('.wx-x');
    if(!hit || !forecast || !Array.isArray(forecast.hours)){ if(tip) tip.hidden = true; if(cross) cross.setAttribute('visibility', 'hidden'); if(!(e.target.closest && e.target.closest('[data-part]'))) lightHour(null); return; }
    const hr = Number(hit.dataset.h), h = forecast.hours[hr]; if(!h) return;
    const mid = Number(hit.getAttribute('x')) + Number(hit.getAttribute('width')) / 2;
    cross.setAttribute('x1', mid); cross.setAttribute('x2', mid); cross.setAttribute('visibility', 'visible');
    lightHour(hr);
    tip.textContent = tpl(P().wxTip || '{h}: {t} °C, wind {s} {u} from the {dir}, {r} mm of rain', { h: String(hr).padStart(2, '0') + ':00', t: Math.round(h.tempC), s: Math.round(h.windKmh), u: P().wxKmh || 'km/h', dir: dirWord(h.fromDeg), r: h.rainMm < 1 ? h.rainMm.toFixed(1) : Math.round(h.rainMm) });
    tip.hidden = false;
    const svg = box.querySelector('svg'), k = svg.clientWidth / 300;
    tip.style.left = Math.max(0, Math.min(svg.clientWidth - tip.offsetWidth, mid * k - tip.offsetWidth / 2)) + 'px';
  });
  root.addEventListener('mouseleave', () => { const tip = root.querySelector('.cc-wx-tip'), cross = root.querySelector('.wx-x'); if(tip) tip.hidden = true; if(cross) cross.setAttribute('visibility', 'hidden'); lightHour(null); });
}
/* The water stop nearest the middle of the longest stretch without water or food (route-planner.md section 4.4), from our
   own data through /map/coverage/nearby, in at its km along as Add to ride puts a place. */
/* The longest stretch without water or food on the map while its line in the panel is hovered or focused (owner 2026-10-15). */
function showGap(on){
  const src = map.getSource('plan-gap'); if(!src) return;
  const span = on && alongRows ? waterGapSpan(nearRows().concat(stopRows(ride.stops, ride.legs)), figures(ride.legs).distM) : null;
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
  const f = figures(ride.legs), gap = waterGap(nearRows().concat(stopRows(ride.stops, ride.legs)), f.distM);
  if(gap != null && gap < p.beforeKm - 0.5){ setStatus(tpl(P().fillWaterDone || 'Added {name}. The longest stretch without water or food is now {d}.', { name: p.name, d: uKm(gap, 0) })); return; }
  const prev = history.discard(); if(prev) applyStep(prev);
  notice(P().fillWaterH || 'No water stop added', tpl(P().fillWaterUndone || '{name}, {kind}, is {off} from your ride, level with {at} along it. The way there changes the route, so the dry stretch would not get shorter. It is not added.', fillWhere(p.stop)), p.stop);
}
async function fillWater(){
  if(!alongRows || !CC_PLAN.nearby) return;
  const f = figures(ride.legs), span = waterGapSpan(nearRows().concat(stopRows(ride.stops, ride.legs)), f.distM); if(!span) return;
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
/* The forecast for the ride's date near the ride's middle (forecastPoint), through our server, once the ride has been
   still for WEATHER_SETTLE_MS; one ask per 0.1° cell and date. A ride under 20 km asks nothing and shows no forecast. */
const WEATHER_SETTLE_MS = 3000;
function scheduleWeather(){ clearTimeout(weatherTimer); weatherTimer = setTimeout(fetchWeather, WEATHER_SETTLE_MS); }
async function fetchWeather(){
  if(asleep) return;   // a sleeping ride asks nothing (sleepRide())
  if(!CC_PLAN.weather || ride.stops.length < 2 || unrouted()) return;
  const at = forecastPoint(ride.legs);
  if(!at){
    if(forecast || forecastKey){ forecast = null; forecastKey = ''; renderFigures(); }
    return;
  }
  const date = rideDate(), key = `${at[1].toFixed(1)},${at[0].toFixed(1)},${date}`;
  if(forecastKey === key) return;
  let got = weatherCache.get(key);
  if(got === undefined){
    try {
      const r = await fetch(CC_PLAN.weather, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CC-Token': CC_PLAN.weatherToken }, body: JSON.stringify({ lat: at[1], lng: at[0], date }) });
      got = r.ok ? ((await r.json()).forecast || null) : null;
    } catch(e){ got = null; }
    weatherCache.set(key, got);
  }
  // The ride may have changed while the answer travelled: an answer for a ride no longer drawn is dropped.
  const now = forecastPoint(ride.legs);
  if(!now || `${now[1].toFixed(1)},${now[0].toFixed(1)},${rideDate()}` !== key) return;
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
  // L leaves room for the height scale's numbers (owner 2026-10-17: a scale on the left with thin lines).
  const L = 38, R = 6, T = 14, B = 16, total = f.distM;
  let minH = Infinity, maxH = -Infinity;
  for(const b of bins){ minH = Math.min(minH, b.h0, b.h1); maxH = Math.max(maxH, b.h0, b.h1); }
  const span = Math.max(maxH - minH, 40);
  const y = h => T + (H - T - B) * (1 - (h - minH) / span), x = d => L + (W - L - R) * d / total, base = H - B;
  let svg = '';
  // The height scale: round heights in the rider's unit, a faint line across the profile at each, its number at the
  // left, the unit written once above them.
  const perM = uElevValue(1) || 1;
  const lines = heightTicks(minH * perM, (minH + span) * perM).map(v => v / perM).filter(h => y(h) >= T - 0.5 && y(h) <= base + 0.5);
  let grid = '';
  for(const h of lines) grid += `<line class="hgrid" x1="${L}" x2="${W - R}" y1="${y(h).toFixed(1)}" y2="${y(h).toFixed(1)}"/><text x="${L - 4}" y="${(y(h) + 3).toFixed(1)}" text-anchor="end">${Math.round(h * perM).toLocaleString(document.documentElement.lang || undefined)}</text>`;
  grid += `<text x="${L - 4}" y="${(T - 4).toFixed(1)}" text-anchor="end">${escPend(uElevUnit())}</text>`;
  for(const b of bins){
    svg += `<polygon points="${x(b.d0).toFixed(1)},${base} ${x(b.d0).toFixed(1)},${y(b.h0).toFixed(1)} ${x(b.d1).toFixed(1)},${y(b.h1).toFixed(1)} ${x(b.d1).toFixed(1)},${base}" fill="${gradColor(b.grad)}" fill-opacity=".92" stroke="#fff" stroke-width=".4"><title>${uKm(b.d0 / 1000)} to ${uKm(b.d1 / 1000)} · ${b.grad.toFixed(0)}%</title></polygon>`;
  }
  svg += grid;
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
/* A place without a name is its group and the street the ride takes there, "Water & food · Dorpsstraat", so two of them
   in one list tell apart (route-planner.md section 5); the group alone where the street has no name. */
function unnamedTitle(label, alongKm){
  const road = streetAt(ride.legs, alongKm * 1000);
  return road ? tpl(P().unnamedOn || '{kind} · {road}', { kind: label, road }) : label;
}
/* "0.8 km along · 81 m off" with each half kept whole, so a narrow row breaks at the dot and never under its button. */
function kmOffHtml(t, a, b){ return tpl(t, { a, b }).split(' · ').map(p => `<span>${escPend(p)}</span>`).join(' · '); }
async function renderAlong(){
  // A sleeping ride asks nothing and moves no scope (owner 2026-10-18: Search & region showed North Holland, and the
  // refresh after its camera move put Wallonia and the ride back two seconds later).
  if(asleep) return;
  const g = $('planAlongGrp'), ul = $('planAlong'); if(!g || !ul) return;
  const line = rideLine();
  if(line.length < 2 || unrouted()){ g.hidden = true; return; }
  const myGen = gen;
  const d = await fetchAlong(thinLine(line, ALONG_MAX_POINTS));
  if(myGen !== gen || !d) return;
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
      const nm = r.name || unnamedTitle(meta.label, r.alongKm);
      const place = JSON.stringify({ item: r.item, letter: r.letter, name: nm, lat: r.ll[0], lng: r.ll[1] });
      return `<li class="cc-plan-row"><button type="button" class="cc-near" data-plan-ll="${r.ll[0]},${r.ll[1]}" data-plan-key="${escPend(r.item)}"><span class="cc-near-nm">${escPend(nm)}</span><em>${kmOffHtml(D.kmOff || '{a} along · {b} off', uKm(r.alongKm), uM(r.distM))}</em></button><button type="button" class="cc-plan-add${inRide ? ' in' : ''}" data-plan-place="${escPend(place)}">${escPend(inRide ? (P().inRide || 'In your ride') : (P().addStop || 'Add as checkpoint'))}</button></li>`;
    };
    html += items.slice(0, ALONG_VISIBLE).map(row).join('');
    if(items.length > ALONG_VISIBLE) html += `<li class="cc-near-rest" id="${id}" hidden><ul class="cc-near-list">${items.slice(ALONG_VISIBLE).map(row).join('')}</ul></li>`;
  }
  if(!html) html = `<li class="cc-near-empty">${escPend(tpl(P().alongEmpty || 'Nothing the Commons knows within {d} of this ride yet.', { d: reachText(alongM) }))}</li>`;
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
  if(asleep) return;
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
    // A dragged checkpoint is a map point: it moves onto the road too (owner 2026-10-18).
    m.on('dragend', async () => { const ll = m.getLngLat(); const p = await snapPoint(ll.lat, ll.lng); remember(); const st = ride.stops[i]; if(!st) return; st.lng = p.lng; st.lat = p.lat; if(st.item){ st.item = null; st.letter = null; st.name = P().mapPoint || 'Map point'; } afterChange(); });
    markers.push(m);
  });
  joinEnds();
  sizeMarkers();
  if(!endsWired){ endsWired = true; map.on('zoomend', joinEnds); map.on('zoom', sizeMarkers); }
}
/* The checkpoints shrink as the map zooms out (owner 2026-10-18: "checkpoint numbers must be smaller in a zoomed out
   window"): full size from zoom MARKER_ZOOM[1], a size smaller from MARKER_ZOOM[0], the smallest below it. */
const MARKER_ZOOM = [9.5, 11.5];
function sizeMarkers(){
  const z = map.getZoom(), body = document.body;
  body.classList.toggle('cc-mk-s', z < MARKER_ZOOM[0]);
  body.classList.toggle('cc-mk-m', z >= MARKER_ZOOM[0] && z < MARKER_ZOOM[1]);
}
/* A round ride's start and finish meet on screen when the map is zoomed out: one marker then carries both, the start
   with the flag beside its S, and the finish comes back as the map zooms in (route-planner.md section 4.1). */
let endsWired = false;
const ENDS_JOIN_PX = 26;
function joinEnds(){
  const n = markers.length; if(n < 3) return;
  const a = markers[0], b = markers[n - 1], ea = a.getElement(), eb = b.getElement();
  const pa = map.project(a.getLngLat()), pb = map.project(b.getLngLat());
  const join = Math.hypot(pa.x - pb.x, pa.y - pb.y) < ENDS_JOIN_PX;
  if(join === ea.classList.contains('loop')) return;
  ea.classList.toggle('loop', join);
  ea.innerHTML = join ? `<span>S</span>${FINISH_FLAG}` : 'S';
  eb.style.visibility = join ? 'hidden' : '';
  const s0 = ride.stops[0], s1 = ride.stops[n - 1];
  if(s0 && s1) ea.title = (join ? s0.name + ' · ' + s1.name : s0.name) + '\n' + (P().removeHint || '');
}
function applyLineShown(){
  ['plan-casing', 'plan-line', 'plan-straight', 'plan-arrows'].forEach(id => { if(map.getLayer(id)) map.setLayoutProperty(id, 'visibility', lineShown ? 'visible' : 'none'); });
  const b = $('planLineToggle'); if(!b) return;
  const label = lineShown ? (P().lineHide || 'Hide the ride line') : (P().lineShow || 'Show the ride line');
  b.setAttribute('aria-pressed', lineShown ? 'true' : 'false'); b.title = label; b.setAttribute('aria-label', label);
  b.classList.toggle('off', !lineShown);
}
/* The harder the wind, the longer and the bigger its arrows (owner 2026-10-17, longer 2026-10-18): one arrow per
   band of the Beaufort scale, its shaft longer each band (WIND_ARROWS: from km/h, shaft px), and a size that grows
   with the wind within it, a breeze at 80 %, 20 km/h full size, a gale 1.4 times. */
const WIND_ARROWS = [[0, 6], [12, 14], [20, 24], [29, 34], [39, 46]];
const WIND_IMAGE = ['step', ['get', 'kmh'], 'plan-wind-arrow-0', ...WIND_ARROWS.slice(1).flatMap(([kmh], i) => [kmh, `plan-wind-arrow-${i + 1}`])];
const WIND_SCALE = ['interpolate', ['linear'], ['get', 'kmh'], 0, 0.8, 20, 1, 40, 1.2, 60, 1.4];
/* A rain drop: blue on a cream edge, drawn once per style. */
function addRainDropImage(){
  if(map.hasImage && map.hasImage('plan-rain-drop')) return;
  const S = 2, D = 22, c = document.createElement('canvas'); c.width = c.height = D * S;
  const x = c.getContext('2d'); if(!x) return;
  x.scale(S, S);
  x.beginPath(); x.moveTo(11, 2.5); x.bezierCurveTo(14, 7, 17.5, 10.5, 17.5, 13.5); x.arc(11, 13.5, 6.5, 0, Math.PI); x.bezierCurveTo(4.5, 10.5, 8, 7, 11, 2.5); x.closePath();
  x.fillStyle = '#3A6FC4'; x.strokeStyle = '#F3EBD8'; x.lineWidth = 2; x.stroke(); x.fill();
  map.addImage('plan-rain-drop', x.getImageData(0, 0, D * S, D * S), { pixelRatio: S });
}
/* The wind's arrows, one per band of WIND_ARROWS: dark on a cream edge, pointing up (north) before they are turned, the
   same head on a longer shaft each band; drawn once per style. */
function addWindArrowImages(){
  WIND_ARROWS.forEach(([, shaft], i) => {
    const id = `plan-wind-arrow-${i}`;
    if(map.hasImage && map.hasImage(id)) return;
    const S = 2, W = 26, H = 16 + shaft, c = document.createElement('canvas'); c.width = W * S; c.height = H * S;
    const x = c.getContext('2d'); if(!x) return;
    x.scale(S, S); x.lineJoin = 'round';
    x.beginPath(); x.moveTo(13, 2); x.lineTo(21, 14); x.lineTo(15.5, 12.5); x.lineTo(15.5, H - 2); x.lineTo(10.5, H - 2); x.lineTo(10.5, 12.5); x.lineTo(5, 14); x.closePath();
    x.fillStyle = '#1F2A44'; x.strokeStyle = '#F3EBD8'; x.lineWidth = 2.2; x.stroke(); x.fill();
    map.addImage(id, x.getImageData(0, 0, W * S, H * S), { pixelRatio: S });
  });
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
  // The stretches of the stat the rider points at (owner 2026-10-17), over the ride: a dark edge, the part's colour.
  map.addSource('plan-hl', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
  map.addLayer({ id: 'plan-gap', type: 'line', source: 'plan-gap', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#F5C518', 'line-opacity': 0.55, 'line-width': ['interpolate', ['linear'], ['zoom'], 10, 9, 18, 18] } });
  // Thin, so the surface band under the ride stays readable on both sides of it (owner 2026-10-15).
  map.addLayer({ id: 'plan-casing', type: 'line', source: 'plan', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#F3EBD8', 'line-width': ['interpolate', ['linear'], ['zoom'], 10, 3, 18, 4.5], 'line-opacity': .7 } });
  map.addLayer({ id: 'plan-line', type: 'line', source: 'plan', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#FF5A1F', 'line-width': ['interpolate', ['linear'], ['zoom'], 10, 1.8, 18, 3] } });
  map.addLayer({ id: 'plan-straight', type: 'line', source: 'plan-straight', paint: { 'line-color': '#E8A33D', 'line-width': 3, 'line-dasharray': [1.5, 1.5] } });
  // Which way the ride goes (owner 2026-10-15): chevrons along the routed line, from zoom 11.
  addArrowImage();
  map.addLayer({ id: 'plan-hl-casing', type: 'line', source: 'plan-hl', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#101E16', 'line-opacity': 0.6, 'line-width': ['interpolate', ['linear'], ['zoom'], 10, 7, 18, 11] } });
  map.addLayer({ id: 'plan-hl', type: 'line', source: 'plan-hl', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#FF5A1F', 'line-width': ['interpolate', ['linear'], ['zoom'], 10, 4, 18, 7] } });
  addWindArrowImages();
  addRainDropImage();
  // The veil the map steps back under while a stat or the weather is pointed at: the basemap's paper, most of the way.
  const still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  map.addLayer({ id: 'plan-veil', type: 'background', paint: { 'background-color': '#F3EBD8', 'background-opacity': 0, 'background-opacity-transition': { duration: still ? VEIL_FADE_STILL_MS : VEIL_FADE_MS, delay: 0 } } });
  map.addSource('plan-rain', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
  map.addLayer({ id: 'plan-rain', type: 'symbol', source: 'plan-rain', layout: { 'icon-image': 'plan-rain-drop', 'icon-size': ['interpolate', ['linear'], ['get', 'mm'], 0.1, 0.6, 1, 0.85, 4, 1.25], 'icon-allow-overlap': true, 'icon-ignore-placement': true } });
  map.addSource('plan-wind', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
  map.addLayer({ id: 'plan-wind', type: 'symbol', source: 'plan-wind', layout: { 'icon-image': WIND_IMAGE, 'icon-size': ['interpolate', ['linear'], ['zoom'], 8, ['*', 0.85, WIND_SCALE], 14, ['*', 1.25, WIND_SCALE]], 'icon-rotate': ['get', 'rot'], 'icon-rotation-alignment': 'map', 'icon-allow-overlap': true, 'icon-ignore-placement': true } });
  map.addLayer({ id: 'plan-arrows', type: 'symbol', source: 'plan', minzoom: 11, layout: { 'symbol-placement': 'line', 'symbol-spacing': 90, 'icon-image': 'plan-arrow', 'icon-size': ['interpolate', ['linear'], ['zoom'], 11, 0.75, 16, 1], 'icon-rotation-alignment': 'map', 'icon-allow-overlap': true, 'icon-ignore-placement': true } });
  // Into the one stacking order (map-and-search.md section 4.1): over every other line, under the icons.
  liftInfoLayersAboveRoutes();
  applyLineShown();
  drawRide();
}
/* A click on the map is a stop, also on a line or an area of ours (a route, a surface stretch, traffic; owner
   2026-10-17: the road under a surface line took no stop), whose own drawer stands aside (plannerTakesLineClick).
   A click on a point of ours (a pin, a coverage point) is not: that is the place, with its Add to ride. The
   basemap's own sources are remembered when the style loads (catalog-load.js); everything else is ours. */
/* Within LINE_HIT_PX of the drawn ride line (route-planner.md section 4.1): the ride's own layers only. */
const LINE_HIT_PX = 8;
let overLine = false;
function onRideLine(pt){
  if(ride.stops.length < 2 || !lineShown) return false;
  const layers = ['plan-line', 'plan-casing'].filter(id => map.getLayer(id));
  if(!layers.length) return false;
  return map.queryRenderedFeatures([[pt.x - LINE_HIT_PX, pt.y - LINE_HIT_PX], [pt.x + LINE_HIT_PX, pt.y + LINE_HIT_PX]], { layers }).length > 0;
}
function hitsFeature(e){
  const baseSources = window.__ccBaseSources;
  if(!baseSources) return false;
  return map.queryRenderedFeatures(e.point).some(f => f.source && !baseSources.has(f.source) && f.source !== 'plan' && f.source !== 'plan-straight' && !isLineOrArea(f));
}
function renderBar(){
  const bar = $('planBar'); if(!bar) return;
  const side = $('planSide');
  const show = !asleep && activePanel() !== 'plan' && ride.stops.length >= 2 && !(side && !side.hidden);
  bar.hidden = !show;
  if(show){ const day = rideTime(dayLegs(), ride.bike); $('planBarText').textContent = barText(figures(ride.legs), ride.stops.length, { km: fmtKm, m: uElev, stops: n => tpl(P().barStops || '{n} checkpoints', { n }) }, day ? hmText(day.seconds) : ''); }
}
/* ---------- the ride's details on the right (route-planner.md section 3, owner 2026-10-17) ----------
   A wide screen is wider than high: the details (#planFigsGrp) leave the panel for a side of their own on the right,
   with the ride bar's Open and Clear. A place's drawer takes that side while it is open; closed, the details come back.
   The side's close button puts them back in the panel until the planner is opened again. On a phone they stay in the
   panel's sheet. */
const WIDE = window.matchMedia('(min-width:821px)');
let sideClosed = false, figsHome = null, legendSync = null;
function spotOpen(){ const d = document.getElementById('drawer'); return !!d && d.classList.contains('open'); }
function syncSide(){
  const side = $('planSide'), body = $('planSideBody'), figs = $('planFigsGrp'), wrap = document.querySelector('.map-wrap');
  if(!side || !body || !figs) return;
  const on = WIDE.matches && !asleep && !sideClosed && ride.stops.length >= 2 && !spotOpen();
  if(!figsHome){ figsHome = document.createComment(' planFigsGrp '); figs.parentNode.insertBefore(figsHome, figs); }
  if(on && figs.parentNode !== body) body.insertBefore(figs, $('planSideKey'));
  else if(!on && figs.previousSibling !== figsHome) figsHome.parentNode.insertBefore(figs, figsHome.nextSibling);
  const x = $('planSideX'); if(x) x.hidden = !on;
  const back = $('planSideBack'); if(back) back.hidden = !(WIDE.matches && sideClosed && !asleep);
  if(legendSync) legendSync(on);   // the key docked in the side waits in its corner while the side is away
  if(on === !side.hidden) return;
  side.hidden = !on;
  if(wrap) wrap.classList.toggle('cc-side-on', on);
  renderBar();
  if(stripShown) renderProfile();   // the strip stops at the side: its drawing follows its width
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
    if(ride.stops.length) localStorage.setItem(DRAFT_KEY, JSON.stringify({ bike: ride.bike, stops: ride.stops, name: ride.name, date: rideDate(), start: startMin(), along: alongM, at: Date.now() }));
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
/* Open a GPX (route-planner.md section 6.1): the planner's own ride file or another app's, read in this browser; only
   its track goes to our server, to put the ride on the same roads (routeMatch()), and is not kept there.
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
/* The file's name, bike and stops go on the map, its legs on the file's own roads (routeMatch()), any leg without
   them routed; undo goes back to the ride before. */
async function applyRideFile(r, fileName){
  remember(true);
  if(r.bike && BIKES.includes(r.bike)){ ride.bike = r.bike; if(!document.querySelector(`#planBikes .chip[data-bike="${ride.bike}"]`)) showAllBikes(); paintBikes(); }
  ride.name = r.name || ride.name;
  // A point converted from another app's track is a map point, as a click on the map makes one.
  ride.stops = r.stops.map(s => s.name ? s : Object.assign({}, s, { name: P().mapPoint || 'Map point' }));
  ride.legs = [];
  const name = ride.name || fileName;
  openPanel();
  fitRide();
  if(Array.isArray(r.track) && r.track.length === ride.stops.length - 1){
    setStatus(tpl(P().matching || 'Following the roads of {name}…', { name }), true);
    const stops = ride.stops, got = await routeMatch(r.track, ride.bike);
    if(got && !got.limited){
      const keys = legKeys(stops, ride.bike);
      got.forEach((leg, i) => { if(!leg) return; leg.key = keys[i]; legCache.set(leg.key, leg); fileLegs.set(fileLegKey(stops[i], stops[i + 1]), leg); });
    }
    if(ride.stops !== stops) return;   // the rider moved on while the file was matched
  }
  afterChange();
  setStatus(r.converted
    ? tpl(P().converted || 'Opened {name}, made in another app, as {n} checkpoints. The ride follows the roads in the file; a leg you change is routed for your bike.', { name, n: r.stops.length })
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
/* What each step of the guide speaks of (owner 2026-10-18): hovered or focused, an orange glowing frame lies over it, above
   the veil. The map steps frame the map; the checkpoints step frames each checkpoint. */
const GUIDE_TARGETS = { 1: ['#planRideName', '#planOpen'], 2: ['#planWhen'], 3: ['#planStartBox'], 4: ['.cc-plan-bike'], 5: ['#map'], 6: ['.cc-plan-mk'], 7: ['#map'], 8: ['spots'], 9: [] };
/* The places on the map as the rider sees them now: every pin of ours in view, as screen points, at most GLOW_SPOTS. */
const GLOW_SPOTS = 60;
function spotPoints(){
  const base = window.__ccBaseSources || new Set(), out = [];
  const box = map.getContainer().getBoundingClientRect();
  for(const f of map.queryRenderedFeatures()){
    if(out.length >= GLOW_SPOTS) break;
    if(!f.layer || f.layer.type !== 'symbol' || !f.source || base.has(f.source) || String(f.source).startsWith('plan')) continue;
    if(!f.geometry || f.geometry.type !== 'Point') continue;
    const p = map.project(f.geometry.coordinates), x = box.left + p.x, y = box.top + p.y;
    if(out.some(q => Math.hypot(q[0] - x, q[1] - y) < 18)) continue;   // one ring per pin, not per layer of it
    out.push([x, y]);
  }
  return out;
}
function guideGlow(step){
  document.querySelectorAll('.cc-guide-glow').forEach(g => g.remove());
  for(const q of GUIDE_TARGETS[step] || []){
    if(q === 'spots'){
      // A pin under the guide gets no ring: the guide is read on top of everything.
      const gb = $('planGuide') ? $('planGuide').getBoundingClientRect() : null;
      const under = (x, y) => gb && x > gb.left - 17 && x < gb.right + 17 && y > gb.top - 17 && y < gb.bottom + 17;
      for(const [x, y] of spotPoints().filter(([x, y]) => !under(x, y))){ const g = document.createElement('div'); g.className = 'cc-guide-glow spot'; g.setAttribute('aria-hidden', 'true'); Object.assign(g.style, { left: (x - 17) + 'px', top: (y - 17) + 'px', width: '34px', height: '34px' }); document.body.appendChild(g); }
      continue;
    }
    document.querySelectorAll(q).forEach(el => {
      const r = el.getBoundingClientRect(); if(!(r.width > 0 && r.height > 0)) return;
      const g = document.createElement('div'); g.className = 'cc-guide-glow'; g.setAttribute('aria-hidden', 'true');
      const pad = q === '#map' ? -3 : 4;
      Object.assign(g.style, { left: (r.left - pad) + 'px', top: (r.top - pad) + 'px', width: (r.width + 2 * pad) + 'px', height: (r.height + 2 * pad) + 'px' });
      document.body.appendChild(g);
    });
  }
}
function showGuide(show){
  const g = $('planGuide'); if(!g) return;
  g.hidden = !show;
  if(!show) guideGlow(null);
  const veil = $('planGuideVeil'); if(veil) veil.hidden = !show;
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
    else if(e.target.closest('[data-fly]')){ const s = ride.stops[i]; flyToPin([s.lng, s.lat]); followStopScope(s); }
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
  // The details' tiles may already stand in the side on the right: their info icons are wired there too.
  document.querySelectorAll('#p-plan .cc-info-wrap, #planSide .cc-info-wrap').forEach(w => { const b = w.querySelector('.cc-info'); if(b) b.addEventListener('click', () => { const open = !w.classList.contains('open'); w.classList.toggle('open', open); b.setAttribute('aria-expanded', String(open)); }); });
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
  wirePartHover($('planFigsGrp'));
  legendSync = initLegendDock($('planSide'), $('planSideKey'), { dock: P().keyDock || 'Drag the key into the ride details, or press Enter', undock: P().keyUndock || 'Drag the key back onto the map, or press Enter' });
  syncSide();
  wireWeatherHover($('planWeatherGrp'));
  // Optional: a page and a script of different builds must not stop the planner before it restores the ride.
  $('planSideX')?.addEventListener('click', () => { sideClosed = true; syncSide(); });
  $('planSideBack')?.addEventListener('click', () => { sideClosed = false; syncSide(); });
  WIDE.addEventListener('change', syncSide);
  // A place's drawer says nothing when it opens or closes; its class does.
  const spot = document.getElementById('drawer');
  if(spot) new MutationObserver(syncSide).observe(spot, { attributes: true, attributeFilter: ['class'] });
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
  document.addEventListener('cc:panel', e => { if(e.detail && e.detail.key === 'search') sleepRide(); renderBar(); syncPlanning(); const key = e.detail && e.detail.key; if(key === 'plan' && ride.stops.length >= 2) fitRide(); if(key === 'plan' && !guideShownOnce && !guideOff()){ guideShownOnce = true; showGuide(true); }
    // Another panel, or the drawer closing, takes the guide away (owner 2026-10-17); its tick stays as it was.
    if(key !== 'plan'){ const g = $('planGuide'); if(g && !g.hidden) showGuide(false); } });
  { const o = $('planGuideOpen'), c = $('planGuideClose'), s = $('planGuideShow');
    if(o) o.addEventListener('click', () => showGuide(true));
    if(c) c.addEventListener('click', () => showGuide(false));
    const veil = $('planGuideVeil'); if(veil) veil.addEventListener('click', () => showGuide(false));
    const steps = $('planGuide');
    if(steps){
      const step = e => { const li = e.target.closest && e.target.closest('[data-guide]'); return li ? Number(li.dataset.guide) : null; };
      steps.addEventListener('mouseover', e => { const n = step(e); if(n) guideGlow(n); });
      steps.addEventListener('focusin', e => { const n = step(e); if(n) guideGlow(n); });
      steps.addEventListener('mouseleave', () => guideGlow(null));
      steps.addEventListener('focusout', e => { if(!steps.contains(e.relatedTarget)) guideGlow(null); });
    }
    if(s) s.addEventListener('change', () => setGuideShown(s.checked));
    // Escape closes the guide and nothing else: caught first, so the panel's own Escape does not close the planner.
    document.addEventListener('keydown', e => { const g = $('planGuide'); if(e.key === 'Escape' && g && !g.hidden){ e.preventDefault(); e.stopImmediatePropagation(); showGuide(false); } }, true); }
  // The map: layers once the style is there.
  const onLoad = () => { addLayers(); };
  if(map.loaded && map.loaded()) onLoad(); else map.once('load', onLoad);
  map.on('style.load', () => { if(map.loaded()) addLayers(); });
  map.on('click', e => {
    if(isPicking() || !planning()) return;
    const guide = $('planGuide'); if(guide && !guide.hidden) return;   // the guide is being read: no checkpoint
    if(hitsFeature(e)) return;
    const t = e.originalEvent && e.originalEvent.target;
    if(t && t.closest && t.closest('.cc-plan-mk')) return;   // a click on a stop is about that stop
    openPanel();
    // On the ride line the checkpoint goes between the two checkpoints of that stretch (owner 2026-10-18), elsewhere at the end;
    // where it lands is decided where it was clicked, then it moves onto the road.
    const raw = { lat: e.lngLat.lat, lng: e.lngLat.lng, name: P().mapPoint || 'Map point' };
    const at = onRideLine(e.point) ? insertIndex(ride.stops, ride.legs, raw) : undefined;
    snapPoint(raw.lat, raw.lng).then(p => addStop({ ...raw, lat: p.lat, lng: p.lng }, at));
  });
  // Over the ride line the pointer says a click inserts there.
  // A class with an !important rule, not the canvas's inline cursor: the surface and route layers set a pointer there on
  // their own hover, and over the ride line the insert cursor must win (owner 2026-10-18: a finger instead of the +).
  map.on('mousemove', e => {
    const over = planning() && onRideLine(e.point);
    if(over === overLine) return;
    overLine = over;
    map.getContainer().classList.toggle('cc-insert', over);
  });
  map.on('moveend', () => { if(!asleep && ride.stops.length >= 2){ scheduleAlong(); scheduleTileRead(); } });
  map.on('idle', () => { if(!asleep && ride.stops.length >= 2) scheduleTileRead(); });
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
    dateIn.addEventListener('change', () => { paintDate(); saveDraft(); renderFigures(); scheduleWeather(); });
    // Our own calendar (owner 2026-10-18: the browser's was not in style); the field stays as the value, unseen.
    const show = $('planDateShow');
    if(show){ show.hidden = false; dateIn.classList.add('under'); initDatePick(show, dateIn, { prev: P().calPrev, next: P().calNext, aria: P().calAria, forecast: P().calForecast }, FORECAST_DAYS); }
    paintDate();
  }
  // The start time: the draft's when it kept one, else 09:00 or, today past 09:00, the next half hour.
  fillStart(draft && Number.isFinite(draft.start) ? draft.start : null);
  if(draft && ALONG_STEPS.includes(draft.along)) alongM = draft.along;
  fillAlongReach();
  const reachSel = $('planAlongR');
  if(reachSel) reachSel.addEventListener('change', () => { const v = Number(reachSel.value); if(!ALONG_STEPS.includes(v)) return; alongM = v; alongRows = null; saveDraft(); scheduleAlong(); });
  const startSel = $('planStartTime');
  if(startSel) startSel.addEventListener('change', () => { saveDraft(); renderFigures(); });
  // The draft from the last visit with its undo and redo steps. The steps are read first: restoring the
  // draft saves, and an empty history would overwrite the kept one.
  const keptHistory = loadHistory();
  const d = draft; if(d && Array.isArray(d.stops) && d.stops.length){ ride = { name: d.name || '', bike: d.bike || ride.bike, stops: d.stops, legs: [] }; if(!document.querySelector(`#planBikes .chip[data-bike="${ride.bike}"]`)) showAllBikes(); paintBikes(); asleep = true; }
  if(keptHistory){ history.restore(keptHistory); saveDraft(); }
  syncStart();
  syncPlanning();
}
