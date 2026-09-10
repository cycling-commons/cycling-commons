// SPDX-License-Identifier: AGPL-3.0-only
//
// The pin grammar (docs/specs/data-provider-hierarchy.md §6.3): kind lives in
// the glyph, state is two shared badges. waterKind() and stateOf() are the
// two rules every renderer shares, so their edges are pinned here: the tile
// properties (old boolean tiles and new tri-state ones), the detail response,
// and a rider's own vocabulary all have to land on the same five kinds.
//
// icons.js imports map-init.js, which constructs MapLibre, so the two
// functions are lifted out of the source and evaluated on their own.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'map', 'icons.js'), 'utf8');

function lift(name) {
  const m = src.match(new RegExp(`export function ${name}\\(p(, now)?\\)\\{[\\s\\S]*?\\n\\}`));
  assert.ok(m, `${name}() not found in icons.js`);
  return m[0].replace('export ', '');
}
const ctx = {};
vm.createContext(ctx);
vm.runInContext(
  "const yes = v => v===true || v==='true' || v===1 || v==='1' || v==='yes';\n"
  + lift('waterKind') + '\n' + lift('stateOf').replace('(p)', '(p, now)'),
  ctx,
);
const waterKind = p => vm.runInContext('waterKind', ctx)(p);
const JULY = new Date(2026, 6, 15), JANUARY = new Date(2026, 0, 15);
const stateOf = (p, now = JANUARY) => vm.runInContext('stateOf', ctx)(p, now);

test('tile properties: tri-state potable and the food flag', () => {
  assert.equal(waterKind({ potable: 'yes' }), 'tap');
  assert.equal(waterKind({ potable: 'no' }), 'no');
  assert.equal(waterKind({}), 'unk', 'absent = nobody said');
  assert.equal(waterKind({ food: true }), 'food');
  assert.equal(waterKind({ food: true, potable: 'yes' }), 'food_water');
  assert.equal(waterKind({ food: 'true', potable: 'yes' }), 'food_water', 'string-coerced through the tile');
});

test('tiles published before the tri-state read boolean false as unknown', () => {
  // false covered both "tagged no" and "nobody said"; only the detail
  // response can tell, so the first paint must not claim "not drinkable".
  assert.equal(waterKind({ potable: true }), 'tap');
  assert.equal(waterKind({ potable: false }), 'unk');
});

test('the detail response settles what the tile could not', () => {
  assert.equal(waterKind({ potable: false, osmPotable: false }), 'no');
  assert.equal(waterKind({ osmPotable: true, osmFood: true }), 'food_water');
  assert.equal(waterKind({ osmFood: true }), 'food');
});

test("a rider's own record uses the form vocabulary", () => {
  assert.equal(waterKind({ potable: 'Yes (public supply)' }), 'tap');
  assert.equal(waterKind({ potable: 'No / non-potable' }), 'no');
  assert.equal(waterKind({ potable: 'Unknown' }), 'unk');
  // The pre-2026-09-10 spelling still arrives on tiles published before the rename.
  assert.equal(waterKind({ potable: 'Unsigned — use judgement' }), 'unk');
  // "Unknown" must never fall into the No branch: an unknown tap is not a bad one.
  assert.notEqual(waterKind({ potable: 'Unknown' }), 'no');
  assert.equal(waterKind({ type: 'Café — refill point', potable: 'Yes (public supply)' }), 'food_water');
  assert.equal(waterKind({ type: 'Café — refill point' }), 'food');
});

test('state: the two shared badges, nothing else', () => {
  assert.equal(stateOf({ condition: 'Out of order' }), 'warn');
  assert.equal(stateOf({ condition: 'Closed' }), 'warn');
  assert.equal(stateOf({ condition: 'As mapped' }), null);
  assert.equal(stateOf({ seasonal: 'Summer only' }), 'hours', 'summer-only in January');
  assert.equal(stateOf({ seasonal: 'Frost-shut in winter' }), 'hours', 'frost-shut in January');
  assert.equal(stateOf({ seasonal: 'Year-round' }), null);
  // The clock answers "now": a frost-shut tap in July is a working tap.
  assert.equal(stateOf({ seasonal: 'Frost-shut in winter' }, JULY), null);
  assert.equal(stateOf({ seasonal: 'Summer only' }, JULY), null);
  assert.equal(stateOf({ seasonal: 'Frost-shut in winter' }, new Date(2026, 10, 1)), 'hours', 'November');
  assert.equal(stateOf({ seasonal: 'Frost-shut in winter' }, new Date(2026, 3, 1)), null, 'April');
  assert.equal(stateOf({ availability: 'Daytime only' }), 'hours');
  assert.equal(stateOf({ availability: 'Ask or behind a gate' }), 'hours');
  assert.equal(stateOf({ availability: 'Always' }), null);
  assert.equal(stateOf({ availability: 'Unknown' }), null);
  assert.equal(stateOf({ condition: 'Out of order', seasonal: 'Summer only' }), 'warn', 'not usable now beats not always');
  assert.equal(stateOf(undefined), null);
});
