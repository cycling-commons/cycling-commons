// SPDX-License-Identifier: AGPL-3.0-only
/* How a loaded ride check steers the normal map (docs/specs/map-and-search.md §9).
   A leaf: no DOM, no map. The ride draws only its track; the places it lists
   are drawn by the map's own renderers, and these are the decisions they share. */

/** `letter:id` of every commons place the ride lists, across its groups. */
export function listedPlaceKeys(groups){
  const keys = new Set();
  (Array.isArray(groups) ? groups : []).forEach(g => {
    if(!g || !g.letter || !Array.isArray(g.items)) return;
    g.items.forEach(it => { if(it && it.id != null) keys.add(g.letter + ':' + it.id); });
  });
  return keys;
}

/**
 * One pool, split for drawing. `cluster` feeds the clustered source; `leaves`
 * are the listed places, drawn as their own leaf pins so none folds into a
 * count bubble. `visible(f)` is the pool's normal rule (scope, view mode), and
 * it judges both halves: a place the mode hides is in neither.
 */
export function splitPool(features, letter, listed, visible){
  const cluster = [], leaves = [];
  (features || []).forEach(f => {
    if(!visible(f)) return;
    const id = f && f.properties ? f.properties.id : null;
    if(id != null && listed && listed.has(letter + ':' + id)) leaves.push(f);
    else cluster.push(f);
  });
  return { cluster, leaves };
}

/**
 * The view mode a loaded ride lifted, so Clear can put the rider's own back,
 * the way it puts their scope back. `lifted(from, to)` records each lift and
 * keeps the mode from before the first one; `riderChose()` forgets it, because
 * a mode the rider picked while the ride was loaded is their choice. `restore`
 * answers the mode to go back to, or null, and forgets either way. It answers
 * null too when the mode is no longer the one the ride lifted to.
 */
export function createRideModeMemo(){
  let before = null, liftedTo = null;
  return {
    lifted(from, to){ if(before == null) before = from; liftedTo = to; },
    riderChose(){ before = null; liftedTo = null; },
    restore(current){
      const back = before != null && current === liftedTo ? before : null;
      before = null; liftedTo = null;
      return back;
    },
  };
}

/** The hover ring's offset: onto the pin body only when a bottom-anchored pin is drawn at the spot. */
export function ringOffset(pinDrawn, pinOffset){
  return pinDrawn && Array.isArray(pinOffset) ? pinOffset : [0, 0];
}

/* Which "back" button a place drawer shows (docs/specs/map-and-search.md §6.3,
   §9). `base` is the lasting return of a loaded ride; `hop` is a one-step
   return to the list a place was opened from, such as a route's climbs, kept
   only while that very place (`hop.forKey`, `letter:id`) is on screen. The
   nearest step back wins. Any other place drops the hop. */
export function pickDrawerReturn(base, hop, keys){
  const list = Array.isArray(keys) ? keys : [keys];
  if(hop && list.includes(hop.forKey)) return { target: hop, keepHop: true };
  return { target: base || null, keepHop: false };
}

/** Every key a place drawer answers to: `letter:id` for a commons row, `letter:ref` for an open coverage point. */
export function drawerPlaceKeys(letter, f){
  const keys = [];
  if(f && f.id != null) keys.push(letter + ':' + f.id);
  if(f && f.osmRef) keys.push(letter + ':' + f.osmRef);
  return keys;
}

/**
 * Whether a drawer about to open keeps the route held open behind it
 * (docs/specs/map-and-search.md §6.3).
 *
 * A picked route stays picked (owner 2026-09-16): its line stays thick, the
 * others stay faded, and its listed places stay leaf pins, while the rider
 * opens anything else on the map. Only two things take the focus off it: the
 * rider closing the drawer by hand, which is `closeDrawer()`'s job and never
 * reaches here, and picking ANOTHER route, which is the one case this answers
 * false to. Before that, opening any place that was not in the route's own
 * lists dropped the route the rider was reading, so a tap on a water pin
 * beside the line lost the line.
 */
export function keepsRouteHold(hold, next){
  if(!hold || !next) return false;
  if(next.routeId != null && String(next.routeId) !== String(hold.routeId)) return false;
  return true;
}

/** One set of listed places from every list showing at once (a loaded ride, an open route), so a place either lists stands as a leaf pin. */
export function mergeListed(byOwner){
  const out = new Set();
  byOwner.forEach(keys => (keys || []).forEach(k => out.add(k)));
  return out;
}


/**
 * The letters whose listed places differ between two merged sets (`letter:id`
 * keys). Only those pools need their clustered source re-split: each re-split
 * is a setData that costs the map a full re-render, so an unchanged set
 * answers none.
 */
export function listedLettersChanged(prev, next){
  const letters = new Set();
  const letterOf = k => String(k).slice(0, String(k).indexOf(':'));
  (prev || new Set()).forEach(k => { if(!next || !next.has(k)) letters.add(letterOf(k)); });
  (next || new Set()).forEach(k => { if(!prev || !prev.has(k)) letters.add(letterOf(k)); });
  return letters;
}
