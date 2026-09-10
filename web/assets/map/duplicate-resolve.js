// SPDX-License-Identifier: AGPL-3.0-only
/* Resolve a duplicate finding on the map (?finding=<id>).
 *
 * The data desk can only ask the question flat: two names, two ids, a distance.
 * That is enough for "Signal de Botrange twice at 54 m" and nowhere near enough
 * for "Ligne KW", where the honest answer is that these are two bunkers along
 * one defence line and both should stay. Standing the two pins in the landscape
 * is what makes that answerable, so the desk links here for exactly that.
 *
 * Three answers, and the desk's yes/no cannot express the first two:
 *   keep this one   → the OTHER row is retired, whichever the ranking preferred
 *   keep the other  → same, the other way round
 *   keep both       → a dismissal; the finding never comes back
 *
 * Curator-only by construction: /moderate/data/finding/<id> is behind
 * ROLE_CURATOR and scoped to the curator's own area, so a rider following a
 * shared link gets a 404 and this module quietly does nothing.
 */
import { map } from './map-init.js';
import { D } from './i18n.js';
import { widenForDeepLink } from './coverage.js';

const ENDPOINT = '/moderate/data/finding/';

export function initDuplicateResolve() {
  const raw = new URLSearchParams(location.search).get('finding');
  if (!raw || !/^\d+$/.test(raw)) return;

  fetch(ENDPOINT + raw, { headers: { Accept: 'application/json' } })
    .then(r => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
    .then(d => {
      if (d && Array.isArray(d.items) && d.items.length >= 2) showResolve(d);
    })
    .catch(() => { /* not a curator, or already decided: leave the map alone */ });
}

let markers = [];

function clearMarkers() {
  markers.forEach(m => m.remove());
  markers = [];
}

function showResolve(finding) {
  clearMarkers();
  const pts = [];
  finding.items.forEach((it, i) => {
    const lat = Number(it.lat);
    const lng = Number(it.lng);
    if (!isFinite(lat) || !isFinite(lng)) return;
    pts.push([lng, lat]);

    // Numbered, because the panel below refers to them as 1 and 2. A colour
    // difference alone would not survive two pins 3 m apart on a phone.
    const el = document.createElement('div');
    el.className = 'cc-dupe-pin';
    el.textContent = String(i + 1);
    markers.push(new maplibregl.Marker({ element: el, anchor: 'center' })
      .setLngLat([lng, lat]).addTo(map));
  });

  // The rows may sit outside the current scope; a resolve link that shows an
  // empty map would be worse than no link. The scope follows the first row
  // to its country.
  if (pts.length) { try { widenForDeepLink([pts[0][1], pts[0][0]]); } catch (e) { /* noop */ } }
  if (pts.length >= 2) fitBoth(pts);

  document.getElementById('drawerBody').innerHTML = panelHtml(finding);
  const d = document.getElementById('drawer');
  d.classList.add('open');
  d.setAttribute('aria-hidden', 'false');
  d.focus({ preventScroll: true });
}

/* Two points 1 m apart must not zoom to street level and two 500 m apart must
   both be on screen: fitBounds does the second, maxZoom stops the first. */
function fitBoth(pts) {
  const b = pts.reduce(
    (acc, p) => acc.extend(p),
    new maplibregl.LngLatBounds(pts[0], pts[0]),
  );
  map.fitBounds(b, { padding: 90, maxZoom: 17, duration: 500 });
}

function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

function panelHtml(finding) {
  const token = (window.CC_FINDING_TOKEN || '');
  const rows = finding.items.map((it, i) => `
    <li class="cc-dupe-row">
      <span class="cc-dupe-n">${i + 1}</span>
      <span class="cc-dupe-nm">${esc(it.name)}
        <small>#${esc(it.id)} &middot; ${esc(it.source)}</small></span>
      <form method="post" action="/moderate/data/decide">
        <input type="hidden" name="_token" value="${esc(token)}">
        <input type="hidden" name="finding" value="${esc(finding.id)}">
        <input type="hidden" name="keep" value="${esc(it.id)}">
        <button type="submit">${esc(D.dupeKeepThis || 'Keep this one')}</button>
      </form>
    </li>`).join('');

  return `
    <div class="cc-dupe">
      <h2>${esc(D.dupeSamePlace || 'Are these the same place?')}</h2>
      <ul class="cc-dupe-list">${rows}</ul>
      <form method="post" action="/moderate/data/decide" class="cc-dupe-both">
        <input type="hidden" name="_token" value="${esc(token)}">
        <input type="hidden" name="finding" value="${esc(finding.id)}">
        <input type="hidden" name="verdict" value="no">
        <button type="submit">${esc(D.dupeKeepBoth || 'Keep both')}</button>
      </form>
    </div>`;
}
