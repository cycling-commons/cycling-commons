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

/* The stays Bike-friendly chip (coverage-provider.md §4, `cyl`), opt-in:
   while it is on, only a stay with a cyclist label passes. */
export function bikeFriendlyMatch(labels, on){
  return !on || (Array.isArray(labels) && labels.length > 0);
}

/* How many coverage stays the stays chips leave, from the server's kept numbers
   (coverage-provider.md §11, /map/coverage/counts `stays`): Bike-friendly keeps
   the labelled ones, Wheelchair-accessible switched off drops the wheelchair=yes
   ones, the tile filter's two rules. No numbers: the total, never a guess. */
export function stayCoverageShown(total, facets, chips){
  if(!facets) return total;
  const base = chips.bikeFriendly ? facets.cyl : total;
  const out = chips.wheelchairOut ? (chips.bikeFriendly ? facets.cyl_acc : facets.acc) : 0;
  return Math.max(0, base - out);
}

/** A tile's `cyl` prop ("Bett+Bike;Accueil Vélo") as the label list. */
export function tileLabels(cyl){
  return String(cyl ?? '').split(';').filter(Boolean);
}

/* Routes for your bikes (map-and-search.md §4.4): a chip that is on is used, as in every chip row. A route for a bike
   that is on stays, a route that declares no bike type stays while any chip is on, and no chip on keeps no route.
   Example: Road on keeps a Road route and one with no bike type, and hides an MTB route. */
export function routeBikeMatch(bikeTypes, activeBikes){
  if(!activeBikes || !activeBikes.size) return false;
  if(!Array.isArray(bikeTypes) || !bikeTypes.length) return true;   // undeclared → keep
  return bikeTypes.some(t => activeBikes.has(t));
}

/** The bike chips a route row starts with: the rider's saved bikes, every bike when none is saved. */
export function startBikes(riderBikes, allBikes){
  const all = allBikes || [];
  const mine = all.filter(b => (riderBikes || []).includes(b));
  return mine.length ? mine : all.slice();
}

/* The route bike chips remembered in this browser, kept to the bike types the map has. `stored` is the JSON list
   under the current key; an empty or unreadable one, or none, is the start set (startBikes), because the map opens
   with Recommended routes on. `legacy` is the key from before a chip that is on was used: there an empty list, or
   'off', meant no filter, so it reads as every bike; 'on' meant the saved bikes.
   Example: legacy '[]' → all eight bikes; stored '["MTB"]' → ['MTB']. */
export function readRouteBikes(stored, legacy, riderBikes, allBikes){
  const all = allBikes || [], start = startBikes(riderBikes, all);
  const list = v => { try{ const l = JSON.parse(v); return Array.isArray(l) ? all.filter(b => l.includes(b)) : null; }catch(e){ return null; } };
  if(stored != null){
    const l = list(stored);
    return l && l.length ? l : start;
  }
  if(legacy == null || legacy === 'on') return start;
  if(legacy === 'off') return all.slice();
  const l = list(legacy);
  return !l ? start : l.length ? l : all.slice();
}

/* A Best of chip row as the /map/best-of query reads it (docs/specs/map-and-search.md §4.0): a chip that is on is
   used, one that is off is not, as in every chip row. null when none is on: no Best of route can show, so nothing is
   asked. '' when every chip is on: the server reads an empty list as the whole vocabulary. Else the CSV of the ones on.
   Example: seasons on ['summer'] of four → 'summer'; all four → ''; none → null. */
export function bestOfParam(on, all){
  const picked = (all || []).filter(v => (on || []).includes(v));
  if(!picked.length) return null;
  return picked.length === all.length ? '' : picked.join(',');
}

/** A chip group narrows the map exactly when something in it is deselected. */
export function narrowed(activeSet, allSet){
  return !!(activeSet && allSet) && activeSet.size < allSet.size;
}

// The four chip facets and the route bikes narrow by deselection (.f-match). Bike-friendly is .f-optin.
const FACETS = ['surface', 'traffic', 'effort', 'access'];

/** How many filter groups are currently making the map show less. */
export function narrowingCount(state){
  let n = 0;
  for(const key of FACETS){
    const g = state[key];
    if(g && narrowed(g.active, g.all)) n++;
  }
  // Route bikes with no chip on are the Recommended routes switch off, not a filter.
  const rb = state.routeBikes;
  if(rb && rb.active && rb.active.size && narrowed(rb.active, rb.all)) n++;
  // .f-optin: narrows while it is ON
  if(state.bikeFriendlyOn) n++;
  return n;
}

/** One place across every renderer, as `letter:id`; null when it has no id. */
export function placeKey(letter, id){
  return letter && id != null ? letter + ':' + id : null;
}

/* docs/specs/map-and-search.md §4.3, §9: a place opened from a ride row that
   the rider's own chips hide is shown anyway while its drawer is up. One place
   at a time; the chips themselves never change. `show` and `releaseUnless`
   answer whether anything changed, so the caller redraws only then. */
export function createShownAnyway(){
  let key = null;
  return {
    has: k => k != null && k === key,
    show(k){
      if(k == null || k === key) return false;
      key = k;
      return true;
    },
    releaseUnless(k){
      if(key == null || k === key) return false;
      key = null;
      return true;
    },
  };
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
