// SPDX-License-Identifier: AGPL-3.0-only
/* A row in a drawer that names a place: the ride check's lists
   (docs/specs/map-and-search.md §9) and a route's climbs and places along it
   (§6.3). The row opens the place through the normal map path, so the map's own
   renderers draw it, and its hover ring sits where that pin really is. Nothing
   here draws a pin. */
import { D, tpl } from './i18n.js';
import { active, mode, layerByKey, CATALOG, LETTER_KEY } from './catalog.js';
import { openRouteById } from './places.js';
import { liftModeFor } from './panels.js';
import { chipsPass, staysAccessible, showPlaceAnyway, featureVisible, render } from './render.js';
import { mapToast, highlightAt, clearHighlight, setDrawerHop } from './drawer.js';
import { poolPinDrawn, setListedPlaces } from './osm-pools.js';
import { flyToPin } from './map-init.js';
import { itemIndex } from './item-index.js';
import { widenForDeepLink, openCoverageByRef } from './coverage.js';
import { inScope } from './scope-ui.js';
import { ringOffset, listedPlaceKeys } from './ride-places.js';
import { routeClimbsHtml } from './route-climbs.js';
import { alongListHtml } from './along-list.js';
import { layerGlyph } from './icons.js';
import { uM } from './units.js';

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

/** Turn a layer on (its chip too), the way a rider's tap on the layer would. */
function showLayer(k){
  if(active.has(k)) return;
  active.add(k);
  const t = document.querySelector(`#layers .layer[data-key="${k}"]`); if(t) t.classList.remove('off');
  render();
}

/** A catalog layer's header facts for along-list.js, by letter. */
function catalogMeta(letter){
  const layer = CATALOG.find(l => l.letter === letter);
  return layer ? {color: layer.color, label: layer.label, glyph: layerGlyph(layer)} : null;
}

/**
 * Bind the rows alongListHtml wrote under `root` for the answer `d`
 * ({groups, coverage}). A commons row opens its item-index entry
 * (openListedPlace); a coverage row turns its layer on, lifts the view mode to
 * one that draws coverage (a coverage point carries no confirmation, so
 * modeShows judges it as `{}`), and opens through openCoverageByRef, whose
 * `cov-sel` overlay keeps the icon drawn. A row with nothing to open flies to
 * the point. Hover rings the pin.
 *
 * `o.beforeOpen(key)` runs before a place opens, with the key its drawer will
 * answer to (`letter:id`, or `letter:ref` for coverage); `o.onLifted(from, to)`
 * hears every view-mode lift; `o.widen` moves the scope to a place's country
 * when the scope hides it (widenForDeepLink), for a list that does not set the
 * scope itself.
 */
export function bindAlongList(root, d, o = {}){
  const idxByKey = new Map(itemIndex().map(x => [x.letter+':'+x.id, x]));   // one index pass, not one .find() per row
  const groupsByLetter = Object.fromEntries((d.groups||[]).map(g => [g.letter, g]));
  root.querySelectorAll('[data-rc-g]').forEach(b => {
    const letter = b.dataset.rcG, it = (groupsByLetter[letter]||{items:[]}).items[+b.dataset.rcI]; if(!it) return;
    const entry = idxByKey.get(letter+':'+it.id);
    b.onclick = () => {
      if(!entry){ flyToPin([it.ll[1], it.ll[0]]); highlightAt(it.ll); return; }
      if(o.widen && !inScope(entry.rid)) widenForDeepLink(entry.ll || it.ll);
      if(o.beforeOpen) o.beforeOpen(letter+':'+it.id);
      const from = openListedPlace(entry.layer, entry.modeF, () => entry.go());
      if(from != null && o.onLifted) o.onLifted(from, mode());
    };
    b.onmouseenter = () => highlightAt((entry && entry.ll) || it.ll, ringOffset(listedPinDrawn(entry), entry && entry.hlOff));
    b.onmouseleave = clearHighlight;
  });
  const covByLetter = Object.fromEntries((d.coverage||[]).map(g => [g.letter, g]));
  root.querySelectorAll('[data-rc-c]').forEach(b => {
    const letter = b.dataset.rcC, it = (covByLetter[letter]||{items:[]}).items[+b.dataset.rcI]; if(!it) return;
    b.onclick = () => {
      const k = LETTER_KEY[letter], layer = k && layerByKey[k];
      if(!it.ref || !layer){ flyToPin([it.ll[1], it.ll[0]]); highlightAt(it.ll); return; }
      if(o.widen) widenForDeepLink(it.ll);
      if(o.beforeOpen) o.beforeOpen(letter+':'+it.ref);
      showLayer(k);
      const from = mode();
      if(liftModeFor(layer, {}) && o.onLifted) o.onLifted(from, mode());
      openCoverageByRef(it.ref, letter, it.ll, it.name);
    };
    b.onmouseenter = () => highlightAt(it.ll);
    b.onmouseleave = clearHighlight;
  });
}

/* The one-step way back from a place to the route whose list opened it
   (§6.3). `routeId` rides along so the drawer keeps the route held. */
