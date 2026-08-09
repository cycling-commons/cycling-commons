// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Cycling Commons — build marker (shown on every page).
 *
 * window.CC_VERSION is emitted by the templates from the release tag (App\Service\BuildVersion:
 * `git describe --tags --match 'v*'` + HEAD's commit date) — cut a tag like v0.2.0 and every
 * footer follows it, nothing edited anywhere. This file only paints the stamp. It used to hold a
 * hand-edited 'Demo v0.1.2 · 2026-06-26' constant, which went stale the way every hand-edited
 * date does and said "demo" long after the site stopped being one (owner, 2026-08-09). */

(function () {
  var T = window.ccT || function (k, fb) { return fb; };   // ccT is absent on chrome-less pages
  var V = window.CC_VERSION || { number: 'dev', date: '' };   // chrome-less template missed the emit — still no broken footer
  var label = T('build', 'Build') + ' ' + V.number + (V.date ? ' · ' + V.date : '');

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
      r.innerHTML = (T('build', 'Build') + ' ' + V.number).toUpperCase() + (V.date ? '<br>' + V.date : '');   // date on its own line
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
