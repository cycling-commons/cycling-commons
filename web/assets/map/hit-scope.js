// SPDX-License-Identifier: AGPL-3.0-only
/* Which scope a search hit or a deep link asks for (docs/specs/map-and-search.md
   §4.5, §8). A leaf: no DOM, no map, no storage - the decision alone, so it can
   be tested. */

/**
 * The smallest scope that shows one target, or null when the rider's own scope
 * already shows it and must be left alone.
 *
 * `current` is the active scope ({kind, regionIds, countryCode}); `target` is
 * the region the hit sits in ({id, countryCode}).
 *
 * The answer is the target's OWN region, never its country: a region is the
 * smallest area that draws the hit, and a country throws away more of the
 * rider's own scope than reaching one place needs (owner 2026-09-16: a town
 * card for Liege moved a Friesland rider to All Belgium). Everywhere already
 * shows everything, and a scope that already holds the region is already
 * looking there, so both answer null.
 */
export function hitScopeFor(current, target){
  return hitScopeForAll(current, [target]);
}

/**
 * The same answer for a target that spans several regions: a route whose line
 * crosses a border (docs/specs/map-and-search.md §8). `targets` is every region
 * the target touches ([{id, countryCode}], its own region first). The answer
 * holds all of them, the way a loaded ride does (ride-scope.js), so no part of
 * the route falls outside the scope; the country code is kept only when they
 * share one. Null when the rider's scope already holds every one of them.
 */
export function hitScopeForAll(current, targets){
  const list = (Array.isArray(targets) ? targets : []).filter(t => t && t.id != null);
  if(!list.length) return null;                                   // outside every onboarded region: nothing better to show it
  if(current && current.kind === 'everywhere') return null;       // everything is drawn already
  const held = (current && current.regionIds) || [];
  if(list.every(t => held.indexOf(t.id) !== -1)) return null;
  const ids = [...new Set(list.map(t => t.id))];
  const ccs = [...new Set(list.map(t => t.countryCode).filter(Boolean))];
  return { kind:'region', regionIds:ids, countryCode: ccs.length === 1 ? ccs[0] : null };
}
