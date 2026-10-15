// SPDX-License-Identifier: AGPL-3.0-only
//
// Map presets wiring (docs/specs/map-presets.md): the Presets group above
// View mode with its pencil and the visitor line, presets.js last in the
// map's boot so the default applies over the remembered switches, the
// settings section with its script, the token id and the limiter.
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');
const twig = read('templates/map/index.html.twig');
const settings = read('templates/settings/index.html.twig');
const presets = read('assets/map/presets.js');
const entry = read('assets/map/map.js');
const csrf = read('config/packages/csrf.yaml');
const limiters = read('config/packages/rate_limiter.yaml');

test('the Presets group is the first group of the Layers panel, with the pencil and the visitor line', () => {
  const panel = twig.slice(twig.indexOf('id="p-layers"'), twig.indexOf('id="mode"'));
  assert.ok(panel.includes('id="presets"'), 'no Presets group before View mode');
  assert.ok(panel.includes('id="presetsEdit"') && panel.includes("path('settings', {tab: 'presets'})"), 'the pencil does not link to Settings');
  assert.ok(panel.includes('id="presetsSignIn"') && panel.includes("path('login')"), 'no sign-in line for a visitor');
  assert.ok(twig.includes('window.CC_PRESETS ='), 'no CC_PRESETS blob');
  assert.ok(/CC_PRESETS[\s\S]*path\('map_presets_save'\)[\s\S]*csrf_token\('map-preset'\)/.test(twig), 'the blob lacks the save endpoint or its token id');
});

test('presets.js applies through the controls own handlers, and the mode without persisting', () => {
  assert.ok(presets.includes('applyMode(') && presets.includes('{ persist: false }'), 'a preset must not rewrite the profile mode');
  assert.ok(entry.indexOf('initPresets();') > entry.indexOf('initChips();'), 'initPresets() runs before the chips exist');
  assert.ok(entry.indexOf('initPresets();') > entry.indexOf('initAddClimbHere();'), 'initPresets() is not the last call of the map boot');
});

test('the settings page has the Map presets tab, its script and the catalogue', () => {
  assert.ok(settings.includes('id="tabbtn-presets"') && settings.includes('aria-controls="map-presets"'), 'no Map presets tab');
  assert.ok(/<div class="dpane[^"]*" id="map-presets" role="tabpanel"/.test(settings), 'the presets are not a pane of their own');
  const profile = settings.slice(settings.indexOf('id="tab-profile"'), settings.indexOf('{# /#tab-profile #}'));
  assert.ok(!profile.includes('id="mpList"'), 'the presets still sit inside the profile tab');
  assert.ok(twig.includes("path('settings', {tab: 'presets'})"), 'the pencil does not open the presets tab');
  assert.ok(settings.includes("asset('settings/map-presets.js')"), 'the settings script is not loaded');
  assert.ok(settings.includes('window.CC_PRESET_CATALOG ='), 'the catalogue is not handed to the script');
});

test('the token id and the limiter exist', () => {
  assert.match(csrf, /^\s*- map-preset$/m);
  assert.match(limiters, /^\s{8}map_preset_save:\n/m);
});

test('a preset is used per tool: the page picks map or scout, Plan a ride and Ride tools switch on their panel and put the switches back', () => {
  assert.ok(twig.includes("page: scout_review|default(false) ? 'scout' : 'map'"), 'the page does not tell the script whether it is Scout review');
  assert.match(presets, /const TOOL_OF_PANEL = \{ plan: 'plan', tools: 'ride' \};/);
  assert.match(presets, /document\.addEventListener\('cc:panel'/);
  assert.match(presets, /if\(tool && \(next === tool \|\| key === 'layers'\)\) return;/, 'opening Layers from a tool must keep its preset');
  assert.match(presets, /apply\(before\.settings\)/, 'leaving a tool does not put the switches back');
  assert.ok(!/isDefault/.test(presets) && !/isDefault/.test(read('assets/settings/map-presets.js')), 'the single default is gone from the scripts');
  assert.ok(settings.includes('id="mpUse"') && !settings.includes('id="mpDefault"'), 'the editor has no Use this preset for chips');
});
