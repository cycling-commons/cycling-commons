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
  assert.match(presets, /if\(tool && \(next === tool \|\| key === 'layers' \|\| byDrawer\)\) return;/, 'opening Layers, or a place drawer, from a tool must keep its preset');
  assert.match(presets, /apply\(before\.settings\)/, 'leaving a tool does not put the switches back');
  assert.ok(!/isDefault/.test(presets) && !/isDefault/.test(read('assets/settings/map-presets.js')), 'the single default is gone from the scripts');
  assert.ok(settings.includes('id="mpUse"') && !settings.includes('id="mpDefault"'), 'the editor has no Use this preset for chips');
});

/* A pin clicked while planning opened the place drawer, the drawer closed the
   Plan panel, and that close read as leaving the planner: every switch was
   clicked back to the map's own, one redraw each, and the page froze with the
   planner's hidden routes back on (owner 2026-10-15). Looking at a place is
   part of planning, so a close made by a place drawer is told apart. */
test('a place drawer closing the panel does not end the tool', () => {
  const shell = read('assets/map/shell.js');
  assert.match(shell, /new CustomEvent\('cc:panel', \{detail: \{key, byDrawer\}\}\)/, 'the panel event does not say the drawer closed it');
  const m = shell.match(/export function closeRailPanel\(\)\{([\s\S]*?)\n\}/);
  assert.ok(m, 'closeRailPanel not found');
  assert.match(m[1], /byDrawer = true;\s*try\s*\{\s*x\.click\(\);\s*\}\s*finally\s*\{\s*byDrawer = false;\s*\}/,
    'the drawer close is marked only for its own click, and the mark is always cleared');
  assert.match(presets, /const byDrawer = !!\(e\.detail && e\.detail\.byDrawer\);/);
});

test('the map shows the active preset only: no Save as preset, a hint to Settings when none is on', () => {
  const panel = twig.slice(twig.indexOf('id="presets"'), twig.indexOf('id="presetsTool"'));
  assert.ok(!panel.includes('presetSaveAsBox') && !panel.includes('id="presetName"'), 'the map still has a Save as preset box');
  assert.ok(panel.includes('id="presetsNone"') && panel.includes("path('settings', {tab: 'presets'})"), 'no hint to Settings when no preset is on');
  assert.ok(!/presetSaveAs|saveAs\(/.test(presets), 'presets.js still offers Save as preset');
  assert.match(presets, /presets\.find\(x => x\.id === active\)/, 'the chip is not the active preset alone');
  assert.match(presets, /none\.hidden = !!p/, 'the Settings hint does not show when no preset is on');
});
