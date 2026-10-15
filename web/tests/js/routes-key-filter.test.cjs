// SPDX-License-Identifier: AGPL-3.0-only
//
// The route rows of the key switch their type on and off, as the surface rows
// do (owner 2026-10-15: "we can enable/disable road surfaces but we should
// also be able to enable/disable route type"). map-and-search.md section 4.7.
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');
const twig = read('templates/map/index.html.twig');
const routes = read('assets/map/routes-tiles.js');
const panels = read('assets/map/panels.js');

test('each route type and the numbered junctions are a pressed row in the key', () => {
  const key = twig.slice(twig.indexOf('id="routesKey"'), twig.indexOf('</div>\n      </div>', twig.indexOf('id="routesKey"')) + 20);
  for (const g of ['national', 'regional', 'mtb', 'knoop']) {
    assert.match(key, new RegExp(`<button type="button" class="skey-row" data-route-grp="${g}" aria-pressed="true">`), `no switch for ${g}`);
  }
});

test('a route type off hides its lines only; the overlay switch still turns the whole network off', () => {
  assert.match(routes, /export function toggleRouteGroup\(key\)/);
  assert.match(routes, /const groupOn = \{ national: true, regional: true, mtb: true, knoop: true \};/);
  assert.match(routes, /visible && groupOn\[/, 'the group state does not take part in the visibility');
  assert.match(panels, /document\.querySelectorAll\('\.skey-row\[data-route-grp\]'\)\.forEach/);
});
