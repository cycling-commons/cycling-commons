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

import { map } from './map-init.js';
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

/** The sun/moon control, bottom-left with the nav buttons and zoom badge. */
export function initTheme(){
  map.addControl({
    onAdd(){
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'maplibregl-ctrl theme-toggle';
      // The glyph and label name the theme a press will GIVE you, the same
      // way the base switcher's buttons name the base they switch to.
      const paint = () => {
        const dark = current() === 'dark';
        b.textContent = dark ? '☀' : '☾';   // ☀ / ☾
        const label = dark
          ? (I18N.themeToLight || 'Switch to light panels')
          : (I18N.themeToDark || 'Switch to dark panels');
        b.title = label;
        b.setAttribute('aria-label', label);
      };
      b.onclick = () => {
        const next = current() === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-map-theme', next);
        persist(next);
        paint();
      };
      paint();
      this._b = b;
      return b;
    },
    onRemove(){ this._b.remove(); },
  }, 'bottom-left');
}
