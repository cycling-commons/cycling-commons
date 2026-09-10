// SPDX-License-Identifier: AGPL-3.0-only
//
// Compare two elevation sources on real climbs, by reading both with the SAME
// code. Comparing through two different services measures their interpolation
// as much as their data; reading the rasters directly does not.
//
//   node compare-sources.js <hgt_dir_a> <hgt_dir_b> [routes.json]
//
//     node compare-sources.js ./eu-dem/hgt ./glo30/hgt
//
// routes.json is {"climb name": [[lat,lng], ...], ...}. Without it, routes are
// read from the dev database (docs/specs/climb-elevation.md section 8).
//
// What it reports, and why those numbers: gain and average gradient are the
// published figures, so a disagreement there is user-visible. Per-bin
// disagreement is the honest spread between two sources that are both plausible.
//
// Bins reading downhill are reported but MUST NOT be read as a source-quality
// score. Real climbs descend in their middles - Roche-aux-Faucons drops 40 m
// between two ramps, and 27 of Hockai's bins are genuine rail-trail descent. A
// downhill bin only condemns a source on a road known to rise monotonically,
// which is what made it decisive for GLO-90 on La Redoute.
//
// The column that generalises is `disputed`: bins where the two sources
// DISAGREE about the sign of the gradient. A descent both see is terrain; one
// only a single source sees is an artifact. That needs no prior knowledge of
// the road, so it works on climbs nobody has profiled.
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const N = 3601;                       // 1 arc-second postings, edge inclusive
const TILE_BYTES = N * N * 2;

/** Reads a directory of .hgt tiles, interpolating the way skadi does. */
function hgtReader(dir) {
  const cache = new Map();
  const load = (key) => {
    if (cache.has(key)) return cache.get(key);
    const file = path.join(dir, key + '.hgt');
    let buf = null;
    if (fs.existsSync(file)) {
      buf = fs.readFileSync(file);
      if (buf.length !== TILE_BYTES) {
        throw new Error(`${file}: ${buf.length} bytes, expected ${TILE_BYTES}`);
      }
    }
    cache.set(key, buf);
    return buf;
  };
  return (lat, lon) => {
    const latSW = Math.floor(lat), lonSW = Math.floor(lon);
    const key = (latSW < 0 ? 'S' : 'N') + String(Math.abs(latSW)).padStart(2, '0')
              + (lonSW < 0 ? 'W' : 'E') + String(Math.abs(lonSW)).padStart(3, '0');
    const buf = load(key);
    if (!buf) return null;                       // no tile: caller decides
    const r = (latSW + 1 - lat) * 3600, c = (lon - lonSW) * 3600;
    const r0 = Math.floor(r), c0 = Math.floor(c), dr = r - r0, dc = c - c0;
    const at = (rr, cc) => buf.readInt16BE(
      2 * (Math.min(N - 1, Math.max(0, rr)) * N + Math.min(N - 1, Math.max(0, cc))));
    const top = at(r0, c0) * (1 - dc) + at(r0, c0 + 1) * dc;
    const bot = at(r0 + 1, c0) * (1 - dc) + at(r0 + 1, c0 + 1) * dc;
    return Math.round(top * (1 - dr) + bot * dr);  // skadi returns integer metres
  };
}

const R = 6371000, rad = (d) => d * Math.PI / 180;
function cumulative(pts) {
  const d = [0];
  for (let i = 1; i < pts.length; i++) {
    const [la1, lo1] = pts[i - 1], [la2, lo2] = pts[i];
    const x = rad(lo2 - lo1) * Math.cos(rad((la1 + la2) / 2)), y = rad(la2 - la1);
    d.push(d[i - 1] + Math.sqrt(x * x + y * y) * R);
  }
  return d;
}

