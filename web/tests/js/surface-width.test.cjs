// SPDX-License-Identifier: AGPL-3.0-only
//
// The road-surface class lines grow with the zoom (owner 2026-10-14: at street
// zoom a 3 px line beside the basemap's wider road did not say what the
// surface was). The width expression has to keep growing past z14, where the
// build's last tiles are stretched, so the colour band covers the road.
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'map', 'surface-tiles.js'), 'utf8');

test('the class line widens past the last built zoom', () => {
  const fn = source.slice(source.indexOf('function addClassifiedLayers'), source.indexOf('// Quality ticks above the class lines'));
  const m = fn.match(/'line-width': \['interpolate', \['linear'\], \['zoom'\], ([^\]]+)\]/);
  assert.ok(m, 'no zoom-interpolated line-width on the class lines');
  const stops = m[1].split(',').map(Number);
  const pairs = [];
  for (let i = 0; i < stops.length; i += 2) pairs.push([stops[i], stops[i + 1]]);
  const last = pairs[pairs.length - 1];
  assert.ok(last[0] >= 17, `the width stops at z${last[0]}; it must keep growing to street zoom`);
  assert.ok(last[1] >= 10, `at z${last[0]} the band is ${last[1]} px; it must cover the basemap road`);
  for (let i = 1; i < pairs.length; i++) assert.ok(pairs[i][1] > pairs[i - 1][1], 'the width must grow with every stop');
});
