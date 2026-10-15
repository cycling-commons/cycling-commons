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
import { layerGlyph } from './icons.js';
import { MAX_STOPS, CORRIDOR_M, legKeys, insertIndex, figures, profileRuns, pointAt, gpxText, barText, createHistory, tickStepKm, rideTime, surfaceBars, gradientBars, roundedHm, tileSurfaceClass, mergeSurfaces, streetAt, stopStreet, roadAt, steepestKm, longestClimb, waterGap, sunTimes, windShares } from './plan-model.js';

const CC_PLAN = window.CC_PLAN || {};
const P = () => I18N.plan || {};
const ALONG_VISIBLE = 3;
const DRAFT_KEY = 'cc-plan-draft';
/* Whether the ride line shows (owner 2026-10-15): a per-browser convenience, the stops stay either way. */
const LINE_KEY = 'cc-plan-line';
let lineShown = (() => { try { return localStorage.getItem(LINE_KEY) !== 'off'; } catch(e){ return true; } })();
const BIKES = ['Road', 'Gravel', 'MTB', 'E-bike', 'Handbike', 'Recumbent', 'Trike', 'Tandem'];

let ride = { id: null, name: '', bike: 'Road', stops: [], legs: [] };
const history = createHistory(30, 5);
const legCache = new Map();
let gen = 0, routing = false, alongTimer = null, dirty = false;
/* The day (route-planner.md section 4.4): the along rows once fetched (null until then), the forecast for the ride's date, and what was last asked. */
let alongRows = null, forecast = null, forecastKey = '', weatherTimer = null;
const weatherCache = new Map();
let markers = [], scrubMarker = null;
const $ = id => document.getElementById(id);
const fmtKm = m => uKm(m / 1000);

