// SPDX-License-Identifier: AGPL-3.0-only
//
// The CITIES quick-picks (catalog.js) open the same town card as every other
// town (docs/specs/map-and-search.md §6.5): each carries its OpenStreetMap
// element, and the card fetches its text per language from /map/town/{ref},
// with the curators' own text and the "!" that reports it. No town carries a
// fixed text of its own (owner-reported 2026-09-30: Spa had an English
// sentence and no way to report it).
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

globalThis.window = {};
globalThis.document = { documentElement: { lang: 'en' } };
const { CITIES } = await import('../../assets/map/catalog.js');

const ROOT = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const places = fs.readFileSync(path.join(ROOT, 'assets', 'map', 'places.js'), 'utf8');

test('every quick-pick town names its OpenStreetMap element, as the town endpoint takes it', () => {
  const names = Object.keys(CITIES);
  assert.ok(names.length > 0);
  for (const name of names) {
    assert.match(String(CITIES[name].osm), /^(node|way|relation)\/\d+$/, `${name} has no OSM ref`);
  }
});

test('no quick-pick town carries a fixed text or link of its own', () => {
  for (const [name, c] of Object.entries(CITIES)) {
    assert.deepEqual(Object.keys(c).filter((k) => !['t', 'll', 'osm'].includes(k)), [], `${name} carries extra fields`);
  }
});

test('each town is a distinct element on a point in Belgium', () => {
  const refs = Object.values(CITIES).map((c) => c.osm);
  assert.equal(new Set(refs).size, refs.length);
  for (const [name, c] of Object.entries(CITIES)) {
    const [lat, lng] = c.ll;
    assert.ok(lat > 49.4 && lat < 51.6 && lng > 2.5 && lng < 6.5, `${name} lies outside Belgium`);
  }
});

test('Spa opens as the Spa town relation', () => {
  assert.equal(CITIES.Spa.osm, 'relation/2422528');
});

test('openCity hands the town, ref and all, to the one town card', () => {
  assert.match(places, /export function openCity\(name\)\{\s*const c = CITIES\[name\]; if\(!c\) return;\s*openPlace\(name, c\);/);
});

test('the card fetches its text whenever it has a ref, with no fixed-text branch', () => {
  assert.match(places, /\$\{meta\.osm\?townWaiting\(meta\):''\}/);
  assert.match(places, /if\(meta\.osm\) startTownWatch\(name, meta\);/);
  assert.doesNotMatch(places, /meta\.info|meta\.wiki/);
});

test('the fetched text carries the report "!" to the town report route', () => {
  assert.match(places, /const reportHref = '\/report\/town\/'\+encodeURIComponent\(String\(meta\.osm\|\|''\)\.replace\('\/', '-'\)\)/);
  assert.match(places, /<a class="cc-bang" href="\$\{safeHref\(reportHref\)\}"/);
});
