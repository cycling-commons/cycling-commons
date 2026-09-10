// SPDX-License-Identifier: AGPL-3.0-only
//
// share-links.js: what the Share button copies, and what map.js reads back
// (docs/specs/map-and-search.md §8).
//
// Two separate breakages met here. Most OSM scenic views carry no `name`, so
// the drawer titles them by category and every one of them reads "Viewpoint".
// `?feature=Viewpoint` was a single link shared for 1,743 Belgian viewpoints,
// and it opened whichever the search returned first. And a bare id, while
// unique, tells a reader nothing before they click it.
//
// So the id decides and the slug is decoration. Every test below exists to
// hold one half of that: the slug must never be able to change which place
// opens, and it must never be able to leave the query value it lives in.
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { slugify, shareQuery, refFromShare, idFromShare } from '../../assets/map/share-links.js';

test('a named OSM point puts its name in the link', () => {
  assert.equal(shareQuery({ osmRef: 'node/462149319', shareName: 'Roche aux Faucons' }),
    'ref=node/462149319/roche-aux-faucons');
});

test('an unnamed OSM point shares the id alone, not the category word', () => {
  // p.n is absent, so osmDrawer sets no shareName: "Viewpoint" is our label.
  assert.equal(shareQuery({ osmRef: 'node/2348266912', name: 'Viewpoint' }),
    'ref=node/2348266912');
});

test('a catalog item uses its own name, which is never a category', () => {
  assert.equal(shareQuery({ id: 482, name: 'Côte de Wanne' }), 'item=482/cote-de-wanne');
});

test('the id wins over the ref, and the ref over the name', () => {
  const f = { id: 7, name: 'Col du Rosier', osmRef: 'node/1' };
  assert.equal(shareQuery(f), 'item=7/col-du-rosier');
  assert.equal(shareQuery({ shareName: 'Col du Rosier', osmRef: 'node/1' }), 'ref=node/1/col-du-rosier');
  // No shareName on an OSM point means the drawer found no `name` tag, so its
  // visible title is our category word. Never slug that: it would read as the
  // mapper's name for the place.
  assert.equal(shareQuery({ name: 'Col du Rosier', osmRef: 'node/1' }), 'ref=node/1');
  assert.equal(shareQuery({ name: 'Col du Rosier' }), 'feature=Col%20du%20Rosier');
  assert.equal(shareQuery({}), '');
  assert.equal(shareQuery(null), '');
});

test('a ref the poi endpoint would refuse never becomes a share link', () => {
  assert.equal(shareQuery({ osmRef: 'relation/7', shareName: 'X' }), '');
  assert.equal(shareQuery({ osmRef: 'node/1 javascript:alert(1)' }), '');
});

test('the slug is read back and discarded, so the id alone decides', () => {
  assert.equal(refFromShare('node/462149319/roche-aux-faucons'), 'node/462149319');
  assert.equal(refFromShare('node/462149319'), 'node/462149319');            // links already sent out
  assert.equal(refFromShare('node/462149319/a-name-that-has-since-changed'), 'node/462149319');
  assert.equal(idFromShare('482/cote-de-wanne'), '482');
  assert.equal(idFromShare('482'), '482');
});

test('a slug cannot smuggle a different target past the parser', () => {
  assert.equal(refFromShare('node/1/../../way/2'), 'node/1');   // the tail is never a path
  assert.equal(refFromShare('relation/7/x'), null);
  assert.equal(refFromShare('way/12a/x'), null);
  assert.equal(refFromShare('https://evil.example/node/1'), null);
  assert.equal(refFromShare(''), null);
  assert.equal(refFromShare(null), null);
  assert.equal(idFromShare(null), '');
});

test('a slug is only ever lowercase letters, digits and hyphens', () => {
  const ok = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
  for (const name of ['Café de la Gare', 'Mur de Huy!!', '  spaced  out  ', 'A/B/C',
                      'Sankt Vith (Belgien)', '<script>alert(1)</script>', '100% Wall']) {
    const s = slugify(name);
    assert.ok(ok.test(s), `${name} -> ${s}`);
  }
});

test('a name our alphabet cannot carry folds away, leaving the bare id', () => {
  assert.equal(slugify('東京'), '');
  assert.equal(slugify('Δελφοί'), '');
  assert.equal(shareQuery({ osmRef: 'node/9', shareName: '東京' }), 'ref=node/9');
});

test('a long name is cut at a word, never mid-word or on a hyphen', () => {
  const s = slugify('Cote de la Redoute par le chemin des ecoliers et du vieux moulin');
  assert.ok(s.length <= 60, s);
  assert.ok(!s.endsWith('-'), s);
  assert.ok('cote-de-la-redoute-par-le-chemin-des-ecoliers-et-du-vieux-moulin'.startsWith(s), s);
  assert.equal(slugify('a'.repeat(70)).length, 60);   // no word break to find
});
