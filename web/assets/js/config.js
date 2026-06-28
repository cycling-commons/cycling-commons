// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Runtime config for the Atlas demo. Copy this file to `config.js` (which is
// git-ignored) and fill in your own values. On deploy, place config.js on the
// server manually — it is never committed.
//
// NOTE: a Mapillary client token used in the browser is necessarily served to
// every visitor and CANNOT be domain-locked. Keeping it out of git history is the
// point of this file; it does not hide it from the live page. For real protection,
// proxy the Mapillary tile/Graph requests through your own backend so the token
// stays server-side.
window.MAPILLARY_TOKEN = 'MLY|PASTE_TOKEN_HERE';   // leave as-is to disable street-level imagery