/** Profile trimmed at the summit, per climb-elevation.md section 4a. */
function profile(sample, pts, binM = 100) {
  const e = pts.map(([la, lo]) => sample(la, lo));
  if (e.some((v) => v === null)) return null;
  const d = cumulative(pts);
  let si = 0;
  for (let i = 0; i < e.length; i++) if (e[i] >= e[si]) si = i;
  const reversed = si === 0;
  const L = d[si], G = e[si] - e[0];
  const it = (x) => {
    if (x <= 0) return e[0];
    if (x >= L) return e[si];
    for (let i = 1; i <= si; i++) {
      if (d[i] >= x) {
        const t = (x - d[i - 1]) / ((d[i] - d[i - 1]) || 1);
        return e[i - 1] + t * (e[i] - e[i - 1]);
      }
    }
    return e[si];
  };
  const bins = [];
  for (let x = 0; x < L; x += binM) {
    const w = Math.min(x + binM, L) - x;
    bins.push((it(x + w) - it(x)) / w * 100);
  }
  return { e, bins, gain: G, length: L, reversed, overshoot: d[d.length - 1] - L };
}

function routesFromDb() {
  const sql = "select jsonb_object_agg(name, attributes->'route') "
            + "from item where letter='B' and attributes ? 'route';";
  const out = execFileSync('docker', [
    'compose', '-f', 'developers/docker/compose.yaml', 'exec', '-T', 'db',
    'psql', '-U', 'cc', '-d', 'cyclingcommons', '-tAc', sql,
  ], { encoding: 'utf8' });
  return JSON.parse(out.trim());
}

const [dirA, dirB, routesFile] = process.argv.slice(2);
if (!dirA || !dirB) {
  console.error('usage: node compare-sources.js <hgt_dir_a> <hgt_dir_b> [routes.json]');
  process.exit(2);
}
const routes = routesFile
  ? JSON.parse(fs.readFileSync(routesFile, 'utf8'))
  : routesFromDb();

const A = hgtReader(dirA), B = hgtReader(dirB);
const nameA = path.basename(path.resolve(dirA)), nameB = path.basename(path.resolve(dirB));

console.log(`A = ${dirA}\nB = ${dirB}\n`);
console.log('climb'.padEnd(30) + 'length   ' + `${nameA} (A)`.padEnd(16) + `${nameB} (B)`.padEnd(16)
  + 'diff/bin  down A/B  disputed');

let sum = 0, seen = 0, skipped = 0, disputedTotal = 0, binsTotal = 0;
const flags = [];
for (const [name, pts] of Object.entries(routes)) {
  const a = profile(A, pts), b = profile(B, pts);
  if (!a || !b) { skipped++; console.log(name.padEnd(30) + 'no tile coverage in one source'); continue; }
  const n = Math.min(a.bins.length, b.bins.length);
  let s = 0, disputed = 0;
  for (let i = 0; i < n; i++) {
    s += Math.abs(a.bins[i] - b.bins[i]);
    // Sign disagreement: one source says this stretch climbs, the other says it
    // descends. Flat bins are excluded - a 0% bin has no sign to disagree about.
    if (a.bins[i] < 0 !== b.bins[i] < 0 && a.bins[i] !== 0 && b.bins[i] !== 0) disputed++;
  }
  const per = n ? s / n : 0;
  if (n) { sum += per; seen++; disputedTotal += disputed; binsTotal += n; }

  const fmt = (p) => `${p.gain}m/${(p.length ? p.gain / p.length * 100 : 0).toFixed(1)}%`;
  console.log(name.padEnd(30)
    + `${(a.length / 1000).toFixed(2)}km`.padEnd(9)
    + fmt(a).padEnd(16) + fmt(b).padEnd(16)
    + `${per.toFixed(2)}pt`.padStart(8)
    + `  ${a.bins.filter((x) => x < 0).length}/${b.bins.filter((x) => x < 0).length}`.padEnd(9)
    + `  ${disputed}/${n}`);

  if (a.reversed) flags.push(`${name}: stored BACKWARDS - summit is the first point (section 4a)`);
  else if (a.overshoot > 50) flags.push(`${name}: runs ${a.overshoot.toFixed(0)}m past its summit (section 4a)`);
}

console.log(`\nmean per-bin disagreement: ${seen ? (sum / seen).toFixed(2) : 'n/a'} points over ${seen} climbs`
  + (skipped ? ` (${skipped} skipped)` : ''));
console.log(`disputed direction: ${disputedTotal}/${binsTotal} bins`
  + ' - the sources disagree about whether the road rises there.');
console.log('  "down A/B" counts are NOT a quality score: real climbs descend in their middles.');
if (flags.length) {
  console.log('\ngeometry problems, independent of the elevation source:');
  for (const f of flags) console.log('  ! ' + f);
}
