// SPDX-License-Identifier: AGPL-3.0-only
// Settings, Public profile.
// - The line under the switch follows what is saved. While the switch differs
//   from the saved state, the line says what Save profile will do instead of
//   showing a link or a "no page" note that the click has not made true yet
//   (owner 2026-10-01).
// - At the display name, the name others see leads and the other one is
//   dimmed: the anonymous name while the switch is off, the display name
//   while it is on, following the field as it is typed (owner 2026-10-03).
(function () {
  'use strict';

  var toggle = document.querySelector('[data-public-toggle]');
  if (!toggle) return;

  var view = document.querySelector('[data-public-view]');
  var saved = view ? view.getAttribute('data-saved') === 'on' : toggle.checked;
  var savedLine = view ? view.querySelector('[data-public-saved]') : null;
  var pendingOn = view ? view.querySelector('[data-public-pending="on"]') : null;
  var pendingOff = view ? view.querySelector('[data-public-pending="off"]') : null;

  var seen = document.querySelector('[data-seen-as]');
  var main = seen ? seen.querySelector('[data-seen-main]') : null;
  var other = seen ? seen.querySelector('[data-seen-other]') : null;
  var nameField = document.querySelector('input[name$="[displayName]"]');

  var sync = function () {
    var on = toggle.checked;
    var changed = on !== saved;
    if (savedLine) savedLine.hidden = changed;
    if (pendingOn) pendingOn.hidden = !(changed && on);
    if (pendingOff) pendingOff.hidden = !(changed && !on);
    if (main && other) {
      var anon = seen.getAttribute('data-anon') || '';
      var typed = nameField ? nameField.value.trim() : '';
      var name = typed || seen.getAttribute('data-name') || '';
      main.textContent = on ? name : anon;
      other.textContent = on ? anon : name;
    }
  };
  toggle.addEventListener('change', sync);
  if (nameField) nameField.addEventListener('input', sync);
  sync();
})();
