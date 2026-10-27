// SPDX-License-Identifier: AGPL-3.0-only
/* Where the add form's map opens when the link names no spot (moderation-and-contribution.md §1.2, "Where the
   form's map opens"): the region or country the rider last looked at on the map, else their base location, else
   Spa. `token` is the map's stored scope ('cc-scope': "region:noord-holland", "country:NL", "myarea"), read in the
   browser and never sent anywhere. `start` comes with the page (window.CC_FORM_START): each region's slug, country
   and camera box, and the rider's base location or null. Registers window.Cc.formStart, which improve.js calls. */
(function () {
  'use strict';

  var SPA = { center: [5.86, 50.49], zoom: 12 };
  var BASE_ZOOM = 11;

  function finite(n) { return 'number' === typeof n && isFinite(n); }

  /* The camera box of a region or country token, or null. A country is the union of its regions' boxes. A box
     that crosses the antimeridian (west > east) is left out: no union of those is one box. */
  function scopeBox(token, regions) {
    var i = token.indexOf(':');
    if (i < 0) return null;
    var kind = token.slice(0, i);
    var rest = token.slice(i + 1);
    var views = [];
    if ('region' === kind) {
      var slugs = rest.split(',');
      for (var s = 0; s < slugs.length && !views.length; s++) {
        for (var r = 0; r < regions.length; r++) {
          if (regions[r].slug === slugs[s]) { views = [regions[r].view]; break; }
        }
      }
    } else if ('country' === kind) {
      views = regions.filter(function (x) { return x.cc === rest; }).map(function (x) { return x.view; });
    }
    views = views.filter(function (v) { return Array.isArray(v) && 4 === v.length && v.every(finite) && v[0] <= v[2]; });
    if (!views.length) return null;
    var pick = function (k, f) { return f.apply(null, views.map(function (v) { return v[k]; })); };
    return [pick(0, Math.min), pick(1, Math.min), pick(2, Math.max), pick(3, Math.max)];
  }

  /** A MapLibre start: `{bounds: [[w, s], [e, n]]}` or `{center: [lng, lat], zoom}`. Pure. */
  function formStart(token, start) {
    var regions = (start && Array.isArray(start.regions)) ? start.regions : [];
    var box = scopeBox(String(token || ''), regions);
    if (box) return { bounds: [[box[0], box[1]], [box[2], box[3]]] };
    var base = start && start.base;
    if (base && finite(base.lat) && finite(base.lng)) return { center: [base.lng, base.lat], zoom: BASE_ZOOM };
    return { center: SPA.center.slice(), zoom: SPA.zoom };
  }

  var API = { formStart: formStart, SPA: SPA };
  if (typeof window !== 'undefined') {
    window.Cc = window.Cc || {};
    window.Cc.formStart = formStart;
  }
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
