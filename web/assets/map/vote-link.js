// SPDX-License-Identifier: AGPL-3.0-only
/* The season ballot link a drawer offers (docs/specs/route-domain.md §8d).
   The ballot reads the region from the row itself, so the link names only
   the category and the row. */

/** Map layer key to the ballot category (an ItemType value). */
export const VOTE_CATEGORY = Object.freeze({
  climbs: 'climbs',
  stays: 'where-to-sleep',
  scenic: 'scenic-views',
  history: 'history-culture',
  experience: 'quality-rides',
});

/** The ballot URL with this row picked, or null when the row cannot get a vote. */
export function voteHref(base, layerKey, id) {
  const cat = Object.prototype.hasOwnProperty.call(VOTE_CATEGORY, layerKey) ? VOTE_CATEGORY[layerKey] : null;
  if (!base || !cat || id == null || !/^[1-9]\d*$/.test(String(id))) return null;
  return `${base}?cat=${encodeURIComponent(cat)}&pick=${encodeURIComponent(String(id))}`;
}
