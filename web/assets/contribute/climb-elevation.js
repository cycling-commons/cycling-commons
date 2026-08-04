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

  function haversineM(a, b) {
    var R = 6371000, tR = function (x) { return x * Math.PI / 180; };
    var dLat = tR(b[1] - a[1]), dLng = tR(b[0] - a[0]);
    var s = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.cos(tR(a[1])) * Math.cos(tR(b[1])) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return R * 2 * Math.atan2(Math.sqrt(s), Math.sqrt(1 - s));
  }

  // Cumulative along-route distance for each sampled point (meters), dist[0] === 0.
  function cumulativeDistances(pts) {
    var d = [0];
    for (var i = 1; i < pts.length; i++) d.push(d[i - 1] + haversineM(pts[i - 1], pts[i]));
    return d;
  }

  // Interpolate elevation + coordinate at a given along-route distance.
  function atDistance(pts, elevs, cum, target) {
    var n = cum.length;
    if (target <= cum[0]) return { elev: elevs[0], coord: pts[0] };
    if (target >= cum[n - 1]) return { elev: elevs[n - 1], coord: pts[n - 1] };
    for (var i = 1; i < n; i++) {
      if (cum[i] >= target) {
        var d0 = cum[i - 1], d1 = cum[i];
        var t = d1 > d0 ? (target - d0) / (d1 - d0) : 0;
        return {
          elev: elevs[i - 1] + t * (elevs[i] - elevs[i - 1]),
          coord: [
            pts[i - 1][0] + t * (pts[i][0] - pts[i - 1][0]),
            pts[i - 1][1] + t * (pts[i][1] - pts[i - 1][1])
          ]
        };
      }
    }
    return { elev: elevs[n - 1], coord: pts[n - 1] };
  }

  function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

  // ~11 equal-distance bins over the whole route; each bar averages out point
  // noise by spanning a real chunk of distance instead of two adjacent samples.
  function toBars(pts, elevs, cum, bars) {
    var total = cum[cum.length - 1];
    var out = [];
    if (!(total > 0)) {
      for (var b0 = 0; b0 < bars; b0++) out.push(0);
      return out;
    }
    var binDist = total / bars;
    for (var b = 0; b < bars; b++) {
      var s = atDistance(pts, elevs, cum, b * binDist);
      var e = atDistance(pts, elevs, cum, (b + 1) * binDist);
      var g = ((e.elev - s.elev) / binDist) * 100;
      out.push(clamp(Math.round(g), -35, 35));
    }
    return out;
  }

  /* Average gradient, counting only the parts that go UP.

     A climb with a dip in it has two defensible averages, and they differ a
     lot. Net gain over length treats the descent as cancelling out the climbing
     around it: Roche-aux-Faucons, which drops ~40 m between two ramps, reads
     4.4% that way. Summing only the ascent reads 5.7%, against climbfinder's
     5.4% for the same road. The second is what a rider experiences and what
     climb sites publish, so it is what we publish (owner, 2026-08-04).

     Measured over ~100 m bins rather than raw samples, on purpose. Ascent-only
     is noise-sensitive by construction - every upward wobble in the DEM adds to
     the total and nothing subtracts it - so summing raw sample deltas inflates
     the figure on exactly the wooded climbs where the readings are least
     trustworthy. Binning first is the same defence as the display profile's bin
     floor (climb-elevation.md 3a). */
  function ascentOnlyAverage(pts, elevs, cum) {
    var total = cum[cum.length - 1];
    if (!(total > 0)) return 0;
    var BIN = 100;
    var bins = Math.max(1, Math.round(total / BIN));
    var step = total / bins;
    var ascent = 0, prev = atDistance(pts, elevs, cum, 0).elev;
    for (var b = 1; b <= bins; b++) {
      var here = atDistance(pts, elevs, cum, b * step).elev;
      if (here > prev) ascent += here - prev;
      prev = here;
    }
    return (ascent / total) * 100;
  }

  // Slide a ~150m window along the route and take the steepest sustained
  // gradient, instead of a single (noise-prone) adjacent-point delta.
  function steepestWindow(pts, elevs, cum) {
    var total = cum[cum.length - 1];
    if (!(total > 0)) return { g: 0, coord: pts[0] };
    var win = Math.min(150, total);
    var maxG = 0, maxCoord = pts[0];
    for (var i = 0; i < pts.length; i++) {
      var startD = cum[i], endD = startD + win;
      if (endD > total) { endD = total; startD = Math.max(0, total - win); }
      var dist = endD - startD;
      if (dist <= 0) continue;
      var s = atDistance(pts, elevs, cum, startD);
      var e = atDistance(pts, elevs, cum, endD);
      var g = ((e.elev - s.elev) / dist) * 100;
      if (g > maxG) {
        maxG = g;
        maxCoord = atDistance(pts, elevs, cum, (startD + endD) / 2).coord;
      }
    }
    return { g: maxG, coord: maxCoord };
  }

  /* The sustained gradient AT a point, over the same ~150 m window
     steepestWindow() uses for the maximum.

     This exists because the 11-bar display profile is the wrong instrument for
     reading a gradient at a position. Those bars are equal-DISTANCE bins over
     the whole climb, so extending a climb widens every bin and averages a short
     ramp together with the flatter road around it: the same 19% ramp reads ~10%
     once the bins get longer. The bar array is for drawing a shape; a number
     printed next to a marker has to be measured the way the maximum was
     (owner-reported 2026-08-03). */
  function sustainedAtCoord(pts, elevs, cum, coord) {
    var total = cum[cum.length - 1];
    if (!(total > 0)) return 0;
    var bestI = 0, bestD = Infinity;
    for (var i = 0; i < pts.length; i++) {
      var dx = pts[i][0] - coord[0], dy = pts[i][1] - coord[1];
      var d = dx * dx + dy * dy;                    // squared degrees: ordering only
      if (d < bestD) { bestD = d; bestI = i; }
    }
    var win = Math.min(150, total);
    var centre = cum[bestI];
    var startD = clamp(centre - win / 2, 0, Math.max(0, total - win));
    var endD = Math.min(total, startD + win);
    var dist = endD - startD;
    if (dist <= 0) return 0;
    var a = atDistance(pts, elevs, cum, startD);
    var b = atDistance(pts, elevs, cum, endD);
    return ((b.elev - a.elev) / dist) * 100;
  }

  // Resolves null for "no usable data", REJECTS on API failure (HTTP error,
  // network, abort) so the caller can tell the two apart and warn the user.
  // `signal` (optional AbortSignal) lets the caller cancel a superseded request.
  window.Cc.profileFromRoute = function (coords, signal) {
    if (!coords || coords.length < 2) return Promise.resolve(null);
    var pts = sample(coords, 100);
    var lats = pts.map(function (c) { return c[1]; }).join(',');
    var lngs = pts.map(function (c) { return c[0]; }).join(',');
    var url = 'https://api.open-meteo.com/v1/elevation?latitude=' + encodeURIComponent(lats) +
      '&longitude=' + encodeURIComponent(lngs);
    return fetch(url, { signal: signal }).then(function (r) {
      if (!r.ok) throw new Error('elevation API HTTP ' + r.status);
      return r.json();
    }).then(function (d) {
      if (!d || !Array.isArray(d.elevation) || d.elevation.length !== pts.length) return null;
      var elevs = d.elevation;
      if (elevs.some(function (e) { return e == null; })) return null;
      var cum = cumulativeDistances(pts);
      var grad = toBars(pts, elevs, cum, 11);
      var steep = steepestWindow(pts, elevs, cum);
      // Sanity clamp: real cycling ramps rarely exceed ~35%; anything higher is DEM noise.
      var pct = clamp(Math.round(steep.g), 0, 35);
      var avg = ascentOnlyAverage(pts, elevs, cum);
      return {
        grad: grad,
        // One decimal: a climb's average is the headline figure and rounding it
        // to whole percent throws away a distinction riders care about (8.6 and
        // 9.4 are not the same climb). The maximum stays whole because it is a
        // single window's reading and its precision is not real.
        avg: (Math.round(clamp(avg, 0, 35) * 10) / 10).toFixed(1) + '%',
        steep: { at: steep.coord, pct: '~' + pct + '%' },
        // Closes over THIS profile's samples so the caller can re-read the
        // gradient at a kept marker position without a second API call.
        sustainedAt: function (coord) {
          return '~' + clamp(Math.round(sustainedAtCoord(pts, elevs, cum, coord)), 0, 35) + '%';
        }
      };
    });
  };
})();
