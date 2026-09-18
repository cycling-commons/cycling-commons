// SPDX-License-Identifier: AGPL-3.0-only
/* Screenshot zoom: a button with `data-zoom="<dialog id>"` opens that <dialog>
   as a modal. Esc (native), the close button or a click on the backdrop shut
   it, and focus goes back to the button that opened it. A file, not inline
   handlers: the CSP blocks those silently. */
(function () {
  document.querySelectorAll('[data-zoom]').forEach(function (btn) {
    var dlg = document.getElementById(btn.getAttribute('data-zoom'));
    if (!dlg || typeof dlg.showModal !== 'function') return;
    btn.addEventListener('click', function () { dlg.showModal(); });
    dlg.addEventListener('click', function (e) {
      if (e.target === dlg || e.target.closest('[data-close]')) dlg.close();
    });
    dlg.addEventListener('close', function () { btn.focus(); });
  });
})();
