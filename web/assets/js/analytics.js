// SPDX-License-Identifier: AGPL-3.0-only
// Self-hosted Umami: production hosts only, so local/staging is never tracked.
(function () {
  var PROD = ['cyclingcommons.org', 'www.cyclingcommons.org', 'wiki.cyclingcommons.org'];
  if (PROD.indexOf(location.hostname) === -1) return;
  var s = document.createElement('script');
  s.defer = true;
  // No nonce is copied across: script-src names this host directly
  // (docs/specs/security-architecture.md §2, page-caching.md §3.2). Copying one
  // would tie every page carrying analytics to a per-request value, which is
  // exactly what stops a page being cacheable.
  s.src = 'https://analytics.bikecoders.life/script.js';
  s.setAttribute('data-website-id', 'e230cd93-eb85-4455-b185-8bed827ca8fa');
  document.head.appendChild(s);
})();
