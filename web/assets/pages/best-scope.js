// SPDX-License-Identifier: AGPL-3.0-only
/* The country picker on /best opens the country as soon as it is picked
   (owner 2026-10-03), so the Show button is only for a page without script.
   The form stays a plain GET: every choice is still its own URL
   (page-caching.md §3.2). */
(function () {
  'use strict';

  var form = document.querySelector('.frow[data-scope]');
  if (!form) { return; }
  var select = form.querySelector('select[name="cc"]');
  var show = form.querySelector('[data-scope-show]');
  if (!select) { return; }
  if (show) { show.hidden = true; }
  select.addEventListener('change', function () { form.submit(); });
})();
