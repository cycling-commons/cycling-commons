// SPDX-License-Identifier: AGPL-3.0-only
/* Map shell: icon rail and drawer (docs/specs/map-and-search.md §4).
   One section at a time; the same icon, ✕, or Escape closes it. Ships closed.
   Nothing else opens it. The drawer is a flex sibling of #map, so MapLibre
   must be told to resize after the width transition. */

import { map } from './map-init.js';
import { I18N } from './i18n.js';

// Panel key → drawer heading; same three sections as the rail.
const TITLES = () => ({
  search: I18N.railSearch || 'Search & region',
  layers: I18N.railLayers || 'Layers & filters',
  tools: I18N.railTools || 'Ride tools',
  key: I18N.railKey || 'Map key',
});

let active = null;

/* MapLibre only re-measures when told. Resize on transitionend (new box) and
   after 220ms (prefers-reduced-motion fires no transitionend). */
function resizeWhenSettled(dwr){
  const once = () => map.resize();
  dwr.addEventListener('transitionend', function h(e){
    if(e.propertyName !== 'width') return;
    dwr.removeEventListener('transitionend', h);
    once();
  });
  setTimeout(once, 220);
}

/* Close the rail panel (search, layers, tools, key) from anywhere. A feature
   drawer opening means the rider has chosen: the panel would otherwise keep
   half the map, and a route framed for the drawer would sit behind it
   (owner 2026-09-16). docs/specs/map-and-search.md §4. */
export function closeRailPanel(){
  const dwr = document.getElementById('dwr');
  if(!dwr || !dwr.classList.contains('open')) return false;
  const x = document.getElementById('dwr-x');
  if(x){ x.click(); return true; }
  dwr.classList.remove('open');
  dwr.setAttribute('aria-hidden', 'true');
  return true;
}

export function initShell(){
  const dwr = document.getElementById('dwr');
  const title = document.getElementById('dwr-title');
  const closeBtn = document.getElementById('dwr-x');
  const buttons = [...document.querySelectorAll('.ib[data-panel]')];
  if(!dwr || !title || !buttons.length) return;

  function paintButtons(key){
    buttons.forEach(b => {
      const on = b.dataset.panel === key;
      b.classList.toggle('on', on);
      b.setAttribute('aria-expanded', on ? 'true' : 'false');
    });
  }

  function openPanel(key){
    document.querySelectorAll('.dwr .panel').forEach(p => p.classList.toggle('on', p.id === 'p-' + key));
    paintButtons(key);
    title.textContent = TITLES()[key] || '';
    dwr.classList.add('open');
    dwr.setAttribute('aria-hidden', 'false');
    active = key;
    resizeWhenSettled(dwr);
  }

  function closePanel(){
    dwr.classList.remove('open');
    dwr.setAttribute('aria-hidden', 'true');
    paintButtons(null);
    active = null;
    resizeWhenSettled(dwr);
  }

  buttons.forEach(b => {
    b.onclick = () => (active === b.dataset.panel ? closePanel() : openPanel(b.dataset.panel));
  });
  if(closeBtn) closeBtn.onclick = closePanel;

  /* Escape closes the drawer only when nothing nearer owns the key: search
     dropdown, feature drawer, lightbox, climb profile. */
  const searchRes = document.getElementById('searchRes');
  document.addEventListener('keydown', e => {
    if(e.key !== 'Escape' || !active) return;
    if(searchRes && !searchRes.hidden) return;
    if(document.querySelector('.cc-lightbox.open, .cc-cp.open, .cc-drawer.open')) return;
    closePanel();
  });

  closePanel();   // closed on load: the map is the hero
}

/* Dot on the Layers icon when a chip filter is narrowing the map.
   render.js owns the count; the on-map pill is the other half. */
export function setFilterDot(on){
  const b = document.getElementById('ib-layers');
  if(b) b.classList.toggle('filtered', !!on);
}
