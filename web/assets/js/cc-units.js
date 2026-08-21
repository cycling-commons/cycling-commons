// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Client half of the rider's unit preference (docs/specs/account-and-auth.md §9).
// Inputs are METRIC (what the app stores and every API returns). Conversion is
// the last step before a number becomes text — nothing is stored in miles/feet.
(function () {
  'use strict';

  var CFG = window.CC_UNITS || {};
  var DIST = 'mi' === CFG.distance ? 'mi' : 'km';
  var ELEV = 'ft' === CFG.elevation ? 'ft' : 'm';

  var MI_PER_KM = 0.621371192237334;   // 1 mile = 1609.344 m exactly
  var FT_PER_M = 3.280839895013123;    // 1 foot = 0.3048 m exactly
  // Where feet stop being readable; a quarter mile ≈ the kilometre it replaces.
  var SHORT_LIMIT_M = 'mi' === DIST ? 402.336 : 1000;
  var SPEED = 'mi' === DIST ? 'mph' : 'km/h';

  function num(value, decimals) {
    if (!isFinite(value)) return '';
    var fixed = Math.abs(value).toFixed(Math.max(0, decimals || 0));
    if (fixed.indexOf('.') >= 0) fixed = fixed.replace(/\.?0+$/, '');
    var parts = fixed.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return (value < 0 ? '-' : '') + parts.join('.');
  }

  function numeric(v) { return v != null && v !== '' && isFinite(Number(v)); }

  function ccKm(km, decimals) {
    if (!numeric(km)) return '';
    var v = 'mi' === DIST ? Number(km) * MI_PER_KM : Number(km);
    return num(v, decimals == null ? 1 : decimals) + ' ' + DIST;
  }

  /* Short distance in metres. Past SHORT_LIMIT_M it promotes to the long form. */
  function ccM(metres, decimals) {
    if (!numeric(metres)) return '';
    var m = Number(metres);
    if (Math.abs(m) >= SHORT_LIMIT_M) return ccKm(m / 1000, 1);
    return 'mi' === DIST
      ? num(m * FT_PER_M, decimals == null ? 0 : decimals) + ' ft'
      : num(m, decimals == null ? 0 : decimals) + ' m';
  }

  function ccElev(metres, decimals) {
    if (!numeric(metres)) return '';
    var v = 'ft' === ELEV ? Number(metres) * FT_PER_M : Number(metres);
    return num(v, decimals == null ? 0 : decimals) + ' ' + ELEV;
  }

  /* Speed in km/h. Follows the distance unit — no separate speed preference. */
  function ccSpeed(kmh, decimals) {
    if (!numeric(kmh)) return '';
    var v = 'mi' === DIST ? Number(kmh) * MI_PER_KM : Number(kmh);
    return num(v, decimals == null ? 0 : decimals) + ' ' + SPEED;
  }

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

  /* Reverse of display: typed distances leave the browser metric again. */
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
  window.ccSpeed = ccSpeed;
  window.ccDistUnit = DIST;
  window.ccSpeedUnit = SPEED;
  window.ccElevUnit = ELEV;
})();
