// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Self-hosted Umami: production hosts only, so local/staging is never tracked.
(function () {
  var PROD = ['cyclingcommons.org', 'www.cyclingcommons.org', 'wiki.cyclingcommons.org'];
  if (PROD.indexOf(location.hostname) === -1) return;
  var s = document.createElement('script');
  s.defer = true;
  // Pass this script's CSP nonce: script-src is 'self' + nonce with no third-party
  // host (docs/specs/security-architecture.md §2).
  s.nonce = (document.currentScript && document.currentScript.nonce) || '';
  s.src = 'https://analytics.bikecoders.life/script.js';
  s.setAttribute('data-website-id', 'e230cd93-eb85-4455-b185-8bed827ca8fa');
  document.head.appendChild(s);
})();
