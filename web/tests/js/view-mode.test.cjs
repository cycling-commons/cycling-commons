// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// The view-mode rung rules (map-and-search.md §4.2) and the deep-link lift (§8).
//
// A curator approved a climb, opened the desk's "what did I approve" link, and
// found a halo with nothing under it: their own mode was Best of, which draws
// only the picks, and the climb was Verified, not a pick (2026-08-25). The
// rules live in filters.js so render.js, the legend count and the deep-link
// lift all read one predicate, and so it can be tested here without a DOM.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');
const load = () => import('file://' + path.join(ROOT, 'assets/map/filters.js'));

const climbs = { key: 'climbs', exp: true };
const water = { key: 'water', exp: false };
const routes = { key: 'experience', exp: true };

test('modeShows: the three rungs, per layer kind', async () => {
  const { modeShows } = await load();
  const verifiedClimb = { v: 1 }, pickClimb = { cur: 1 }, importClimb = {};
  // Best of: picks only on experiential layers; utilities draw as before.
  assert.equal(modeShows('curated', climbs, verifiedClimb), false);
  assert.equal(modeShows('curated', climbs, pickClimb), true);
  assert.equal(modeShows('curated', water, importClimb), true);
  assert.equal(modeShows('curated', routes, verifiedClimb), false, 'R routes honour cur only in Best of');
  // Confirmed: anything vouched for, a true superset of Best of.
  assert.equal(modeShows('confirmed', climbs, verifiedClimb), true);
  assert.equal(modeShows('confirmed', climbs, pickClimb), true);
  assert.equal(modeShows('confirmed', water, importClimb), false);
  // Everything: all of it.
  assert.equal(modeShows('all', climbs, importClimb), true);
});

test('modeToShow: lift to the lowest rung that draws the target, never lower', async () => {
  const { modeToShow } = await load();
  // The reported case: Best of → Confirmed for a Verified climb.
  assert.equal(modeToShow('curated', climbs, { v: 1 }), 'confirmed');
  // An unverified import needs Everything.
  assert.equal(modeToShow('curated', climbs, {}), 'all');
  assert.equal(modeToShow('confirmed', climbs, {}), 'all');
  // Already drawn: nothing to lift.
  assert.equal(modeToShow('curated', climbs, { cur: 1 }), null);
  assert.equal(modeToShow('confirmed', climbs, { v: 1 }), null);
  assert.equal(modeToShow('all', climbs, {}), null);
  // Never steps down: a utility import drawn in Best of is not "lifted".
  assert.equal(modeToShow('curated', water, {}), null);
});

test('render.js, the route reveal pin and the deep-link lift read the one rule', () => {
  assert.match(read('assets/map/render.js'), /modeShows\(mode\(\), layer, f\)/);
  assert.doesNotMatch(read('assets/map/render.js'), /mode\(\)==='confirmed'\s*\?/,
    'featureVisible must not inline its own copy of the rung rule');
  assert.match(read('assets/map/places.js'), /!modeShows\(mode\(\), layer, f\) && p\) revealPinAt/);
  assert.match(read('assets/map/panels.js'), /modeToShow\(mode\(\), layer, f\)/);
  assert.match(read('assets/map/panels.js'), /applyMode\(to, \{persist:false\}\)/,
    'a deep-link lift is for this visit only, never written to the profile');
  // map.js lifts before it opens, for every local-feature deep link.
  const mapJs = read('assets/map/map.js');
  assert.match(mapJs, /liftModeFor\(/);
  assert.ok(mapJs.indexOf('liftModeFor(') < mapJs.indexOf('if(ip) openFeatureById(ip);'),
    'the lift must run before the drawer opens, or the halo lands on an empty map');
});
