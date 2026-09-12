// SPDX-License-Identifier: AGPL-3.0-only
//
// Right-click on the map copies a spot as "52.367612, 5.239157" (map-init.js).
// The search box has to read that same text back (map-and-search.md §7.4), so
// the map loads the one parser the contribute wizard already uses and never
// sends a coordinate pair to the geocoder.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.join(__dirname, '..', '..');
const src = fs.readFileSync(path.join(ROOT, 'assets/contribute/coords.js'), 'utf8');
const sandbox = { window: {} };
vm.createContext(sandbox);
vm.runInContext(src, sandbox);
const { formatLatLng } = sandbox.window.Cc;
// The parser answers with an object minted inside the vm context, whose
// prototype is not this file's Object: copy the two numbers out before any
// deep compare, or every assertion fails on the prototype alone.
const parseLatLng = (raw) => {
  const r = sandbox.window.Cc.parseLatLng(raw);
  return r ? { lat: r.lat, lng: r.lng } : r;
};

test('the pair the map copies parses back to the same point', () => {
  assert.deepEqual(parseLatLng('52.36761237569525, 5.239156927545921'),
    { lat: 52.36761237569525, lng: 5.239156927545921 });
  // Round trip: six decimals in, six decimals out, Almere unmoved.
  assert.equal(formatLatLng(52.36761237569525, 5.239156927545921), '52.367612, 5.239157');
  assert.deepEqual(parseLatLng('52.367612, 5.239157'), { lat: 52.367612, lng: 5.239157 });
});

test('separators, hemispheres and prefixes riders actually paste', () => {
  assert.deepEqual(parseLatLng('52.367612 5.239157'), { lat: 52.367612, lng: 5.239157 });
  assert.deepEqual(parseLatLng('52.367612; 5.239157'), { lat: 52.367612, lng: 5.239157 });
  assert.deepEqual(parseLatLng('geo:-33.9249, 18.4241'), { lat: -33.9249, lng: 18.4241 });
  assert.deepEqual(parseLatLng('@52.367612,5.239157'), { lat: 52.367612, lng: 5.239157 });
  assert.deepEqual(parseLatLng('52.3676°N 5.2392°E'), { lat: 52.3676, lng: 5.2392 });
  // A letter, not the order, says which value is which.
  assert.deepEqual(parseLatLng('E5.2392 N52.3676'), { lat: 52.3676, lng: 5.2392 });
  assert.deepEqual(parseLatLng('33.9249S 18.4241E'), { lat: -33.9249, lng: 18.4241 });
});

test('a bare pair is read in the order it is written, never swapped', () => {
  // GeoJSON order looks identical to a rider's paste, so guessing would drop
  // the pin in another country: 5.24, 52.37 is a point at sea off Somalia and
  // is reported as exactly that (coords.js header).
  assert.deepEqual(parseLatLng('5.239157, 52.367612'), { lat: 5.239157, lng: 52.367612 });
});

test('anything that is not a point is not a point', () => {
  assert.equal(parseLatLng('Almere'), null);
  assert.equal(parseLatLng('52.367612'), null);
  assert.equal(parseLatLng('95.0, 5.2'), null);        // latitude past the pole
  assert.equal(parseLatLng('52.3676, 190.0'), null);   // longitude past the antimeridian
  assert.equal(parseLatLng('N52.3676N, 5.2392'), null); // a letter on both sides of one value
  assert.equal(parseLatLng('N52.3676 N5.2392'), null); // two of the same hemisphere
  assert.equal(parseLatLng(''), null);
  assert.equal(parseLatLng(undefined), null);
});

const ui = fs.readFileSync(path.join(ROOT, 'assets/map/search-ui.js'), 'utf8');

test('the map search reads the shared parser, not a second copy of the regex', () => {
  assert.match(ui, /window\.Cc\s*&&\s*window\.Cc\.parseLatLng/);
  assert.match(ui, /window\.Cc\.formatLatLng/);
  assert.doesNotMatch(ui, /NSEWnsew/);
});

test('a coordinate pair reaches neither Photon nor the coverage endpoint', () => {
  const photon = ui.slice(ui.indexOf('function runPhoton'), ui.indexOf('function runCoverageSearch'));
  const coverage = ui.slice(ui.indexOf('function runCoverageSearch'), ui.indexOf('function runS('));
  for (const [name, body] of [['runPhoton', photon], ['runCoverageSearch', coverage]]) {
    const gate = body.indexOf('coordPoint(q)');
    assert.ok(gate > 0, `${name} does not gate on a coordinate pair`);
    // The gate comes before the fetch, and drops any answer still in flight.
    assert.ok(gate < body.indexOf('fetch('), `${name} gates after it has already asked`);
    assert.match(body.slice(gate, gate + 120), /abort\(\)/);
  }
});

test('the typed point is the first row, and no widen row undercuts it', () => {
  const runS = ui.slice(ui.indexOf('function runS('));
  const coordPush = runS.indexOf('groups.push({label:D.coordinates');
  const scopePush = runS.indexOf("groups.push({label:D.scopes");
  const townPush = runS.indexOf('groups.push({label:D.places');
  assert.ok(coordPush > 0 && scopePush > coordPush && townPush > coordPush);
  assert.match(runS, /if\(window\.CCScope && !_worldwide && !coord\)\{/);
});

test('the row chip and the place-card badge share one colour token', () => {
  const util = fs.readFileSync(path.join(ROOT, 'assets/map/util.js'), 'utf8');
  const places = fs.readFileSync(path.join(ROOT, 'assets/map/places.js'), 'utf8');
  const hex = util.match(/export const COORD_COLOR='(#[0-9A-Fa-f]{6})'/);
  assert.ok(hex, 'util.js does not define COORD_COLOR');
  for (const [name, body] of [['search-ui.js', ui], ['places.js', places]]) {
    assert.match(body, /COORD_COLOR/, `${name} does not read the shared token`);
    assert.ok(!body.includes(hex[1]), `${name} repeats the hex instead of importing it`);
  }
  // The card says what it is looking at: a point, never a town.
  assert.match(places, /meta\.point \? COORD_COLOR/);
});
