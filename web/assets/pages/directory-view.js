// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Map / list toggle on the pages directory. The two views are both in the
   page and the toggle is a pair of plain links (?view=map, ?view=list), so
   everything works with no script. This file only makes the switch instant
   and remembers the last choice for the next visit.

   A file rather than an inline block, so the page carries no CSP nonce and
   can be held in a shared cache (docs/specs/page-caching.md §3.2). */
document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('dir');
  if (!root) return;
  const KEY = 'cc.pages.view';
  const views = { map: document.getElementById('dir-map'), list: document.getElementById('dir-list') };
  const buttons = Array.from(root.querySelectorAll('[data-view]'));

  const show = (name) => {
    if (!views[name]) return;
    for (const [k, el] of Object.entries(views)) el.hidden = k !== name;
    for (const b of buttons) b.setAttribute('aria-pressed', b.dataset.view === name ? 'true' : 'false');
  };
  const remember = (name) => { try { localStorage.setItem(KEY, name); } catch (e) { /* private mode: nothing to remember into */ } };

  for (const b of buttons) {
    b.addEventListener('click', (ev) => {
      ev.preventDefault();
      show(b.dataset.view);
      remember(b.dataset.view);
      history.replaceState(null, '', b.getAttribute('href'));
    });
  }

  // A remembered choice wins only when the URL does not say otherwise.
  if (!new URLSearchParams(location.search).has('view')) {
    let saved = null;
    try { saved = localStorage.getItem(KEY); } catch (e) { /* see above */ }
    if (saved && views[saved]) show(saved);
  }
});
