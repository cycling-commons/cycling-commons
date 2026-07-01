// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Native confirmation for destructive admin action links (class "action-confirm").
document.addEventListener('click', (e) => {
    const link = e.target.closest('a.action-confirm');
    if (link && !window.confirm('This action is irreversible. Continue?')) {
        e.preventDefault();
    }
});
