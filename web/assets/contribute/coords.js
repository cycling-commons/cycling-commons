// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Coordinate paste for contribute place-search. Refuses a silent lat/lng swap:
   a wrong guess would drop the pin in another country. */
(function () {
  'use strict';

  window.Cc = window.Cc || {};

  var PART = '([NSEWnsew])?\\s*([+-]?\\d{1,3}(?:\\.\\d+)?)\\s*([NSEWnsew])?';
  var PAIR = new RegExp('^' + PART + '(?:\\s*[,;/]\\s*|\\s+)' + PART + '$');

  function hemi(pre, post) {
    if (pre && post) return false;
    return (pre || post || '').toUpperCase();
  }

  window.Cc.parseLatLng = function (raw) {
    if (typeof raw !== 'string') return null;

    var s = raw.trim()
      .replace(/^geo:/i, '')
      .replace(/^@/, '')
      .replace(/[°º]/g, ' ')
      .trim();
    if (!s) return null;

    var m = PAIR.exec(s);
    if (!m) return null;

    var h1 = hemi(m[1], m[3]);
    var h2 = hemi(m[4], m[6]);
    if (h1 === false || h2 === false) return null;
    if (h1 && h2 && ('NS'.indexOf(h1) >= 0) === ('NS'.indexOf(h2) >= 0)) return null;

    var v1 = parseFloat(m[2]);
    var v2 = parseFloat(m[5]);
    if (!isFinite(v1) || !isFinite(v2)) return null;

    // A letter fixes which value is which; with none, lat comes first.
    var lngFirst = (h1 && 'EW'.indexOf(h1) >= 0) || (!h1 && h2 && 'NS'.indexOf(h2) >= 0);
    var lat = lngFirst ? v2 : v1;
    var lng = lngFirst ? v1 : v2;
    var latH = lngFirst ? h2 : h1;
    var lngH = lngFirst ? h1 : h2;

    // S/W flip the sign; the letter wins over a stray minus.
    if (latH) lat = ('S' === latH ? -1 : 1) * Math.abs(lat);
    if (lngH) lng = ('W' === lngH ? -1 : 1) * Math.abs(lng);

    if (!(lat >= -90 && lat <= 90) || !(lng >= -180 && lng <= 180)) return null;
    return { lat: lat, lng: lng };
  };

  window.Cc.formatLatLng = function (lat, lng) {
    return lat.toFixed(6) + ', ' + lng.toFixed(6);
  };
})();
