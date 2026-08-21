// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Local PoW challenge on the photo-report form (docs/specs/photo-uploads.md §6c).
   Solving starts when the urgent category is picked, not at submit. */
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

  /* Yield between slices so the tab stays usable. */
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
