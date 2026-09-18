// SPDX-License-Identifier: AGPL-3.0-only
// The Scout ride bundle (docs/specs/scout-bundle.md): the zip a phone app
// exports, read in the browser. These build real zips with the same library
// the page uses, so the format is tested end to end, not mocked.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { zipSync, unzipSync, strToU8 } from '../../assets/lib/fflate-0.8.3.js';
import { readBundle, bundleKey, secondsIso, isZip, photoType, NOTE_MAX, BUNDLE_LIMITS }
  from '../../assets/map/scout-bundle.js';

const JPEG = new Uint8Array([0xFF, 0xD8, 0xFF, 0xE0, 0, 0x10, 0x4A, 0x46, 0x49, 0x46, 0, 1, 1]);
const WEBP = strToU8('RIFF\0\0\0\0WEBPVP8 ');
const FIT = strToU8('.FIT pretend ride bytes');

const zip = (index, extra = {}) => zipSync({
  'ride.fit': FIT,
  'scout.json': strToU8(JSON.stringify(index)),
  ...extra,
});
const base = tags => ({ format: 'scout-bundle', version: 1, fit: 'ride.fit', tags });

test('a tag time links on its second, whatever the milliseconds', () => {
  assert.equal(secondsIso(new Date('2026-09-12T14:46:33.740Z')), '2026-09-12T14:46:33Z');
  assert.equal(bundleKey('2026-09-12T14:46:33Z'), '2026-09-12T14:46:33Z#0');
  assert.equal(bundleKey(new Date('2026-09-12T14:46:33.2Z'), 1), '2026-09-12T14:46:33Z#1');
  assert.equal(bundleKey('not a time'), null);
});

test('zip and photo types are read from the bytes, not the name', () => {
  assert.ok(isZip(zip(base([]))));
  assert.ok(!isZip(FIT));
  assert.equal(photoType(JPEG), 'image/jpeg');
  assert.equal(photoType(WEBP), 'image/webp');
  assert.equal(photoType(strToU8('<svg>not a photo</svg>')), null);
});

test('notes and photos land on the tag whose second they name', () => {
  const b = readBundle(zip(base([
    { at: '2026-09-12T14:46:33Z', note: 'Cobbles start\nafter the bridge', photos: ['photos/a.jpg', 'photos/b.webp'] },
    { at: '2026-09-12T13:51:51Z', n: 1, note: 'Second tap that second' },
  ]), { 'photos/a.jpg': JPEG, 'photos/b.webp': WEBP }), unzipSync);
  assert.deepEqual([...b.fit], [...FIT]);
  const e = b.entries.get('2026-09-12T14:46:33Z#0');
  assert.equal(e.note, 'Cobbles start after the bridge');
  assert.deepEqual(e.photos.map(p => [p.name, p.type]), [['a.jpg', 'image/jpeg'], ['b.webp', 'image/webp']]);
  assert.equal(b.entries.get('2026-09-12T13:51:51Z#1').note, 'Second tap that second');
  assert.equal(b.skipped, 0);
});

test('what cannot be used is counted, never guessed', () => {
  const b = readBundle(zip(base([
    { at: 'yesterday', note: 'no time' },
    { at: '2026-09-12T14:46:33Z', photos: ['photos/missing.jpg', 'photos/fake.jpg'] },
  ]), { 'photos/fake.jpg': strToU8('not an image at all') }), unzipSync);
  assert.equal(b.entries.size, 0);
  assert.equal(b.skipped, 3);
});

test('a long note is cut, not refused', () => {
  const b = readBundle(zip(base([{ at: '2026-09-12T14:46:33Z', note: 'x'.repeat(500) }])), unzipSync);
  assert.equal(b.entries.get('2026-09-12T14:46:33Z#0').note.length, NOTE_MAX);
});

test('what is not a bundle is refused with a reason', () => {
  const code = fn => { try { fn(); return 'ok'; } catch (e) { return e.code; } };
  assert.equal(code(() => readBundle(FIT, unzipSync)), 'bad-zip');
  assert.equal(code(() => readBundle(zipSync({ 'ride.fit': FIT }), unzipSync)), 'not-bundle');
  assert.equal(code(() => readBundle(zip({ format: 'other', version: 1, fit: 'ride.fit' }), unzipSync)), 'not-bundle');
  assert.equal(code(() => readBundle(zip({ ...base([]), version: 2 }), unzipSync)), 'version');
  assert.equal(code(() => readBundle(zip({ ...base([]), fit: 'nope.fit' }), unzipSync)), 'no-fit');
});

test('limits are checked before anything big is unpacked', () => {
  assert.ok(BUNDLE_LIMITS.photo === 15 * 1024 * 1024);
  const big = new Uint8Array(BUNDLE_LIMITS.json + 10).fill(32);
  const z = zipSync({ 'ride.fit': FIT, 'scout.json': big });
  assert.throws(() => readBundle(z, unzipSync), e => 'too-large' === e.code);
});
