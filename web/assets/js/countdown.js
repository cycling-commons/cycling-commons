// SPDX-License-Identifier: AGPL-3.0-only
/* The ballot's countdown (owner 2026-10-03), the same steps as
   App\Vote\Countdown: days while more than two are left, then hours, then
   hours and minutes in the last day, and a clock to the second in the last
   hour. The page renders the first value; this keeps it moving.
   Markup: [data-countdown] with data-deadline (ISO 8601) and one data-t-*
   string per step. */
(function () {
  'use strict';

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  /* Seconds left -> the step and its values. Pure, so a node test loads it. */
  function step(s) {
    if (s <= 0) return { key: 'closed', vars: {} };
    if (s < 3600) return { key: 'hms', vars: { '%time%': '00:' + pad(Math.floor(s / 60)) + ':' + pad(s % 60) } };
    if (s < 86400) return { key: 'hm', vars: { '%h%': String(Math.floor(s / 3600)), '%m%': pad(Math.floor((s % 3600) / 60)) } };
    if (s < 172800) return { key: 'hours', vars: { '%count%': String(Math.floor(s / 3600)) } };
    return { key: 'days', vars: { '%count%': String(Math.floor(s / 86400)) } };
  }

  function fill(tpl, vars) {
    return Object.keys(vars).reduce(function (t, k) { return t.split(k).join(vars[k]); }, String(tpl || ''));
  }

  function mount(el) {
    var deadline = Date.parse(el.getAttribute('data-deadline') || '');
    if (isNaN(deadline)) return;
    function tick() {
      var s = Math.max(0, Math.floor((deadline - Date.now()) / 1000));
      var st = step(s);
      el.textContent = fill(el.getAttribute('data-t-' + st.key), st.vars);
      el.setAttribute('data-step', st.key);
      if (s <= 0) return;
      // Every second in the last hour, else on the next whole minute.
      setTimeout(tick, s < 3600 ? 1000 : 1000 * (60 - (Math.floor(Date.now() / 1000) % 60)));
    }
    tick();
  }

  if (typeof document !== 'undefined') {
    Array.prototype.forEach.call(document.querySelectorAll('[data-countdown]'), mount);
  }
  if (typeof module !== 'undefined' && module.exports) module.exports = { step: step, fill: fill };
})();
