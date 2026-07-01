// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Account dashboard tab switching (Contributions / Votes / Saved regions /
// Settings). Client-side only — the panes are a preview; no server round-trip.
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    var tabs = document.querySelectorAll('.dtabs a[data-pane]');
    if (!tabs.length) return;

    function activate(pane) {
      Array.prototype.forEach.call(tabs, function (a) {
        a.classList.toggle('on', a.dataset.pane === pane && !!document.getElementById('p-' + pane));
      });
      Array.prototype.forEach.call(document.querySelectorAll('.dpane'), function (p) {
        p.classList.toggle('on', p.id === 'p-' + pane);
      });
    }

    Array.prototype.forEach.call(tabs, function (a) {
      a.addEventListener('click', function (e) {
        // Switch client-side only if this page has the pane; otherwise (the
        // Settings tab, or any tab while on /settings) let the link navigate.
        if (document.getElementById('p-' + a.dataset.pane)) { e.preventDefault(); activate(a.dataset.pane); }
      });
    });

    // Open a specific pane from ?tab= (the Votes/Saved links on /settings point
    // back here as /profile?tab=votes).
    var initial = new URLSearchParams(window.location.search).get('tab');
    if (initial && document.getElementById('p-' + initial)) activate(initial);
  });
})();
