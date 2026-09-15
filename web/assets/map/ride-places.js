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

/** The hover ring's offset: onto the pin body only when a bottom-anchored pin is drawn at the spot. */
export function ringOffset(pinDrawn, pinOffset){
  return pinDrawn && Array.isArray(pinOffset) ? pinOffset : [0, 0];
}
