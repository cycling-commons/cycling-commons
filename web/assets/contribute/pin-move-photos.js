// SPDX-License-Identifier: AGPL-3.0-only
/* "Moving the pin here hides N rider photos", asked in the edit form.

   A scenic view shows a rider photo only while its distance, or 0 m for a
   curator's "Taken here", plus how far the pin now is from the pin it was
   recorded at stays within 250 m (docs/specs/scenic-views.md §8). So a pin
   move can hide
   photos until a curator confirms them at the new spot, and the person moving
   the pin is told before saving. The server counts
   (`GET /contribute/pin-move-photos`, PhotoValidator::hiddenByMove()); this
   file only shows the answer.

   Loaded only when editing a scenic view. The point comes from `cc:loc`. */
(function () {
  'use strict';

  var box = document.getElementById('wz-pinphotos');
  var msg = document.getElementById('wz-pinphotos-msg');
  var why = document.getElementById('wz-pinphotos-why');
  if (!box || !msg || !why) { return; }

  var item = box.getAttribute('data-item');
  var D = window.CC_PIN_PHOTOS_I18N || {};
  var seq = 0;
  var timer = null;

  function hide() {
    box.hidden = true;
    msg.textContent = '';
    why.textContent = '';
  }

  function fill(text, vars) {
    return Object.keys(vars).reduce(function (s, k) { return s.split(k).join(String(vars[k])); }, String(text));
  }

  /* Why the photos hide: with the pin moved, the farthest one may now have
     been taken beyond the limit. A "Taken here" counts as 0 m from the pin it
     was given at, so it has a distance like any GPS photo. */
  function reason(answer, n) {
    if (typeof answer.farthestM !== 'number') { return ''; }
    var text = 1 === n
      ? (D.whyFarOne || 'With the pin moved here, the photo may have been taken up to %d% m from it. A scenic view only shows a rider photo taken within %m% m of the pin.')
      : (D.whyFarMany || 'With the pin moved here, the photos may have been taken up to %d% m from it. A scenic view only shows rider photos taken within %m% m of the pin.');
    return fill(text, { '%m%': answer.withinM || 250, '%d%': answer.farthestM });
  }

  function show(answer) {
    var n = answer && typeof answer.hidden === 'number' ? answer.hidden : 0;
    if (n < 1) { hide(); return; }
    // The consequence is the title; why, and what happens next, follow as text.
    msg.textContent = 1 === n
      ? (D.one || 'Moving the pin here hides 1 rider photo.')
      : fill(D.many || 'Moving the pin here hides %n% rider photos.', { '%n%': n });
    var next = 1 === n
      ? (D.nextOne || 'It stays hidden until a curator confirms it was taken here.')
      : (D.nextMany || 'They stay hidden until a curator confirms they were taken here.');
    why.textContent = [reason(answer, n), next].filter(Boolean).join(' ');
    box.hidden = false;
  }

  function check(loc) {
    clearTimeout(timer);
    if (!loc || loc.type !== 'point') { hide(); return; }
    var mine = ++seq;
    timer = setTimeout(function () {
      fetch('/contribute/pin-move-photos?item=' + encodeURIComponent(item) + '&lat=' + loc.lat.toFixed(6) + '&lng=' + loc.lng.toFixed(6),
        { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (answer) { if (mine === seq) { show(answer); } })
        .catch(function () { if (mine === seq) { hide(); } });
    }, 350);
  }

  document.addEventListener('cc:loc', function (e) { check(e.detail); });
})();
