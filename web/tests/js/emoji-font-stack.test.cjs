// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// Every surface that prints a category glyph must ask for an emoji font.
//
// Eight of the thirteen layer icons are emoji — 💧 🚻 🚆 📷 🏛 ⛺ ⛑ ⛰ — and the
// three font families this site serves are LATIN SUBSETS with no emoji coverage
// whatsoever. An element that inherits --sans therefore draws them as tofu
// boxes: the pin still appears, still has its colour, still opens its drawer,
// and simply says nothing. Nothing errors and nothing looks broken from the
// inside; it was reported by the owner seeing a rectangle in a map pin
// (2026-08-10). The canvas-minted tile icons never had the problem because
// icons.js names the emoji fonts explicitly — .cc-pin span did not.
//
// So: one declaration of the stack (--emoji in map.css), asserted here to be
// used by every DOM glyph surface and to agree with the canvas one in
// icons.js. Two lists that must match is exactly how the first one drifts.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const css = fs.readFileSync(path.join(ROOT, 'assets/styles/map.css'), 'utf8');
const icons = fs.readFileSync(path.join(ROOT, 'assets/map/icons.js'), 'utf8');

/** Normalised font list: lowercase family names, quotes and spacing stripped. */
const families = (decl) =>
  decl
    .split(',')
    .map((f) => f.trim().replace(/^["']|["']$/g, '').toLowerCase())
    .filter(Boolean);

test('map.css declares the emoji stack exactly once', () => {
  const decl = css.match(/--emoji:\s*([^;]+);/);
  assert.ok(decl, '--emoji is not declared in map.css');
  assert.equal(css.match(/--emoji:\s*/g).length, 1, '--emoji must have one definition');
  assert.ok(
    families(decl[1]).some((f) => f.includes('emoji')),
    'the --emoji stack names no emoji font at all',
  );
});

test('the CSS stack and the canvas stack name the same emoji fonts', () => {
  const cssFonts = families(css.match(/--emoji:\s*([^;]+);/)[1]).filter((f) => f.includes('emoji') || f.includes('symbols'));
  // icons.js: gx.font = `${size}px "Apple Color Emoji",…`
  const canvas = icons.match(/gx\.font\s*=\s*`[^`]*?px\s*([^`]+)`/);
  assert.ok(canvas, 'could not find the canvas font declaration in icons.js');
  const canvasFonts = families(canvas[1]).filter((f) => f.includes('emoji') || f.includes('symbols'));

  assert.deepEqual(
    canvasFonts,
    cssFonts,
    'the DOM and canvas glyph paths disagree about which emoji fonts to try — ' +
      'one of them will render tofu on a machine the other handles',
  );
});

test('every DOM surface that prints a layer glyph uses the stack', () => {
  // selector -> what renders a category icon there
  const surfaces = {
    '.cc-pin span': 'the map pin itself',
    '.layer .sw .sw-g': 'the layer rail',
    '.cc-near-k': 'the ride-check nearby list',
    '.cc-g': 'the drawer type chip',
    '.search-res .sw': 'the search results',
  };

  for (const [selector, where] of Object.entries(surfaces)) {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const rule = css.match(new RegExp(`${escaped}\\s*\\{([^}]*)\\}`));
    assert.ok(rule, `no rule found for ${selector} (${where})`);
    assert.match(
      rule[1],
      /font-family:\s*var\(--emoji\)/,
      `${selector} (${where}) prints a category glyph without the emoji stack — it will render a tofu box`,
    );
  }
});
