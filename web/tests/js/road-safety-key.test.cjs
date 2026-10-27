// SPDX-License-Identifier: AGPL-3.0-only
// The Road safety overlay and its key (traffic-measurements.md section 10, owner 2026-10-26: "Road safety is still
// beta sort of as you can only see it when you are in the add road safety mode. We also have no place where we
// explain the colors. Zoom a bit out and the colors are gone."). Pinned in the source, as the other map tests do.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const read = p => fs.readFileSync(path.join(__dirname, '../..', p), 'utf8');
const twig = read('templates/map/index.html.twig');
const rs = read('assets/map/road-safety.js');
const panels = read('assets/map/panels.js');
const colours = read('assets/lib/traffic-colours.js');

test('Road safety is its own map overlay, for everyone, once the road pieces are built', () => {
  const ov = twig.slice(twig.indexOf("<h3>{{ 'map.map_overlays'|trans }}</h3>"), twig.indexOf('id="ovFadeRoads"'));
  assert.match(ov, /<button class="layer ov-row" id="ovRoadSafety" type="button" hidden aria-pressed="false">/);
  const before = twig.slice(0, twig.indexOf('id="ovRoadSafety"'));
  assert.ok(!/\{% if (pending_is_curator|is_granted)[^%]*%\}(?![\s\S]*\{% endif %\})/.test(before.slice(before.lastIndexOf('<h3>'))), 'not behind a sign-in or a curator check');
  assert.match(panels, /if\(roadSafetyBtn && roadSafetyLayerAvailable\(\)\)\{\n    roadSafetyBtn\.hidden=false;/);
  assert.match(panels, /roadSafetyBtn\.onclick=\(\)=>paintOverlay\(roadSafetyBtn, setRoadSafetyLayer\(!roadSafetyLayerVisible\(\)\)\);/);
});

test('the legend explains the three colours while the overlay or the mode draws them, in the map\'s own colours', () => {
  const key = twig.slice(twig.indexOf('<div class="rkey" id="roadSafetyKey" hidden>'), twig.indexOf('id="roadSafetyKeyZoom"'));
  assert.ok(key.length > 0, 'no key block');
  const lc = Object.fromEntries([...colours.matchAll(/(\w): '(#[0-9A-F]{6})'/g)].filter(m => 'plr'.includes(m[1]) && m[1].length === 1).map(m => [m[1], m[2]]));
  for (const label of ['p', 'l', 'r']) {
    const row = new RegExp(`data-rs-label="${label}" aria-pressed="true"><span class="skey-ln" style="--sc:${lc[label]}">`);
    assert.match(key, row, `row ${label} in ${lc[label]}`);
  }
  assert.match(panels, /if\(roadSafetyKey\) roadSafetyKey\.hidden=!roadSafetyKeyShown\(\);/);
  assert.match(rs, /export const roadSafetyKeyShown = \(\) => shown\(\);/);
  assert.match(rs, /const shown = \(\) => on \|\| viewOn;/);
  assert.match(rs, /document\.dispatchEvent\(new Event\('cc:legend'\)\);/, 'the mode and the overlay tell the legend');
});

test('the road types draw from zoom 12, and picking a road still waits for zoom 14', () => {
  assert.match(rs, /export const VIEW_ZOOM = 12;/);
  assert.match(rs, /id: line, type: 'line', source: sourceId, 'source-layer': sourceLayer, minzoom: VIEW_ZOOM,/);
  assert.match(rs, /eachSourceInView\(map, 'roadpieces', 'roadpieces', VIEW_ZOOM,/);
  assert.match(rs, /if \(!ids\.length \|\| map\.getZoom\(\) < PIECE_ZOOM\) return;/, 'a click picks a road only from zoom 14');
  assert.match(twig, /id="roadSafetyKeyZoom" hidden>\{\{ 'map\.legend_rs_zoom'\|trans \}\}/);
});

test('each key row switches its road type, and the overlay keeps drawing when the mode ends', () => {
  assert.match(rs, /export function toggleRoadType\(label\)\{?/);
  assert.match(rs, /const ROAD_TYPE = \['case', \['==', \['get', 'a'\], 1\], 'a', \['get', 'l'\]\];/);
  assert.match(rs, /const labelFilter = \(\) => \['!', \['in', ROAD_TYPE, \['literal', \[\.\.\.hiddenLabels\]\]\]\];/);
  assert.match(rs, /releaseMapClicks\(\);\n  choose\(\[\]\);\n  show\(viewOn\);/);
});

test('the Key panel carries the road safety colours too, read only', () => {
  const grp = twig.slice(twig.indexOf('<div class="grp" id="mk-road-safety">'), twig.indexOf('{# The ride review\'s own lines'));
  for (const c of ['#2F6FDB', '#E8A33D', '#7A3FB8']) assert.ok(grp.includes(`border-top-color:${c}`), c);
  assert.ok(!grp.includes('<button'), 'the Key panel explains; the switches live in the key box');
});

test('a road-safety layer is added with no second argument: that slot names the layer to draw under', () => {
  // An options object there made MapLibre refuse the layer, and the overlay drew nothing (owner 2026-10-26).
  for (const m of rs.matchAll(/map\.addLayer\(\{[\s\S]*?\n  \}(,[^)]*)?\);/g)) assert.equal(m[1], undefined, m[0].slice(0, 60));
});

test('an advisory lane draws dashed, in the lane colour, with its own key row (traffic-measurements.md section 10)', () => {
  const key = twig.slice(twig.indexOf('<div class="rkey" id="roadSafetyKey" hidden>'), twig.indexOf('id="roadSafetyKeyZoom"'));
  assert.match(key, /data-rs-label="a" aria-pressed="true"><span class="skey-ln dash" style="--sc:#E8A33D"><\/span>\{\{ 'map\.legend_rs_advisory'\|trans \}\}/);
  assert.match(rs, /'line-dasharray': \['case', \['==', \['get', 'a'\], 1\], \['literal', \[2, 1\.5\]\], \['literal', \[1, 0\]\]\],/);
  const grp = twig.slice(twig.indexOf('<div class="grp" id="mk-road-safety">'), twig.indexOf('{# The ride review\'s own lines'));
  assert.ok(grp.includes('border-top-color:#E8A33D;border-top-style:dashed'), 'the Key panel too');
});
