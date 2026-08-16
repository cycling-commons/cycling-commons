// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// The wizard's pending state, now that an upload is asynchronous
// (docs/specs/media-storage-architecture.md §3.3, photo-uploads.md §4).
//
// Four things here are the CONTRACT rather than the implementation, and every
// one of them fails silently - the wizard would look fine and quietly lose a
// rider's photo:
//
// 1. The id is claimed at "received", not at "landed". If the rider presses
//    Next while the worker is still running, the hidden field must already
//    carry the photo or the contribution arrives without it.
// 2. Giving up after 30 seconds does NOT drop that id. The window is a
//    client-side patience limit, never a server timeout; getting it backwards
//    turns a slow scan into a lost contribution.
// 3. A photo the worker REFUSED does drop its id, so a rejected file is never
//    submitted.
// 4. Next is held while a photo is uploading or checking, and released once
//    the rider has been told we will follow up.
//
// media-upload.js mounts against a live wizard DOM, so these are structural
// pins in the house two-lists style (see wizard-addpoint.test.cjs). The states
// themselves were watched resolve in a real browser on a 390px viewport with
// the worker container stopped.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');

const js = read('assets/contribute/media-upload.js');
const handler = read('src/Media/MessageHandler/ScanAndReleaseUploadHandler.php');
const LOCALES = ['en', 'fr', 'nl', 'de', 'es'];

test('the id is claimed the moment the server has the bytes', () => {
  const received = js.match(/function received\(item, data, file\) \{([\s\S]*?)\n    \}/);
  assert.ok(received, 'received() not found');
  assert.match(received[1], /item\.id\s*=\s*data\.id;/,
    'the id must be set at received(), or a rider who presses Next mid-scan loses the photo');
  assert.match(received[1], /syncHidden\(\);/, 'and it has to reach the hidden field in the same pass');
});

test('giving up keeps the photo, it does not cancel it', () => {
  const stop = js.match(/function stopWaiting\(item\) \{([\s\S]*?)\n    \}/);
  assert.ok(stop, 'stopWaiting() not found');
  assert.doesNotMatch(stop[1], /item\.id\s*=\s*null/,
    'the 30 s window is a patience limit, not a timeout - the photo still travels with the submission');
});

test('a refused photo drops its id so it is never submitted', () => {
  const fail = js.match(/function fail\(item, reason\) \{([\s\S]*?)\n    \}/);
  assert.ok(fail, 'fail() not found');
  assert.match(fail[1], /item\.id\s*=\s*null;/);
});

test('Next is held for uploading and checking, and released for waiting', () => {
  const busy = js.match(/function announceBusy\(\) \{([\s\S]*?)\n    \}/);
  assert.ok(busy, 'announceBusy() not found');
  assert.match(busy[1], /'uploading' === i\.state \|\| 'checking' === i\.state/);
  assert.doesNotMatch(busy[1], /'waiting'/,
    "a rider told 'we will let you know' must not then be made to sit there");
});

test('the patience window is 30 s on both sides, and means different things on each', () => {
  const ms = js.match(/var PATIENCE_MS\s*=\s*(\d+);/);
  assert.ok(ms, 'PATIENCE_MS not found');
  assert.equal(Number(ms[1]), 30000);

  const s = handler.match(/private const int PATIENCE_S\s*=\s*(\d+);/);
  assert.ok(s, 'PATIENCE_S not found in the handler');
  assert.equal(Number(s[1]) * 1000, Number(ms[1]),
    'the client stops waiting and the server decides whether a message is owed - same number, or a rider is told nothing or told twice');

  // The server half must never become a scan deadline.
  assert.doesNotMatch(handler, /PATIENCE_S[^\n]*(timeout|abandon|cancel)/i);
});

test('the preview is the rider OWN file, and it is released again', () => {
  assert.match(js, /window\.URL\.createObjectURL\(file\)/,
    'the pending thumbnail must come from the local file, never from an unscanned served url');
  assert.match(js, /function releaseBlob\(item\)[\s\S]*?revokeObjectURL\(item\.blobUrl\)/);
  // Landing and removal both have to hand the blob back.
  assert.match(js, /function landed\(item, data\) \{[\s\S]*?releaseBlob\(item\);/);
  assert.match(js, /function removeItem\(item\) \{[\s\S]*?releaseBlob\(item\);/);
});

test('polling stops when the item is no longer checking', () => {
  const poll = js.match(/function pollState\(item, startedAt\) \{([\s\S]*?)\n    \}/);
  assert.ok(poll, 'pollState() not found');
  assert.match(poll[1], /if \('checking' !== item\.state\) return;/,
    'a removed or resolved item must not keep polling');
  assert.match(poll[1], /Date\.now\(\) - startedAt >= PATIENCE_MS/);
});

test('the strings exist in all five locales', () => {
  for (const l of LOCALES) {
    const y = read(`translations/messages.${l}.yaml`);
    for (const key of ['checking', 'still_checking']) {
      assert.match(y, new RegExp(`^\\s{4}${key}: `, 'm'), `messages.${l}.yaml is missing media.pending.${key}`);
    }
    assert.match(y, /^\s{4}photo_rejected: /m, `messages.${l}.yaml is missing media.error.photo_rejected`);
  }
});

test('both mount points ship the poll url and the pending strings', () => {
  for (const tpl of ['templates/contribute/improve.html.twig', 'templates/map/index.html.twig']) {
    const twig = read(tpl);
    assert.match(twig, /stateUrl: path\('media_photos_state', \{id: '__ID__'\}\)/, `${tpl} has no poll url`);
    assert.match(twig, /checking: 'media\.pending\.checking'\|trans/, `${tpl} is missing the checking string`);
    assert.match(twig, /stillChecking: 'media\.pending\.still_checking'\|trans/, `${tpl} is missing the give-up string`);
  }
});
