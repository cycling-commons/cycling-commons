// SPDX-License-Identifier: AGPL-3.0-only
/* Which of /coverage's two views opens, decided before the first paint.

   Both views are in the one response and CSS shows the table, because the
   table is the view that works with no JavaScript and the globe is not. This
   adds `cc-globe` to <html> when the globe should be the one on screen,
   early enough that the table never flashes first.

   Two things decide it, in order:
     - an explicit ?view= in the URL, so a link opens on the view it names;
     - screen width, because a phone gets the table and never pays for
       MapLibre (owner 2026-09-08: "should open on the globe page if not
       mobile").

   In the head, as a file rather than inline, so the page needs no CSP nonce
   and a shared cache can hold it (docs/specs/page-caching.md §3.2). */
(function () {
  'use strict';
  var wide = window.matchMedia && window.matchMedia('(min-width: 900px)').matches;
  var asked = null;

  try {
    asked = new URL(location.href).searchParams.get('view');
  } catch (e) { /* an unparseable URL is simply no answer */ }

  if ('table' === asked) { return; }
  if ('globe' === asked || wide) {
    document.documentElement.classList.add('cc-globe');
  }
}());
