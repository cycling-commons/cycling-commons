// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Solve the proof-of-work on a guarded public form, using window.ccPow.
   docs/specs/contact-and-support.md §3

   Any form carrying `id="cc-guarded-form"` with `data-pow-challenge` is picked
   up. Two behaviours are deliberate:

   1. Solving starts on FIRST INPUT, not on page load. Somebody who opened the
      contact page only to read the address should not have their CPU spun for
      a submission they are not going to make.

   2. If solving has not finished when submit is pressed, the submit WAITS
      instead of failing. A 20-bit challenge is a second or two on a laptop and
      can be several on an old phone; a fast typist pasting a prepared message
      must not be told they are a bot.

   With JavaScript off there is no nonce and the server refuses the post. That
   is a real cost, which is why the address is published beside the form. */
(function () {
  'use strict';

  var form = document.getElementById('cc-guarded-form');
  if (!form) return;

  var nonceField = document.getElementById('pow_nonce');
  var status = document.getElementById('pow-status');
  var challenge = form.dataset.powChallenge || '';
  var difficulty = form.dataset.powDifficulty;
  if (!challenge || !nonceField || !window.ccPow) return;

  var pending = null;
  var solved = false;

  function say(key) {
    if (!status) return;
    status.textContent = status.dataset[key] || '';
    status.hidden = !status.textContent;
  }

  if (!window.ccPow.available()) {
    /* Say so now, rather than after they have written three paragraphs. */
    say('failed');
    return;
  }

  function start() {
    if (pending) return pending;
    say('working');
    pending = window.ccPow.solve(challenge, difficulty).then(function (nonce) {
      if (!nonce) { say('failed'); return; }
      nonceField.value = nonce;
      solved = true;
      say('ready');
    }).catch(function () { say('failed'); });
    return pending;
  }

  form.addEventListener('input', start, { once: true });
  form.addEventListener('change', start, { once: true });

  form.addEventListener('submit', function (e) {
    if (solved) return;
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
