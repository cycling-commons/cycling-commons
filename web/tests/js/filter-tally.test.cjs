// SPDX-License-Identifier: AGPL-3.0-only
//
// The chip-filter rules behind the on-map pill (map-and-search.md §4).
//
// The pill tells a rider how many places their filters are hiding, so the
// number has to be a real count, not an estimate: a wrong one is worse than
// none, because it teaches them to stop believing the map. These run the real
// functions over a fixture rather than reading the source, which is why the
// rules live in filters.js - a module that imports nothing and so can be
// loaded here at all.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const FX = require('./fixtures/chip-filters.json');
const ROOT = path.join(__dirname, '..', '..');
const load = () => import('file://' + path.join(ROOT, 'assets/map/filters.js'));

const ALL_SURF = new Set(['Smooth', 'Good', 'Worn', 'Rough', 'Broken / loose']);
const ALL_TRAF = new Set(['Traffic-free', 'Quiet', 'Moderate', 'Busy']);
const ALL_EFFORT = new Set(['Steady', 'Challenging', 'Tough', 'Very steep']);
const ALL_ACCESS = new Set(['Step-free access', 'Handbike-friendly', 'Wheelchair-accessible']);

// The state shape render.js hands the rules: each facet is what is selected
// now, beside the full vocabulary it was selected from.
const state = (over = {}) => ({
  surface: {active: new Set(ALL_SURF), all: ALL_SURF},
  traffic: {active: new Set(ALL_TRAF), all: ALL_TRAF},
  effort: {active: new Set(ALL_EFFORT), all: ALL_EFFORT},
  access: {active: new Set(ALL_ACCESS), all: ALL_ACCESS},
  prefFilterOn: false,
  bikeFriendlyOn: false,
  ...over,
});

test('nothing deselected means nothing narrowed', async () => {
  const { narrowingCount } = await load();
  assert.equal(narrowingCount(state()), 0);
});

test('each group counts once, however many chips it lost', async () => {
  const { narrowingCount } = await load();
  assert.equal(narrowingCount(state({surface: {active: new Set(['Smooth']), all: ALL_SURF}})), 1);
  assert.equal(narrowingCount(state({
    surface: {active: new Set(['Smooth']), all: ALL_SURF},
    traffic: {active: new Set(['Quiet', 'Moderate']), all: ALL_TRAF},
  })), 2);
});

test('the preference chip narrows while it is ON, the opposite of the others', async () => {
  const { narrowingCount } = await load();
  assert.equal(narrowingCount(state({prefFilterOn: true})), 1);
});

test('a climb with no value for an attribute survives every narrowing', async () => {
  const { climbChipsMatch } = await load();
  const unmeasured = FX.climbs.find(c => c.name === 'Unmeasured hill');
  const st = state({surface: {active: new Set(['Smooth']), all: ALL_SURF}});
  assert.equal(climbChipsMatch(unmeasured, st), true, 'unknown is not a verdict');
});

test('the hidden tally counts exactly the climbs the chips exclude', async () => {
  const { climbChipsMatch } = await load();
  // Surface narrowed to Smooth + Good: Baraque Michel (Worn) and Rosier
  // (Broken / loose) go; the unmeasured hill stays.
  const st = state({surface: {active: new Set(['Smooth', 'Good']), all: ALL_SURF}});
  const hidden = FX.climbs.filter(c => !climbChipsMatch(c, st)).map(c => c.name);
  assert.deepEqual(hidden.sort(), ['Baraque Michel', 'Rosier']);
});

test('narrowing two facets hides the union, not the intersection', async () => {
  const { climbChipsMatch } = await load();
  const st = state({
    surface: {active: new Set(['Smooth', 'Good']), all: ALL_SURF},   // drops Baraque Michel, Rosier
    traffic: {active: new Set(['Quiet']), all: ALL_TRAF},            // drops Mur de Huy too
  });
  const hidden = FX.climbs.filter(c => !climbChipsMatch(c, st)).map(c => c.name);
  assert.deepEqual(hidden.sort(), ['Baraque Michel', 'Mur de Huy', 'Rosier']);
});

