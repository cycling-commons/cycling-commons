// SPDX-License-Identifier: AGPL-3.0-only
/* Chip-filter rules as pure functions (docs/specs/map-and-search.md §4.3).
   No DOM, no map — so node tests can load this without map-init.js. */

/* Unknown is not a verdict: an item with no value for the attribute is not
   filtered out. Only an item that HAS a value can be judged. Same rule as
   prefMatch() for route bike types. */
export function attrMatch(value, activeSet, allSet){
  if(!activeSet) return true;                    // group not on the page → no filter
  if(activeSet.size === allSet.size) return true;  // nothing narrowed → nothing hidden
  // Multiselect (stays' accessibility): match if ANY selected option is present.
  if(Array.isArray(value)) return value.length ? value.some(v => activeSet.has(v)) : true;
  return value ? activeSet.has(value) : true;
}

/** A chip group narrows the map exactly when something in it is deselected. */
export function narrowed(activeSet, allSet){
  return !!(activeSet && allSet) && activeSet.size < allSet.size;
}

// The four chip facets narrow by deselection (.f-match). Preference is .f-optin.
const FACETS = ['surface', 'traffic', 'effort', 'access'];

/** How many filter groups are currently making the map show less. */
export function narrowingCount(state){
  let n = 0;
  for(const key of FACETS){
    const g = state[key];
    if(g && narrowed(g.active, g.all)) n++;
  }
  if(state.prefFilterOn) n++;   // .f-optin: this one narrows while it is ON
  return n;
}

/** True when a climb still matches every chip facet that applies to it. */
export function climbChipsMatch(f, state){
  return attrMatch(f.sq, state.surface.active, state.surface.all)
    && attrMatch(f.tr, state.traffic.active, state.traffic.all)
    && attrMatch(f.effort, state.effort.active, state.effort.all);
}

/* docs/specs/map-and-search.md §4.2: the three rungs, lowest first. */
export const MODE_RUNGS = ['curated', 'confirmed', 'all'];

/** Does view mode `m` draw feature `f` on `layer`? The mode half of
 *  render.js featureVisible(); scope and chips are judged there. */
export function modeShows(m, layer, f){
  if(m === 'all') return true;
  if(m === 'confirmed') return !!f.v || !!f.cur;
  // curated: experiential layers filter to the picks; utility layers draw as before.
  return layer.key === 'experience' ? !!f.cur : (!layer.exp || !!f.cur);
}

/** docs/specs/map-and-search.md §8: a deep link must show what it points at.
 *  Returns the lowest rung at or above `from` that draws `f`, or null when
 *  `from` already does (nothing to lift). */
export function modeToShow(from, layer, f){
  if(modeShows(from, layer, f)) return null;
  const i = MODE_RUNGS.indexOf(from);
  return MODE_RUNGS.slice(i < 0 ? 0 : i + 1).find(m => modeShows(m, layer, f)) || null;
}
