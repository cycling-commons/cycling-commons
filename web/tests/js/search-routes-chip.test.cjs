// SPDX-License-Identifier: AGPL-3.0-only
//
// Routes in the search results are the rider's choice (owner 2026-09-28):
// a "Routes" checkbox above "Everywhere", off until ticked and remembered in
// this browser. It governs both halves of a search, the routes the map holds
// and the server's worldwide hits (docs/specs/map-and-search.md §7.1).
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const ui = fs.readFileSync(path.join(ROOT, 'assets', 'map', 'search-ui.js'), 'utf8');
const twig = fs.readFileSync(path.join(ROOT, 'templates', 'map', 'index.html.twig'), 'utf8');

test('the checkbox sits above the reach, unticked, with a translated name and title', () => {
  assert.match(twig, /<label class="search-routes" title="\{\{ 'map\.search_routes_title'\|trans \}\}"><input type="checkbox" id="searchRoutes"> \{\{ 'map\.search_routes'\|trans \}\}<\/label>/);
  assert.ok(twig.indexOf('id="searchRoutes"') < twig.indexOf('id="searchReach"'), 'Routes, then Everywhere');
  // Stacked in one column, so the heading keeps the width for the region name.
  assert.match(twig, /<div class="search-chips">\s*<label class="search-routes"[\s\S]*?<\/label>\s*<button[^>]*id="searchReach"/);
  const css = fs.readFileSync(path.join(ROOT, 'assets', 'styles', 'map.css'), 'utf8');
  assert.match(css, /\.search-chips\{[^}]*flex-direction:column/);
  assert.match(css, /\.search-head\{[^}]*align-items:flex-start/, 'the heading starts at the top, beside the first control');
});

test('the choice is remembered in this browser, and a blocked storage means off', () => {
  assert.match(ui, /const ROUTES_KEY='cc-search-routes';/);
  assert.match(ui, /try \{ _routes = localStorage\.getItem\(ROUTES_KEY\)==='1'; \} catch\(e\)/);
  assert.match(ui, /try \{ localStorage\.setItem\(ROUTES_KEY, _routes\?'1':'0'\); \} catch\(e\)/);
});

test('off, routes leave both halves of the search; on, the server is asked for them', () => {
  assert.match(ui, /if\(!_routes && it\.letter==='R'\) continue;/, 'the routes the map holds');
  assert.match(ui, /'\/v1\/search\?limit=30'\+\(_routes\?'&routes=include':''\)\+'&q='/, 'the worldwide hits');
});

test('a route hit opens as a route, anything else as a place', () => {
  assert.match(ui, /letter==='R' \? openRouteById\(id\) : openFeatureById\(id\)/);
});

test('ticking the box keeps the results open and reruns the search', () => {
  assert.match(ui, /closest\('#searchRes, #searchReach, \.search-routes'\)/);
  assert.match(ui, /routesBox\.addEventListener\('change'/);
});

test('while routes are in the list, the example in the empty box names them', () => {
  assert.match(twig, /data-placeholder="\{\{ 'map\.search_placeholder'\|trans \}\}" data-placeholder-routes="\{\{ 'map\.search_placeholder_routes'\|trans \}\}"/);
  assert.match(ui, /const ph = _routes \? sBox\.dataset\.placeholderRoutes : sBox\.dataset\.placeholder;/);
  for (const l of ['en', 'nl', 'fr', 'de', 'es']) {
    const y = fs.readFileSync(path.join(ROOT, 'translations', 'messages.' + l + '.yaml'), 'utf8');
    assert.match(y, /^  search_placeholder_routes: '[^']+…'$/m, l);
  }
});

test('the result list grows into the panel\'s empty space and never past it', () => {
  const css = fs.readFileSync(path.join(ROOT, 'assets', 'styles', 'map.css'), 'utf8');
  assert.match(css, /\.search-res\{[^}]*max-height:300px/, 'the base height');
  assert.match(ui, /const spare=Math\.floor\(box\.top\+pane\.clientHeight-padBottom-bottom\);/, 'measured from where the content ends');
  assert.match(ui, /const over=pane\.scrollHeight-pane\.clientHeight;\s*if\(over>0\) sRes\.style\.maxHeight=/, 'an overshoot is taken back');
  assert.match(ui, /window\.addEventListener\('resize', fitResults\);/);
});

test('where a search result is sits above every pin, and popups above that', () => {
  const css = fs.readFileSync(path.join(ROOT, 'assets', 'styles', 'map.css'), 'utf8');
  assert.match(css, /\.cc-town-pin,\.cc-highlight,\.cc-pin\.reveal\{z-index:3\}/);
  assert.match(css, /\.maplibregl-popup\{z-index:4\}/);
  const pins = fs.readFileSync(path.join(ROOT, 'assets', 'styles', 'pins.css'), 'utf8');
  assert.ok(!/\.cc-pin[^{]*\{[^}]*z-index/.test(pins.replace(/\.cc-pin\.reveal/g, '')), 'no ordinary pin claims a z-index of its own');
});
