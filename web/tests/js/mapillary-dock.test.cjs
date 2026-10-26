// SPDX-License-Identifier: AGPL-3.0-only
// The street-level dock shows the image clicked last (map-and-search.md section 10, owner 2026-10-26: "When I click on
// a new Mapillary icon it does not load the new location but keeps showing an old one"). The viewer needs a browser
// and a token, so these pin the wiring in the source, as the other map tests do.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const src = fs.readFileSync(path.join(__dirname, '../../assets/map/mapillary.js'), 'utf8');
const open = (src.match(/export async function openMapillaryImage\(imageId\)\{[\s\S]*?\n\}/) || [''])[0];
const mount = (src.match(/function mountViewer\(imageId\)\{[\s\S]*?\n\}/) || [''])[0];

test('the image asked for last is remembered, and only it ends the wait', () => {
  assert.match(open, /mlyWanted=imageId;/);
  assert.match(open, /openMapillaryDock\(\);/, 'the spinner goes up on every click');
  assert.match(mount, /if\(!ev\.image \|\| String\(ev\.image\.id\)!==mlyWanted\) return;/, 'an earlier image changes nothing');
});

test('a dropped move opens a fresh viewer on the image asked for, unless a newer click took over', () => {
  assert.match(open, /mlyViewer\.resize\(\)/, 'the canvas is measured anew after the dock was closed');
  assert.match(open, /mlyViewer\.moveTo\(imageId\)\.catch\(\(\)=>\{ if\(imageId===mlyWanted\) mountViewer\(imageId\); \}\);/);
  assert.doesNotMatch(src, /moveTo\(imageId\)\.catch\(\(\)=>\{\}\)/, 'never swallowed');
  assert.match(mount, /mlyViewer\.remove\(\)/, 'the old viewer goes');
});

test('closing the dock forgets the image and stops a sequence playing', () => {
  const close = (src.match(/export function mlyClose\(\)\{[\s\S]*?\n\}/) || [''])[0];
  assert.match(close, /mlyWanted=null;/);
  assert.match(close, /mlyViewer\.getComponent\('sequence'\)\.stop\(\)/, 'it played on in the background (owner 2026-10-26)');
});
