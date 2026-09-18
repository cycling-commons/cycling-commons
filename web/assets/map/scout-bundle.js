// SPDX-License-Identifier: AGPL-3.0-only
/* Scout ride bundle reader (docs/specs/scout-bundle.md): zip bytes in, ride
   bytes plus a note/photo index out. Pure, so node tests it; the zip library
   is passed in rather than imported, for the same reason.

   Nothing here touches the network. The review page decides what, if
   anything, ever leaves the browser. */

export const BUNDLE_LIMITS = {
  json: 1024 * 1024,
  fit: 64 * 1024 * 1024,
  photo: 15 * 1024 * 1024,     // PhotoProcessor::MAX_BYTES
  total: 300 * 1024 * 1024,
};
export const NOTE_MAX = 200;

/** A zip starts with the local-file-header signature PK\3\4. */
export function isZip(bytes) {
  return bytes && bytes.length >= 4
    && 0x50 === bytes[0] && 0x4B === bytes[1] && 0x03 === bytes[2] && 0x04 === bytes[3];
}

/** A tag time as the bundle writes it: UTC, whole seconds, `Z`. Null if unreadable. */
export function secondsIso(when) {
  const d = when instanceof Date ? when : new Date(when);
  const ms = d.getTime();
  if (!Number.isFinite(ms)) return null;
  return new Date(Math.floor(ms / 1000) * 1000).toISOString().replace('.000Z', 'Z');
}

/** The link between a bundle entry and a tag: its second, plus its place within that second. */
export function bundleKey(when, n) {
  const s = secondsIso(when);
  return null === s ? null : s + '#' + (Number.isInteger(n) && n > 0 ? n : 0);
}

/** JPEG or WebP by content, not by name. */
export function photoType(bytes) {
  if (!bytes || bytes.length < 12) return null;
  if (0xFF === bytes[0] && 0xD8 === bytes[1] && 0xFF === bytes[2]) return 'image/jpeg';
  const tag = (i, s) => [...s].every((c, k) => bytes[i + k] === c.charCodeAt(0));
  if (tag(0, 'RIFF') && tag(8, 'WEBP')) return 'image/webp';
  return null;
}

function fail(code) {
  const e = new Error(code);
  e.code = code;
  return e;
}

/**
 * Read a bundle. `unzipSync` is fflate's.
 * Returns { fit: Uint8Array, entries: Map<key, {note, photos:[{name, type, bytes}]}>, skipped }.
 * Throws an Error whose `code` is one of: bad-zip, not-bundle, version, too-large, no-fit.
 */
export function readBundle(bytes, unzipSync) {
  /* Pass 1: sizes of everything, and the index itself. Nothing big is inflated. */
  const sizes = new Map();
  let total = 0;
  let head;
  try {
    head = unzipSync(bytes, {
      filter: f => {
        sizes.set(f.name, f.originalSize);
        total += f.originalSize;
        return 'scout.json' === f.name && f.originalSize <= BUNDLE_LIMITS.json;
      },
    });
  } catch (e) {
    throw fail('bad-zip');
  }
  if (total > BUNDLE_LIMITS.total) throw fail('too-large');
  if (!head['scout.json']) throw fail(sizes.has('scout.json') ? 'too-large' : 'not-bundle');

  let index;
  try {
    index = JSON.parse(new TextDecoder().decode(head['scout.json']));
  } catch (e) {
    throw fail('not-bundle');
  }
  if (!index || 'scout-bundle' !== index.format) throw fail('not-bundle');
  if (1 !== index.version) throw fail('version');
  const fitName = typeof index.fit === 'string' ? index.fit : '';
  if (!sizes.has(fitName)) throw fail('no-fit');
  if (sizes.get(fitName) > BUNDLE_LIMITS.fit) throw fail('too-large');

  /* Pass 2: the ride and the photos the index names, each within its limit. */
  const tags = Array.isArray(index.tags) ? index.tags : [];
  const wanted = new Set([fitName]);
  let skipped = 0;
  tags.forEach(t => (Array.isArray(t && t.photos) ? t.photos : []).forEach(p => {
    if (typeof p === 'string' && sizes.has(p) && sizes.get(p) <= BUNDLE_LIMITS.photo) wanted.add(p);
  }));
  let files;
  try {
    files = unzipSync(bytes, { filter: f => wanted.has(f.name) });
  } catch (e) {
    throw fail('bad-zip');
  }
  if (!files[fitName]) throw fail('no-fit');

  const entries = new Map();
  tags.forEach(t => {
    const key = t ? bundleKey(t.at, t.n) : null;
    if (!key) { skipped++; return; }
    const note = typeof t.note === 'string'
      ? t.note.replace(/[\r\n\t]+/g, ' ').trim().slice(0, NOTE_MAX) : '';
    const photos = [];
    (Array.isArray(t.photos) ? t.photos : []).forEach(p => {
      const b = typeof p === 'string' ? files[p] : null;
      const type = b ? photoType(b) : null;
      if (!type) { skipped++; return; }
      photos.push({ name: p.split('/').pop(), type, bytes: b });
    });
    if (!note && !photos.length) return;
    const have = entries.get(key);
    if (have) {
      have.note = have.note || note;
      have.photos.push(...photos);
    } else {
      entries.set(key, { note, photos });
    }
  });
  return { fit: files[fitName], entries, skipped };
}
