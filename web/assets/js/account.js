// SPDX-License-Identifier: AGPL-3.0-only
// Account dashboard tab switching. Client-side only when the pane is on this page.
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
        if (document.getElementById('p-' + a.dataset.pane)) { e.preventDefault(); activate(a.dataset.pane); }
      });
    });

    var initial = new URLSearchParams(window.location.search).get('tab');
    if (initial && document.getElementById('p-' + initial)) activate(initial);
  });

  // data-confirm on the form: CSP-safe, no inline handler.
  ready(function () {
    document.addEventListener('submit', function (e) {
      var f = e.target;
      if (f && f.dataset && f.dataset.confirm && !window.confirm(f.dataset.confirm)) e.preventDefault();
    });
  });
})();
