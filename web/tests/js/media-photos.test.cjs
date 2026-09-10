// SPDX-License-Identifier: AGPL-3.0-only
//
// Node tests for attachPhotos() (web/assets/map/util.js) — the mapping that
// carries a served feature's photo attributes onto its drawer object
// (docs/specs/photo-uploads.md §5).
//
// This exists because the bug it guards shipped once: the pool drawers
// (osmDrawer/waterDrawer) build their drawer object field by field, and copied
// only the legacy singular `photo`. Every approved rider upload writes the
// gallery `photos[]` instead, so on letters C/D/E/G/H/I/J/M — most of what
// riders photograph — an approved photo silently never reached the drawer and
// the "Add the first photo" prompt showed instead.
//
// util.js is a dependency-free leaf module, so it imports straight into node.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const util = import('../../assets/map/util.js');

test('the gallery an approved upload produces reaches the drawer', async () => {
  const { attachPhotos } = await util;
  const photos = [{ sm: '/p/sm.webp', lg: '/p/lg.webp', credit: 'Marta', license: 'CC BY-SA 4.0' }];

  assert.deepEqual(attachPhotos({}, { photos }), { photos });
});

test('the legacy singular still reaches the drawer', async () => {
  const { attachPhotos } = await util;
  const photo = { sm: '/w/sm.jpg', lg: '/w/lg.jpg', credit: 'Someone', license: 'CC BY-SA 3.0' };

  assert.deepEqual(attachPhotos({}, { photo }), { photo });
});

test('both shapes survive together — photoList() decides which wins', async () => {
  const { attachPhotos } = await util;
  const out = attachPhotos({}, { photos: [{ sm: 'a', lg: 'b' }], photo: { sm: 'c', lg: 'd' } });

  assert.ok(Array.isArray(out.photos));
  assert.equal(out.photo.sm, 'c');
});

test('a JSON-string attribute is parsed, because these are importable attributes', async () => {
  const { attachPhotos } = await util;

  assert.deepEqual(
    attachPhotos({}, { photos: '[{"sm":"/p/sm.webp","lg":"/p/lg.webp"}]' }).photos,
    [{ sm: '/p/sm.webp', lg: '/p/lg.webp' }],
  );
  assert.deepEqual(attachPhotos({}, { photo: '{"sm":"/w/sm.jpg"}' }).photo, { sm: '/w/sm.jpg' });
});

test('unparseable or absent attributes are dropped, never handed to the img sink', async () => {
  const { attachPhotos } = await util;

  assert.deepEqual(attachPhotos({}, { photo: 'not json at all' }), {});
  assert.deepEqual(attachPhotos({}, { photos: '{{{' }), {});
  assert.deepEqual(attachPhotos({}, {}), {});
});

test('an empty gallery adds no key, so photoList() falls through to the singular', async () => {
  const { attachPhotos } = await util;

  assert.deepEqual(attachPhotos({}, { photos: [] }), {});
  assert.deepEqual(attachPhotos({}, { photos: [], photo: { sm: 'x' } }), { photo: { sm: 'x' } });
});

test('the target object is returned and its other fields are untouched', async () => {
  const { attachPhotos } = await util;
  const target = { name: 'Fontaine', id: 20489 };

  const out = attachPhotos(target, { photos: [{ sm: 'a', lg: 'b' }] });
  assert.equal(out, target);
  assert.equal(out.name, 'Fontaine');
  assert.equal(out.id, 20489);
});