function routeHop(routeId){
  const route = (layerByKey.experience && layerByKey.experience.features || []).find(x => String(x.id) === String(routeId));
  return {label: route && route.name ? route.name : (D.backToRoute || 'Back to route'), go: () => openRouteById(routeId), routeId};
}

/* One per-route list in the route drawer, fetched when the drawer opens: the
   list is per route and the catalog payload stays as it is. The slot shows its
   waiting line (along-list.js listWaitHtml) until the answer arrives. The
   answer lands only in the slot of the route it was asked for, so a drawer that
   moved on in the meantime is left alone. `fill(slot, d)` answers whether it
   wrote a list; a failed request, or an answer with nothing to show, removes
   the waiting line and leaves the slot hidden. */
function hydrateRouteSlot(routeId, slotId, path, fill){
  const slotFor = () => {
    const el = document.getElementById(slotId);
    return el && el.dataset.route === String(routeId) ? el : null;
  };
  if(!slotFor()) return;
  fetch(`/map/route/${encodeURIComponent(routeId)}/${path}`, {credentials:'same-origin', headers:{'Accept':'application/json'}})
    .then(r => r.ok ? r.json() : null)
    .catch(() => null)
    .then(d => {
      const slot = slotFor(); if(!slot) return;
      slot.removeAttribute('aria-busy');
      if(d && fill(slot, d)){ slot.hidden = false; return; }
      slot.innerHTML = '';
      slot.hidden = true;
    });
}

/* The route drawer's "Climbs on this route" (docs/specs/map-and-search.md §6.3).
   A row opens the climb through its item-index entry, the same path search and
   the ride check use, after moving the scope to the climb's country when the
   scope hides it (widenForDeepLink); hover rings the climb's foot. */
export function hydrateRouteClimbs(routeId){
  hydrateRouteSlot(routeId, 'cc-d-climbs-slot', 'climbs', (slot, d) => {
    if(!Array.isArray(d.climbs)) return false;
    const html = routeClimbsHtml(d.climbs, {heading:D.routeClimbsH, at:D.routeClimbAt, avg:D.avgShort});
    if(!html) return false;
    slot.innerHTML = html;
    const byId = new Map(d.climbs.map(c => [String(c.id), c]));
    const idx = new Map(itemIndex().filter(e => e.letter === 'N' && e.id != null).map(e => [String(e.id), e]));
    slot.querySelectorAll('[data-route-climb]').forEach(b => {
      const c = byId.get(b.dataset.routeClimb), entry = idx.get(b.dataset.routeClimb);
      if(!c) return;
      b.onclick = () => {
        clearHighlight();
        if(!entry){ flyToPin([c.ll[1], c.ll[0]]); highlightAt(c.ll); return; }
        if(!inScope(entry.rid)) widenForDeepLink(c.ll);
        setDrawerHop(routeHop(routeId), 'N:'+c.id);   // the climb drawer offers the way back to this route
        openListedPlace(entry.layer, entry.modeF, () => entry.go());
      };
      // The climb's pin stands at its foot (util.js pinPoint), which is `c.ll`.
      b.onmouseenter = () => highlightAt(c.ll, ringOffset(listedPinDrawn(entry), entry && entry.hlOff));
      b.onmouseleave = clearHighlight;
    });
    return true;
  });
}

/* The route drawer's places along the route (docs/specs/map-and-search.md
   §6.3): the ride check's two lists (RideCheckService::alongRoute, the ride
   check's default corridor) written and bound by the same code as the ride
   summary. The route drawer holds its route (drawer.js holdRoute): while it is
   held, the listed pool places stay unclustered, and a place opened from a row
   keeps the route highlighted and offers "‹ route name" back. Letting the
   route go runs releaseRouteList. */
export function hydrateRouteAlong(routeId){
  hydrateRouteSlot(routeId, 'cc-d-along-slot', 'along', (slot, d) => {
    if(!Array.isArray(d.groups)) return false;
    const radius = uM(d.radiusM);
    slot.innerHTML = alongListHtml(d, {metaFor: catalogMeta, labels: {
      commonsH: D.alongRouteH || 'In the commons along the route',
      within: tpl(D.alongRouteWithin || 'Within {r} of the route', {r: radius}),
      coverageH: D.alongRouteCovH || 'Open coverage along the route',
      empty: tpl(D.nothingAlongRoute || 'Nothing in the Commons within {r} of this route yet.', {r: radius}),
      capped: D.capped, kmOff: D.kmOff, covNote: D.covArmNote,
    }});
    setListedPlaces(listedPlaceKeys(d.groups), 'route');
    bindAlongList(slot, d, {widen: true, beforeOpen: key => setDrawerHop(routeHop(routeId), key)});
    return true;
  });
}

/** The route's listed places go back into their clusters. */
export function releaseRouteList(){ setListedPlaces([], 'route'); }

/** Whether the normal map draws a bottom-anchored pin for this item-index entry right now, so the hover ring may sit on the pin body. */
export function listedPinDrawn(entry){
  if(!entry || !entry.layer) return false;
  if(entry.poolKey) return poolPinDrawn(entry.poolKey, entry.id);
  return active.has(entry.layer.key) && featureVisible(entry.layer, entry.modeF||{});
}
