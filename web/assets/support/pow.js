// SPDX-License-Identifier: AGPL-3.0-only
/* The local proof-of-work solver, shared by every guarded form.
   docs/specs/contact-and-support.md §3

   This is the whole of Cycling Commons' bot check on the client side. There is
   no Turnstile script, no reCAPTCHA iframe, and nothing here talks to anybody:
   the work is SHA-256 in this tab, and the only party that ever sees the answer
   is our own server.

   Exposes `window.ccPow.solve(challenge, difficulty)` -> Promise<nonce string>.
   Returns null if the browser has no WebCrypto, which happens on an insecure
   origin and on genuinely old browsers. Callers must handle null by telling the
   visitor, never by silently sending a submission the server will refuse. */
(function () {
  'use strict';

  var encoder = new TextEncoder();

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

  function available() {
    return !!(window.crypto && window.crypto.subtle);
  }

  async function solve(challenge, difficulty) {
    if (!available() || !challenge) return null;
    var target = parseInt(difficulty, 10) || 20;
    var nonce = 0;
    for (;;) {
      /* 500 hashes between yields: long enough that the loop is not all
         scheduling overhead, short enough that the tab never feels stuck. */
      for (var i = 0; i < 500; i++) {
        var digest = await window.crypto.subtle.digest(
          'SHA-256',
          encoder.encode(challenge + '.' + String(nonce))
        );
        if (leadingZeroBits(new Uint8Array(digest)) >= target) {
          return String(nonce);
        }
        nonce++;
      }
      await new Promise(function (resolve) { setTimeout(resolve, 0); });
    }
  }

  window.ccPow = { solve: solve, available: available };
})();
