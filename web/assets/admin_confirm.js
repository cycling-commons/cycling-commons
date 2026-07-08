// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Native confirmation for destructive admin actions. Support-desk actions now
// render as CSRF-protected POST forms (security review #2), so the confirm hook
// covers both the legacy link and the form's submit button — cancelling the
// click prevents the form from submitting.
document.addEventListener('click', (e) => {
    const el = e.target.closest('a.action-confirm, button.action-confirm');
    if (el && !window.confirm('This action is irreversible. Continue?')) {
        e.preventDefault();
    }
});
