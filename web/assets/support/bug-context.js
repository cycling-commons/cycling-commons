// SPDX-License-Identifier: AGPL-3.0-only
/* Fill the "what else gets sent" block on /report-bug, and the hidden fields
   behind it. docs/specs/contact-and-support.md §5

   The panel captures browser and screen from JavaScript. Without this the plain
   page form sent neither, so the same bug reported through the two doors
   arrived with different amounts of context, and the page's own "what else gets
   sent" block would have been describing something it was not sending.

   With JavaScript off, the hidden fields stay empty and the block shows only
   the page. That is the honest state, not a broken one: the report is still
   filed, it just carries less. */
(function () {
  'use strict';

  var form = document.getElementById('cc-guarded-form');
  if (!form) return;

  var browserField = form.querySelector('input[name="browser"]');
  var viewportField = form.querySelector('input[name="viewport"]');
  var versionField = form.querySelector('input[name="app_version"]');
  if (!browserField) return;

  var browser = (navigator.userAgent || '').slice(0, 300);
  var viewport = window.innerWidth + 'x' + window.innerHeight +
    (window.devicePixelRatio && window.devicePixelRatio !== 1 ? ' @' + window.devicePixelRatio : '');
  /* CC_VERSION is an object ({number, date}), not a string. */
  var version = (window.CC_VERSION && window.CC_VERSION.number) || '';

  browserField.value = browser;
  if (viewportField) viewportField.value = viewport;
  if (versionField) versionField.value = version;

  /* Show the reporter exactly what those fields now hold. Same class names the
     panel uses, so the two blocks stay describable in one sentence. */
  var out = {
    '.bf-ctx-browser': browser,
    '.bf-ctx-viewport': viewport
  };
  Object.keys(out).forEach(function (sel) {
    var el = document.querySelector(sel);
    if (el) el.textContent = out[sel];
  });

  /* Four rows, not one: the browser and screen labels AND their values. */
  document.querySelectorAll('.ctx-jsonly').forEach(function (el) { el.hidden = false; });
})();
