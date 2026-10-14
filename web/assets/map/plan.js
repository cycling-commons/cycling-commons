// SPDX-License-Identifier: AGPL-3.0-only
/* The ride planner (docs/specs/route-planner.md sections 3 to 6): the plan
   panel, the stops and legs on the map, the figures, the profile, the along
   list, Add to ride on the place drawer, the ride bar, saving and the GPX.
   The model is plan-model.js; this module owns the DOM and the map. The
   browser never talks to the routing engine: every leg is one
   POST /map/plan/leg, and what is along the ride is POST /map/plan/along. */
import { map, flyToPin } from './map-init.js';
import { I18N, D, tpl, trVal } from './i18n.js';
import { escPend, txtOn, gradColor } from './util.js';
import { uKm, uM, uElev } from './units.js';
import { highlightAt, clearHighlight } from './drawer.js';
import { activePanel } from './shell.js';
import { isPicking } from './picking.js';
import { setPlanning } from './plan-state.js';
import { CATALOG } from './catalog.js';
import { layerGlyph } from './icons.js';
import { MAX_STOPS, CORRIDOR_M, legKeys, insertIndex, figures, profileRuns, pointAt, gpxText, barText, createHistory, toughness, tickStepKm, surfaceMix, toughnessBars } from './plan-model.js';

/* The routes' difficulty words by level (DifficultyVocabulary::LABELS), translated as attribute values. */
const TOUGH_WORDS = { 1: 'Easy', 2: 'Moderate', 3: 'Challenging', 4: 'Hard', 5: 'Very hard' };

const CC_PLAN = window.CC_PLAN || {};
const P = () => I18N.plan || {};
const ALONG_VISIBLE = 3;
const DRAFT_KEY = 'cc-plan-draft';
const BIKES = ['Road', 'Gravel', 'MTB', 'E-bike', 'Handbike', 'Recumbent', 'Trike', 'Tandem'];

let ride = { id: null, name: '', bike: 'Road', stops: [], legs: [] };
const history = createHistory(30);
const legCache = new Map();
let gen = 0, routing = false, alongTimer = null, dirty = false;
let markers = [], scrubMarker = null;
let baseSources = null;
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
    return { routed: true, line: d.coordinates, distM: d.distanceM, heights: d.heights || null, surfaces: d.surfaces || null };
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
}

function addStop(stop, at){
  if(ride.stops.length >= MAX_STOPS){ setStatus(tpl(P().maxStops || 'A ride holds at most {n} stops.', { n: MAX_STOPS })); return; }
  if(stop.item && ride.stops.some(s => s.item === stop.item)){ setStatus(P().already || 'That place is already in your ride.'); return; }
  if(at == null || at < 0 || at > ride.stops.length) at = ride.stops.length;
  remember();
  ride.stops.splice(at, 0, stop);
  afterChange();
}
function removeStop(i){ remember(); ride.stops.splice(i, 1); afterChange(); }
function moveStop(i, d){ const j = i + d; if(j < 0 || j >= ride.stops.length) return; remember(); const s = ride.stops.splice(i, 1)[0]; ride.stops.splice(j, 0, s); afterChange(); }
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
function remember(){ history.push(ride.stops); }
/* Ctrl+Z, or the command key with Z on a Mac: the stops before the last change. */
function undo(){ const prev = history.pop(); if(!prev) return; ride.stops = prev; afterChange(); }
function afterChange(){ dirty = ride.stops.length >= 2; renderStops(); drawRide(); routeAll(); saveDraft(); syncPlanning(); paintDrawerButtons(); }

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
  renderFigures(); renderProfile(); scheduleAlong(); renderBar(); saveDraft();
}

/* ---------- panel: bikes ---------- */
function bikeLabel(b){ const el = document.querySelector(`#planBikes .chip[data-bike="${b}"]`); return el ? el.textContent : b; }
function paintBikes(){
  document.querySelectorAll('#planBikes .chip').forEach(c => { const on = c.dataset.bike === ride.bike; c.classList.toggle('on', on); c.setAttribute('aria-pressed', on ? 'true' : 'false'); });
  const why = $('planWhy'); if(why) why.textContent = (P().why || {})[ride.bike] || '';
}
function showAllBikes(){
  const box = $('planBikes'); if(!box) return;
  const have = new Set([...box.querySelectorAll('.chip')].map(c => c.dataset.bike));
  BIKES.filter(b => !have.has(b)).forEach(b => { const c = document.createElement('button'); c.type = 'button'; c.className = 'chip'; c.dataset.bike = b; c.textContent = b; box.appendChild(c); });
  const all = $('planBikesAll'); if(all) all.remove();
}

