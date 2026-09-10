// SPDX-License-Identifier: AGPL-3.0-only
/* A page with two views, one URL each, opens on the second when the screen
   is wide enough (owner 2026-09-08: "should open on the globe page if not
   mobile"). Runs in the head, before paint, so the table never flashes
   first; a phone, or a URL that names its view, is left alone. The script
   tag carries the rule: data-param, data-value, data-min (px). */
(function () {
  'use strict';
  var me = document.currentScript;
  if (!me) { return; }
  var param = me.getAttribute('data-param') || 'view';
  var value = me.getAttribute('data-value') || 'globe';
  var min = parseInt(me.getAttribute('data-min') || '900', 10);
  if (!window.matchMedia || !window.matchMedia('(min-width: ' + min + 'px)').matches) { return; }
  var url = new URL(location.href);
  if (url.searchParams.has(param)) { return; }
  url.searchParams.set(param, value);
  location.replace(url.toString());
}());
