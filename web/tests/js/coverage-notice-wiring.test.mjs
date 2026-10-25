// SPDX-License-Identifier: AGPL-3.0-only
//
// scope-ui.js's coverage-notice wiring (docs/specs/map-and-search.md §4.5b)
// cannot be loaded under node:test: it imports map-init.js, which reaches
// for a live MapLibre instance at module load time. These are source-text
// pins on two contracts:
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

test('the pending/fetch branch is entered for a pan only at PAN_MIN_ZOOM or above', () => {
  // Below PAN_MIN_ZOOM the pure module hides a pan regardless of `near`, so
  // that tick needs no shapes and must not wait on them - a rider zooming out
  // while the fetch is in flight must not keep the nudge suppressed for it.
  // A search hit is explicit and may wait at any zoom.
  assert.match(src, /import\s*\{[^}]*\bPAN_MIN_ZOOM\b[^}]*\}\s*from\s*['"]\.\/coverage-notice\.js['"]/,
    'PAN_MIN_ZOOM must be imported from the pure module, not a second magic number');
  assert.match(src, /if\s*\(\s*!covered\s*&&\s*\(\s*hit\s*\|\|\s*zoom\s*>=\s*PAN_MIN_ZOOM\s*\)\s*\)\s*\{/,
    'the shapes/pending branch must be gated on an explicit hit or zoom >= PAN_MIN_ZOOM');
});

test('covered means inside a live region outline, never only its bbox', () => {
  assert.match(src, /function coveredAt\([^)]*\)\s*\{[^}]*liveRegionAt\(/,
    'coveredAt() must ask the registry for the outline test');
  assert.doesNotMatch(src.slice(src.indexOf('function evaluateCoverageNotice'), src.indexOf('async function coverageClick')), /countryAt\(/,
    'the banner decision must not use the bbox-only countryAt()');
});

test('a search hit in a partly covered country is not covered by its country code alone', () => {
  assert.match(src, /onboardedAt:\s*hit\s*\?\s*\(\s*covered\s*\|\|\s*\(\s*hitCountryOnboarded\(hit\.countryCode\)\s*&&\s*near\s*\)\s*\)/,
    'a hit is covered only inside a live region, or near one in its own country');
});

test('a click outside coverage shows the notice instead of scoping to a region whose bbox holds it', () => {
  const click = src.slice(src.indexOf("map.on('click', async e=>{"));
  const cov = click.indexOf('coverageClick(');
  const scope = click.indexOf('regionOfPointPrecise(');
  assert.ok(cov >= 0 && scope >= 0 && cov < scope, 'coverageClick() must run, and can return, before the region lookup');
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
  assert.equal(fetches.length, 1, 'one fetch() call site, for both shape files');
  assert.match(src, /if\s*\(\s*_shapesReq\s*\)\s*return\s*_shapesReq\s*;/,
    'a second call while the first is in flight (or after it settled) must return the same promise, not fetch again');
});

test('a failed outlines fetch is remembered, and read as "always near" for the pan branch', () => {
  assert.match(src, /catch\s*\(\s*\(\s*\)\s*=>\s*\{[^}]*_outlinesFailed\s*=\s*true/s,
    'the fetch failure path must set a distinct failure flag');
  assert.match(src, /_outlinesFailed\s*\?\s*true\s*:\s*isNearOutlines/,
    'a prior failure must force "near" (hidden, state untouched) rather than "not near" (which could show the banner over water)');
});
