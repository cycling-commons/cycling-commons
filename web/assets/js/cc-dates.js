// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// The client half of the rider's date preference
// (docs/specs/account-and-auth.md §9).
//
// Server-rendered dates go through the cc_date/cc_datetime/cc_month Twig
// filters. Anything JavaScript prints has to reach the same answer, or a single
// page shows the same date two ways and the preference reads as broken. So both
// halves are driven by one value, handed over as window.CC_DATE by base.html.twig.
//
// Deliberately not a module and deliberately tiny: it is loaded on every page
// and used by scripts that are themselves plain classic scripts.
(function () {
  'use strict';

  var CFG = window.CC_DATE || {};
  var FMT = CFG.format || 'auto';
  var TIME = CFG.time || 'auto';
  var LOC = CFG.locale || document.documentElement.lang || undefined;

  function pad(n) { return n < 10 ? '0' + n : String(n); }

  function intl(d, opts) {
    try { return d.toLocaleDateString(LOC, opts); } catch (e) { return d.toDateString(); }
  }

  /**
   * A calendar date in the reader's chosen notation.
   * Accepts a Date, an ISO string, or anything Date can parse; an unparseable
   * value returns '' rather than "Invalid Date", because a page printing that
   * at somebody is worse than a page printing nothing.
   */
  function ccDate(value) {
    var d = value instanceof Date ? value : new Date(value);
    if (!value || isNaN(d.getTime())) return '';

    var y = d.getFullYear(), m = pad(d.getMonth() + 1), day = pad(d.getDate());
    switch (FMT) {
      case 'ymd':  return y + '-' + m + '-' + day;
      case 'dmy':  return day + '-' + m + '-' + y;
      case 'mdy':  return m + '/' + day + '/' + y;
      case 'long': return intl(d, { year: 'numeric', month: 'long', day: 'numeric' });
      default:     return intl(d, { year: 'numeric', month: 'short', day: 'numeric' });
    }
  }

  /**
   * Month and year only — the granularity photos are published at
   * (docs/specs/photo-uploads.md §5). Always a month NAME: "08/2026" is not
   * something anyone says out loud, and this string is read as prose.
   */
  function ccMonth(value) {
    var d = value instanceof Date ? value : new Date(value);
    if (!value || isNaN(d.getTime())) return '';

    return intl(d, { year: 'numeric', month: 'long' === FMT ? 'long' : 'short' });
  }

  /** The time of day, 24-hour or 12-hour as the rider asked. */
  function ccTime(value) {
    var d = value instanceof Date ? value : new Date(value);
    if (!value || isNaN(d.getTime())) return '';

    if ('h24' === TIME) return pad(d.getHours()) + ':' + pad(d.getMinutes());
    var opts = { hour: 'numeric', minute: '2-digit' };
    if ('h12' === TIME) opts.hour12 = true;
    try { return d.toLocaleTimeString(LOC, opts); } catch (e) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
  }

  function ccDateTime(value) {
    var date = ccDate(value);
    var time = ccTime(value);
    return date && time ? date + ' ' + time : (date || time);
  }

  window.ccDate = ccDate;
  window.ccMonth = ccMonth;
  window.ccTime = ccTime;
  window.ccDateTime = ccDateTime;
})();
