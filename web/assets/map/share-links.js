// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The query a share link carries, and how it is read back
   (docs/specs/map-and-search.md §8).

   A link is pasted into a chat and read before it is clicked, so it says what
   it opens: `?ref=node/462149319/roche-aux-faucons`, not an id on its own. The
   id comes first and decides everything; the slug is decoration and is thrown
   away on the way back in. A place that gets renamed, and a link somebody
   trimmed by hand, both still open the same point.

   A leaf apart from osm-tags.js, so the node tests can import it directly. */

import { osmRefUrl } from './osm-tags.js';

/* A name as URL text: accents folded, everything else a hyphen. Names OSM
   holds in a non-latin script fold away to nothing, and then the link is just
   the id rather than a row of hyphens. */
export function slugify(name, max = 60){
  const s = String(name ?? '')
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')   // fold accents: Côte -> Cote
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
  if(s.length <= max) return s;
  const cut = s.slice(0, max);
  return (cut.replace(/-[^-]*$/, '') || cut).replace(/-+$/, '');   // never end mid-word
}

/* The name worth putting in a link, which is not always `f.name`. An OSM point
   with no `name` tag is titled by its category, and every unnamed scenic view
   is drawn as "Viewpoint". Those drawers therefore set `shareName` only when
   the mapper actually wrote one. A catalog item's name is its own. */
function shareName(f){
  if(!f) return '';
  return f.shareName != null ? f.shareName : (f.id != null ? (f.name || '') : '');
}

/* docs/specs/map-and-search.md §8: `item=<id>`, then `ref=<osm ref>`, and
   `feature=<name>` last. Returns '' when the feature has nothing unique. */
export function shareQuery(f){
  const slug = slugify(shareName(f));
  const tail = slug ? '/' + slug : '';
  if(f && f.id != null) return 'item=' + encodeURIComponent(f.id) + tail;
  // Already `node|way/<digits>` (osmRefUrl proved it), so the slashes are safe
  // unencoded, and a %2F in the middle would defeat the point of the slug.
  if(f && osmRefUrl(f.osmRef)) return 'ref=' + f.osmRef + tail;
  if(f && f.name) return 'feature=' + encodeURIComponent(f.name);
  return '';
}

/* `node/462149319/roche-aux-faucons` -> `node/462149319`; null for anything
   `/map/coverage/poi/{osmType}/{osmId}` would refuse. */
export function refFromShare(value){
  const m = String(value ?? '').match(/^((?:node|way)\/\d+)(?:\/|$)/);
  return m ? m[1] : null;
}

/* `482/col-du-rosier` -> `482`. Bare `?item=482` is unchanged, so links already
   sent out keep working. */
export function idFromShare(value){
  return String(value ?? '').split('/')[0];
}