/* ---------- panel: stops ---------- */
function metaFor(letter){ const m = CATALOG.find(x => x.letter === letter); return m ? { color: m.color, label: m.label, glyph: layerGlyph(m) } : null; }
function renderStops(){
  const ul = $('planStops'); if(!ul) return;
  const n = ride.stops.length;
  const instr = $('planInstr'); if(instr) instr.hidden = n >= 2;
  if(!n){ ul.innerHTML = `<li class="cc-near-empty">${escPend(P().noStops || 'No stops yet.')}</li>`; return; }
  let html = '';
  ride.stops.forEach((s, i) => {
    const cls = i === 0 ? 'start' : (i === n - 1 && n > 1 ? 'end' : '');
    const lbl = i === 0 ? 'S' : (i === n - 1 && n > 1 ? 'E' : String(i));
    const meta = s.letter ? metaFor(s.letter) : null;
    const kind = meta ? `<span class="k" style="background:${meta.color}" title="${escPend(meta.label)}"></span>` : '';
    const sub = meta ? meta.label : (s.lat.toFixed(4) + ', ' + s.lng.toFixed(4));
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
  // Toughness: the routes' difficulty words, so a ride and a route speak one scale.
  const mix = surfaceMix(ride.legs);
  const t = toughness(f.distM, f.upM, mix);
  const tEl = $('planTough'), tSub = $('planToughSub');
  if(tEl) tEl.textContent = t ? trVal(TOUGH_WORDS[t.level]) : '';
  if(tSub){
    const parts = t ? [`${(P().terrain || {})[t.terrain] || t.terrain} · ${tpl(P().perKm || '{n} m/km', { n: Math.round(t.perKm) })}`] : [];
    if(t && mix.gravel >= 500) parts.push(tpl(P().surfGravel || '{d} gravel', { d: fmtKm(mix.gravel) }));
    if(t && mix.rough >= 500) parts.push(tpl(P().surfRough || '{d} rough', { d: fmtKm(mix.rough) }));
    tSub.textContent = parts.join(' · ');
  }
  renderToughGraphic(t, mix, f);
  const wait = unrouted() || routing;
  const save = $('planSave'), gpx = $('planGpx');
  if(save) save.disabled = wait;
  if(gpx) gpx.disabled = wait;
  const hint = $('planSaveHint'); if(hint && (unrouted() && !routing)) hint.textContent = P().saveWait || 'Save and GPX wait until every leg has a road.';
}
/* How the toughness is built (route-planner.md section 4.4), in three rows:
   the distance bar split by surface, with the ride's km and its effective km
   and the base they give; the climb gauge in the profile's purples with the
   ride's m/km and what it adds; and the five-step ladder with the level. */
const SURFACE_COLOUR = { paved: '#577A71', gravel: '#C8923A', rough: '#6E5849', unknown: 'rgb(var(--chrome-fg) / .25)' };
function renderToughGraphic(t, mix, f){
  const box = $('planToughFx'), svg = $('planToughSvg'); if(!box || !svg) return;
  if(!t){ box.hidden = true; return; }
  box.hidden = false;
  const W = 280, L = 58, BW = 128, X2 = L + BW + 6;
  const lbl = (y, text) => `<text x="0" y="${y}" class="lbl">${escPend(text)}</text>`;
  let s = lbl(23, P().distance || 'distance');
  const bars = toughnessBars(mix);
  let x = L;
  for(const seg of bars){ const w = BW * seg.share; s += `<rect x="${x.toFixed(1)}" y="12" width="${Math.max(w, 0.5).toFixed(1)}" height="14" rx="2" fill="${SURFACE_COLOUR[seg.cls]}"/>`; x += w; }
  if(!bars.length) s += `<rect x="${L}" y="12" width="${BW}" height="14" rx="2" fill="${SURFACE_COLOUR.unknown}"/>`;
  const eff = Math.abs(t.effectiveKm - f.distM / 1000) >= 0.5 ? ` → ${uKm(t.effectiveKm, 0)}` : '';
  s += `<text x="${X2}" y="23" class="val">${escPend(fmtKm(f.distM) + eff)}</text>`;
  s += `<text x="${W}" y="23" text-anchor="end" class="num">${t.base}</text>`;
  s += lbl(53, P().climbed || 'climbed');
  const zones = [[0, 8, '#D9A6F2'], [8, 15, '#B25BE8'], [15, 25, '#8A2BD0'], [25, 35, '#5E18A0']];
  for(const [a, b, c] of zones) s += `<rect x="${(L + BW * a / 35).toFixed(1)}" y="42" width="${(BW * (b - a) / 35).toFixed(1)}" height="14" rx="2" fill="${c}"/>`;
  const px = L + BW * Math.min(35, Math.max(0, t.perKm)) / 35;
  s += `<polygon points="${px.toFixed(1)},41 ${(px - 5).toFixed(1)},34 ${(px + 5).toFixed(1)},34" fill="var(--trail)"/>`;
  s += `<text x="${X2}" y="53" class="val">${escPend(tpl(P().perKm || '{n} m/km', { n: Math.round(t.perKm) }))}</text>`;
  s += `<text x="${W}" y="53" text-anchor="end" class="num">+${t.bump}</text>`;
  s += lbl(91, P().tough || 'toughness');
  for(let i = 1; i <= 5; i++){
    const bx = L + (i - 1) * (BW / 5), on = i === t.level;
    s += `<rect x="${(bx + 1).toFixed(1)}" y="78" width="${(BW / 5 - 2).toFixed(1)}" height="20" rx="4" fill="${on ? 'var(--trail)' : 'rgb(var(--chrome-fg) / .1)'}"/>`;
    s += `<text x="${(bx + BW / 10).toFixed(1)}" y="92" text-anchor="middle" class="${on ? 'on' : 'off'}">${i}</text>`;
  }
  s += `<text x="${X2}" y="92" class="val">${escPend(trVal(TOUGH_WORDS[t.level]))}</text>`;
  svg.innerHTML = s;
}

/* The profile in the panel and, the map's width wide, as a strip along the
   foot of the map (route-planner.md section 4.5); one drawing, two sizes. */
let stripClosed = false;
function renderProfile(){
  const g = $('planProfGrp'), svgEl = $('planProfSvg'), strip = $('planStrip'); if(!g || !svgEl) return;
  const f = figures(ride.legs);
  const runs = ride.stops.length >= 2 ? profileRuns(ride.legs, Math.max(100, Math.ceil(f.distM / 120 / 100) * 100)) : [];
  const bins = runs.flat();
  const wrap = document.querySelector('.map-wrap');
  if(bins.length < 2){ g.hidden = true; if(strip){ strip.hidden = true; wrap && wrap.classList.remove('cc-strip-on'); } return; }
  g.hidden = false;
  drawProfile($('planProf'), svgEl, $('planProfCur'), $('planProfTip'), 280, 118, f, bins);
  if(strip){
    strip.hidden = stripClosed;
    wrap && wrap.classList.toggle('cc-strip-on', !stripClosed);
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
    tip.style.display = 'block'; tip.textContent = `${uKm(d / 1000)} · ${uElev(near.h0)}`; tip.style.left = Math.min(fx / W * 100, 70) + '%';
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
    el.textContent = i === 0 ? 'S' : (i === n - 1 && n > 1 ? 'E' : String(i));
    el.title = s.name;
    const m = new maplibregl.Marker({ element: el, draggable: true }).setLngLat([s.lng, s.lat]).addTo(map);
    m.on('dragend', () => { remember(); const ll = m.getLngLat(); const st = ride.stops[i]; st.lng = ll.lng; st.lat = ll.lat; if(st.item){ st.item = null; st.letter = null; st.name = P().mapPoint || 'Map point'; } afterChange(); });
    markers.push(m);
  });
}
function addLayers(){
  if(map.getSource('plan')) return;
  map.addSource('plan', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
  map.addSource('plan-straight', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
  map.addLayer({ id: 'plan-casing', type: 'line', source: 'plan', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#F3EBD8', 'line-width': 7, 'line-opacity': .9 } });
  map.addLayer({ id: 'plan-line', type: 'line', source: 'plan', layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#FF5A1F', 'line-width': 4 } });
  map.addLayer({ id: 'plan-straight', type: 'line', source: 'plan-straight', paint: { 'line-color': '#E8A33D', 'line-width': 3, 'line-dasharray': [1.5, 1.5] } });
  drawRide();
}
/* An empty map click is a stop. A click on anything of ours (a pin, a coverage
   point, a route, a surface stretch) is not: the basemap's own sources are
   remembered when the style loads, and everything else is a feature. */
function hitsFeature(e){
  if(!baseSources) return false;
  return map.queryRenderedFeatures(e.point).some(f => f.source && !baseSources.has(f.source) && f.source !== 'plan' && f.source !== 'plan-straight');
}
function renderBar(){
  const bar = $('planBar'); if(!bar) return;
  const show = activePanel() !== 'plan' && ride.stops.length >= 2;
  bar.hidden = !show;
  if(show) $('planBarText').textContent = barText(figures(ride.legs), ride.stops.length, { km: fmtKm, m: uElev, stops: n => tpl(P().barStops || '{n} stops', { n }) });
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
function saveDraft(){ try { if(ride.stops.length) localStorage.setItem(DRAFT_KEY, JSON.stringify({ bike: ride.bike, stops: ride.stops, id: ride.id, name: ride.name })); else localStorage.removeItem(DRAFT_KEY); } catch(e){} }
function loadDraft(){ try { return JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null'); } catch(e){ return null; } }
function suggestName(){ const a = ride.stops[0], b = ride.stops[ride.stops.length - 1]; const nm = s => s && s.letter ? s.name : ''; return [nm(a), nm(b)].filter(Boolean).join(' · ') || ''; }
async function doSave(){
  const name = ($('planName').value || '').trim(); if(!name){ $('planName').focus(); return; }
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
  const all = $('planBikesAll'); if(all) all.addEventListener('click', e => { e.preventDefault(); showAllBikes(); });
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
  along.addEventListener('mouseover', e => { const row = e.target.closest('.cc-near[data-plan-ll]'); if(!row) return; const [lat, lng] = row.dataset.planLl.split(',').map(Number); highlightAt([lat, lng], [0, 0], row.dataset.planKey); });
  along.addEventListener('mouseleave', clearHighlight);
  const save = $('planSave'); if(save) save.addEventListener('click', () => { $('planSaveRow').hidden = false; $('planName').value = ride.name || suggestName(); $('planName').focus(); });
  const go = $('planSaveGo'); if(go) go.addEventListener('click', doSave);
  const nameEl = $('planName'); if(nameEl) nameEl.addEventListener('keydown', e => { if(e.key === 'Enter') doSave(); });
  $('planGpx').addEventListener('click', downloadGpx);
  $('planClear').addEventListener('click', clearRide);
  $('planBarOpen').addEventListener('click', openPanel);
  $('planBarClear').addEventListener('click', clearRide);
  const stripX = $('planStripX'); if(stripX) stripX.addEventListener('click', () => { stripClosed = true; renderProfile(); });
  let resizeT = null;
  window.addEventListener('resize', () => { clearTimeout(resizeT); resizeT = setTimeout(renderProfile, 150); });
  // Add to ride on any place drawer (drawer.js writes the button).
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-plan-add]'); if(!b) return;
    togglePlace({ item: b.dataset.planItem, letter: b.dataset.planLetter || null, name: b.dataset.planName || b.dataset.planLetter || (P().mapPoint || 'Map point'), lat: Number(b.dataset.planLat), lng: Number(b.dataset.planLng) });
    paintDrawerButtons();
    openPanel();
  });
  const drawer = document.getElementById('drawer');
  if(drawer) new MutationObserver(paintDrawerButtons).observe(drawer, { childList: true, subtree: true });
  document.addEventListener('cc:panel', () => { renderBar(); syncPlanning(); });
  // The map: layers once the style is there, the basemap's sources remembered for the click test.
  const ready = () => { baseSources = new Set(Object.keys((map.getStyle() || {}).sources || {})); };
  if(map.isStyleLoaded && map.isStyleLoaded()) ready(); else map.once('style.load', ready);
  const onLoad = () => { addLayers(); };
  if(map.loaded && map.loaded()) onLoad(); else map.once('load', onLoad);
  map.on('style.load', () => { if(map.loaded()) addLayers(); });
  map.on('click', e => {
    if(isPicking() || !planning()) return;
    if(hitsFeature(e)) return;
    openPanel();
    addStop({ lat: e.lngLat.lat, lng: e.lngLat.lng, name: P().mapPoint || 'Map point' });
  });
  map.on('moveend', () => { if(ride.stops.length >= 2) scheduleAlong(); });
  document.addEventListener('keydown', e => {
    if(!(e.ctrlKey || e.metaKey) || e.altKey || e.shiftKey || 'z' !== String(e.key).toLowerCase()) return;
    const t = e.target;
    if(t && t.closest && t.closest('input, textarea, select, [contenteditable]')) return;   // a field keeps its own undo
    if(!planning() && !history.size()) return;
    e.preventDefault();
    undo();
  });
  window.addEventListener('beforeunload', e => { if(dirty && ride.stops.length >= 2 && !ride.id){ e.preventDefault(); e.returnValue = P().leave || ''; } });
  // A saved ride by its link, else the draft from the last visit.
  const wanted = new URLSearchParams(location.search).get('ride');
  if(wanted && CC_PLAN.ride) openSaved(wanted);
  else { const d = loadDraft(); if(d && Array.isArray(d.stops) && d.stops.length){ ride = { id: d.id || null, name: d.name || '', bike: d.bike || ride.bike, stops: d.stops, legs: [] }; if(!document.querySelector(`#planBikes .chip[data-bike="${ride.bike}"]`)) showAllBikes(); paintBikes(); afterChange(); dirty = false; } }
  syncPlanning();
}
