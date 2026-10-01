// SPDX-License-Identifier: AGPL-3.0-only
// Settings, Public profile: the line under the switch follows what is saved.
// While the switch differs from the saved state, the line says what
// Save profile will do instead of showing a link or a "no page" note that
// the click has not made true yet (owner 2026-10-01).
(function () {
  'use strict';

  var toggle = document.querySelector('[data-public-toggle]');
  var view = document.querySelector('[data-public-view]');
  if (!toggle || !view) return;

  var saved = view.getAttribute('data-saved') === 'on';
  var savedLine = view.querySelector('[data-public-saved]');
  var pendingOn = view.querySelector('[data-public-pending="on"]');
  var pendingOff = view.querySelector('[data-public-pending="off"]');

  var sync = function () {
    var on = toggle.checked;
    var changed = on !== saved;
    if (savedLine) savedLine.hidden = changed;
    if (pendingOn) pendingOn.hidden = !(changed && on);
    if (pendingOff) pendingOff.hidden = !(changed && !on);
  };
  toggle.addEventListener('change', sync);
  sync();
})();
