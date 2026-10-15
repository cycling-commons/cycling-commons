// SPDX-License-Identifier: AGPL-3.0-only
/* Map presets on the Layers panel (docs/specs/map-presets.md section 3): one
   chip per preset, a tap sets every switch below through the control's own
   handler, a change after that shows the chip edited with Save changes and
   Reset beside it, Save as preset keeps the current switches under a name,
   and the default preset applies once when the map opens. The model is
   presets-model.js; this module owns the DOM and the server calls. */
import { I18N, tpl } from './i18n.js';
import { escPend } from './util.js';
import { applyMode } from './panels.js';
import { MAX_PRESETS, LAYER_KEYS, FACET_GROUPS, normalize, same, clicksFor } from './presets-model.js';

const CFG = window.CC_PRESETS || {};
const S = () => I18N.presets || {};
const $ = id => document.getElementById(id);
let presets = [], active = null, baseline = null;
/* A tool's preset (map-presets.md section 3): the switches before it, put back when the tool is left. */
let tool = null, before = null;
const TOOL_OF_PANEL = { plan: 'plan', tools: 'ride' };

/* ---------- the panel's switches as a settings object ---------- */
const onChips = sel => [...document.querySelectorAll(sel + ' .chip.on')].map(c => c.dataset.v);
const allChips = sel => [...document.querySelectorAll(sel + ' .chip')].map(c => c.dataset.v);
export function readState(){
  const modeBtn = document.querySelector('#mode button.on');
  const facets = {};
  for(const g of FACET_GROUPS){ if(document.querySelector('#' + g)) facets[g] = onChips('#' + g); }
  return normalize({
    mode: modeBtn ? modeBtn.dataset.m : 'all',
    layers: [...document.querySelectorAll('#layers .layer:not(.off)')].map(el => el.dataset.key).filter(k => LAYER_KEYS.includes(k)),
    overlays: ['surface', 'routes'].filter(o => { const b = $(o === 'surface' ? 'ovSurface' : 'ovRoutes'); return b && b.getAttribute('aria-pressed') === 'true'; }),
    seasons: onChips('#boSeason'),
    bikes: onChips('#boBike'),
    facets,
    // The key's rows (owner 2026-10-15).
    surfaces: [...document.querySelectorAll('.skey-row[data-surf-cls]')].filter(b => b.getAttribute('aria-pressed') !== 'false').map(b => b.dataset.surfCls),
    routes: [...document.querySelectorAll('.skey-row[data-route-grp]')].filter(b => b.getAttribute('aria-pressed') !== 'false').map(b => b.dataset.routeGrp),
  });
}
function facetAll(){ const out = {}; for(const g of FACET_GROUPS){ if(document.querySelector('#' + g)) out[g] = allChips('#' + g); } return out; }

/* Every tap goes through the control's own handler, so the map reacts as to a rider's taps; the mode is set without persisting it to the profile. */
function apply(settings){
  for(const c of clicksFor(readState(), settings, facetAll())){
    if(c.kind === 'mode') applyMode(c.value, { persist: false });
    else if(c.kind === 'layer'){ const el = document.querySelector(`#layers .layer[data-key="${c.value}"]`); if(el) el.click(); }
    else if(c.kind === 'overlay'){ const b = $(c.value === 'surface' ? 'ovSurface' : 'ovRoutes'); if(b && !b.hidden) b.click(); }
    else if(c.kind === 'season'){ const el = document.querySelector(`#boSeason .chip[data-v="${c.value}"]`); if(el) el.click(); }
    else if(c.kind === 'bike'){ const el = document.querySelector(`#boBike .chip[data-v="${c.value}"]`); if(el) el.click(); }
    else if(c.kind === 'surface'){ const el = document.querySelector(`.skey-row[data-surf-cls="${c.value}"]`); if(el) el.click(); }
    else if(c.kind === 'route'){ const el = document.querySelector(`.skey-row[data-route-grp="${c.value}"]`); if(el) el.click(); }
    else if(c.kind === 'facet'){ const el = document.querySelector(`#${c.group} .chip[data-v="${c.value.replace(/"/g, '\\"')}"]`); if(el) el.click(); }
  }
}
function pick(p){ active = p.id; apply(p.settings); baseline = normalize(readState()); renderChips(); }

