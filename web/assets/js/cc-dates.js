// SPDX-License-Identifier: AGPL-3.0-only
// Client half of the rider's date preference (docs/specs/account-and-auth.md §9).
(function () {
  'use strict';

  var CFG = window.CC_DATE || {};
  var FMT = CFG.format || 'auto';
  var TIME = CFG.time || 'auto';
  var LOC = CFG.locale || document.documentElement.lang || undefined;
  /* The rider's chosen zone, or undefined for the browser's own. */
  var TZ = CFG.timeZone || undefined;

  function pad(n) { return n < 10 ? '0' + n : String(n); }

  function zoned(opts) {
    var o = {};
    for (var k in opts) o[k] = opts[k];
    if (TZ) o.timeZone = TZ;
    return o;
  }

  /* Year, month, day, hour and minute of `d` in the rider's zone. */
  function parts(d) {
    if (!TZ) return { y: d.getFullYear(), m: d.getMonth() + 1, d: d.getDate(), H: d.getHours(), M: d.getMinutes() };
    var o = {};
    new Intl.DateTimeFormat('en-US', { timeZone: TZ, year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric', hourCycle: 'h23' })
      .formatToParts(d).forEach(function (p) { o[p.type] = p.value; });
    return { y: +o.year, m: +o.month, d: +o.day, H: +o.hour % 24, M: +o.minute };
  }

  function intl(d, opts) {
    try { return d.toLocaleDateString(LOC, zoned(opts)); } catch (e) { return d.toDateString(); }
  }

  /* Unparseable values return '' rather than "Invalid Date". */
  function ccDate(value) {
    var d = value instanceof Date ? value : new Date(value);
    if (!value || isNaN(d.getTime())) return '';

    var p = parts(d);
    var y = p.y, m = pad(p.m), day = pad(p.d);
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

    var p = parts(d);
    if ('h24' === TIME) return pad(p.H) + ':' + pad(p.M);
    var opts = { hour: 'numeric', minute: '2-digit' };
    if ('h12' === TIME) opts.hour12 = true;
    try { return d.toLocaleTimeString(LOC, zoned(opts)); } catch (e) { return pad(p.H) + ':' + pad(p.M); }
  }

  function ccDateTime(value) {
    var date = ccDate(value);
    var time = ccTime(value);
    return date && time ? date + ' ' + time : (date || time);
  }

  /* While the zone is automatic, a signed-in page reports the browser's own
     zone when it differs from the stored one, so times the server writes
     (cc_datetime) read in the zone the rider is in. */
  var R = CFG.report;
  if (R && R.token && R.url && window.fetch) {
    var own = '';
    try { own = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { own = ''; }
    if (own && own !== (R.detected || '')) {
      window.fetch(R.url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CC-Token': R.token },
        body: JSON.stringify({ zone: own }),
      }).catch(function () {});
    }
  }

  window.ccDate = ccDate;
  window.ccMonth = ccMonth;
  window.ccTime = ccTime;
  window.ccDateTime = ccDateTime;
})();
