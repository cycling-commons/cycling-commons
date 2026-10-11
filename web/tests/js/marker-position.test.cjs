// SPDX-License-Identifier: AGPL-3.0-only
//
// MapLibre places every marker with position:absolute and a transform
// (.maplibregl-marker). A marker whose own class sets position:relative is
// laid out in page flow first, below the markers added before it, and drifts
// by their height. Owner-reported 2026-10-11: on the Spa town card, the hover
// ring for Ardennes Bike sat 43 px (the town pin's height) south of its disc.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = (p) => fs.readFileSync(path.join(ROOT, p), 'utf8');
const CSS = ['assets/styles/map.css', 'assets/styles/pins.css'].map(read).join('\n').replace(/\/\*[\s\S]*?\*\//g, '');

// The root element of each DOM marker on the map, as the map code builds it.
const MARKER_ROOTS = {
  'cc-highlight': ['assets/map/drawer.js', /el\.className='cc-highlight'; hlMarker=new maplibregl\.Marker\(\{element:el/],
  'cc-town-pin': ['assets/map/drawer.js', /el\.className='cc-town-pin';/],
  'cc-pin': ['assets/map/drawer.js', /new maplibregl\.Marker\(\{element:el, anchor:'bottom'\}\)/],
};

// Every rule whose selector names `.cls` as its own element (not a descendant
// of a page-only wrapper such as the Map key's `.mkp-sw`, not a ::before or
// ::after drawn inside it), with its body.
function rulesFor(cls) {
  const out = [];
  // Innermost rules only: a rule inside @media or @layer is matched on its own.
  const re = /([^{};]+)\{([^{}]*)\}/g;
  let m;
  while ((m = re.exec(CSS))) {
    for (const sel of m[1].split(',').map((s) => s.trim())) {
      if (sel.includes('::')) continue;
      if (new RegExp(`^\\.${cls}(?![\\w-])([^\\s>+~]*)$`).test(sel)) out.push({ sel, body: m[2] });
    }
  }
  return out;
}

for (const [cls, [file, built]] of Object.entries(MARKER_ROOTS)) {
  test(`.${cls} is a map marker`, () => {
    assert.match(read(file), built);
  });

  test(`.${cls} leaves MapLibre's position:absolute alone on the map`, () => {
    const offending = rulesFor(cls)
      .filter((r) => /(^|;)\s*position\s*:/.test(r.body))
      .filter((r) => !r.sel.includes(':not(.maplibregl-marker)'))
      .map((r) => r.sel);
    assert.deepEqual(offending, [], `a position on .${cls} must carry :not(.maplibregl-marker)`);
  });
}

test('off the map, the ring and the pin still hold their badges and pulses', () => {
  assert.match(CSS, /\.cc-highlight:not\(\.maplibregl-marker\)\{position:relative\}/);
  assert.match(CSS, /\.cc-pin:not\(\.maplibregl-marker\)\{position:relative\}/);
});
