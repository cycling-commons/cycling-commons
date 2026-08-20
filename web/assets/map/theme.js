// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The map chrome theme toggle (map-and-search.md §4.6). One attribute carries
   the whole theme: map.css keys its light palette on
   html[data-map-theme="light"], the server renders the rider's stored choice
   into that attribute, and this control only flips it and persists the flip.

   Persistence mirrors panels.js persistMode(): a logged-in rider's choice
   goes to their PROFILE via window.CC_MAP_THEME (emitted in the riders-only
   template block), an anonymous visitor's stays on the device in
   localStorage — the same key the template's inline head script reads before
   first paint. Fire-and-forget: a failed save must never block the map, and
   the theme is already applied locally. */

import { I18N } from './i18n.js';

const LS_KEY = 'cc:mapTheme';

const current = () =>
  document.documentElement.getAttribute('data-map-theme') === 'light' ? 'light' : 'dark';

function persist(t){
  const cfg = window.CC_MAP_THEME;
  if(cfg && cfg.url){
    const body = new URLSearchParams({theme: t, _token: cfg.token || ''});
    fetch(cfg.url, {method:'POST', credentials:'same-origin', body}).catch(()=>{});
    return;
  }
  try { localStorage.setItem(LS_KEY, t); } catch(e){ /* private mode */ }
}

// Line art rather than the ☀/☾ characters the bottom-left control used: the
// rail draws every icon as a stroked 24px SVG, and an emoji-font glyph among
// them sat on a different baseline at a different weight.
const SUN = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.5M12 19v2.5M2.5 12H5M19 12h2.5M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M19.1 4.9l-1.8 1.8M6.7 17.3l-1.8 1.8"/></svg>';
const MOON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8.5 8.5 0 0 1 9.5 4 8.5 8.5 0 1 0 20 14.5Z"/></svg>';

/** The sun/moon button in the rail's bottom cluster (#ib-theme). */
export function initTheme(){
  const b = document.getElementById('ib-theme');
  if(!b) return;
  // The glyph and label name the theme a press will GIVE you, the same
  // way the base switcher's buttons name the base they switch to.
  const paint = () => {
    const dark = current() === 'dark';
    b.innerHTML = dark ? SUN : MOON;
    const label = dark
      ? (I18N.themeToLight || 'Switch to light panels')
      : (I18N.themeToDark || 'Switch to dark panels');
    b.title = label;
    b.setAttribute('aria-label', label);
    b.setAttribute('data-tip', label);
  };
  b.onclick = () => {
    const next = current() === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-map-theme', next);
    persist(next);
    paint();
  };
  paint();
}
