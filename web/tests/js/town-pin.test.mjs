// SPDX-License-Identifier: AGPL-3.0-only
//
// The town pointer (map-and-search.md §6.5) is the site's own mark: the pin
// with the spoked wheel from assets/brand/logo-mark.svg, and the card lands
// on the town at one fixed zoom.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { townPinSvg, TOWN_ZOOM } from '../../assets/map/town-pin.js';

test('the pin outline is the mark\'s pin', () => {
  const mark = readFileSync(new URL('../../assets/brand/logo-mark.svg', import.meta.url), 'utf8');
  const pinPath = /d="(M50 8 C31 8[^"]*)"/.exec(mark)[1];
  assert.match(townPinSvg(), new RegExp(pinPath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
});

test('the wheel: fourteen spokes with their rim dots, the hub, colours left to CSS', () => {
  const svg = townPinSvg();
  assert.equal((svg.match(/<line /g) || []).length, 14);
  assert.equal((svg.match(/<circle [^>]*r="1\.7"/g) || []).length, 14);
  assert.match(svg, /fill="currentColor"/);
  assert.match(svg, /var\(--spoke,#C2551F\)/);
  assert.match(svg, /var\(--wheel,#EFE6D4\)/);
  assert.match(svg, /aria-hidden="true"/);
});

test('a town card lands at street zoom', () => {
  assert.equal(TOWN_ZOOM, 14);
});
