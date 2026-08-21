// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Build marker. window.CC_VERSION is emitted from the release tag; this file only paints it. */

(function () {
  var T = window.ccT || function (k, fb) { return fb; };
  var V = window.CC_VERSION || { number: 'dev', date: '' };
  var label = T('build', 'Build') + ' ' + V.number + (V.date ? ' · ' + V.date : '');

  function stamp() {
    var foot = document.querySelector('.foot .mono, footer .mono');
    if (foot) {
      var s = document.createElement('span');
      s.className = 'cc-ver';
      s.textContent = ' · ' + label.toUpperCase();
      foot.appendChild(s);
      return;
    }
    var rail = document.querySelector('.dwr-foot');
    if (rail) {
      var r = document.createElement('span');
      r.className = 'cc-ver';
      r.innerHTML = (T('build', 'Build') + ' ' + V.number).toUpperCase() + (V.date ? '<br>' + V.date : '');
      r.style.cssText = 'font-family:var(--mono,monospace);font-size:.58rem;letter-spacing:.08em;opacity:.7;line-height:1.35;text-align:right';
      rail.appendChild(r);
      return;
    }
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
