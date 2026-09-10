// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// The marker grammar (docs/specs/data-provider-hierarchy.md §6.7): two axes,
// one mark each. The border answers custody and nothing else; the badge
// answers evidence and nothing else. The rung and the custody arrive
// computed from the server (`rung`, `custody` on every served point);
// icons.js never derives them, so the map and the API cannot disagree.
//
// icons.js imports map-init.js, which constructs MapLibre, so the two pure
// functions are lifted out of the source and evaluated on their own, the way
// kind-icons.test.cjs lifts waterKind() and stateOf().
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.join(__dirname, '..', '..');
const src = fs.readFileSync(path.join(ROOT, 'assets', 'map', 'icons.js'), 'utf8');
const css = fs.readFileSync(path.join(ROOT, 'assets', 'styles', 'pins.css'), 'utf8');
const mapCss = fs.readFileSync(path.join(ROOT, 'assets', 'styles', 'map.css'), 'utf8');

function lift(name) {
  const m = src.match(new RegExp(`export function ${name}\\([^)]*\\)\\{[\\s\\S]*?\\n\\}`));
  assert.ok(m, `${name}() not found in icons.js`);
  return m[0].replace('export ', '');
}
const ctx = {};
vm.createContext(ctx);
vm.runInContext(
  "const NO_WITNESS=[1,2,3,4,5,6,8];\n" + lift('borderFor') + '\n' + lift('badgeFor') + '\n' + lift('pinClasses')
  + '\n' + lift('kindImageId') + '\n' + lift('hasWitness'),
  ctx,
);
const { borderFor, badgeFor, pinClasses, kindImageId, hasWitness } = ctx;

test('the border answers custody and nothing else', () => {
  assert.equal(borderFor('gross'), 'disc');
  assert.equal(borderFor('specialty'), 'dashed');
  assert.equal(borderFor('ours'), 'solid');
  assert.equal(borderFor(undefined), 'solid', 'a pin with no custody is one of ours');
});

test('the badge answers evidence and nothing else', () => {
  for (const rung of [1, 2, 3, 4, 5, 6, 8]) {
    assert.equal(badgeFor(rung), '?', `rung ${rung} keeps the ?`);
  }
  for (const rung of [7, 9, 10, 11]) {
    assert.equal(badgeFor(rung), '', `rung ${rung} drops the ?`);
  }
});

test('the NO_WITNESS list in icons.js is the one EvidenceRung.php carries', () => {
  const php = fs.readFileSync(path.join(ROOT, 'src', 'Catalog', 'EvidenceRung.php'), 'utf8');
  const phpList = php.match(/NO_WITNESS = \[([\d, ]+)\]/)[1].replace(/\s/g, '');
  const jsList = src.match(/const NO_WITNESS\s*=\s*\[([\d, ]+)\]/)[1].replace(/\s/g, '');
  assert.equal(jsList, phpList, 'two copies of the badge rule must not drift');
});

test('a pin with no rung wears the ? unless the payload says verified', () => {
  assert.equal(badgeFor(undefined), '?', 'no receipt on record reads as nobody stood here');
  assert.ok(pinClasses({}).includes('q'));
  assert.ok(!pinClasses({ v: 1 }).includes('q'), 'a verified row without a rung is still verified');
});

test('pinClasses puts the border and the badge on the element, and nothing about a dot', () => {
  // Joined: the array was built in another vm context and deepEqual would
  // reject its foreign Array prototype.
  const classes = props => pinClasses(props).join(' ');
  assert.equal(classes({ rung: 4, custody: 'specialty' }), 'dashed q');
  assert.equal(classes({ rung: 9, custody: 'ours' }), '');
  assert.equal(classes({ rung: 1, custody: 'gross' }), 'disc q');
  assert.equal(classes({ rung: 7, custody: 'gross' }), 'disc');
});

test('no marker carries a verified dot any more', () => {
  assert.ok(!/\.cc-pin\.cur::after/.test(css + mapCss), 'the paper dot rule is gone');
  assert.ok(!/\.cc-pin\.community/.test(css + mapCss), 'the old one-class tier is gone; border and badge are two classes');
  assert.ok(!/^\s*\.cc-pin\{/m.test(mapCss), 'map.css must not carry a second copy of the pin: pins.css is the one definition');
  assert.ok(/\.cc-pin\.q::after\{content:"\?"/.test(css), 'the badge is its own class');
  assert.ok(/\.cc-pin\.dashed\{/.test(css), 'the dashed border is its own class');
  assert.ok(/\.cc-pin\.disc\{/.test(css), 'the small disc is its own class');
});

// --- the coverage symbol layer draws the same grammar (Task B2) ---------

test('every minted tile icon has a badged twin with its own image id', () => {
  assert.equal(kindImageId('B', 'tap', false), 'kind-b-tap');
  assert.equal(kindImageId('B', 'tap', true), 'kind-b-tap-q');
  assert.equal(kindImageId('B', 'tap'), 'kind-b-tap', 'no badge argument means the plain icon');
  assert.notEqual(kindImageId('B', 'tap', true), kindImageId('B', 'tap', false), 'two states cannot share one image');
});

test('a tile point drops the badge only for a dated check_date inside the window', () => {
  const cutoff = '2026-03-10';
  assert.equal(hasWitness('2026-08-01', cutoff), true);
  assert.equal(hasWitness('2026-03-10', cutoff), true, 'on the cutoff day is inside');
  assert.equal(hasWitness('2025-12-31', cutoff), false, 'aged out: the badge returns (rung 6)');
  assert.equal(hasWitness(undefined, cutoff), false, 'no check_date: nobody has stood here on record');
  assert.equal(hasWitness('summer', cutoff), false, 'free text is not a date, whatever it sorts as');
  assert.equal(hasWitness('2026', cutoff), false, 'a bare year is not a dated witness');
  assert.equal(hasWitness('2026-08-01', undefined), false, 'no cutoff from the shell: fail closed, keep the badge');
});

test('the coverage layer picks its icon by the tile check_date, in every branch', () => {
  const cov = fs.readFileSync(path.join(ROOT, 'assets', 'map', 'coverage.js'), 'utf8');
  assert.ok(/\['get',\s*'cd'\]/.test(cov), 'the icon-image expression never reads cd');
  assert.ok(/CC_WITNESS_CUTOFF/.test(src) || /CC_WITNESS_CUTOFF/.test(cov), 'the cutoff comes from the shell, not a JS constant');
  assert.ok(!/kindImageId\('B',\s*'[a-z_]+'\)/.test(cov), 'a plain kindImageId in coverage.js would draw one state for both');
});

test('the shell template emits the cutoff for the tiles', () => {
  const twig = fs.readFileSync(path.join(ROOT, 'templates', 'map', 'index.html.twig'), 'utf8');
  assert.ok(twig.includes('window.CC_WITNESS_CUTOFF'), 'index.html.twig does not emit CC_WITNESS_CUTOFF');
});
