// SPDX-License-Identifier: AGPL-3.0-only
//
// Finding the ride files in a folder or an exported archive
// (docs/specs/traffic-measurements.md §3.2): .fit, .fit.gz and nested zips,
// everything else left alone.
import test from 'node:test';
import assert from 'node:assert/strict';
import { zipSync, gzipSync, unzipSync, gunzipSync, strToU8 } from '../../assets/lib/fflate-0.8.3.js';
import { fitEntries } from '../../assets/lib/ride-archive.js';

const FIT_A = strToU8('fit-a');
const FIT_B = strToU8('fit-b');
const tools = { unzipSync, gunzipSync };

test('a plain .fit is itself', () => {
  assert.deepEqual(fitEntries('Morning Ride.fit', FIT_A, tools).map(e => e.name), ['Morning Ride.fit']);
});

test('a .fit.gz is unpacked', () => {
  const got = fitEntries('123.fit.gz', gzipSync(FIT_B), tools);
  assert.equal(got.length, 1);
  assert.deepEqual([...got[0].bytes], [...FIT_B]);
});

test('an archive with nested archives yields every ride and nothing else', () => {
  const inner = zipSync({ 'activity_2.fit': FIT_B, 'notes.txt': strToU8('x') });
  const outer = zipSync({
    'DI_CONNECT/DI-Connect-Fitness/activity_1.fit': FIT_A,
    'DI_CONNECT/UploadedFiles_0.zip': inner,
    'DI_CONNECT/profile.json': strToU8('{}'),
    'activities/3.fit.gz': gzipSync(FIT_A),
  });
  const names = fitEntries('export.zip', outer, tools).map(e => e.name).sort();
  assert.deepEqual(names, ['activities/3.fit.gz', 'activity_2.fit', 'DI_CONNECT/DI-Connect-Fitness/activity_1.fit'].sort());
});

test('anything else is skipped quietly', () => {
  assert.deepEqual(fitEntries('photo.jpg', FIT_A, tools), []);
});

test('a broken archive is reported, not thrown', () => {
  const got = fitEntries('broken.zip', strToU8('not a zip'), tools);
  assert.deepEqual(got, [{ name: 'broken.zip', bytes: null, error: true }]);
});
