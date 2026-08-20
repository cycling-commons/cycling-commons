// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The map shell: the icon rail and the drawer it opens (map-and-search.md §4).

   One section at a time. Pressing a rail icon opens that section; pressing the
   same icon again, the ✕, or Escape closes the drawer. Nothing else on the
   page drives it - no window global, no cross-module calls - because "which
   panel is open" is chrome state and every module that wanted to reach for it
   would be reaching past the rider's own choice.

   The drawer is a flex sibling of the map canvas, so opening it genuinely
   narrows #map. MapLibre does not observe that on its own, hence the resize
   after the width transition settles. */

import { map } from './map-init.js';
import { I18N } from './i18n.js';

// Panel key -> the drawer heading. Same three sections the rail declares in
// data-panel, and the same strings its tooltips carry.
const TITLES = () => ({
  search: I18N.railSearch || 'Search & region',
  layers: I18N.railLayers || 'Layers & filters',
  tools: I18N.railTools || 'Ride tools',
});

let active = null;

/* MapLibre re-measures its canvas only when told to. The drawer animates its
   width over 180ms, so a resize fired now would measure the old box; one fired
   on transitionend measures the new one. The timer is not belt-and-braces: a
   rider on prefers-reduced-motion has no transition at all, so transitionend
   never fires for them. Resizing twice is cheap and idempotent. */
function resizeWhenSettled(dwr){
  const once = () => map.resize();
  dwr.addEventListener('transitionend', function h(e){
    if(e.propertyName !== 'width') return;
    dwr.removeEventListener('transitionend', h);
    once();
  });
  setTimeout(once, 220);
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

  /* Escape closes the drawer only when the drawer is what is open. The feature
     drawer, the lightbox and the climb profile bind Escape too, and they are
     modal over the map while this panel is beside it - so a rider pressing
     Escape to dismiss a photo must not also lose the section they were
     reading. Those overlays are checked by their own open state. */
  document.addEventListener('keydown', e => {
    if(e.key !== 'Escape' || !active) return;
    const modalOpen = document.querySelector('.cc-lightbox.open, .cc-cp.open, .cc-drawer.open');
    if(modalOpen) return;
    closePanel();
  });

  // Drawer closed on load: the map is the hero. (The prototype opened Layers
  // to demo itself; the real page has a map to show.)
  closePanel();
}

/* The rail's half of "why is data missing?": a dot on the Layers icon whenever
   a chip filter is narrowing what the map draws. render.js owns the count and
   calls this; the on-map pill is the other half (Task 6 of the shell plan). */
export function setFilterDot(on){
  const b = document.getElementById('ib-layers');
  if(b) b.classList.toggle('filtered', !!on);
}
