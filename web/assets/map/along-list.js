// SPDX-License-Identifier: AGPL-3.0-only
/* "What is along" a line: the lists the ride check summary shows for an
   uploaded GPX (docs/specs/map-and-search.md §9) and the route drawer shows for
   a recommended route (§6.3). A leaf: no map, no globals. The server
   decides what is in the corridor (RideCheckService); this writes the rows,
   and bindAlongMore folds and unfolds a long group under the root it is given.
   Opening and hovering a row go through the normal map path
   (listed-place.js bindAlongList), so nothing here draws a pin. */
import { escPend, txtOn } from './util.js';
import { uKm, uM } from './units.js';

const NEUTRAL = { color: '#6b6f5e', glyph: '•' };
const fill = (s, vars) => String(s).replace(/\{(\w+)\}/g, (m, k) => vars[k] != null ? vars[k] : m);

/** Rows a group shows before its "Show N more" button. */
export const ALONG_VISIBLE = 2;
// A group folds only when the button would hide at least this many rows.
const MIN_FOLDED = 2;
// Folded-rows ids, unique across every list written in this page.
let restSeq = 0;

function groupRows(groups, attr, metaFor, L){
  return groups.map(g => {
    const meta = Object.assign({}, NEUTRAL, { label: g.letter }, metaFor(g.letter) || {});
    const grpHead = toggle => `<li class="cc-near-grp"><span class="cc-near-k" style="background:${meta.color};color:${txtOn(meta.color)}">${meta.glyph || '•'}</span>${escPend(meta.label)} · ${g.items.length}${g.truncated ? ` ${L.capped || '(capped)'}` : ''}${toggle}</li>`;
    const rows = g.items.map((it, i) => `<li><button class="cc-near" ${attr}="${escPend(g.letter)}" data-rc-i="${i}"><span class="cc-near-nm">${escPend(it.name || meta.label)}</span><em>${fill(L.kmOff || '{a} along · {b} off', { a: uKm(it.alongKm), b: uM(it.distM) })}</em></button></li>`);
    const folded = rows.length - ALONG_VISIBLE;
    if(folded < MIN_FOLDED) return grpHead('') + rows.join('');
    // The folded rows sit in the DOM from the start, so bindAlongList binds them
    // with the rest. The toggle sits at the right end of the group's header
    // line (owner 2026-09-30), so it stays in one place whether the group is
    // open or folded.
    const id = `cc-near-rest-${++restSeq}`;
    const more = fill(L.more || 'Show {n} more', { n: folded }), fewer = L.fewer || 'Show fewer';
    const toggle = `<button type="button" class="cc-near-more" aria-expanded="false" aria-controls="${id}" data-more="${escPend(more)}" data-fewer="${escPend(fewer)}">${escPend(more)}</button>`;
    return grpHead(toggle) + rows.slice(0, ALONG_VISIBLE).join('')
      + `<li class="cc-near-rest" id="${id}" hidden><ul class="cc-near-list">${rows.slice(ALONG_VISIBLE).join('')}</ul></li>`;
  }).join('');
}

/**
 * The commons arm (`d.groups`) under its heading, or the empty line, then the
 * open-coverage arm (`d.coverage`) under its own heading and note when it has
 * any rows. Commons rows carry `data-rc-g` + `data-rc-i`, coverage rows
 * `data-rc-c` + `data-rc-i`. `o` = {metaFor(letter) -> {color, label, glyph},
 * labels: {commonsH, within, coverageH, empty, capped, kmOff, covNote, more,
 * fewer}}; `within`, when given, says the corridor width under the first
 * heading. The climbs group (letter N) holds the climbs near the line that it
 * does not ride; the ones it rides have their own section (route-climbs.js).
 * A group shows its first ALONG_VISIBLE rows; the rest wait folded behind a
 * "Show {n} more" button (bindAlongMore) when there are at least two of them,
 * and the header keeps the full count.
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

/**
 * Wire the "Show {n} more" buttons alongListHtml wrote under `root`: a press
 * unfolds that group's rows in place and the button reads "Show fewer"; a second
 * press folds them again and keeps the button in view. The button keeps focus
 * throughout, and says its state in `aria-expanded`.
 */
export function bindAlongMore(root){
  root.querySelectorAll('.cc-near-more[aria-controls]').forEach(b => {
    const rest = root.querySelector('#' + b.getAttribute('aria-controls'));
    if(!rest) return;
    b.onclick = () => {
      const open = b.getAttribute('aria-expanded') !== 'true';
      rest.hidden = !open;
      b.setAttribute('aria-expanded', String(open));
      b.textContent = open ? b.dataset.fewer : b.dataset.more;
      // Folding a long group keeps its header, and so the toggle, in view.
      if(!open && typeof b.scrollIntoView === 'function') b.scrollIntoView({ block: 'nearest' });
    };
  });
}

/** A drawer list on its way: the drawer's spinner and a short line saying what it is looking for. */
export function listWaitHtml(label){
  return `<div class="cc-d-photo-wait cc-d-list-wait"><span class="cc-d-spin" aria-hidden="true"></span><span role="status">${escPend(label || '')}</span></div>`;
}

/** The slot the route drawer renders for a route with a database id, showing `waitLabel` until its lists arrive (listed-place.js hydrateRouteAlong fills it, or removes it when there is nothing to show). */
export function routeAlongSlot(f, waitLabel){
  return f && f.id != null ? `<div class="cc-d-along" id="cc-d-along-slot" data-route="${escPend(f.id)}" aria-busy="true">${listWaitHtml(waitLabel)}</div>` : '';
}
