// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Coordinate paste for the contribute place-search boxes.

   The map's right-click popup (assets/map/map-init.js, initCoordPopup) copies
   a spot as "50.492000, 5.860000" — lat first, six decimals. A rider who does
   that is telling us exactly where the thing is, so the contribute forms have
   to be able to READ that string: pasting it into a Photon place-search box
   otherwise returns "No matches" and leaves the map sitting on its default
   centre, which reads as "the search sent me to the wrong country".

   Classic script, mounted through window.Cc like climb-editor.js and
   review-card.js — the contribute pages are not ESM.

   What is accepted, in one place so both forms agree:
     50.492, 5.86            our own copy format (and any , ; / or space)
     50.4920°N 5.8600°E      the wizard's own readout format
     N50.492 E5.86           hemisphere letter either side of the number
     5.86E, 50.492N          letters make the order explicit, so honour it
     geo:50.492,5.86         a geo: URI, pasted from a phone
   What is refused, deliberately:
     - anything with a third number, or trailing text ("50.492, 5.86 Spa")
     - out-of-range values. "5.86, 50.492" is NOT silently swapped: a wrong
       guess would drop the pin in another country and look authoritative
       while doing it. Refusing sends the rider back to the map, which is the
       only place that can settle the ambiguity.
     - decimal commas ("50,492 5,86") — indistinguishable from a separator. */
(function () {
  'use strict';

  window.Cc = window.Cc || {};

  // <optional hemisphere letter> <signed number> <optional hemisphere letter>
  var PART = '([NSEWnsew])?\\s*([+-]?\\d{1,3}(?:\\.\\d+)?)\\s*([NSEWnsew])?';
  // The two parts are split by a punctuation separator or by whitespace alone.
  var PAIR = new RegExp('^' + PART + '(?:\\s*[,;/]\\s*|\\s+)' + PART + '$');

  function hemi(pre, post) {
    if (pre && post) return false;              // "N50.4N" is not a coordinate
    return (pre || post || '').toUpperCase();
  }

  /**
   * @param {string} raw
   * @return {{lat:number,lng:number}|null} null when this is not a coordinate
   *   pair — the caller then treats the query as an ordinary place search.
   */
  window.Cc.parseLatLng = function (raw) {
    if (typeof raw !== 'string') return null;

    var s = raw.trim()
      .replace(/^geo:/i, '')
      .replace(/^@/, '')          // Google Maps' "@lat,lng" URL fragment
      .replace(/[°º]/g, ' ')
      .trim();
    if (!s) return null;

    var m = PAIR.exec(s);
    if (!m) return null;

    var h1 = hemi(m[1], m[3]);
    var h2 = hemi(m[4], m[6]);
    if (h1 === false || h2 === false) return null;
    // Two letters have to name two different axes ("50N 5N" is meaningless).
    if (h1 && h2 && ('NS'.indexOf(h1) >= 0) === ('NS'.indexOf(h2) >= 0)) return null;

    var v1 = parseFloat(m[2]);
    var v2 = parseFloat(m[5]);
    if (!isFinite(v1) || !isFinite(v2)) return null;

    // A letter fixes which value is which; with none, lat comes first — the
    // order everything in this codebase writes and copies.
    var lngFirst = (h1 && 'EW'.indexOf(h1) >= 0) || (!h1 && h2 && 'NS'.indexOf(h2) >= 0);
    var lat = lngFirst ? v2 : v1;
    var lng = lngFirst ? v1 : v2;
    var latH = lngFirst ? h2 : h1;
    var lngH = lngFirst ? h1 : h2;

    // S/W flip the sign; the letter wins over a stray minus, which is why the
    // magnitude is taken rather than multiplied.
    if (latH) lat = ('S' === latH ? -1 : 1) * Math.abs(lat);
    if (lngH) lng = ('W' === lngH ? -1 : 1) * Math.abs(lng);

    if (!(lat >= -90 && lat <= 90) || !(lng >= -180 && lng <= 180)) return null;
    return { lat: lat, lng: lng };
  };

  /** The one display format for a parsed pair — matches the map's copy popup. */
  window.Cc.formatLatLng = function (lat, lng) {
    return lat.toFixed(6) + ', ' + lng.toFixed(6);
  };
})();
