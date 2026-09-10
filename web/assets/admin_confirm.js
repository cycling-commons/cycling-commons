// SPDX-License-Identifier: AGPL-3.0-only
// Confirm destructive admin actions. Forms hook submit (covers Enter); legacy
// confirm-links hook click. Prompt text is data-confirm from the template.
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
