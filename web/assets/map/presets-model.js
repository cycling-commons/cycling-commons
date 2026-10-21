// SPDX-License-Identifier: AGPL-3.0-only
/* The pure half of the map presets (docs/specs/map-presets.md section 1 and
   3): a preset's settings normalised, whether two states are the same, the
   taps that take the panel from one state to another, and the chips that
   summarise a preset. No DOM, no network; presets.js and the settings page
   script own those. */

export const MAX_PRESETS = 6;
export const MODES = ['curated', 'confirmed', 'all'];
/* Road surface has no layer row: the Surfaces overlay switches it (catalog.js), so a preset names it under OVERLAYS only. */
export const LAYER_KEYS = ['climbs', 'water', 'toilets', 'services', 'stays', 'hazards', 'transit', 'shelter', 'scenic', 'history', 'experience'];
export const OVERLAYS = ['surface', 'routes'];
export const SEASONS = ['spring', 'summer', 'autumn', 'winter'];
export const FACET_GROUPS = ['sqf', 'trf', 'effortf', 'accessf'];
/* The key's switches (owner 2026-10-15); a preset without them leaves the key as it is. */
export const SURFACES = ['paved', 'gravel', 'pave', 'dirt', 'rock', 'unverified'];
export const ROUTE_TYPES = ['national', 'regional', 'mtb', 'knoop'];

const sortedUnique = (list, allowed) => {
  const set = new Set((Array.isArray(list) ? list : []).filter(v => typeof v === 'string' && (!allowed || allowed.includes(v))));
  return [...set].sort();
};

/** The settings with every key present, unknown keys dropped, every list sorted and unique. */
export function normalize(s){
  const src = s && typeof s === 'object' ? s : {};
  const facets = {};
  const raw = src.facets && typeof src.facets === 'object' ? src.facets : {};
  for(const g of FACET_GROUPS){ if(Array.isArray(raw[g])) facets[g] = sortedUnique(raw[g]); }
  const out = {
    mode: MODES.includes(src.mode) ? src.mode : 'all',
    layers: sortedUnique(src.layers, LAYER_KEYS),
    overlays: sortedUnique(src.overlays, OVERLAYS),
    seasons: sortedUnique(src.seasons, SEASONS),
    bikes: sortedUnique(src.bikes),
    facets,
  };
  if(Array.isArray(src.surfaces)) out.surfaces = sortedUnique(src.surfaces, SURFACES);
  if(Array.isArray(src.routes)) out.routes = sortedUnique(src.routes, ROUTE_TYPES);
  return out;
}

/** Whether two settings describe the same switches. */
export function same(a, b){ return JSON.stringify(normalize(a)) === JSON.stringify(normalize(b)); }

/**
 * The taps that take the panel from `current` to `target`, each a control
 * whose state differs: mode first, then the layer rows, the overlay rows, the
 * season and bike chips, the facet chips. `facetAll` names every chip of each
 * facet group; a group the target leaves out means every chip on.
 */
export function clicksFor(current, target, facetAll){
  const c = normalize(current), t = normalize(target), out = [];
  if(c.mode !== t.mode) out.push({ kind: 'mode', value: t.mode });
  const flip = (kind, now, want, order) => { for(const v of order){ const a = now.includes(v), b = want.includes(v); if(a !== b) out.push({ kind, value: v }); } };
  flip('layer', c.layers, t.layers, LAYER_KEYS);
  flip('overlay', c.overlays, t.overlays, OVERLAYS);
  flip('season', c.seasons, t.seasons, SEASONS);
  flip('bike', c.bikes, t.bikes, [...new Set([...c.bikes, ...t.bikes])].sort());
  // The key's rows: only when the preset names them.
  if(t.surfaces) flip('surface', c.surfaces || SURFACES, t.surfaces, SURFACES);
  if(t.routes) flip('route', c.routes || ROUTE_TYPES, t.routes, ROUTE_TYPES);
  for(const g of FACET_GROUPS){
    const all = (facetAll && facetAll[g]) || [];
    if(!all.length) continue;
    const now = c.facets[g] || all, want = t.facets[g] || all;
    for(const v of all){ if(now.includes(v) !== want.includes(v)) out.push({ kind: 'facet', group: g, value: v }); }
  }
  return out;
}

/** The chips that say what a preset switches on: the mode, each layer, each overlay, each season, each bike; `{key?, text, kind}`. */
export function summaryChips(settings, layerLabels, modeLabels){
  const s = normalize(settings), chips = [];
  chips.push({ kind: 'mode', text: (modeLabels || {})[s.mode] || s.mode });
  for(const k of LAYER_KEYS){ if(s.layers.includes(k)) chips.push({ kind: 'layer', key: k, text: (layerLabels || {})[k] || k }); }
  for(const o of OVERLAYS){ if(s.overlays.includes(o)) chips.push({ kind: 'overlay', key: o, text: o }); }
  for(const v of s.seasons) chips.push({ kind: 'season', key: v, text: v });
  for(const v of s.bikes) chips.push({ kind: 'bike', key: v, text: v });
  return chips;
}