/* ---------- the engine, through our server ---------- */
async function routeLeg(a, b, bike){
  try {
    const r = await fetch(CC_PLAN.leg, { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CC-Token': CC_PLAN.legToken || '' },
      body: JSON.stringify({ a: [a.lng, a.lat], b: [b.lng, b.lat], bike }) });
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
function addStop(stop, at){
  if(!ride.stops.length && !(ride.name || '').trim()){ askName(); return; }
  if(ride.stops.length >= MAX_STOPS){ setStatus(tpl(P().maxStops || 'A ride holds at most {n} stops.', { n: MAX_STOPS })); return; }
  if(stop.item && ride.stops.some(s => s.item === stop.item)){ setStatus(P().already || 'That place is already in your ride.'); return; }
  if(at == null || at < 0 || at > ride.stops.length) at = ride.stops.length;
  remember();
  ride.stops.splice(at, 0, stop);
  afterChange();
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
  const pts = rideLine(); if(pts.length < 2) return;
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
  remember();
  ride = { id: null, name: '', bike: ride.bike, stops: [], legs: [] };
  dirty = false;
  afterChange();
}
/* The stops as they are now, kept for the undo key (route-planner.md section 4.1). */
/* Every change to the stops is kept for undo, as a full copy of the stops before it; the legs are routed
   again from them. The change also takes the keyboard off a text field: a drag or a right-click on the map
   leaves the focus where it was, and Ctrl+Z in the ride's name would undo the name, not the ride. */
function remember(){
  const a = document.activeElement; if(a && a.blur && a.matches && a.matches('input, textarea, select, [contenteditable]')) a.blur();
  history.push(ride.stops);
}
/* Ctrl+Z, or the command key with Z on a Mac: the stops before the last change. */
function undo(){ const prev = history.undo(ride.stops); if(!prev) return; ride.stops = prev; afterChange(); }
function redo(){ const next = history.redo(ride.stops); if(!next) return; ride.stops = next; afterChange(); }
function afterChange(){ dirty = ride.stops.length >= 2; alongRows = null; syncStart(); renderStops(); drawRide(); routeAll(); saveDraft(); syncPlanning(); paintDrawerButtons(); }

async function routeAll(){
  const myGen = ++gen;
  const keys = legKeys(ride.stops, ride.bike);
  const needed = [];
  ride.legs = keys.map((key, i) => { const c = legCache.get(key); if(!c) needed.push([i, key]); return c || null; });
  if(!needed.length){ finishRouting(); return; }
  routing = true;
  setStatus(tpl(P().routing || 'Routing the legs for a {bike} bike…', { bike: ride.bike }), true);
  for(const [i, key] of needed){
    const leg = await routeLeg(ride.stops[i], ride.stops[i + 1], ride.bike);
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
  else if(legs.some(l => !l.routed)) setStatus(P().noRoad || 'No road found between these stops');
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
  if(sum){ sum.hidden = !folded; if(folded) sum.textContent = tpl(P().stopsSum || '{n} stops, {from} to {to}', { n, from: stopTitle(0), to: stopTitle(n - 1) }); }
  ul.hidden = folded;
  if(!n){ ul.innerHTML = `<li class="cc-near-empty">${escPend(P().noStops || 'No stops yet.')}</li>`; return; }
  let html = '';
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
      if(leg && !leg.routed && !leg.limited && !leg.down) html += `<li class="cc-plan-leg bad">${escPend(P().noRoad || 'No road found between these stops')}</li>`;
      else if(leg && leg.routed && !leg.heights) html += `<li class="cc-plan-leg bad">${escPend(fmtKm(leg.distM))} · ${escPend(P().noHeights || 'no heights here')}</li>`;
      else if(leg && leg.routed) html += `<li class="cc-plan-leg">${escPend(fmtKm(leg.distM))}</li>`;
    }
  });
  ul.innerHTML = html;
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
  const save = $('planSave'), gpx = $('planGpx');
  if(save) save.disabled = wait;
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
  if(alongRows){ const gap = waterGap(alongRows, f.distM); if(gap != null && gap >= 15) lines.push(tpl(P().waterGap || '{d} without water or food along the way', { d: uKm(gap, 0) })); }
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
  ul.innerHTML = lines.map(l => `<li>${escPend(l)}</li>`).join('') + credit;
  grp.hidden = !lines.length;
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
  const d = await fetchAlong(line);
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
      const place = JSON.stringify({ item: r.item, letter: r.letter, name: r.name || meta.label, lat: r.ll[0], lng: r.ll[1] });
      return `<li class="cc-plan-row"><button type="button" class="cc-near" data-plan-ll="${r.ll[0]},${r.ll[1]}" data-plan-key="${escPend(r.item)}"><span class="cc-near-nm">${escPend(r.name || meta.label)}</span><em>${escPend(tpl(D.kmOff || '{a} along · {b} off', { a: uKm(r.alongKm), b: uM(r.distM) }))}</em></button><button type="button" class="cc-plan-add${inRide ? ' in' : ''}" data-plan-place="${escPend(place)}">${escPend(inRide ? (P().inRide || 'In your ride') : (P().addStop || 'Add as stop'))}</button></li>`;
    };
    html += items.slice(0, ALONG_VISIBLE).map(row).join('');
    if(items.length > ALONG_VISIBLE) html += `<li class="cc-near-rest" id="${id}" hidden><ul class="cc-near-list">${items.slice(ALONG_VISIBLE).map(row).join('')}</ul></li>`;
  }
  if(!html) html = `<li class="cc-near-empty">${escPend(tpl(P().alongEmpty || 'Nothing the Commons knows within {d} of this ride yet.', { d: uM(CORRIDOR_M) }))}</li>`;
  ul.innerHTML = html;
  alongRows = rows; renderFigures();   // the water gap reads the rows
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
  ['plan-casing', 'plan-line', 'plan-straight'].forEach(id => { if(map.getLayer(id)) map.setLayoutProperty(id, 'visibility', lineShown ? 'visible' : 'none'); });
  const b = $('planLineToggle'); if(!b) return;
  const label = lineShown ? (P().lineHide || 'Hide the ride line') : (P().lineShow || 'Show the ride line');
  b.setAttribute('aria-pressed', lineShown ? 'true' : 'false'); b.title = label; b.setAttribute('aria-label', label);
  b.classList.toggle('off', !lineShown);
}
function addLayers(){
  if(map.getSource('plan')) return;
  map.addSource('plan', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
  map.addSource('plan-straight', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
  // Thin, so the surface band under the ride stays readable on both sides of it (owner 2026-10-15).
  map.addLayer({ id: 'plan-casing', type: 'line', source: 'plan', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#F3EBD8', 'line-width': ['interpolate', ['linear'], ['zoom'], 10, 3, 18, 4.5], 'line-opacity': .7 } });
  map.addLayer({ id: 'plan-line', type: 'line', source: 'plan', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#FF5A1F', 'line-width': ['interpolate', ['linear'], ['zoom'], 10, 1.8, 18, 3] } });
  map.addLayer({ id: 'plan-straight', type: 'line', source: 'plan-straight', paint: { 'line-color': '#E8A33D', 'line-width': 3, 'line-dasharray': [1.5, 1.5] } });
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
  if(show){ const day = rideTime(dayLegs(), ride.bike); $('planBarText').textContent = barText(figures(ride.legs), ride.stops.length, { km: fmtKm, m: uElev, stops: n => tpl(P().barStops || '{n} stops', { n }) }, day ? hmText(day.seconds) : ''); }
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
function saveDraft(){ try { if(ride.stops.length) localStorage.setItem(DRAFT_KEY, JSON.stringify({ bike: ride.bike, stops: ride.stops, id: ride.id, name: ride.name, date: rideDate() })); else localStorage.removeItem(DRAFT_KEY); } catch(e){} }
function loadDraft(){ try { return JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null'); } catch(e){ return null; } }
function suggestName(){ const a = ride.stops[0], b = ride.stops[ride.stops.length - 1]; const nm = s => s && s.letter ? s.name : ''; return [nm(a), nm(b)].filter(Boolean).join(' · ') || ''; }
async function doSave(){
  const name = (ride.name || '').trim(); if(!name){ askName(); return; }
  const f = figures(ride.legs);
  const body = { id: ride.id, name, bike: ride.bike, stops: ride.stops, line: { type: 'LineString', coordinates: rideLine() }, distanceM: Math.round(f.distM), ascentM: Math.round(f.upM), descentM: Math.round(f.downM) };
  const hint = $('planSaveHint');
  try {
    const r = await fetch(CC_PLAN.save, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CC-Token': CC_PLAN.saveToken || '' }, body: JSON.stringify(body) });
    if(r.status === 409){ hint.textContent = P().tooMany || 'You hold 100 rides already. Delete one first.'; return; }
    if(!r.ok){ hint.textContent = P().saveFailed || 'Could not save the ride.'; return; }
    const d = await r.json();
    ride.id = d.id; ride.name = name; dirty = false;
    $('planSaveRow').hidden = true;
    hint.innerHTML = escPend(tpl(P().saved || 'Saved as {name}.', { name })) + (CC_PLAN.rides ? ` <a href="${escPend(CC_PLAN.rides)}">${escPend(P().myRides || 'My rides')}</a>` : '');
    saveDraft();
  } catch(e){ hint.textContent = P().saveFailed || 'Could not save the ride.'; }
}
function downloadGpx(){
  const text = gpxText({ name: ride.name, stops: ride.stops, legs: ride.legs }, { itemUrl: item => item.startsWith('osm:') ? null : (location.origin + (CC_PLAN.item || '/map?item=') + item) });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([text], { type: 'application/gpx+xml' }));
  a.download = (ride.name || 'Ride') + '.gpx';
  document.body.appendChild(a); a.click(); a.remove();
}
/* A saved ride (?ride=<id>): its stops and bike, drawn at once from the stored
   line, then its legs routed again under its bike so the heights come back. */
async function openSaved(id){
  if(!CC_PLAN.ride) return;
  try {
    const r = await fetch(CC_PLAN.ride + encodeURIComponent(id), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
    if(!r.ok) return;
    const d = await r.json();
    ride = { id: d.id, name: d.name, bike: d.bike, stops: d.stops, legs: [] };
    if(!document.querySelector(`#planBikes .chip[data-bike="${ride.bike}"]`)) showAllBikes();
    paintBikes();
    afterChange();
    dirty = false;
    const line = d.line && d.line.coordinates;
    if(line && line.length){ const b = new maplibregl.LngLatBounds(line[0], line[0]); line.forEach(p => b.extend(p)); map.fitBounds(b, { padding: 60, duration: 700 }); }
    openPanel();
  } catch(e){ /* a missing ride opens the map without one */ }
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
    const li = e.target.closest('.cc-plan-stop'); if(!li) return;
    const i = Number(li.dataset.i);
    if(e.target.closest('[data-up]')) moveStop(i, -1);
    else if(e.target.closest('[data-down]')) moveStop(i, 1);
    else if(e.target.closest('[data-rm]')) removeStop(i);
    else if(e.target.closest('[data-fly]')){ const s = ride.stops[i]; flyToPin([s.lng, s.lat]); }
  });
  $('planStops').addEventListener('mouseover', e => { const li = e.target.closest('.cc-plan-stop'); if(!li) return; const s = ride.stops[Number(li.dataset.i)]; if(s) highlightAt([s.lat, s.lng], [0, 0], null); });
  $('planStops').addEventListener('mouseleave', clearHighlight);
  const along = $('planAlong');
  along.addEventListener('click', e => {
    const add = e.target.closest('[data-plan-place]'); if(add){ togglePlace(JSON.parse(add.dataset.planPlace)); renderAlong(); return; }
    const row = e.target.closest('.cc-near[data-plan-ll]'); if(row){ const [lat, lng] = row.dataset.planLl.split(',').map(Number); flyToPin([lng, lat]); }
  });
  along.addEventListener('mouseover', e => { const row = e.target.closest('.cc-near[data-plan-ll]'); if(!row) return; const [lat, lng] = row.dataset.planLl.split(',').map(Number); /* A drawn pin stands on its tip: the ring goes onto its body (owner 2026-10-15). */ highlightAt([lat, lng], pinElFor(row.dataset.planKey) ? [0, -16] : [0, 0], row.dataset.planKey); });
  along.addEventListener('mouseleave', clearHighlight);
  // One save: the name is the one at the top of the panel (owner 2026-10-15).
  const save = $('planSave'); if(save) save.addEventListener('click', doSave);
  { const t = $('planStopsToggle'); if(t) t.addEventListener('click', () => { stopsOpen = !stopsOpen; renderStops(); }); }
  { const sm = $('planStopsSum'); if(sm) sm.addEventListener('click', () => { stopsOpen = true; renderStops(); }); }
  { const w = $('planWhyWrap'), b = $('planWhyBtn'); if(w && b) b.addEventListener('click', () => { const open = !w.classList.contains('open'); w.classList.toggle('open', open); b.setAttribute('aria-expanded', String(open)); }); }
  { const u = $('planDayUnit'); if(u) u.addEventListener('click', e => { const b = e.target.closest('button[data-u]'); if(!b) return; dayUnit = b.dataset.u; u.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b)); renderFigures(); }); }
  $('planGpx').addEventListener('click', downloadGpx);
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
  document.addEventListener('cc:panel', e => { renderBar(); syncPlanning(); const key = e.detail && e.detail.key; if(key === 'plan' && ride.stops.length >= 2) fitRide(); });
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
    const t = e.target;
    if(t && t.closest && t.closest('input, textarea, select, [contenteditable]')) return;   // a field keeps its own undo
    if(!planning() && !history.size() && !history.redoSize()) return;
    e.preventDefault();
    if(isRedo) redo(); else undo();
  });
  window.addEventListener('beforeunload', e => { if(dirty && ride.stops.length >= 2 && !ride.id){ e.preventDefault(); e.returnValue = P().leave || ''; } });
  // The day of the ride: today unless the draft holds a later date; a change redraws the daylight and asks the forecast.
  const dateIn = $('planDate'), draft = loadDraft();
  if(dateIn){
    dateIn.min = todayIso();
    dateIn.value = draft && typeof draft.date === 'string' && draft.date >= todayIso() ? draft.date : todayIso();
    dateIn.addEventListener('change', () => { saveDraft(); renderFigures(); scheduleWeather(); });
  }
  // A saved ride by its link, else the draft from the last visit.
  const wanted = new URLSearchParams(location.search).get('ride');
  if(wanted && CC_PLAN.ride) openSaved(wanted);
  else { const d = draft; if(d && Array.isArray(d.stops) && d.stops.length){ ride = { id: d.id || null, name: d.name || '', bike: d.bike || ride.bike, stops: d.stops, legs: [] }; if(!document.querySelector(`#planBikes .chip[data-bike="${ride.bike}"]`)) showAllBikes(); paintBikes(); afterChange(); dirty = false; } }
  syncStart();
  syncPlanning();
}
