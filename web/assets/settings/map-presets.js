// SPDX-License-Identifier: AGPL-3.0-only
// Settings, Map presets (docs/specs/map-presets.md section 4): the list of a
// rider's presets with what each switches on, and an editor for one. The
// page hands the script the presets, the catalogue of switches with their
// labels and icons, the endpoints and the token as window.CC_PRESETS_PAGE.
(function () {
  'use strict';
  const CFG = window.CC_PRESETS_PAGE || {};
  const CAT = window.CC_PRESET_CATALOG || {};
  const T = CFG.strings || {};
  const root = document.getElementById('map-presets');
  if (!root || !CFG.save) return;
  let presets = Array.isArray(CFG.list) ? CFG.list.slice() : [];
  let editing = null;
  const $ = id => document.getElementById(id);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const tpl = (s, v) => String(s).replace(/\{(\w+)\}/g, (m, k) => (k in v ? v[k] : m));
  const icon = (svg, colour, ink) => `<i class="mp-ic" style="--c:${colour}${ink ? ';--ic:' + ink : ''}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="${svg}" fill="currentColor" fill-rule="evenodd"/></svg></i>`;
  const layerOf = k => (CAT.layers || []).find(l => l.key === k);
  const overlayOf = k => (CAT.overlays || []).find(o => o.key === k);
  const bikeLabel = v => ((CAT.bikes || []).find(b => b.value === v) || {}).label || v;
  const seasonLabel = v => ((CAT.seasons || []).find(s => s.value === v) || {}).label || v;
  const CTX = ['map', 'plan', 'ride', 'scout'];
  const useLabel = c => (T.uses || {})[c] || c;
  const usesOf = p => Array.isArray(p.uses) ? p.uses : [];
  /* A tool takes one preset: giving it to one takes it from every other. */
  const claim = (id, ctxs) => presets.forEach(p => { if (p.id !== id) p.uses = usesOf(p).filter(c => !ctxs.includes(c)); });

  function summary(p) {
    const s = p.settings || {}, out = [];
    out.push(`<span class="mp-chip"><i class="mp-ic" style="--c:#101E16"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm0 4.5a3.5 3.5 0 1 1 0 7 3.5 3.5 0 0 1 0-7Z" fill="currentColor"/></svg></i>${esc((CAT.modes || {})[s.mode] || s.mode)}</span>`);
    (CAT.layers || []).forEach(l => { if ((s.layers || []).includes(l.key)) out.push(`<span class="mp-chip">${icon(l.svg, l.color)}${esc(l.label)}</span>`); });
    (CAT.overlays || []).forEach(o => { if ((s.overlays || []).includes(o.key)) out.push(`<span class="mp-chip">${icon(o.svg, o.color, '#101E16')}${esc(o.label)}</span>`); });
    (s.seasons || []).forEach(v => out.push(`<span class="mp-chip">${esc(seasonLabel(v))}</span>`));
    (s.bikes || []).forEach(v => out.push(`<span class="mp-chip">${esc(bikeLabel(v))}</span>`));
    return out.join('');
  }

  function renderList() {
    const list = $('mpList'), note = $('mpCount'), add = $('mpNew');
    if (!presets.length) list.innerHTML = `<p class="help">${esc(T.none || 'No presets yet. Make one here, or on the map with Save as preset.')}</p>`;
    else list.innerHTML = presets.map(p => `<div class="mp-card${usesOf(p).includes('map') ? ' default' : ''}" data-id="${p.id}">
      <div class="mp-nm">${esc(p.name)}${CTX.filter(c => usesOf(p).includes(c)).map(c => `<span class="mp-tag${c === 'map' ? '' : ' muted'}">${esc(useLabel(c))}</span>`).join('')}</div>
      <div class="mp-acts"><button type="button" data-act="edit">${esc(T.edit || 'Edit')}</button>${usesOf(p).includes('map') ? '' : `<button type="button" data-act="default">${esc(T.makeDefault || 'Make default')}</button>`}<button type="button" data-act="delete">${esc(T.delete || 'Delete')}</button></div>
      <div class="mp-sum">${summary(p)}</div></div>`).join('');
    if (note) note.textContent = tpl(T.count || '{n} of {max} presets', { n: presets.length, max: CAT.max || 6 });
    if (add) add.disabled = presets.length >= (CAT.max || 6);
  }

  function openEditor(p) {
    editing = p ? JSON.parse(JSON.stringify(p)) : { id: null, name: '', uses: presets.length === 0 ? ['map'] : [], settings: { mode: 'all', layers: (CAT.layers || []).map(l => l.key), overlays: [], seasons: [], bikes: [], facets: {}, bikeFriendly: false } };
    const s = editing.settings;
    const ed = $('mpEditor'); ed.hidden = false;
    $('mpName').value = editing.name;
    $('mpMode').innerHTML = Object.entries(CAT.modes || {}).map(([k, l]) => `<button type="button" class="${s.mode === k ? 'on' : ''}" data-m="${k}">${esc(l)}</button>`).join('');
    const row = (key, label, svg, colour, on, ink) => `<div class="mp-row${on ? '' : ' off'}" data-k="${esc(key)}">${icon(svg, colour, ink)}<span class="mp-lbl">${esc(label)}</span><button type="button" class="mp-tog" role="switch" aria-checked="${on}" aria-label="${esc(label)}"></button></div>`;
    $('mpLayers').innerHTML = (CAT.layers || []).map(l => row(l.key, l.label, l.svg, l.color, (s.layers || []).includes(l.key))).join('');
    $('mpOverlays').innerHTML = (CAT.overlays || []).map(o => row(o.key, o.label, o.svg, o.color, (s.overlays || []).includes(o.key), '#101E16')).join('');
    const chip = (v, l, on) => `<button type="button" class="mp-pchip${on ? ' on' : ''}" data-v="${esc(v)}">${esc(l)}</button>`;
    $('mpSeasons').innerHTML = (CAT.seasons || []).map(x => chip(x.value, x.label, (s.seasons || []).includes(x.value))).join('');
    $('mpBikes').innerHTML = (CAT.bikes || []).map(x => chip(x.value, x.label, (s.bikes || []).includes(x.value))).join('');
    // The key's rows: a preset without them reads as every one on.
    $('mpSurfaces').innerHTML = (CAT.surfaces || []).map(x => chip(x.value, x.label, !Array.isArray(s.surfaces) || s.surfaces.includes(x.value))).join('');
    $('mpRoutes').innerHTML = (CAT.routes || []).map(x => chip(x.value, x.label, !Array.isArray(s.routes) || s.routes.includes(x.value))).join('');
    $('mpFacets').innerHTML = (CAT.facets || []).map(f => { const on = (s.facets || {})[f.group]; return `<div class="mp-facet" data-g="${f.group}"><span class="mp-flbl">${esc(f.label)}</span><span class="mp-pchips">${f.values.map(v => chip(v.value, v.label, !on || on.includes(v.value))).join('')}</span></div>`; }).join('');
    const bf = CAT.bikeFriendly || {};
    $('mpBikeFriendly').innerHTML = chip('yes', bf.chip || 'Bike-friendly', s.bikeFriendly === true);
    $('mpUse').innerHTML = CTX.map(c => chip(c, useLabel(c), usesOf(editing).includes(c))).join('');
    ed.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  function readEditor() {
    const s = editing.settings;
    s.mode = ($('mpMode').querySelector('button.on') || {}).dataset ? $('mpMode').querySelector('button.on').dataset.m : 'all';
    s.layers = [...$('mpLayers').querySelectorAll('.mp-row:not(.off)')].map(r => r.dataset.k);
    s.overlays = [...$('mpOverlays').querySelectorAll('.mp-row:not(.off)')].map(r => r.dataset.k);
    s.seasons = [...$('mpSeasons').querySelectorAll('.on')].map(c => c.dataset.v);
    s.bikes = [...$('mpBikes').querySelectorAll('.on')].map(c => c.dataset.v);
    s.surfaces = [...$('mpSurfaces').querySelectorAll('.on')].map(c => c.dataset.v);
    s.routes = [...$('mpRoutes').querySelectorAll('.on')].map(c => c.dataset.v);
    s.facets = {};
    $('mpFacets').querySelectorAll('.mp-facet').forEach(f => { const all = [...f.querySelectorAll('.mp-pchip')], on = all.filter(c => c.classList.contains('on')); if (on.length !== all.length) s.facets[f.dataset.g] = on.map(c => c.dataset.v); });
    s.bikeFriendly = !!$('mpBikeFriendly').querySelector('.on');
    return { id: editing.id, name: $('mpName').value.trim(), uses: [...$('mpUse').querySelectorAll('.on')].map(c => c.dataset.v), settings: s };
  }
  async function post(url, body) {
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CC-Token': CFG.token || '' }, body: JSON.stringify(body || {}) });
  }
  function say(text) { const el = $('mpStatus'); if (el) { el.textContent = text || ''; el.hidden = !text; } }

  $('mpNew').addEventListener('click', () => openEditor(null));
  $('mpCancel').addEventListener('click', () => { $('mpEditor').hidden = true; editing = null; });
  $('mpMode').addEventListener('click', e => { const b = e.target.closest('button'); if (!b) return; $('mpMode').querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b)); });
  ['mpLayers', 'mpOverlays'].forEach(id => $(id).addEventListener('click', e => { const t = e.target.closest('.mp-tog'); if (!t) return; const row = t.closest('.mp-row'), on = t.getAttribute('aria-checked') !== 'true'; t.setAttribute('aria-checked', String(on)); row.classList.toggle('off', !on); }));
  ['mpSeasons', 'mpBikes', 'mpFacets', 'mpBikeFriendly', 'mpUse', 'mpSurfaces', 'mpRoutes'].forEach(id => $(id).addEventListener('click', e => { const c = e.target.closest('.mp-pchip'); if (c) c.classList.toggle('on'); }));
  $('mpSave').addEventListener('click', async () => {
    const body = readEditor();
    if (!body.name) { $('mpName').focus(); return; }
    const r = await post(CFG.save, body);
    if (!r.ok) { say(r.status === 409 ? (T.full || 'You hold the most presets an account may.') : (T.failed || 'Could not save the preset.')); return; }
    const { id } = await r.json();
    const i = presets.findIndex(p => p.id === body.id);
    const saved = { id: body.id || id, name: body.name, uses: body.uses, settings: body.settings };
    claim(saved.id, body.uses);
    if (i >= 0) presets[i] = saved; else presets.push(saved);
    $('mpEditor').hidden = true; editing = null; say(''); renderList();
  });
  $('mpList').addEventListener('click', async e => {
    const b = e.target.closest('button'); if (!b) return;
    const id = Number(b.closest('.mp-card').dataset.id), p = presets.find(x => x.id === id);
    if (b.dataset.act === 'edit') { openEditor(p); return; }
    if (b.dataset.act === 'default') {
      const r = await post(CFG.use.replace('0', String(id)), { context: 'map' });
      if (!r.ok) { say(T.failed || 'Could not save the preset.'); return; }
      claim(id, ['map']); p.uses = [...new Set([...usesOf(p), 'map'])]; renderList(); return;
    }
    if (b.dataset.act === 'delete') {
      if (!window.confirm(T.deleteConfirm || 'Delete this preset?')) return;
      const r = await post(CFG.delete.replace('0', String(id)), {});
      if (!r.ok) { say(T.failed || 'Could not save the preset.'); return; }
      presets = presets.filter(x => x.id !== id); renderList();
    }
  });
  renderList();
})();
