// SPDX-License-Identifier: AGPL-3.0-only
/* The desk's hook for an approved road (window.__ccApplySurface, map.js) calls populateSurfaceA(): the function has
   to sit in the hook's own scope. Nested inside populateCatalogLayers() it threw "not defined" after the approval,
   the desk said "Could not record the decision" and the road stayed off the map until a reload. */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const src = fs.readFileSync(path.join(__dirname, '../../assets/map/map.js'), 'utf8');

/* Brace depth at a position, skipping comments and string contents (template literals balance their ${}). */
function depthAt(pos) {
  let depth = 0;
  for (let i = 0; i < pos; i++) {
    const c = src[i], n = src[i + 1];
    if (c === '/' && n === '/') { i = src.indexOf('\n', i); if (i < 0) break; continue; }
    if (c === '/' && n === '*') { i = src.indexOf('*/', i + 2) + 1; continue; }
    if (c === "'" || c === '"') { const q = c; for (i++; i < pos && src[i] !== q; i++) if (src[i] === '\\') i++; continue; }
    if (c === '{') depth++;
    else if (c === '}') depth--;
  }
  return depth;
}

test('the approved-road hook and populateSurfaceA share one scope', () => {
  const fn = src.indexOf('function populateSurfaceA(');
  const hook = src.indexOf('window.__ccApplySurface');
  assert.ok(fn > 0 && hook > 0);
  assert.equal(depthAt(fn), depthAt(hook));
});
