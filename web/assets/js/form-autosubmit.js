// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Submit a form when one of its controls changes — filter dropdowns, the
// pager's page-length select, anything where picking IS the action.
//
// This exists because `onchange="this.form.submit()"` DOES NOT WORK HERE and
// fails silently. The CSP is `script-src 'self' 'nonce-…'`, and a nonce does
// not enable inline event handlers — only 'unsafe-inline' or 'unsafe-hashes'
// would, and neither is worth having for a one-liner. The browser logs
// "Executing inline event handler violates the following Content Security
// Policy directive" and does nothing, which looks exactly like a control that
// was never wired up. It cost the Regions desk its country filter and the
// pager its page-length control before anyone noticed (owner-reported
// 2026-08-09).
//
// A file served from 'self' is allowed, so the wiring lives here and the
// markup carries only `data-autosubmit`. Use that attribute, never an
// `on*=` attribute — grep for `onchange=` in templates/ before adding one.
//
// `requestSubmit()` rather than `submit()`: it fires the submit event and runs
// constraint validation, so a form with a required field cannot be posted
// empty by a stray change. The fallback is for browsers without it.
(function formAutosubmit() {
  function submit(form) {
    if (!form) return;
    if (typeof form.requestSubmit === 'function') form.requestSubmit();
    else form.submit();
  }

  // Delegated, so controls added after load (a re-rendered filter bar) work
  // without re-running anything.
  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el || !el.matches || !el.matches('[data-autosubmit]')) return;
    submit(el.form || el.closest('form'));
  });
})();
