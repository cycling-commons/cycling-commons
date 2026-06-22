// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Visitor analytics (self-hosted Umami) — loaded ONLY on the production hosts below.
// On localhost / 127.0.0.1 / file:// / staging it does nothing, so local dev is never tracked.
// Add prod hostnames here if more are introduced.
(function () {
  var PROD = ['cyclingcommons.org', 'www.cyclingcommons.org', 'wiki.cyclingcommons.org'];
  if (PROD.indexOf(location.hostname) === -1) return;
  var s = document.createElement('script');
  s.defer = true;
  s.src = 'https://analytics.bikecoders.life/script.js';
  s.setAttribute('data-website-id', 'e230cd93-eb85-4455-b185-8bed827ca8fa');
  document.head.appendChild(s);
})();
