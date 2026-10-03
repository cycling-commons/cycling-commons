// SPDX-License-Identifier: AGPL-3.0-only
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

  /* The gradient over 90 m of road around one spot, for the rider's steepest
     point (docs/specs/climb-elevation.md §5a). Resolves "18%", or null when
     no height could be read; rejects on transport failure. */
  window.Cc.pointGradient = function (coords, at, signal) {
    if (!coords || coords.length < 2 || !at) return Promise.resolve(null);
    return fetch('/contribute/elevation', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CC-Token': window.CC_ELEV_TOKEN || '' },
      body: JSON.stringify({
        coords: coords.map(function (c) { return [c[1], c[0]]; }),
        pointAt: [at[1], at[0]]
      }),
      signal: signal
    }).then(function (r) {
      if (r.status === 503) return null;
      if (!r.ok) throw new Error('elevation HTTP ' + r.status);
      return r.json();
    }).then(function (d) { return d && d.pointPct ? String(d.pointPct) : null; });
  };

  /* The steepest 90 m of the line, found by the server for "+ Steepest point".
     Resolves {at: [lng,lat], pct: "18%"}, or null when no height could be
     read; rejects on transport failure. */
  window.Cc.findSteepestPoint = function (coords, signal) {
    if (!coords || coords.length < 2) return Promise.resolve(null);
    return fetch('/contribute/elevation', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CC-Token': window.CC_ELEV_TOKEN || '' },
      body: JSON.stringify({ coords: coords.map(function (c) { return [c[1], c[0]]; }), findPoint: true }),
      signal: signal
    }).then(function (r) {
      if (r.status === 503) return null;
      if (!r.ok) throw new Error('elevation HTTP ' + r.status);
      return r.json();
    }).then(function (d) {
      return d && Array.isArray(d.pointAt) && d.pointPct
        ? { at: [d.pointAt[1], d.pointAt[0]], pct: String(d.pointPct) }
        : null;
    });
  };
})();
