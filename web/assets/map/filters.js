// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The chip-filter rules, as pure functions over plain data.

   They live in their own module for one reason: they are the answer to "why
   is my data missing?", the map's most-reported confusion, and an answer that
   cannot be executed in a test is an answer nobody can check. render.js
   imports maplibre through map-init.js, so nothing in it can be loaded by the
   node test runner; this file imports nothing at all and is loaded directly by
   tests/js/filter-tally.test.cjs.

   No DOM, no map, no module state. The caller passes the current chip state
   and gets an answer. */

/* Unknown is not a verdict.

   An item with NO value for the attribute is not filtered out - we know
   nothing about it, and hiding it would state something we have not been
   told. Only an item that HAS a value can be judged, and it survives if any
   of its values is still selected. This is the same rule prefMatch() applies
   to route bike types ("unknown is not unsuitable").

   It replaced an earlier "narrowing" rule where deselecting one chip also hid
   every valueless item. On stays that was indefensible: 0 of 291 carry
   `accessibility`, so unticking one box emptied the layer and told the rider
   there are no accessible stays, when what we actually have is no data.
   (2026-08-03, owner.) */
export function attrMatch(value, activeSet, allSet){
  if(!activeSet) return true;                    // group not on the page → no filter
  if(activeSet.size === allSet.size) return true;  // nothing narrowed → nothing hidden
  // A multiselect attribute (stays' accessibility) arrives as a list: the item
  // matches if it carries ANY of the selected options. A rider filtering for
  // "handbike-friendly" wants every stay that is handbike-friendly, not the
  // ones that are ONLY that.
  if(Array.isArray(value)) return value.length ? value.some(v => activeSet.has(v)) : true;
  return value ? activeSet.has(value) : true;
}

/** A chip group narrows the map exactly when something in it is deselected. */
export function narrowed(activeSet, allSet){
  return !!(activeSet && allSet) && activeSet.size < allSet.size;
}

// The four chip facets, all of which narrow by DESELECTION (the .f-match rule
// in the template). The preference chip is the opposite and is counted apart.
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
