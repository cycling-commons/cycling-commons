// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Native confirmation for destructive admin actions. Support-desk actions now
// render as CSRF-protected POST forms (security review #2), so the guard hooks
// the form's submit event — that covers the button click AND an Enter-key
// submit, which never fires a click. Legacy confirm-links keep the click hook.
// The prompt text comes from the element's data-confirm attribute (translated
// in the Twig template), with an English fallback.
(function () {
    const FALLBACK = 'This action is irreversible. Continue?';
    const confirmed = (el) => window.confirm(el.dataset.confirm || FALLBACK);

    document.addEventListener('click', (e) => {
        const el = e.target.closest('a.action-confirm');
        if (el && !confirmed(el)) {
            e.preventDefault();
        }
    });

    document.addEventListener('submit', (e) => {
        const btn = (e.submitter && e.submitter.closest('button.action-confirm'))
            || e.target.querySelector('button.action-confirm');
        if (btn && !confirmed(btn)) {
            e.preventDefault();
        }
    }, true);
})();
