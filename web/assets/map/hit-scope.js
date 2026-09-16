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
  const id = target && target.id;
  if(id == null) return null;                                    // outside every onboarded region: nothing better to show it
  if(current && current.kind === 'everywhere') return null;       // everything is drawn already
  if(current && (current.regionIds || []).indexOf(id) !== -1) return null;
  return { kind:'region', regionIds:[id], countryCode: (target && target.countryCode) || null };
}
