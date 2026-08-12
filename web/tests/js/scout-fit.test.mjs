// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The vendored Scout FIT reader, against a real Scout-recorded scenario file.
 *
 * The point of this test is NOT to re-test Scout's parser — Scout has its own
 * harness over the same span. It is to prove that the copy in
 * `assets/lib/scout-fit.js` still decodes a Scout ride after a re-sync, and
 * that the tag vocabulary the review screen maps from has not moved underneath
 * it. A silent zero-tag result is the failure mode that matters: it looks
 * exactly like a ride nobody tagged.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const mod = await import(join(here, '../../assets/lib/scout-fit.js'));

const buf = readFileSync(join(here, '../fixtures/scout/scout-scenario.fit'));
const ab = buf.buffer.slice(buf.byteOffset, buf.byteOffset + buf.byteLength);

test('a Scout ride decodes, and its CRC checks out', () => {
  const parsed = mod.parseFit(ab);
  assert.equal(parsed.crcOk, true, 'the fixture must not be corrupt');
  assert.ok(parsed.messages.length > 0);
  // The tag fields are DEVELOPER fields: the whole format hinges on the
  // field_description messages that declare them, and a reader that loses them
  // returns a ride with no tags rather than an error.
  assert.ok(mod.findDevKey(parsed.devFields, 'poi_type'), 'poi_type must be declared');
});

test('the tags come out with a type, a place and a moment', () => {
  const parsed = mod.parseFit(ab);
  const { tags } = mod.extractTags(parsed, 'poi_type', 'poi_detail');
  assert.ok(tags.length > 0, 'the scenario file carries tags');
  for (const t of tags) {
    assert.ok(Number.isInteger(t.type) && t.type > 0);
    assert.ok(t.time instanceof Date);
  }
  const located = tags.filter(t => t.lat != null && t.lon != null);
  assert.ok(located.length > 0, 'at least one tag has a GPS fix');
  for (const t of located) {
    assert.ok(Math.abs(t.lat) <= 90 && Math.abs(t.lon) <= 180);
  }
});

test('every decoded tag type maps into our vocabulary', () => {
  // The map lives in scout-review.js; this asserts the CONTRACT it depends on —
  // that Scout's poi_type values stay inside the range we translate. A new type
  // added on the device must fail here rather than silently become "other".
  const KNOWN = new Set([1, 2, 3, 4, 5, 6, 7, 8, 9]);
  const parsed = mod.parseFit(ab);
  const { tags } = mod.extractTags(parsed, 'poi_type', 'poi_detail');
  for (const t of tags) {
    assert.ok(KNOWN.has(t.type), `unmapped poi_type ${t.type} — teach scout-review.js about it`);
  }
});

test('a surface start and END become a stretch', () => {
  const parsed = mod.parseFit(ab);
  const { tags } = mod.extractTags(parsed, 'poi_type', 'poi_detail');
  const segments = mod.buildSurfaceSegments(tags, null);
  // The scenario may or may not contain a pair; what must hold is the shape.
  for (const seg of segments) {
    assert.ok(seg.startTime instanceof Date);
    assert.ok(seg.type >= 1 && seg.type <= 8, 'a stretch opens on a real surface type');
  }
});

test('a truncated file fails loudly rather than yielding nothing', () => {
  // The one behaviour this screen cannot have: a corrupt ride that reads as a
  // ride with no tags, because a rider would conclude their tagging failed.
  const half = ab.slice(0, Math.floor(ab.byteLength / 2));
  assert.throws(() => mod.parseFit(half), /Truncated|Bad FIT|too small|before it was defined/);
});

test('a file that is not FIT at all is refused', () => {
  const notFit = new TextEncoder().encode('This is a GPX file, honestly'.padEnd(64, ' '));
  assert.throws(() => mod.parseFit(notFit.buffer), /FIT|header/);
});
