// SPDX-License-Identifier: AGPL-3.0-only
//
// The pure half of the map presets (docs/specs/map-presets.md section 3):
// what a preset's settings look like once normalised, whether the panel's
// current switches equal them, and which controls to tap to get from one
// state to another.
import test from 'node:test';
import assert from 'node:assert/strict';
import { MAX_PRESETS, LAYER_KEYS, normalize, same, clicksFor, summaryChips } from '../../assets/map/presets-model.js';

const full = () => ({ mode: 'curated', layers: ['climbs', 'water'], overlays: ['surface'], seasons: ['summer'], bikes: ['Gravel'], facets: { effortf: ['Steady'] } });

test('the cap is six and the eleven layer keys are the catalogue\'s, Road surface aside', () => {
  assert.equal(MAX_PRESETS, 6);
  assert.deepEqual(LAYER_KEYS, ['climbs', 'water', 'toilets', 'services', 'stays', 'hazards', 'transit', 'shelter', 'scenic', 'history', 'experience']);
});

test('Road surface is an overlay only: a preset naming it as a layer loses it there', () => {
  const n = normalize({ mode: 'all', layers: ['surface', 'climbs'], overlays: ['surface'] });
  assert.deepEqual(n.layers, ['climbs']);
  assert.deepEqual(n.overlays, ['surface']);
  assert.deepEqual(clicksFor({ layers: [] }, { layers: ['surface'] }, {}).filter(c => c.kind === 'layer'), []);
});

test('normalize sorts the lists, drops unknown keys and fills what is missing', () => {
  const n = normalize({ mode: 'all', layers: ['water', 'climbs', 'nope'], overlays: ['routes', 'surface'], bikes: ['Road', 'Gravel'], facets: { effortf: ['Tough', 'Steady'], colour: ['Red'] } });
  assert.deepEqual(n, { mode: 'all', layers: ['climbs', 'water'], overlays: ['routes', 'surface'], seasons: [], bikes: ['Gravel', 'Road'], facets: { effortf: ['Steady', 'Tough'] } });
  assert.equal(normalize({}).mode, 'all');
});

test('same compares normalised settings, order aside', () => {
  assert.ok(same(full(), { ...full(), layers: ['water', 'climbs'] }));
  assert.ok(!same(full(), { ...full(), mode: 'all' }));
  assert.ok(!same(full(), { ...full(), facets: {} }), 'an absent facet group means every chip on, which is a different state');
});

test('clicksFor lists the controls whose state differs, and nothing when the states agree', () => {
  const current = { mode: 'all', layers: ['climbs', 'water', 'toilets'], overlays: [], seasons: [], bikes: [], facets: { effortf: ['Steady', 'Challenging', 'Tough', 'Very steep'] } };
  const target = full();
  const clicks = clicksFor(current, target, { effortf: ['Steady', 'Challenging', 'Tough', 'Very steep'] });
  assert.deepEqual(clicks, [
    { kind: 'mode', value: 'curated' },
    { kind: 'layer', value: 'toilets' },
    { kind: 'overlay', value: 'surface' },
    { kind: 'season', value: 'summer' },
    { kind: 'bike', value: 'Gravel' },
    { kind: 'facet', group: 'effortf', value: 'Challenging' },
    { kind: 'facet', group: 'effortf', value: 'Tough' },
    { kind: 'facet', group: 'effortf', value: 'Very steep' },
  ]);
  assert.deepEqual(clicksFor(target, target, {}), []);
});

test('a facet group the preset leaves out is every chip on', () => {
  const current = { mode: 'all', layers: [], overlays: [], seasons: [], bikes: [], facets: { sqf: ['Smooth'] } };
  const clicks = clicksFor(current, { mode: 'all', layers: [], overlays: [], seasons: [], bikes: [], facets: {} }, { sqf: ['Smooth', 'Good'] });
  assert.deepEqual(clicks, [{ kind: 'facet', group: 'sqf', value: 'Good' }]);
});

test('the summary chips name what a preset switches on, mode first', () => {
  const chips = summaryChips(full(), { climbs: 'Climbs', water: 'Water & food' }, { curated: 'Best of' });
  assert.deepEqual(chips.map(c => c.text), ['Best of', 'Climbs', 'Water & food', 'surface', 'summer', 'Gravel']);
  assert.equal(chips[1].key, 'climbs');
});

test('a preset carries the key\'s surface classes and route types; absent means every one on', () => {
  const cur = { mode: 'all', layers: [], overlays: [], seasons: [], bikes: [], facets: {}, surfaces: ['paved', 'gravel', 'pave', 'dirt', 'rock', 'unverified'], routes: ['national', 'regional', 'mtb', 'knoop'] };
  const want = { mode: 'all', layers: [], overlays: [], seasons: [], bikes: [], facets: {}, surfaces: ['gravel'], routes: ['regional', 'knoop'] };
  const clicks = clicksFor(cur, want, {});
  assert.deepEqual(clicks.filter(c => c.kind === 'surface').map(c => c.value), ['paved', 'pave', 'dirt', 'rock', 'unverified']);
  assert.deepEqual(clicks.filter(c => c.kind === 'route').map(c => c.value), ['national', 'mtb']);
  assert.deepEqual(clicksFor(cur, { mode: 'all', layers: [], overlays: [], seasons: [], bikes: [], facets: {} }, {}), [], 'an old preset leaves the key alone');
});
