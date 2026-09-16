// SPDX-License-Identifier: AGPL-3.0-only
/* "What is along" a line: the lists the ride check summary shows for an
   uploaded GPX (docs/specs/map-and-search.md §9) and the route drawer shows for
   a recommended route (§6.3). A leaf: no DOM, no map, no globals. The server
   decides what is in the corridor (RideCheckService); this writes the rows.
   Opening and hovering a row go through the normal map path
   (listed-place.js bindAlongList), so nothing here draws a pin. */
import { escPend, txtOn } from './util.js';
import { uKm, uM } from './units.js';

const NEUTRAL = { color: '#6b6f5e', glyph: '•' };
const fill = (s, vars) => String(s).replace(/\{(\w+)\}/g, (m, k) => vars[k] != null ? vars[k] : m);

function groupRows(groups, attr, metaFor, L){
  return groups.map(g => {
    const meta = Object.assign({}, NEUTRAL, { label: g.letter }, metaFor(g.letter) || {});
    const head = `<li class="cc-near-grp"><span class="cc-near-k" style="background:${meta.color};color:${txtOn(meta.color)}">${meta.glyph || '•'}</span>${escPend(meta.label)} · ${g.items.length}${g.truncated ? ` ${L.capped || '(capped)'}` : ''}</li>`;
    return head + g.items.map((it, i) => `<li><button class="cc-near" ${attr}="${escPend(g.letter)}" data-rc-i="${i}"><span class="cc-near-nm">${escPend(it.name || meta.label)}</span><em>${fill(L.kmOff || '{a} along · {b} off', { a: uKm(it.alongKm), b: uM(it.distM) })}</em></button></li>`).join('');
  }).join('');
}

/**
 * The commons arm (`d.groups`) under its heading, or the empty line, then the
 * open-coverage arm (`d.coverage`) under its own heading and note when it has
 * any rows. Commons rows carry `data-rc-g` + `data-rc-i`, coverage rows
 * `data-rc-c` + `data-rc-i`. `o` = {metaFor(letter) -> {color, label, glyph},
 * labels: {commonsH, within, coverageH, empty, capped, kmOff, covNote}}; `within`,
 * when given, says the corridor width under the first heading.
 */
export function alongListHtml(d, o){
  const L = (o && o.labels) || {};
  const metaFor = (o && o.metaFor) || (() => null);
  const groups = (d && Array.isArray(d.groups) ? d.groups : []).filter(g => g && Array.isArray(g.items));
  let html = `<h4 class="cc-near-h">${L.commonsH || ''}</h4>`;
  if(L.within) html += `<div class="cc-near-note">${L.within}</div>`;
  html += groups.length
    ? `<ul class="cc-near-list">${groupRows(groups, 'data-rc-g', metaFor, L)}</ul>`
    : `<div class="cc-near-empty">${L.empty || ''}</div>`;
  const cov = (d && Array.isArray(d.coverage) ? d.coverage : []).filter(g => g && Array.isArray(g.items) && g.items.length);
  if(cov.length){
    html += `<h4 class="cc-near-h">${L.coverageH || ''}</h4><ul class="cc-near-list">${groupRows(cov, 'data-rc-c', metaFor, L)}</ul><div class="cc-near-note">${L.covNote || ''}</div>`;
  }
  return html;
}

/** A drawer list on its way: the drawer's spinner and a short line saying what it is looking for. */
export function listWaitHtml(label){
  return `<div class="cc-d-photo-wait cc-d-list-wait"><span class="cc-d-spin" aria-hidden="true"></span><span role="status">${escPend(label || '')}</span></div>`;
}

/** The slot the route drawer renders for a route with a database id, showing `waitLabel` until its lists arrive (listed-place.js hydrateRouteAlong fills it, or removes it when there is nothing to show). */
export function routeAlongSlot(f, waitLabel){
  return f && f.id != null ? `<div class="cc-d-along" id="cc-d-along-slot" data-route="${escPend(f.id)}" aria-busy="true">${listWaitHtml(waitLabel)}</div>` : '';
}
