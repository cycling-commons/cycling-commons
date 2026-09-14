// SPDX-License-Identifier: AGPL-3.0-only
/* "Is this scenic view along a bike way?", asked in the add form.

   A scenic view is a stop along a way a bike may ride, within 250 m of it
   (docs/specs/scenic-views.md). When the pin is farther, the rider is told
   and may overrule: they may know the spot, and overruling is how the curator
   learns it is reachable. The intake asks the same question on submit and
   refuses a far view that was not overruled, so this file only decides what
   the form shows and what the hidden field carries: `1` for "overruled",
   empty otherwise.

   Loaded only when adding a scenic view. The point comes from `cc:loc`. */
(function () {
  'use strict';

  var box = document.getElementById('wz-bikeway');
  var msg = document.getElementById('wz-bikeway-msg');
  var ok = document.getElementById('wz-bikeway-ok');
  var field = document.querySelector('[name$="[bikewayOverride]"]');
  if (!box || !msg || !ok || !field) { return; }

  var D = window.CC_BIKEWAY_I18N || {};
  var seq = 0;
  var timer = null;

  function reset() {
    box.hidden = true;
    ok.checked = false;
    field.value = '';
  }

  // Every occurrence: a translation may name the range twice.
  function fill(text, vars) {
    return Object.keys(vars).reduce(function (out, k) { return out.split(k).join(String(vars[k])); }, String(text));
  }

  function show(reading) {
    if (!reading || !reading.far) { reset(); return; }
    var vars = { '%d%': reading.nearestM, '%m%': reading.withinM };
    msg.textContent = null === reading.nearestM
      ? fill(D.none || 'There is no way a bike may ride within %m% m of this spot.', vars)
      : fill(D.far || 'The nearest way a bike may ride is %d% m from this spot.', vars);
    ok.checked = false;
    field.value = '';
    box.hidden = false;
  }

  function check(loc) {
    clearTimeout(timer);
    if (!loc || loc.type !== 'point') { reset(); return; }
    var mine = ++seq;
    timer = setTimeout(function () {
      fetch('/contribute/bike-way-near?lat=' + loc.lat.toFixed(6) + '&lng=' + loc.lng.toFixed(6),
        { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (reading) { if (mine === seq) { show(reading); } })
        .catch(function () { if (mine === seq) { reset(); } });
    }, 350);
  }

  ok.addEventListener('change', function () { field.value = ok.checked ? '1' : ''; });
  document.addEventListener('cc:loc', function (e) { check(e.detail); });
})();
