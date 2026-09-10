// SPDX-License-Identifier: AGPL-3.0-only
// data-autosubmit: CSP forbids inline onchange (docs/specs/security-architecture.md §2).
// requestSubmit() fires submit + constraint validation; submit() is the fallback.
(function formAutosubmit() {
  function submit(form) {
    if (!form) return;
    if (typeof form.requestSubmit === 'function') form.requestSubmit();
    else form.submit();
  }

  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el || !el.matches || !el.matches('[data-autosubmit]')) return;
    submit(el.form || el.closest('form'));
  });
})();
