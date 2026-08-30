// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Solve the proof-of-work on the content-report form, using window.ccPow.
   docs/specs/content-reports.md §5, photo-uploads.md §6c

   Not form-challenge.js, on purpose. That one starts solving on first input
   because its forms need a nonce for every submission. Here almost no report
   does: the server asks for one on the single ground that hides a photo
   before review, and only while the site-wide auto-withhold breaker is open.
   So solving starts when THAT ground is picked, breaker or no breaker. The
   state of the breaker never shows on the page, and a report written while
   it opens still arrives with a nonce.

   If solving has not finished when send is pressed, the submit waits rather
   than failing, same as the contact form. */
(function () {
  'use strict';

  var form = document.getElementById('report-form');
  if (!form) return;

  var select = document.getElementById('rep-ground');
  var nonceField = document.getElementById('pow_nonce');
  var status = document.getElementById('pow-status');
  var challenge = form.dataset.powChallenge || '';
  var difficulty = form.dataset.powDifficulty;
  if (!select || !challenge || !nonceField || !window.ccPow) return;

  var pending = null;
  var solved = false;

  function say(key) {
    if (!status) return;
    status.textContent = status.dataset[key] || '';
    status.hidden = !status.textContent;
  }

  function urgentPicked() {
    var option = select.options[select.selectedIndex];
    return !!(option && option.dataset.autoWithholds === '1');
  }

  function start() {
    if (pending) return pending;
    if (!window.ccPow.available()) {
      say('failed');
      pending = Promise.resolve();
      return pending;
    }
    say('working');
    pending = window.ccPow.solve(challenge, difficulty).then(function (nonce) {
      if (!nonce) { say('failed'); return; }
      nonceField.value = nonce;
      solved = true;
      say('ready');
    }).catch(function () { say('failed'); });
    return pending;
  }

  select.addEventListener('change', function () {
    if (urgentPicked()) start();
  });

  form.addEventListener('submit', function (e) {
    if (solved || !urgentPicked()) return;
    e.preventDefault();
    var button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    start().then(function () {
      if (button) button.disabled = false;
      /* form.submit() skips the submit event, so this cannot loop. */
      form.submit();
    });
  });
})();
