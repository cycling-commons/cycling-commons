// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
(function () {
  'use strict';
  window.Cc = window.Cc || {};

  /* Climb profile from the server (docs/specs/climb-elevation.md).
     Arithmetic lives in PHP so preview and stored values match.
     Resolves null for "no usable data" (incl. 503); rejects on transport failure. */
  window.Cc.profileFromRoute = function (coords, signal, steepAt) {
    if (!coords || coords.length < 2) return Promise.resolve(null);
    // Editor order is [lng,lat]; storage and the API are [lat,lng].
    var body = { coords: coords.map(function (c) { return [c[1], c[0]]; }) };
    if (steepAt) body.steepAt = [steepAt[1], steepAt[0]];
    return fetch('/contribute/elevation', {
      method: 'POST',
      // Stateless 'elevation' CSRF token; server refuses without it.
      headers: { 'Content-Type': 'application/json', 'X-CC-Token': window.CC_ELEV_TOKEN || '' },
      body: JSON.stringify(body),
      signal: signal
    }).then(function (r) {
      if (r.status === 503) return null;
      if (!r.ok) throw new Error('elevation HTTP ' + r.status);
      return r.json();
    }).then(function (d) {
      if (!d || !Array.isArray(d.grad) || !d.steep) return null;
      return {
        grad: d.grad,
        avg: d.avgGradient,
        max: d.maxGradient,
        demSource: d.demSource || '',
        lengthM: d.length,
        gainM: d.gain,
        steep: { at: [d.steep.at[1], d.steep.at[0]], pct: d.steep.pct },
        sustainedAtSteep: d.sustainedAtSteep || null,
        overshootM: d.overshootM || 0
      };
    });
  };
})();