test('Bike-friendly off keeps every stay', async () => {
  const { bikeFriendlyMatch } = await load();
  assert.equal(bikeFriendlyMatch([], false), true);
  assert.equal(bikeFriendlyMatch(undefined, false), true);
});

test('Bike-friendly on keeps only the stays with a cyclist label', async () => {
  const { bikeFriendlyMatch } = await load();
  assert.equal(bikeFriendlyMatch(['Bett+Bike'], true), true);
  assert.equal(bikeFriendlyMatch(['Bett+Bike', 'Accueil Vélo'], true), true);
  assert.equal(bikeFriendlyMatch([], true), false);
  assert.equal(bikeFriendlyMatch(undefined, true), false);
});

test('Bike-friendly narrows while it is ON, like the preference chip', async () => {
  const { narrowingCount } = await load();
  assert.equal(narrowingCount(state({bikeFriendlyOn: true})), 1);
  assert.equal(narrowingCount(state({bikeFriendlyOn: true, prefFilterOn: true})), 2);
});

test('a tile cyl value reads as the same label list', async () => {
  const { tileLabels } = await load();
  assert.deepEqual(tileLabels('Bett+Bike;Accueil Vélo'), ['Bett+Bike', 'Accueil Vélo']);
  assert.deepEqual(tileLabels(undefined), []);
  assert.deepEqual(tileLabels(''), []);
});

test('routes for your bikes: a route for any chosen bike stays, an undeclared route always stays', async () => {
  const { routeBikeMatch } = await load();
  const road = new Set(['Road']);
  assert.equal(routeBikeMatch(['Road', 'Gravel'], road), true);
  assert.equal(routeBikeMatch(['MTB'], road), false, 'only a declared non-overlap hides a route');
  assert.equal(routeBikeMatch([], road), true, 'unknown is not unsuitable');
  assert.equal(routeBikeMatch(undefined, road), true);
  assert.equal(routeBikeMatch(['MTB'], new Set()), true, 'no bike chip on, no filter');
});

test('the remembered bike chips: the saved bikes at first, any bike type after, the old on/off read', async () => {
  const { readPrefBikes } = await load();
  const mine = ['Road'];
  const all = ['Road', 'Gravel', 'MTB', 'E-bike', 'Handbike', 'Recumbent', 'Trike', 'Tandem'];
  assert.deepEqual(readPrefBikes(null, mine, all), mine, 'first visit: the saved bikes on');
  assert.deepEqual(readPrefBikes(null, [], all), [], 'a visitor starts with no bike chip on');
  assert.deepEqual(readPrefBikes('on', mine, all), mine);
  assert.deepEqual(readPrefBikes('off', mine, all), []);
  assert.deepEqual(readPrefBikes('["MTB","Gravel","Unicycle"]', mine, all), ['Gravel', 'MTB'], 'a bike not saved can be on; an unknown type is dropped');
  assert.deepEqual(readPrefBikes('[]', mine, all), []);
  assert.deepEqual(readPrefBikes('{oops', mine, all), mine, 'an unreadable value reads as the first visit');
});

test('the stays count narrows with the stays chips, from the server\'s kept numbers', async () => {
  const { stayCoverageShown } = await load();
  const facets = {cyl: 412, acc: 900, cyl_acc: 37};
  assert.equal(stayCoverageShown(18955, facets, {bikeFriendly: false, wheelchairOut: false}), 18955, 'no chip narrows: every stay');
  assert.equal(stayCoverageShown(18955, facets, {bikeFriendly: true, wheelchairOut: false}), 412, 'Bike-friendly: the labelled stays');
  assert.equal(stayCoverageShown(18955, facets, {bikeFriendly: false, wheelchairOut: true}), 18055, 'Wheelchair-accessible off: those stays go');
  assert.equal(stayCoverageShown(18955, facets, {bikeFriendly: true, wheelchairOut: true}), 375, 'both: labelled, minus the labelled wheelchair ones');
  assert.equal(stayCoverageShown(18955, null, {bikeFriendly: true, wheelchairOut: true}), 18955, 'no numbers from the server: the total, never a guess');
});
