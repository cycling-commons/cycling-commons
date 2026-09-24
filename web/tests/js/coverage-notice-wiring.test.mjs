// SPDX-License-Identifier: AGPL-3.0-only
//
// scope-ui.js's coverage-notice wiring (docs/specs/map-and-search.md §4.5b)
// cannot be loaded under node:test: it imports map-init.js, which reaches
// for a live MapLibre instance at module load time. These are source-text
// pins on the two contracts review round 2 asked for:
//
//   1. while the coverage decision is pending (outlines needed but not yet
//      loaded), the pan-away nudge stands down exactly as if the banner
//      were shown, and settling the fetch re-runs BOTH evaluations;
//   2. a failed outlines fetch fails closed for the pan branch (never
//      "nothing is near", which would let the banner flash over an
//      onboarded coastline) and is never retried.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'map', 'scope-ui.js'), 'utf8');

test('the pan-away nudge stands down while the coverage decision is pending, not only while it is shown', () => {
  assert.match(src, /if\s*\(\s*_covShown\s*\|\|\s*_covPending\s*\)\s*\{\s*hide\(\)\s*;\s*return\s*;\s*\}/,
    'evaluate() must check _covPending alongside _covShown before it shows anything');
});

test('a pending state is declared and set true only while the outlines are unresolved', () => {
  assert.match(src, /_covPending\s*=\s*false/, 'somewhere clears the pending flag once a decision is possible');
  assert.match(src, /_covPending\s*=\s*true/, 'the outlines-not-yet-loaded branch marks the decision pending');
});

test('the nudge exposes its own evaluate() so the outlines fetch can re-run it on settle', () => {
  assert.match(src, /_nudgeEvaluate\s*=\s*evaluate\s*;/, 'initAreaNudge must publish its evaluate() to the module scope');
});

test('settling the outlines fetch re-runs the coverage decision AND the nudge, in that order', () => {
  // A single settle callback (however it is spelled) that reaches both.
  const settleBlock = src.slice(src.indexOf('function ensureOutlines'), src.indexOf('function ensureOutlines') + 800);
  assert.match(settleBlock, /evaluateCoverageNotice\s*\(\s*\)/, 'the settle path must re-run the coverage decision');
  assert.match(settleBlock, /_nudgeEvaluate/, 'the settle path must also reach the nudge');
  const covIdx = settleBlock.indexOf('evaluateCoverageNotice()');
  const nudgeIdx = settleBlock.indexOf('_nudgeEvaluate');
  assert.ok(covIdx >= 0 && nudgeIdx >= 0 && covIdx < nudgeIdx,
    'the coverage decision must be re-run before the nudge (the banner still wins the shared pill)');
});

test('exactly one fetch call, memoized: the outlines are never retried', () => {
  const fetches = src.match(/\bfetch\(/g) || [];
  assert.equal(fetches.length, 1, 'one fetch() call site for /regions/outlines.json');
  assert.match(src, /if\s*\(\s*_outlinesReq\s*\)\s*return\s*_outlinesReq\s*;/,
    'a second call while the first is in flight (or after it settled) must return the same promise, not fetch again');
});

test('a failed outlines fetch is remembered, and read as "always near" for the pan branch', () => {
  assert.match(src, /catch\s*\(\s*\(\s*\)\s*=>\s*\{[^}]*_outlinesFailed\s*=\s*true/s,
    'the fetch failure path must set a distinct failure flag');
  assert.match(src, /_outlinesFailed\s*\?\s*true\s*:\s*isNearOutlines/,
    'a prior failure must force "near" (hidden, state untouched) rather than "not near" (which could show the banner over water)');
});
