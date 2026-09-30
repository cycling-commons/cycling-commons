// SPDX-License-Identifier: AGPL-3.0-only
// Sends one Umami event the first time an element carrying data-track-seen
// scrolls into view, named by that attribute (lower case, English, so every
// locale counts under one name). On the landing page this shows how far down
// readers get. Umami is injected late and only on production, so an event
// waits for it for up to 15 s and is dropped where it never comes (dev,
// staging, a blocker). docs/specs/security-architecture.md §2.
(function () {
  'use strict';
  var els = document.querySelectorAll('[data-track-seen]');
  if (!els.length || !('IntersectionObserver' in window)) return;

  function send(name, tries) {
    if (window.umami && typeof window.umami.track === 'function') {
      window.umami.track(name);
    } else if (tries < 30) {
      setTimeout(function () { send(name, tries + 1); }, 500);
    }
  }

  var seen = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      seen.unobserve(e.target);
      send(e.target.getAttribute('data-track-seen'), 0);
    });
  }, { threshold: 0.5 });

  Array.prototype.forEach.call(els, function (el) { seen.observe(el); });
})();
