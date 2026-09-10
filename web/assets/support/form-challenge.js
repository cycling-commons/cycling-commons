// SPDX-License-Identifier: AGPL-3.0-only
/* Solve the proof-of-work on a guarded public form, using window.ccPow.
   docs/specs/contact-and-support.md §3

   Any form carrying `id="cc-guarded-form"` with `data-challenge-url` is picked
   up. Two behaviours are deliberate:

   1. Solving starts on FIRST INPUT, not on page load. Somebody who opened the
      contact page only to read the address should not have their CPU spun for
      a submission they are not going to make. The challenge is fetched at the
      same moment, from `data-challenge-url`, for the same reason and one more:
      a single-use challenge cannot sit in a page a cache may hold
      (page-caching.md §3.1).

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
  var challengeField = document.getElementById('pow_challenge');
  var challenge = '';
  var difficulty = form.dataset.powDifficulty;
  if (!form.dataset.challengeUrl || !nonceField || !window.ccPow) return;

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

  /* The challenge is fetched, not baked into the page. It is single use, so
     one in the markup would be shared by everybody served a cached copy and
     only the first sender would be accepted (page-caching.md §3.1). Fetching
     also means a page nobody submits from costs no challenge at all, which is
     most of them: these forms are linked from every footer and every drawer.
     The hidden field is filled here so the ordinary form post still carries it. */
  function fetchChallenge() {
    if (challenge) return Promise.resolve(challenge);
    return fetch(form.dataset.challengeUrl, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json();
    }).then(function (out) {
      if (!out || !out.ok || !out.challenge) throw new Error('no challenge');
      challenge = out.challenge;
      difficulty = out.difficulty || difficulty;
      if (challengeField) challengeField.value = challenge;
      return challenge;
    });
  }

  function start() {
    if (pending) return pending;
    say('working');
    pending = fetchChallenge().then(function (value) {
      return window.ccPow.solve(value, difficulty);
    }).then(function (nonce) {
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
