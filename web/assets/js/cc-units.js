// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// The client half of the rider's unit preference
// (docs/specs/account-and-auth.md §9).
//
// Server-rendered distances go through the cc_km/cc_m/cc_elev Twig filters.
// Anything JavaScript prints has to reach the same answer, or one page shows a
// route as 84 km in the moderation table and 52 mi in the drawer beside it, and
// the preference reads as broken. So both halves are driven by one value,
// handed over as window.CC_UNITS by base.html.twig.
//
// Everything passed in is METRIC, because that is what the app stores and what
// every API returns. Conversion happens here, at the last step before a number
// becomes text — no stored value is ever written in miles or feet.
//
// Deliberately not a module and deliberately tiny: it is loaded on every page
// and used by classic scripts as well as by the map's ES modules.
(function () {
  'use strict';

  var CFG = window.CC_UNITS || {};
  var DIST = 'mi' === CFG.distance ? 'mi' : 'km';
  var ELEV = 'ft' === CFG.elevation ? 'ft' : 'm';

  var MI_PER_KM = 0.621371192237334;   // 1 mile = 1609.344 m exactly
  var FT_PER_M = 3.280839895013123;    // 1 foot = 0.3048 m exactly
  // Where feet stop being readable and the long form takes over. A quarter mile
  // is about the same size as the kilometre it replaces.
  var SHORT_LIMIT_M = 'mi' === DIST ? 402.336 : 1000;

  /** Thousands separated, trailing zeros dropped — "1,240 m", "12 mi". */
  function num(value, decimals) {
    if (!isFinite(value)) return '';
    var fixed = Math.abs(value).toFixed(Math.max(0, decimals || 0));
    if (fixed.indexOf('.') >= 0) fixed = fixed.replace(/\.?0+$/, '');
    var parts = fixed.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return (value < 0 ? '-' : '') + parts.join('.');
  }

  function numeric(v) { return v != null && v !== '' && isFinite(Number(v)); }

  /** A ride-scale distance given in kilometres: "42.2 km" or "26.2 mi". */
  function ccKm(km, decimals) {
    if (!numeric(km)) return '';
    var v = 'mi' === DIST ? Number(km) * MI_PER_KM : Number(km);
    return num(v, decimals == null ? 1 : decimals) + ' ' + DIST;
  }

  /**
   * A short distance given in metres: "250 m" or "820 ft".
   * Past the point where feet stop being readable it promotes itself to the
   * long form, so 1500 m never reads as "4921 ft".
   */
  function ccM(metres, decimals) {
    if (!numeric(metres)) return '';
    var m = Number(metres);
    if (Math.abs(m) >= SHORT_LIMIT_M) return ccKm(m / 1000, 1);
    return 'mi' === DIST
      ? num(m * FT_PER_M, decimals == null ? 0 : decimals) + ' ft'
      : num(m, decimals == null ? 0 : decimals) + ' m';
  }

  /** Height climbed or altitude, given in metres: "1,240 m" or "4,068 ft". */
  function ccElev(metres, decimals) {
    if (!numeric(metres)) return '';
    var v = 'ft' === ELEV ? Number(metres) * FT_PER_M : Number(metres);
    return num(v, decimals == null ? 0 : decimals) + ' ' + ELEV;
  }

  /** The bare converted numbers, for axis ticks that write the unit once. */
  function ccKmValue(km, decimals) {
    if (!numeric(km)) return 0;
    var v = 'mi' === DIST ? Number(km) * MI_PER_KM : Number(km);
    return decimals == null ? v : Number(v.toFixed(decimals));
  }

  function ccElevValue(metres, decimals) {
    if (!numeric(metres)) return 0;
    var v = 'ft' === ELEV ? Number(metres) * FT_PER_M : Number(metres);
    return decimals == null ? v : Number(v.toFixed(decimals));
  }

  /* The reverse, for the few controls a rider TYPES a distance into. The value
     leaving the browser is metric again before anything stores or measures it:
     the climb wizard's length field is the rider's unit on screen and
     kilometres in the payload. */
  function ccKmFromValue(value) {
    if (!numeric(value)) return 0;
    return 'mi' === DIST ? Number(value) / MI_PER_KM : Number(value);
  }

  function ccElevFromValue(value) {
    if (!numeric(value)) return 0;
    return 'ft' === ELEV ? Number(value) / FT_PER_M : Number(value);
  }

  window.ccKm = ccKm;
  window.ccM = ccM;
  window.ccElev = ccElev;
  window.ccKmValue = ccKmValue;
  window.ccElevValue = ccElevValue;
  window.ccKmFromValue = ccKmFromValue;
  window.ccElevFromValue = ccElevFromValue;
  window.ccDistUnit = DIST;
  window.ccElevUnit = ELEV;
})();
