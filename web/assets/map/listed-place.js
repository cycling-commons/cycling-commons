// SPDX-License-Identifier: AGPL-3.0-only
/* A row in a drawer that names a place: the ride check's lists
   (docs/specs/map-and-search.md §9) and a route's climbs (§6.3). The row opens
   the place through the normal map path, so the map's own renderers draw it,
   and its hover ring sits where that pin really is. Nothing here draws a pin. */
import { D } from './i18n.js';
import { active, mode, layerByKey } from './catalog.js';
import { openRouteById } from './places.js';
import { liftModeFor } from './panels.js';
import { chipsPass, staysAccessible, showPlaceAnyway, featureVisible } from './render.js';
import { mapToast, highlightAt, clearHighlight, setDrawerHop } from './drawer.js';
import { poolPinDrawn } from './osm-pools.js';
import { flyToPin } from './map-init.js';
import { itemIndex } from './item-index.js';
import { widenForDeepLink } from './coverage.js';
import { inScope } from './scope-ui.js';
import { ringOffset } from './ride-places.js';
import { routeClimbsHtml } from './route-climbs.js';

/**
 * Open one listed place so the map really draws it, then run `go`. The view
 * mode lifts with the deep-link rule (liftModeFor, never persisted); a place
 * the rider's own filter chips hide is shown anyway, that one place only, until
 * the drawer moves on (showPlaceAnyway in render.js; the chips never change).
 * One toast names every reason the place was hidden.
 *
 * Answers the mode from before a lift, or null when the mode stayed, so a
 * caller that puts the rider's mode back later (the ride check) can remember it.
 */
export function openListedPlace(layer, f, go){
  // Stays are drawn by their pool, whose one chip is accessibility (osm-pools.js).
  const chipHidden = layer.key==='stays' ? !staysAccessible(f) : !chipsPass(layer, f);
  if(chipHidden) showPlaceAnyway(layer.letter, f.id);
  const from = mode();
  const lifted = liftModeFor(layer, f, chipHidden ? {also:D.toastFilterToo||'your filter hides it too'} : undefined);
  if(!lifted && chipHidden) mapToast(D.toastShownAnyway||'Shown anyway · your filter hides this place', {center:true});
  go();
  return lifted ? from : null;
}

/* The route drawer's "Climbs on this route" (docs/specs/map-and-search.md §6.3).
   Fetched when the drawer opens: the list is per route and the catalog payload
   stays as it is. The answer lands only in the slot of the route it was asked
   for, so a drawer that moved on in the meantime is left alone. A row opens the
   climb through its item-index entry, the same path search and the ride check
   use, after moving the scope to the climb's country when the scope hides it
   (widenForDeepLink); hover rings the climb's foot. */
export function hydrateRouteClimbs(routeId){
  const slotFor = () => {
    const el = document.getElementById('cc-d-climbs-slot');
    return el && el.dataset.route === String(routeId) ? el : null;
  };
  if(!slotFor()) return;
  fetch(`/map/route/${encodeURIComponent(routeId)}/climbs`, {credentials:'same-origin', headers:{'Accept':'application/json'}})
    .then(r => r.ok ? r.json() : null)
    .catch(() => null)
    .then(d => {
      const slot = slotFor();
      if(!slot || !d || !Array.isArray(d.climbs)) return;
      const html = routeClimbsHtml(d.climbs, {heading:D.routeClimbsH, at:D.routeClimbAt, avg:D.avgShort});
      if(!html) return;
      slot.innerHTML = html;
      slot.hidden = false;
      const byId = new Map(d.climbs.map(c => [String(c.id), c]));
      const idx = new Map(itemIndex().filter(e => e.letter === 'N' && e.id != null).map(e => [String(e.id), e]));
      slot.querySelectorAll('[data-route-climb]').forEach(b => {
        const c = byId.get(b.dataset.routeClimb), entry = idx.get(b.dataset.routeClimb);
        if(!c) return;
        b.onclick = () => {
          clearHighlight();
          if(!entry){ flyToPin([c.ll[1], c.ll[0]]); highlightAt(c.ll); return; }
          if(!inScope(entry.rid)) widenForDeepLink(c.ll);
          // The climb drawer offers the way back to this route (§6.3).
          const route = (layerByKey.experience && layerByKey.experience.features || []).find(x => String(x.id) === String(routeId));
          setDrawerHop({label: route && route.name ? route.name : (D.backToRoute || 'Back to route'), go: () => openRouteById(routeId)}, 'N:'+c.id);
          openListedPlace(entry.layer, entry.modeF, () => entry.go());
        };
        // The climb's pin stands at its foot (util.js pinPoint), which is `c.ll`.
        b.onmouseenter = () => highlightAt(c.ll, ringOffset(listedPinDrawn(entry), entry && entry.hlOff));
        b.onmouseleave = clearHighlight;
      });
    });
}

/** Whether the normal map draws a bottom-anchored pin for this item-index entry right now, so the hover ring may sit on the pin body. */
export function listedPinDrawn(entry){
  if(!entry || !entry.layer) return false;
  if(entry.poolKey) return poolPinDrawn(entry.poolKey, entry.id);
  return active.has(entry.layer.key) && featureVisible(entry.layer, entry.modeF||{});
}
