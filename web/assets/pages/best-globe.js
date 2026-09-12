// SPDX-License-Identifier: AGPL-3.0-only
/* The scope picker on /best: click a country, read its ranking.

   The same globe /regions and /coverage use, so a reader who has picked a
   country on one page already knows how to pick one here. Mounted only when
   the reader asks for it, so the page does not carry MapLibre for somebody
   who never opens it.

   Clicking navigates rather than filtering in place: every choice on this
   page is a URL (page-caching.md §3.2), so a shared cache can hold each
   ranking and a reader can send somebody the one they are looking at. */
(function () {
  'use strict';

  var box = document.getElementById('best-globe');
  var row = document.querySelector('.frow[data-scope]');
  if (!box || typeof window.ccCountryGlobe !== 'function') { return; }

  var names = {};
  var hrefs = {};
  document.querySelectorAll('.frow[data-scope] a[data-cc]').forEach(function (a) {
    names[a.getAttribute('data-cc')] = a.textContent.trim();
    hrefs[a.getAttribute('data-cc')] = a.getAttribute('href');
  });

  var toggle = document.getElementById('best-globe-toggle');
  if (!toggle) { return; }
  toggle.hidden = false;

  var built = false;
  toggle.addEventListener('click', function () {
    var open = box.hidden;
    box.hidden = !open;
    toggle.setAttribute('aria-pressed', open ? 'true' : 'false');
    if (!open || built) { return; }
    built = true;
    window.ccCountryGlobe(box, {
      tip: function (cc) { return names[cc] || cc; },
      // Only the countries this page can actually show a ranking for.
      onPick: function (cc) { if (hrefs[cc]) { location.href = hrefs[cc]; } }
    }).catch(function () { built = false; box.hidden = true; });
  });
}());
