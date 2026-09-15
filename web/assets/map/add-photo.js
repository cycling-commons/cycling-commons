// SPDX-License-Identifier: AGPL-3.0-only
/* Where the drawer's "add the first photo" prompt goes
   (docs/specs/map-and-search.md §6). A place with a real database id opens
   its own /improve form on the photo step; a live recommended route opens
   /propose-route?route=<id>, the route's photo form (route-domain.md §4.5).
   Nothing for a row with no id, and nothing for a route still waiting for
   review, which takes no contributions until it is live. */
export function addPhotoHref(layer, f){
  if(!f || f.id == null) return null;
  if(layer && layer.key === 'experience'){
    return f.state === 'submitted' ? null : `/propose-route?route=${encodeURIComponent(f.id)}`;
  }
  return `/improve?item=${f.id}&name=${encodeURIComponent(f.name)}&type=${layer.letter}&add=photo`;
}
