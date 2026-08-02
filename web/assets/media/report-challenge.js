// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The local challenge on the photo-report form
   (docs/specs/photo-uploads.md §6c).

   The urgent category — intimate imagery, or a child is depicted — is the one
   thing an anonymous visitor can do that takes a photo off the map before a
   human looks. While the site-wide circuit breaker is open, the server demands
   a proof of work for it: a nonce whose SHA-256 digest with the server's
   challenge starts with N zero bits. Roughly a million hashes, a second or two
   of this device's CPU, and nothing about the person is measured or sent.

   Solving starts the moment the urgent category is picked rather than at
   submit time, so by the time the report is written the stamp is ready and
   nobody waits. The stamp is sent always and enforced only while the breaker
   is open, so the form behaves identically either way and reveals nothing
   about whether an attack is underway.

   No third-party script, by design: this page promises to collect as little as
   possible, and a hosted bot check would mean somebody else's JavaScript and
   somebody else's uptime on a rights-exercise route. */
(function () {
  'use strict';

  var form = document.getElementById('report-form');
  if (!form || !window.crypto || !window.crypto.subtle) return;

  var challenge = form.dataset.powChallenge || '';
  var difficulty = parseInt(form.dataset.powDifficulty, 10) || 20;
  var urgent = form.dataset.urgentCategory || '';
  var nonceField = document.getElementById('pow_nonce');
  var status = document.getElementById('pow-status');
  if (!challenge || !nonceField) return;

  var started = false;

  function say(key) {
    if (!status) return;
    status.textContent = status.dataset[key] || '';
    status.hidden = !status.textContent;
  }

  function leadingZeroBits(bytes) {
    var bits = 0;
    for (var i = 0; i < bytes.length; i++) {
      var b = bytes[i];
      if (b === 0) { bits += 8; continue; }
      for (var mask = 0x80; mask > 0; mask >>= 1) {
        if (b & mask) return bits;
        bits++;
      }
    }
    return bits;
  }

  var encoder = new TextEncoder();

  async function attempt(nonce) {
    var digest = await window.crypto.subtle.digest('SHA-256', encoder.encode(challenge + '.' + nonce));
    return leadingZeroBits(new Uint8Array(digest)) >= difficulty;
  }

  /* Solved in slices with a yield between them: a tight million-iteration loop
     would freeze the tab, and this page must stay usable while it works. */
  async function solve() {
    var nonce = 0;
    say('working');
    for (;;) {
      for (var i = 0; i < 500; i++) {
        if (await attempt(String(nonce))) {
          nonceField.value = String(nonce);
          say('ready');
          return;
        }
        nonce++;
      }
      await new Promise(function (resolve) { setTimeout(resolve, 0); });
    }
  }

  form.addEventListener('change', function (e) {
    var target = e.target;
    if (!target || target.name !== 'category' || target.value !== urgent || started) return;
    started = true;
    solve().catch(function () { say('failed'); });
  });
})();
