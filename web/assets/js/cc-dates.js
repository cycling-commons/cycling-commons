// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Client half of the rider's date preference (docs/specs/account-and-auth.md §9).
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

  /* Unparseable values return '' rather than "Invalid Date". */
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

  /* Month name + year — photo publish granularity (docs/specs/photo-uploads.md §5). */
  function ccMonth(value) {
    var d = value instanceof Date ? value : new Date(value);
    if (!value || isNaN(d.getTime())) return '';

    return intl(d, { year: 'numeric', month: 'long' === FMT ? 'long' : 'short' });
  }

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
