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

/* Routes for your bikes (map-and-search.md §4.4): a route for any chosen bike
   stays, a route that declares no bike type always stays; no chip on, no filter. */
export function routeBikeMatch(bikeTypes, activeBikes){
  if(!activeBikes || !activeBikes.size) return true;
  if(!Array.isArray(bikeTypes) || !bikeTypes.length) return true;   // undeclared → keep
  return bikeTypes.some(t => activeBikes.has(t));
}

/* The bike chips remembered in this browser, kept to the bike types the map
   has: a JSON list, or the old single chip's on/off. Nothing stored, or a value
   that does not read, means the rider's saved bikes on. */
export function readPrefBikes(stored, riderBikes, allBikes){
  const mine = Array.isArray(riderBikes) ? riderBikes : [];
  if(stored === 'off') return [];
  if(stored == null || stored === 'on') return mine.slice();
  try{
    const list = JSON.parse(stored);
    return Array.isArray(list) ? (allBikes || []).filter(b => list.includes(b)) : mine.slice();
  }catch(e){ return mine.slice(); }
}

/** A chip group narrows the map exactly when something in it is deselected. */
export function narrowed(activeSet, allSet){
  return !!(activeSet && allSet) && activeSet.size < allSet.size;
}

// The four chip facets narrow by deselection (.f-match). Preference and Bike-friendly are .f-optin.
const FACETS = ['surface', 'traffic', 'effort', 'access'];

/** How many filter groups are currently making the map show less. */
export function narrowingCount(state){
  let n = 0;
  for(const key of FACETS){
    const g = state[key];
    if(g && narrowed(g.active, g.all)) n++;
  }
  if(state.prefFilterOn) n++;   // .f-optin: these narrow while they are ON
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
