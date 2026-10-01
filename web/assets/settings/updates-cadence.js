// SPDX-License-Identifier: AGPL-3.0-only
// Settings, release list: the two "how often" radios show only while the
// switch above them is on, because a cadence means nothing for a list the
// rider is not on. Without script they stay shown; a choice saved while the
// switch is off sends nothing (docs/specs/roadmap-and-changelog.md §4).
(function () {
  'use strict';

  var toggle = document.querySelector('[data-updates-toggle]');
  var choices = document.querySelector('[data-updates-cadence]');
  if (!toggle || !choices) return;

  var sync = function () {
    choices.hidden = !toggle.checked;
  };
  toggle.addEventListener('change', sync);
  sync();
})();