/* ---------- the chips, the edited bar, save as ---------- */
function renderChips(){
  const box = $('presetChips'); if(!box) return;
  const edited = active != null && baseline && !same(readState(), baseline);
  box.innerHTML = presets.map(p => `<span class="chip${p.id === active ? ' on' : ''}${p.id === active && edited ? ' edited' : ''}" role="button" tabindex="0" data-id="${p.id}">${escPend(p.name)}<i class="ed" aria-hidden="true"></i></span>`).join('')
    + (presets.length < MAX_PRESETS ? `<span class="chip plus" role="button" tabindex="0" id="presetSaveAs">${escPend(S().saveAs || '+ Save as preset')}</span>` : '');
  const bar = $('presetEdited'); if(bar){ bar.hidden = !edited; const nm = $('presetEditedName'); if(nm && edited) nm.textContent = (presets.find(p => p.id === active) || {}).name || ''; }
}
async function post(url, body){
  const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CC-Token': CFG.token || '' }, body: JSON.stringify(body || {}) });
  return r;
}
function say(text){ const s = $('presetStatus'); if(s){ s.textContent = text || ''; s.hidden = !text; } }
async function saveAs(name){
  const settings = readState();
  const r = await post(CFG.save, { name, settings });
  if(r.status === 409){ say(S().full || 'You hold 6 presets already. Delete one in Settings first.'); return; }
  if(!r.ok){ say(S().failed || 'Could not save the preset.'); return; }
  const { id } = await r.json();
  presets.push({ id, name, uses: [], settings });
  active = id; baseline = normalize(settings);
  say(S().saved || 'Saved.'); renderChips();
}
async function saveChanges(){
  const p = presets.find(x => x.id === active); if(!p) return;
  const settings = readState();
  const r = await post(CFG.save, { id: p.id, name: p.name, settings });
  if(!r.ok){ say(S().failed || 'Could not save the preset.'); return; }
  p.settings = settings; baseline = normalize(settings);
  say(S().saved || 'Saved.'); renderChips();
}

function toolLine(ctx, p){
  const el = $('presetsTool'); if(!el) return;
  el.hidden = !ctx;
  if(ctx) el.textContent = tpl(S().toolLine || '{tool} uses {name}. Leaving it puts your switches back.', { tool: (S().tools || {})[ctx] || ctx, name: p.name });
}

export function initPresets(){
  const grp = $('presets'); if(!grp) return;
  if(!CFG.signedIn){ const line = $('presetsSignIn'); if(line) line.hidden = false; return; }
  presets = Array.isArray(CFG.list) ? CFG.list.map(p => ({ id: p.id, name: p.name, uses: Array.isArray(p.uses) ? p.uses : [], settings: p.settings })) : [];
  const chips = $('presetChips'), saveAsBox = $('presetSaveAsBox'), nameIn = $('presetName');
  if(chips) chips.hidden = false;
  const hint = $('presetsTap'); if(hint) hint.hidden = false;
  chips.addEventListener('click', e => {
    const c = e.target.closest('.chip'); if(!c) return;
    if(c.id === 'presetSaveAs'){ if(saveAsBox){ saveAsBox.hidden = !saveAsBox.hidden; if(!saveAsBox.hidden && nameIn) nameIn.focus(); } return; }
    const p = presets.find(x => x.id === Number(c.dataset.id)); if(p) pick(p);
  });
  chips.addEventListener('keydown', e => { if(e.key === 'Enter' || e.key === ' '){ e.preventDefault(); e.target.click(); } });
  const saveBtn = $('presetSaveBtn');
  if(saveBtn) saveBtn.addEventListener('click', () => { const name = (nameIn && nameIn.value || '').trim(); if(!name) return; saveAs(name); nameIn.value = ''; if(saveAsBox) saveAsBox.hidden = true; });
  if(nameIn) nameIn.addEventListener('keydown', e => { if(e.key === 'Enter'){ e.preventDefault(); if(saveBtn) saveBtn.click(); } });
  const sc = $('presetSaveChanges'); if(sc) sc.addEventListener('click', saveChanges);
  const rs = $('presetReset'); if(rs) rs.addEventListener('click', () => { const p = presets.find(x => x.id === active); if(p) pick(p); });
  // Any change in the panel outside this group: the chip reads edited or not.
  const panel = document.getElementById('p-layers');
  if(panel) panel.addEventListener('click', e => { if(!e.target.closest('#presets')) setTimeout(renderChips, 0); });
  // The page's own preset applies once, over whatever the map remembered: the map's, or Scout review's on that page (map-presets.md section 1).
  const page = CFG.page === 'scout' ? 'scout' : 'map';
  const def = presets.find(p => p.uses.includes(page));
  if(def) pick(def); else renderChips();
  // Plan a ride and Ride tools switch to their preset when their panel opens; opening Layers from
  // there keeps it, so it can be tweaked, and so does a place drawer closing the panel (looking at
  // a place is part of planning); anything else puts the switches back.
  document.addEventListener('cc:panel', e => {
    const key = e.detail && e.detail.key;
    const byDrawer = !!(e.detail && e.detail.byDrawer);
    const next = TOOL_OF_PANEL[key] || null;
    if(tool && (next === tool || key === 'layers' || byDrawer)) return;
    if(tool){ apply(before.settings); active = before.active; baseline = before.baseline; tool = null; before = null; toolLine(null); renderChips(); }
    const p = next && presets.find(x => x.uses.includes(next));
    if(!p) return;
    before = { settings: readState(), active, baseline };
    tool = next; pick(p); toolLine(next, p);
  });
}
