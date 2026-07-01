// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Toggles the self-managed nav dropdown menus (the logged-in account chip and
// the language switcher). Each menu is a [data-nav-menu] wrapper containing a
// [data-nav-toggle] button and a [data-nav-dd] dropdown. Closes on outside
// click, Escape, menu-item choice, or when another menu opens. No-ops when no
// such menu is on the page. Keyboard-accessible (button + aria-expanded).
(function () {
  'use strict';
  if (window.__ccNavMenus) return;
  window.__ccNavMenus = true;

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    var wraps = Array.prototype.slice.call(document.querySelectorAll('[data-nav-menu]'));
    var menus = wraps.map(function (menu) {
      return { menu: menu, btn: menu.querySelector('[data-nav-toggle]'), dd: menu.querySelector('[data-nav-dd]') };
    }).filter(function (m) { return m.btn && m.dd; });
    if (!menus.length) return;

    function setOpen(m, open) {
      m.dd.hidden = !open;
      m.btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    function closeAll(except) {
      menus.forEach(function (m) { if (m !== except && !m.dd.hidden) setOpen(m, false); });
    }

    document.addEventListener('click', function (e) {
      // A click outside every menu closes them all.
      if (!menus.some(function (m) { return m.menu.contains(e.target); })) closeAll();
    }, true);

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      var open = menus.filter(function (m) { return !m.dd.hidden; });
      if (!open.length) return;
      closeAll();
      open[0].btn.focus();
    });

    menus.forEach(function (m) {
      m.btn.addEventListener('click', function (e) {
        e.stopPropagation();                 // don't let the outside-click handler see this
        var willOpen = m.dd.hidden;
        closeAll(m);
        setOpen(m, willOpen);
      });
      m.dd.addEventListener('click', function (e) {
        if (e.target.closest('a')) setOpen(m, false);  // navigating away — collapse
      });
    });
  });
})();
