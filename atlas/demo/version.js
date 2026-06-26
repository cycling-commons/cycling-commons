// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Cycling Commons — demo build marker (shown on every page).
 *
 * Maintenance: bump `number` by hand (whole numbers — v1, v2, v3 …) whenever you cut a new demo
 * build, and set `date` to the deploy date, in the same commit. That's the whole workflow — this
 * file is the single source of truth and it's wired into every page via <script src="version.js">.
 *
 * Why not auto-stamp the date in CI? The deploy workflows only SSH-trigger a server-side deploy
 * (the server pulls the repo and runs its own deploy command), so there's no in-CI filesystem step
 * that reaches the served files. A true auto deploy-timestamp would have to be a one-liner in the
 * server's deploy script (outside this repo). Keeping it a hand-edited constant here is simplest. */
window.CC_VERSION = { number: 'Demo v0.1.1', date: '2026-06-25' };

(function () {
  var V = window.CC_VERSION;
  var label = 'Build ' + V.number + (V.date ? ' · ' + V.date : '');

  function stamp() {
    // 1) content pages: append to the footer license line
    var foot = document.querySelector('.foot .mono, footer .mono');
    if (foot) {
      var s = document.createElement('span');
      s.className = 'cc-ver';
      s.textContent = ' · ' + label.toUpperCase();
      foot.appendChild(s);
      return;
    }
    // 2) the map: tuck it into the sidebar foot next to the result count
    var rail = document.querySelector('.rail-foot');
    if (rail) {
      var r = document.createElement('span');
      r.className = 'cc-ver';
      r.innerHTML = ('Build ' + V.number).toUpperCase() + (V.date ? '<br>' + V.date : '');   // date on its own line
      r.style.cssText = 'font-family:var(--mono,monospace);font-size:.58rem;letter-spacing:.08em;opacity:.7;line-height:1.35;text-align:right';
      rail.appendChild(r);
      return;
    }
    // 3) chrome-less pages (404, login …): a small fixed corner badge
    var b = document.createElement('div');
    b.className = 'cc-ver-badge';
    b.textContent = label;
    b.style.cssText = 'position:fixed;left:8px;bottom:6px;z-index:6;' +
      'font-family:var(--mono,"Spline Sans Mono",monospace);font-size:.54rem;letter-spacing:.1em;' +
      'text-transform:uppercase;color:rgba(239,230,212,.7);background:rgba(16,30,22,.62);' +
      'padding:.18rem .42rem;border-radius:4px;pointer-events:none';
    document.body.appendChild(b);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', stamp);
  else stamp();
})();
