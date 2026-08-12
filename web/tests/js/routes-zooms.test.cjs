// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// The route-network layer's build/client agreements, in the same spirit as
// surface-zooms.test.cjs: the BUILD decides which networks exist and where the
// knooppunt points start (contract routes), the CLIENT decides how each
// network draws and where the badges appear. Disagree and a network the
// extractor ships has no style (an invisible corridor), or badges are asked
// for at zooms the artifact never built.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const contract = JSON.parse(
  fs.readFileSync(path.join(ROOT, '..', 'pipeline', 'contract', 'coverage-contract.json'), 'utf8'),
);
const source = fs.readFileSync(path.join(ROOT, 'assets/map/routes-tiles.js'), 'utf8');

test('every contract network has a style, and no invented ones', () => {
  const m = source.match(/const NET_STYLE = \{(.*?)\n\};/s);
  assert.ok(m, 'NET_STYLE not found in routes-tiles.js');
  const styled = [...m[1].matchAll(/^\s*(\w+):/gm)].map(x => x[1]);
  // Set equality: the styling order is presentation, the SET is the contract.
  assert.deepEqual([...styled].sort(), [...contract.routes.networks].sort(),
    'an unstyled network is an invisible corridor; an extra one is a promise nobody keeps');
});

test('the badge floor sits at or above the artifact node floor', () => {
  const m = source.match(/^const BADGE_MIN_ZOOM = (\d+);/m);
  assert.ok(m, 'BADGE_MIN_ZOOM not found in routes-tiles.js');
  const badge = Number(m[1]);
  assert.ok(badge >= contract.routes.nodes.minZoom,
    'badges would be asked for at zooms the artifact carries no points for');
  assert.ok(badge <= contract.routes.maxZoom + 1,
    'badges would never appear inside the artifact zoom span');
});

test('the client reads only promised route props', () => {
  // p.net / p.rr / p.refs / p.ref on ways, p.nr on knooppunten. A prop the
  // build stops emitting turns into a drawer row that silently vanishes.
  for (const prop of ['net', 'rr', 'refs', 'ref']) {
    assert.ok(contract.routes.tileProps.includes(prop),
      `the client reads ${prop}, which the contract does not promise`);
    assert.ok(source.includes(`p.${prop}`) || source.includes(`'${prop}'`),
      `${prop} is promised but never read by the client`);
  }
  assert.ok(contract.routes.nodes.tileProps.includes('nr'));
  assert.ok(source.includes('p.nr'));
});

test('the source-layer names match the build split', () => {
  // tiles.py emits routes_<cc> and knoop_<cc>; the client derives the same
  // names from CC_COVERAGE_COUNTRIES.
  assert.ok(source.includes("'routes_' + String(cc).toLowerCase()"));
  assert.ok(source.includes("'knoop_' + String(cc).toLowerCase()"));
});
