// SPDX-License-Identifier: AGPL-3.0-only
//
// Best of season and bike chips follow every chip row (docs/specs/map-and-search.md §4.0): a chip that is on is used,
// one that is off is not. Every chip on asks for the whole vocabulary, none on asks nothing and shows no Best of route.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { bestOfParam } from '../../assets/map/filters.js';

const SEASONS = ['spring', 'summer', 'autumn', 'winter'];
const panels = fs.readFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), '..', '..', 'assets', 'map', 'panels.js'), 'utf8');

test('some chips on: the CSV of those, in the row order', () => {
  assert.equal(bestOfParam(['autumn', 'spring'], SEASONS), 'spring,autumn');
});

test('every chip on: the whole vocabulary, an empty list to the server', () => {
  assert.equal(bestOfParam(SEASONS.slice(), SEASONS), '');
});

test('no chip on: nothing to ask', () => {
  assert.equal(bestOfParam([], SEASONS), null);
});

test('the map asks nothing and shows no Best of route when a row has none on', () => {
  const body = panels.slice(panels.indexOf('export function refreshBestOf'), panels.indexOf("fetch(`/map/best-of"));
  assert.match(body, /if\(null===season \|\| null===bike\)\{ applyBestOf\(\[\]\); return; \}/);
});

test('with no bike on the profile the bike row opens with every chip on', () => {
  assert.match(panels, /c\.classList\.toggle\('on', !PREFS\.bikes\.length \|\| PREFS\.bikes\.includes\(c\.dataset\.v\)\)/);
});

test('a new preset opens with every season and bike on, and a card names a full row once', () => {
  const settings = fs.readFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), '..', '..', 'assets', 'settings', 'map-presets.js'), 'utf8');
  assert.match(settings, /seasons: \(CAT\.seasons \|\| \[\]\)\.map\(x => x\.value\), bikes: \(CAT\.bikes \|\| \[\]\)\.map\(x => x\.value\)/);
  assert.match(settings, /row\(s\.seasons \|\| \[\], CAT\.seasons \|\| \[\], T\.allSeasons/);
});

test('a preset chip row with no chip on is dimmed, after every tap', () => {
  const settings = fs.readFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), '..', '..', 'assets', 'settings', 'map-presets.js'), 'utf8');
  assert.match(settings, /\['mpSeasons', 'mpBikes', 'mpSurfaces', 'mpRoutes'\]\.forEach\(id => \{ const el = \$\(id\); el\.closest\('\.field'\)\.classList\.toggle\('mp-none', !el\.querySelector\('\.mp-pchip\.on'\)\)/);
  assert.match(settings, /c\.classList\.toggle\('on'\); dimEmptyRows\(\);/);
});
