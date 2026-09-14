// SPDX-License-Identifier: AGPL-3.0-only
//
// The curator's block of rider photos a scenic view hides, and the gallery a
// "Taken here" confirmation leaves behind (docs/specs/photo-uploads.md §5g).
'use strict';

import test from 'node:test';
import assert from 'node:assert/strict';
import { wantsHiddenPhotos, hiddenPhotoReason, hiddenPhotosHtml, galleryWithConfirmed, pinMoveHidesHtml } from '../../assets/map/hidden-photos.js';

const D = { photoHiddenNoGps: 'No location in the file', photoHiddenTooFar: 'Taken {d} from the pin', photoHiddenPinMoved: 'The pin moved; taken up to {d} from it', photoHiddenPinMovedConfirmed: 'The pin moved after a curator confirmed this photo', photoHiddenTakenHere: 'Taken here', photoHiddenTitle: 'Hidden photos', pinMoveHidesOne: 'This move hides 1 rider photo until a curator confirms it was taken at the new spot.', pinMoveHidesMany: 'This move hides {n} rider photos until a curator confirms they were taken at the new spot.' };

test('only a curator on a live scenic item with an id asks', () => {
  assert.equal(wantsHiddenPhotos('P', { id: 7 }, true), true);
  assert.equal(wantsHiddenPhotos('P', { id: 7 }, false), false, 'a rider never asks');
  assert.equal(wantsHiddenPhotos('P', { id: 7 }, undefined), false);
  assert.equal(wantsHiddenPhotos('Q', { id: 7 }, true), false, 'other letters hide nothing');
  assert.equal(wantsHiddenPhotos('P', { name: 'OSM viewpoint' }, true), false, 'a coverage point has no photo of ours');
  assert.equal(wantsHiddenPhotos('P', { id: 7, pending: {} }, true), false, 'a pending row is decided in its own card');
});

test('the reason is said in plain words', () => {
  assert.equal(hiddenPhotoReason({ reason: 'no_gps' }, D), 'No location in the file');
  assert.equal(hiddenPhotoReason({ reason: 'too_far', distanceM: 540 }, D), 'Taken 540 m from the pin');
  assert.equal(hiddenPhotoReason({ reason: 'too_far' }, D), 'No location in the file', 'a distance that is not there is not invented');
  assert.equal(hiddenPhotoReason({ reason: 'no_gps' }, {}), 'No location in the file', 'English fallback');
});

test('a photo the moved pin hides says the pin moved', () => {
  assert.equal(hiddenPhotoReason({ reason: 'pin_moved', distanceM: 300 }, D), 'The pin moved; taken up to 300 m from it');
  assert.equal(hiddenPhotoReason({ reason: 'pin_moved' }, D), 'The pin moved after a curator confirmed this photo', 'no distance: it counted on a confirmation');
  assert.equal(hiddenPhotoReason({ reason: 'pin_moved', distanceM: 300 }, {}), 'The pin moved; taken up to 300 m from it', 'English fallback');
});

test('a suggested pin move says how many rider photos it hides', () => {
  assert.equal(pinMoveHidesHtml(0, D), '');
  assert.equal(pinMoveHidesHtml(undefined, D), '');
  assert.equal(pinMoveHidesHtml('2', D), '', 'only a number counts');
  assert.match(pinMoveHidesHtml(1, D), /This move hides 1 rider photo until a curator confirms it was taken at the new spot\./);
  assert.match(pinMoveHidesHtml(3, D), /This move hides 3 rider photos until a curator confirms they were taken at the new spot\./);
  assert.match(pinMoveHidesHtml(3, { pinMoveHidesMany: '<b>{n}</b>' }), /&lt;b&gt;3&lt;\/b&gt;/, 'escaped');
  assert.match(pinMoveHidesHtml(2, {}), /This move hides 2 rider photos/, 'English fallback');
});

test('the block escapes what it prints and renders nothing for an empty list', () => {
  assert.equal(hiddenPhotosHtml([], D), '');
  assert.equal(hiddenPhotosHtml(null, D), '');
  const html = hiddenPhotosHtml([{ id: 'a"b', sm: 'javascript:alert(1)', alt: '<img>', reason: 'too_far', distanceM: 540 }], D);
  assert.match(html, /data-taken-here="a&quot;b"/);
  assert.match(html, /src="#"/, 'an unsafe URL never reaches src');
  assert.match(html, /alt="&lt;img&gt;"/);
  assert.match(html, /Taken 540 m from the pin/);
  assert.match(html, /Taken here/);
  assert.match(html, /<button type="button" class="cc-mod-photo-zoom cc-d-hidden-zoom" data-hidden-photo="0"/, 'the thumbnail opens full size');
});

test('a confirmed photo joins the gallery once, marked confirmed, without its reason', () => {
  const f = { photos: [{ id: 'x', sm: '/x.webp', distanceM: 20 }] };
  const hidden = { id: 'u1', sm: '/u1-sm.webp', lg: '/u1-lg.webp', credit: 'Ann', license: 'CC BY-SA 4.0', takenAt: '2026-05', reason: 'no_gps' };

  const gallery = galleryWithConfirmed(f, hidden);
  assert.equal(gallery.length, 2);
  assert.deepEqual(gallery[1], { id: 'u1', sm: '/u1-sm.webp', lg: '/u1-lg.webp', credit: 'Ann', license: 'CC BY-SA 4.0', takenAt: '2026-05', distanceM: null, locationConfirmed: true });
  assert.equal(f.photos.length, 1, 'the record passed in is not changed');

  assert.equal(galleryWithConfirmed({ photos: gallery }, hidden).length, 2, 'never added twice');
  assert.deepEqual(galleryWithConfirmed({ photo: { id: 'legacy', sm: '/l.webp' } }, hidden).map(p => p.id), ['legacy', 'u1'], 'the single legacy photo is kept');
});
