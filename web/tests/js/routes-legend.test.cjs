// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// The route key and the route rendering must be the same three colours.
//
// A legend is a promise about what is on screen, so it fails in the one way
// nobody notices: change a line colour in routes-tiles.js and the key goes on
// confidently naming the old one. The map still looks fine. Every reader is
// simply told the wrong thing.
//
// So the swatches are pinned against GROUPS, in both directions - every drawn
// family has a row, and no row invents a family - and the knooppunt badge is
// pinned against the layer that actually draws it.
//
// The rows are deliberately NOT filters, unlike the surface key above them, and
// that is pinned too: the surface rows are <button aria-pressed>, and a row
// that looks pressable and does nothing is worse than a plain line of text.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');

const source = read('assets/map/routes-tiles.js');
const twig = read('templates/map/index.html.twig');
const panels = read('assets/map/panels.js');
const LOCALES = ['en', 'fr', 'nl', 'de', 'es'];

/** The <div class="rkey" id="routesKey"> block, and nothing around it. */
function keyBlock() {
  const m = twig.match(/<div class="rkey" id="routesKey" hidden>([\s\S]*?)\n {6}<\/div>/);
  assert.ok(m, 'the routes key block was not found in the map template');
  return m[1];
}

function groupColours() {
  const m = source.match(/const GROUPS = \[(.*?)\n\];/s);
  assert.ok(m, 'GROUPS not found in routes-tiles.js');
  const found = [...m[1].matchAll(/key: '(\w+)'[^}]*?color: '(#[0-9A-Fa-f]{6})'/g)];
  assert.ok(found.length, 'no group colours parsed');
  return Object.fromEntries(found.map(x => [x[1], x[2].toUpperCase()]));
}

test('every drawn route family has a key row in its own colour', () => {
  const block = keyBlock();
  for (const [group, colour] of Object.entries(groupColours())) {
    assert.ok(
      block.includes(`--sc:${colour}`),
      `the ${group} routes draw ${colour} and the key does not show it`,
    );
  }
});

test('the key invents no colour the map never draws', () => {
  const drawn = new Set(Object.values(groupColours()));
  const shown = [...keyBlock().matchAll(/--sc:(#[0-9A-Fa-f]{6})/g)].map(x => x[1].toUpperCase());
  assert.ok(shown.length, 'the key has no swatches at all');
  for (const colour of shown) {
    assert.ok(drawn.has(colour), `the key shows ${colour}, which no route family uses`);
  }
  assert.equal(shown.length, drawn.size, 'one row per drawn family, no more and no fewer');
});

test('the knooppunt swatch matches the badge the map draws', () => {
  const css = read('assets/styles/map.css');
  const swatch = css.match(/\.rkey-node\{([\s\S]*?)\}/);
  assert.ok(swatch, '.rkey-node not found');

  // The three colours the disc and its numeral are painted with.
  assert.match(source, /'circle-color': '#FFFFFF'/);
  assert.match(source, /'circle-stroke-color': '#7A4FCF'/);
  assert.match(source, /'text-color': '#5B3A9E'/);
  for (const colour of ['#FFFFFF', '#7A4FCF', '#5B3A9E']) {
    assert.ok(swatch[1].includes(colour), `the key's badge is missing ${colour}`);
  }
});

test('the key follows the layer it explains', () => {
  assert.match(twig, /<div class="rkey" id="routesKey" hidden>/,
    'the key must start hidden - it explains a layer that is off by default');
  assert.match(panels, /routesKey\.hidden=!routesTilesVisible\(\)/,
    'the key has to hide again with the layer, or it explains an empty map');
  // Once on load and once per toggle: a key that only syncs on click is wrong
  // for anyone who arrives with the layer already on. syncLegend() is shared
  // with the surface key now, so it also runs from the counts event and from
  // the surfaces toggle - three or more, never one.
  assert.ok((panels.match(/syncLegend\(\)/g) || []).length >= 3,
    'syncLegend must run on init as well as on every toggle that changes the map');
});

test('the route rows do not pretend to be filters', () => {
  const block = keyBlock();
  assert.doesNotMatch(block, /<button/, 'a row that looks pressable and does nothing is worse than plain text');
  assert.doesNotMatch(block, /aria-pressed/);
  // The surface rows above ARE filters, and stay that way.
  assert.match(twig, /class="skey-row" data-surf-cls="paved" aria-pressed="true"/);
});

test('the strings exist in all five locales', () => {
  for (const l of LOCALES) {
    const y = read(`translations/messages.${l}.yaml`);
    for (const key of ['legend_route_national', 'legend_route_regional', 'legend_route_mtb', 'legend_route_node']) {
      assert.match(y, new RegExp(`^\\s{2}${key}: `, 'm'), `messages.${l}.yaml is missing map.${key}`);
    }
  }
});
