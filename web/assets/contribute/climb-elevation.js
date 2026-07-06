// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
(function () {
  'use strict';
  window.Cc = window.Cc || {};

  // Downsample to <=100 points (API cap) preserving first/last.
  function sample(coords, max) {
    if (coords.length <= max) return coords.slice();
    var step = (coords.length - 1) / (max - 1), out = [];
    for (var i = 0; i < max; i++) out.push(coords[Math.round(i * step)]);
    return out;
  }

  // Bucket the per-point gradients into ~11 bars (like the demo `grad`).
  function toBars(elevs, pts, bars) {
    var seg = [];
    for (var i = 1; i < elevs.length; i++) {
      var d = haversineM(pts[i - 1], pts[i]);
      seg.push(d > 0 ? ((elevs[i] - elevs[i - 1]) / d) * 100 : 0);
    }
    var out = [], per = Math.max(1, Math.floor(seg.length / bars));
    for (var b = 0; b < seg.length; b += per) {
      var slice = seg.slice(b, b + per);
      out.push(Math.round(slice.reduce(function (a, x) { return a + x; }, 0) / slice.length));
    }
    return out;
  }

  function haversineM(a, b) {
    var R = 6371000, tR = function (x) { return x * Math.PI / 180; };
    var dLat = tR(b[1] - a[1]), dLng = tR(b[0] - a[0]);
    var s = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.cos(tR(a[1])) * Math.cos(tR(b[1])) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return R * 2 * Math.atan2(Math.sqrt(s), Math.sqrt(1 - s));
  }

  window.Cc.profileFromRoute = function (coords) {
    if (!coords || coords.length < 2) return Promise.resolve(null);
    var pts = sample(coords, 100);
    var lats = pts.map(function (c) { return c[1]; }).join(',');
    var lngs = pts.map(function (c) { return c[0]; }).join(',');
    var url = 'https://api.open-meteo.com/v1/elevation?latitude=' + encodeURIComponent(lats) +
      '&longitude=' + encodeURIComponent(lngs);
    return fetch(url).then(function (r) { return r.json(); }).then(function (d) {
      if (!d || !Array.isArray(d.elevation) || d.elevation.length !== pts.length) return null;
      var elevs = d.elevation;
      if (elevs.some(function (e) { return e == null; })) return null;
      var grad = toBars(elevs, pts, 11);
      // steepest = max per-point gradient
      var maxG = 0, maxI = 1;
      for (var i = 1; i < elevs.length; i++) {
        var dd = haversineM(pts[i - 1], pts[i]);
        var g = dd > 0 ? ((elevs[i] - elevs[i - 1]) / dd) * 100 : 0;
        if (g > maxG) { maxG = g; maxI = i; }
      }
      return { grad: grad, steep: { at: pts[maxI], pct: '~' + Math.round(maxG) + '%' } };
    }).catch(function () { return null; });
  };
})();
