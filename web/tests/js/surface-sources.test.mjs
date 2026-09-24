// SPDX-License-Identifier: AGPL-3.0-only
//
// Per-country surface sources: the drawer and the route stitching must find
// the source that holds a clicked way, whichever country's archive it is.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const src = readFileSync(new URL('../../assets/map/surface-tiles.js', import.meta.url), 'utf8');
const routes = readFileSync(new URL('../../assets/map/routes-tiles.js', import.meta.url), 'utf8');
const boot = readFileSync(new URL('../../assets/map/map.js', import.meta.url), 'utf8');
const park = readFileSync(new URL('../../assets/map/tile-park.js', import.meta.url), 'utf8');

test('no fixed tile source ids remain', () => {
  for (const s of [src, routes]) {
    assert.doesNotMatch(s, /'surface-tiles'|'surface-todo'|'routes-tiles'|CC_SURFACE_URL|CC_ROUTES_URL|CC_SURFACE_TODO_URL|CC_SURFACE_GAPS_URL/);
  }
});

test('the flight-parking list matches by prefix too, not the old fixed ids', () => {
  // Per-country sources are named `<family>-<arm>-<cc>` (tile-sources.js), so
  // no live source is ever literally 'coverage' or 'surface-tiles' again; a
  // fixed-id list here would silently stop parking the tiled overlays during
  // a camera move, which is the one thing this file exists to do.
  assert.doesNotMatch(park, /\['coverage', 'surface-tiles'\]|PARKED_SOURCES/);
  assert.match(park, /isSurfaceSource\(src\)/);
  assert.match(park, /startsWith\('coverage-'\)/);
});

test('the surface skin is not mounted at boot', () => {
  assert.doesNotMatch(boot, /addSurfaceTiles\(\)/);
});

test('per-country source ids are matched by prefix, never by a fixed id', () => {
  assert.match(src, /export const isTodoSource = id => String\(id \|\| ''\)\.startsWith\('surface-todo-'\)/);
  assert.match(src, /isTodoSource\(tileCtx\.source\)/);
  assert.match(routes, /!isSurfaceSource\(l\.source\)/);
});
