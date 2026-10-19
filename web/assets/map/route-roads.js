// SPDX-License-Identifier: AGPL-3.0-only
/* "Roads on this route" in the route drawer (docs/specs/route-domain.md §6.4, owner 2026-10-19: "A recommended route
   should also have the road 'quality' info. Like when you plan your own route"), a fold closed until opened: the surfaces, the main roads without
   a cycle lane, the smoothness, the track grades and the speed limits along the route, in the planner's own words
   (route-planner.md section 4.4a), from GET /map/route/{id}/roads. Each line lights its stretches on the route while
   pointed at or focused; closing the card takes the light away (clearRouteRoads()). */
import { map } from './map-init.js';
import { I18N, D, tpl } from './i18n.js';
import { escPend } from './util.js';
import { uKm, uDistUnit } from './units.js';
import { listWaitHtml } from './along-list.js';
import { routePathById } from './catalog.js';
import { sliceAlong } from './plan-model.js';
import { roadsRows, roadsSpans } from './route-roads-model.js';

const SRC = 'route-roads-hl';
const P = () => I18N.plan || {};

/** The slot the drawer renders for a route with a database id, `waitLabel` until the answer is in. */
export function routeRoadsSlot(f, waitLabel) {
  return f && f.id != null ? `<div class="cc-d-roads" id="cc-d-roads-slot" data-route="${escPend(f.id)}" aria-busy="true">${listWaitHtml(waitLabel)}</div>` : '';
}

const dist = m => (m > 0 && m < 50 ? tpl(P().underDist || 'under {d}', { d: uKm(0.1) }) : uKm(m / 1000));

function cardHtml(d) {
  const p = P(), t = p.detail || {}, rows = roadsRows(d);
  const part = { paved: p.partPaved || 'on asphalt', gravel: p.partGravel || 'on gravel', rough: p.partRough || 'on rough road', unknown: p.partUnknown || 'surface not recorded' };
  const li = (key, text) => `<li data-part="${escPend(key)}" tabindex="0">${escPend(text)}</li>`;
  const list = (head, items) => items.length ? `<h5>${escPend(head)}</h5><ul class="cc-plan-daylegend">${items.join('')}</ul>` : '';
  const speedText = k => (k === 'unset' ? t.unset || 'no limit recorded'
    : uDistUnit() === 'mi' ? Math.round(Number(k) / 1.609344 / 5) * 5 + ' mph' : k + ' km/h');
  const surface = rows.surface.map(([k, m]) => li(k, `${part[k]} · ${dist(m)}`));
  if (rows.busyM > 0) surface.push(li('busy', tpl(p.busy || '{d} on main roads without a cycle lane', { d: dist(rows.busyM) })));
  const speeds = rows.detail.speeds.some(([k]) => k !== 'unset') ? rows.detail.speeds : [];
  const html = list(D.routeRoadsSurface || 'Surface', surface)
    + list(t.smooth || 'Smoothness', rows.detail.smoothness.map(([k, m]) => li('sm:' + k, `${(t.sm || {})[k] || k} · ${dist(m)}`)))
    + list(t.track || 'Tracks', rows.detail.tracktype.map(([k, m]) => li('tt:' + k, `${(t.tt || {})[k] || k} · ${dist(m)}`)))
    + list(t.speed || 'Speed limit', speeds.map(([k, m]) => li('sp:' + k, `${speedText(k)} · ${dist(m)}`)));
  if (!html) return '';
  // A fold, closed until the rider opens it (owner 2026-10-19), headed as the card's other sections are.
  return `<details class="cc-d-roadsfold"><summary class="cc-near-h">${escPend(D.routeRoadsH || 'Roads on this route')}</summary>`
    + `<div class="cc-plan-detail">${html}<p class="cc-plan-detailnote">${escPend(t.h || 'From OpenStreetMap, where it is recorded')}</p></div></details>`;
}

function light(routeId, d, part, own) {
  if (!map.getSource(SRC)) {
    map.addSource(SRC, { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
    map.addLayer({ id: SRC, type: 'line', source: SRC, layout: { 'line-cap': 'round', 'line-join': 'round' },
      // A yellow band over the route: the selected route is drawn orange already.
      paint: { 'line-color': '#FFD60A', 'line-width': ['interpolate', ['linear'], ['zoom'], 8, 7, 16, 14], 'line-opacity': 0.95 } });
  }
  // The card's own line first: a route opened from a link or a list may not be among the routes the map holds.
  const path = part ? ((Array.isArray(own) && own.length >= 2 ? own : null) || routePathById(routeId)) : null;
  const leg = path ? { routed: true, line: path.map(([lat, lng]) => [lng, lat]), distM: d.distanceM } : null;
  const lines = leg ? roadsSpans(d, part).map(([a, b]) => sliceAlong([leg], a, b)).filter(l => l.length >= 2) : [];
  map.getSource(SRC).setData({ type: 'FeatureCollection', features: lines.map(c => ({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: c } })) });
}

/** The route's lit stretches go away: the card closed, or another opened. */
export function clearRouteRoads() {
  const src = map.getSource(SRC);
  if (src) src.setData({ type: 'FeatureCollection', features: [] });
}

/** Fill the slot for `routeId`, `path` the card's own line ([lat, lng]); the slot goes when the route has no answer. */
export function hydrateRouteRoads(routeId, path) {
  const slotFor = () => {
    const el = document.getElementById('cc-d-roads-slot');
    return el && el.dataset.route === String(routeId) ? el : null;
  };
  if (!slotFor()) return;
  fetch(`/map/route/${encodeURIComponent(routeId)}/roads`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(r => (r.ok ? r.json() : null))
    .catch(() => null)
    .then(d => {
      const slot = slotFor(); if (!slot) return;
      slot.removeAttribute('aria-busy');
      const html = d ? cardHtml(d) : '';
      if (!html) { slot.innerHTML = ''; slot.hidden = true; return; }
      slot.innerHTML = html;
      const on = e => { const el = e.target.closest && e.target.closest('[data-part]'); if (el && slot.contains(el)) light(routeId, d, el.dataset.part, path); };
      const off = e => { const to = e.relatedTarget; if (!(to && to.closest && to.closest('[data-part]') && slot.contains(to))) light(routeId, d, null, path); };
      slot.addEventListener('mouseover', on); slot.addEventListener('focusin', on);
      slot.addEventListener('mouseout', off); slot.addEventListener('focusout', off);
    });
}
