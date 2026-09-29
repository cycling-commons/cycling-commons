// SPDX-License-Identifier: AGPL-3.0-only
//
// Every photo of ours in the map's full-screen viewer carries "Report this
// photo" (docs/specs/photo-uploads.md §6c). The viewer reads the upload's uuid
// out of the stored image URL; when that match knew only an older URL form,
// no current photo had the link (owner-reported 2026-09-29). These run the
// real pattern against the URL shapes a gallery holds.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'map', 'lightbox.js'), 'utf8');
const literal = src.match(/const MEDIA_UUID = (\/.+\/i);/);
assert.ok(literal, 'MEDIA_UUID is a regex literal in lightbox.js');
// eslint-disable-next-line no-eval
const MEDIA_UUID = eval(literal[1]);
const UUID = 'fe3eb668-c346-4b84-9bdd-1d7b939ca6fc';

test('a published photo, as the media store names it today, is ours and reportable', () => {
  const m = MEDIA_UUID.exec(`http://localhost:9102/img/eu-01/published/${UUID}/6dda72a4/lg.webp`);
  assert.ok(m);
  assert.equal(m[1], UUID);
});

test('a gallery written in the older form still links', () => {
  assert.equal(MEDIA_UUID.exec(`https://media.example/photos/${UUID}/lg.webp`)[1], UUID);
});

test('a linked or imported picture is not ours, and gets no link', () => {
  assert.equal(MEDIA_UUID.exec('/media/stavelot-abbey.jpg'), null);
  assert.equal(MEDIA_UUID.exec('https://upload.wikimedia.org/wikipedia/commons/a/ab/Spa.jpg'), null);
});

test('the link goes straight to the shared report route', () => {
  assert.match(src, /href="\/report\/photo\/\$\{m\[1\]\}"/);
  assert.ok(!src.includes('/report"><span'), 'not the old /photo/{uuid}/report address');
});
