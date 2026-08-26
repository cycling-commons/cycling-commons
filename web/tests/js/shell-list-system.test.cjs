// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// One list system for every shell page (account-and-auth.md §8,
// moderation-and-contribution.md §5.2; owner 2026-08-25).
//
// A rider's contributions, their messages and a curator's queue had each grown
// their own <style> block: three page heads, two row shapes, four chip
// families, a green button on one desk. This pins the cure: the head, the
// container, the filter bar, the card, the pill and the density switch are
// defined once in account/_shell_styles.html.twig, and every list page draws
// from there. A page may still style what is truly its own; it may not carry
// a copy of a shared rule, because a copy is how the pages drift apart again.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');

const SHELL = 'templates/account/_shell_styles.html.twig';

// Every page that lists records, rider side and curator side.
const LIST_PAGES = [
  'templates/profile/show.html.twig',
  'templates/messages/index.html.twig',
  'templates/moderate/index.html.twig',
  'templates/moderate/history.html.twig',
  'templates/moderate_routes/index.html.twig',
  'templates/moderate/takedowns.html.twig',
  'templates/moderate_data/index.html.twig',
  'templates/moderate_regions/index.html.twig',
];
// Shell pages that are not lists but share the head and the container.
const SHELL_PAGES = LIST_PAGES.concat(['templates/settings/index.html.twig']);

// Rules that belong to the shell and nowhere else. A page defining one of
// these has forked the system.
const SHARED_RULES = [
  '.mh{', '.mh .kicker{', '.mh h1{', '.dbody{', '.mod-bar{', '.mod-filters{',
  '.lchip{', '.hchip{', '.msg-chip{', '.pill{', '.q-pill{', '.q-item{', '.q-list{',
  '.empty-queue{', '.empty-state{', '.pager{', '.btn-act{', '.flash-success{', '.flash-error{',
  '.mod-search{', '.sec-band{', '.q-note{', '.q-diff dl{', '.wrap{',
];

test('the shell defines the system once', () => {
  const shell = read(SHELL);
  for (const rule of ['.dbody{', '.mh{', '.mh .kicker{', '.mh h1{', '.mod-bar{', '.mod-filters{',
                      '.lchip{', '.q-pill{', '.q-note{', '.q-diff dl{', '.sec-band{', '.empty-state{',
                      '.pager{', '.btn-act{']) {
    assert.ok(shell.includes(rule), `${rule} must be defined in the shell`);
  }
  assert.ok(shell.includes("{% include 'moderate/_card_styles.html.twig' %}"),
    'the record card is part of the shell, so a rider page gets it without asking');
  // The empty state is left-aligned prose, not a centred block (owner 2026-08-25).
  assert.match(shell, /\.empty-state\{text-align:left/);
});

test('no shell page carries its own copy of a shared rule', () => {
  for (const page of SHELL_PAGES) {
    const src = read(page);
    assert.ok(src.includes("{% include 'account/_shell_styles.html.twig' %}"), `${page} must include the shell styles`);
    assert.ok(!src.includes("{% include 'moderate/_card_styles.html.twig' %}"),
      `${page} includes the card styles itself; the shell already does, so this is a second copy`);
    for (const rule of SHARED_RULES) {
      assert.ok(!src.includes(rule), `${page} defines ${rule} locally; that rule lives in the shell`);
    }
  }
});

test('every shell page opens with the same head inside the same container', () => {
  for (const page of SHELL_PAGES) {
    const src = read(page);
    assert.ok(src.includes('<div class="dbody'), `${page} must render its content in .dbody`);
    assert.ok(src.includes('id="main"'), `${page} must carry id="main" for the skip link`);
    assert.ok(/<div class="mh">\s*<div class="kicker">/.test(src), `${page} must open with the eyebrow + title head`);
    assert.ok(!src.includes('<div class="wrap"'), `${page} still uses the public .wrap container`);
    assert.ok(!src.includes('empty-queue'), `${page} uses the old desk empty state; it is .empty-state everywhere now`);
  }
});

test('every list page renders the record card and offers the density switch', () => {
  for (const page of LIST_PAGES) {
    const src = read(page);
    // The queue and routes desks render their card through an included partial.
    assert.ok(src.includes('q-item') || /_queue_item\.html\.twig/.test(src), `${page} must render rows as .q-item cards`);
    if (page.endsWith('moderate_regions/index.html.twig')) continue;   // a settings desk: no density, no pager (owner 2026-08-14)
    assert.ok(src.includes('class="q-list'), `${page} must group its cards in a .q-list`);
    assert.ok(src.includes("{% include 'account/_density.html.twig' %}"), `${page} must offer the cards/list switch`);
    assert.ok(src.includes("{% include 'moderate/_card_script.html.twig' %}"), `${page} must load the card script that drives the switch`);
  }
});

test('the old row vocabularies are gone', () => {
  const gone = ['class="item"', 'class="hist-row"', 'class="td-log-row', 'class="fd"', 'class="msg-chip',
                'class="hchip', 'class="pill ', 'item-answer', 'item-reply', 'item-diff', 'hist-state', 'fd-actions button.go'];
  for (const page of LIST_PAGES) {
    const src = read(page);
    for (const g of gone) assert.ok(!src.includes(g), `${page} still uses ${g}`);
  }
  // The one green button system is gone: the data desk answers in orange like every other desk.
  const data = read('templates/moderate_data/index.html.twig');
  assert.ok(!/\.fd-actions button\{/.test(data) && !/class="go"/.test(data), 'the data desk must use .btn-act, not its own green');
  assert.match(data, /class="btn-act">\{\{ 'moderate_data\.yes'/);
  assert.match(data, /class="btn-act btn-secondary">\{\{ 'moderate_data\.no'/);
});

test('the messages page keeps its state hooks on top of the shared card', () => {
  const src = read('templates/messages/index.html.twig');
  // The tests and the unread styling read these; they name message states, not looks.
  assert.match(src, /class="q-item msg-row \{\{ mine \? 'q-item--mine msg-mine' : \(m\.isRead \? '' : 'q-item--new msg-new'\) \}\}"/);
  assert.ok(src.includes('<ul class="q-list msg-list"'));
  const shell = read(SHELL);
  assert.match(shell, /ul\.q-list,ol\.q-list\{list-style:none/, 'a ul-based card list must not grow bullets');
});

test('category icons have exactly one home: ItemType', () => {
  // The map's catalog and icons modules read window.CC_TYPE_ICONS; they define nothing.
  const catalog = read('assets/map/catalog.js');
  assert.doesNotMatch(catalog, /icon:'[^']+'/, 'catalog.js still carries a glyph literal; the set lives in ItemType::iconSet()');
  assert.match(catalog, /export const TYPE_ICON = l => /);
  const icons = read('assets/map/icons.js');
  assert.doesNotMatch(icons, /_PATH='M/, 'icons.js still carries a drawn path; the paths live in ItemType::svgPath()');
  // The server partial reads the same set and draws nothing of its own.
  const partial = read('templates/partials/_type_icon.html.twig');
  assert.match(partial, /cc_type_icons\(\)/);
  assert.doesNotMatch(partial, /'M[0-9. ]/, 'the partial must not carry its own paths');
  // The map page injects the set for the modules.
  assert.match(read('templates/map/index.html.twig'), /window\.CC_TYPE_ICONS = /);
  // PHP: the enum is the source, with the glyph and the three drawn paths.
  const php = read('src/Catalog/ItemType.php');
  assert.match(php, /public function icon\(\): string/);
  assert.match(php, /public function svgPath\(\): \?string/);
  assert.match(php, /public static function iconSet\(\): array/);
});
