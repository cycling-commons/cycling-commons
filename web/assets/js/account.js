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
    var tabs = document.querySelectorAll('.dtabs button[data-pane]');
    if (!tabs.length) return;

    function activate(pane) {
      Array.prototype.forEach.call(tabs, function (b) {
        var on = b.dataset.pane === pane;
        b.classList.toggle('on', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      Array.prototype.forEach.call(document.querySelectorAll('.dpane'), function (p) {
        p.classList.toggle('on', p.id === 'p-' + pane);
      });
    }

    Array.prototype.forEach.call(tabs, function (b) {
      b.addEventListener('click', function () { activate(b.dataset.pane); });
    });
  });
})();
