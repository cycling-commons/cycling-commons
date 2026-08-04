// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
(function () {
  'use strict';
  window.Cc = window.Cc || {};

  /* Asks the server for a climb's measured profile.

     THE ARITHMETIC USED TO LIVE HERE — sampling, binning, the ascent-only
     average and the steepest-window search were all done in the browser, and
     the server stored whatever came back after checking only that it looked
     like a gradient. That made the client the author of every published number,
     and it made a catalogue-wide re-measure impossible, because the maths was
     not where the data is.

     It is one implementation in PHP now (App\Elevation\ClimbProfiler), so the
     preview a rider sees and the value that gets stored are the same
     computation by construction, and `app:climbs:recompute` can re-measure the
     whole catalogue with it. See docs/specs/climb-elevation.md.

     Resolves null for "no usable data" — including a 503, which is how the
     server says it could not measure this line — and REJECTS on transport
     failure, so the caller can tell the two apart and tell the rider which
     happened. `signal` (optional AbortSignal) cancels a superseded request.

     `steepAt` is an optional hand-placed marker in editor order [lng,lat]: the
     server re-reads the gradient at that position rather than moving the
     marker. */
  window.Cc.profileFromRoute = function (coords, signal, steepAt) {
    if (!coords || coords.length < 2) return Promise.resolve(null);
    // Editor order is [lng,lat]; storage and the API are [lat,lng].
    var body = { coords: coords.map(function (c) { return [c[1], c[0]]; }) };
    if (steepAt) body.steepAt = [steepAt[1], steepAt[0]];
    return fetch('/contribute/elevation', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
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
