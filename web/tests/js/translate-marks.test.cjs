// SPDX-License-Identifier: AGPL-3.0-only
//
// The browser decoder must read exactly what MarkerCodec (PHP) writes. Both
// sides are pinned to tests/js/fixtures/translate-marks.json.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'translate-mode.js'), 'utf8');
// The decoder is exported for tests through a guarded CommonJS hook at the end of the file.
const mod = { exports: {} };
new Function('module', 'exports', 'window', 'document', src)(mod, mod.exports, undefined, undefined);
const { decode, MARK_RE, findOpenMark, residualMarks } = mod.exports;

const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures', 'translate-marks.json'), 'utf8'));

test('decodes every fixture row', () => {
  for (const row of fixture) {
    const m = MARK_RE.exec(row.encoded);
    assert.ok(m, `no match for id ${row.id}`);
    assert.deepEqual(decode(m[1]), { id: row.id, stale: row.stale });
    assert.equal(m[2], row.text);
  }
});

test('a lone zero-width character is not a mark', () => {
  assert.equal(MARK_RE.exec('a​b'), null);
});

// ---- spanning marks (translations.md §4.1, task-13): a string carrying a
// tag lands its start mark and end mark in two different text nodes once
// the browser parses it. MARK_RE requires both in ONE node, so it never
// matches this shape; wrapSpanningNodes in translate-mode.js finds it with
// the two pure helpers exercised below instead.

test('MARK_RE does not match a start mark with no end in the same node', () => {
  // What an <h1> that opens on the marked text and closes past a <br>
  // hands the same-node pass, for its first (opening) text node alone: a
  // start token and the beginning of the string, no end token at all.
  const row = fixture[0];
  const endAt = row.encoded.indexOf('⁢');
  const openingNode = row.encoded.slice(0, endAt); // start token + full text, no end
  assert.equal(MARK_RE.exec(openingNode), null);
});

test('findOpenMark finds a start token with no end after it', () => {
  const row = fixture[0];
  const endAt = row.encoded.indexOf('⁢');
  const startToken = row.encoded.slice(0, 22); // ⁡ + 21 bit characters
  const openingNode = 'prefix text ' + row.encoded.slice(0, endAt);
  const open = findOpenMark(openingNode);
  assert.ok(open, 'expected an open mark');
  assert.equal(openingNode.slice(open.start, open.end), startToken);
  assert.deepEqual(decode(open.bits), { id: row.id, stale: row.stale });
});

test('findOpenMark returns null once a node closes its own start', () => {
  // A same-node pair (what wrapTextNodes already handles) is not "open".
  const row = fixture[0];
  assert.equal(findOpenMark(row.encoded), null);
});

test('findOpenMark returns null with no start token at all', () => {
  assert.equal(findOpenMark('plain text, nothing marked'), null);
});

test('residualMarks finds the end token in a closing node', () => {
  // What the same <h1> hands the pass for its LAST text node: trailing
  // text (the full stop after `</em>`) then the end token, no start.
  const closingNode = 'tail text.⁢';
  const r = residualMarks(closingNode);
  assert.equal(r.ends, 1);
  assert.equal(closingNode.slice(r.firstEnd, r.firstEnd + 1), '⁢');
  assert.equal(closingNode.slice(0, r.firstEnd), 'tail text.');
});

test('residualMarks finds a trailing open start with no end at all', () => {
  const row = fixture[0];
  const endAt = row.encoded.indexOf('⁢');
  const openingNode = 'prefix text ' + row.encoded.slice(0, endAt);
  assert.deepEqual(residualMarks(openingNode), { starts: 1, ends: 0, firstEnd: -1 });
});

test('residualMarks counts nothing in unmarked text', () => {
  assert.deepEqual(residualMarks('plain text, nothing marked'), { starts: 0, ends: 0, firstEnd: -1 });
});

test('residualMarks discards a pair that both opens and closes in the same string', () => {
  // What a translated parameter looks like once substituted: `%type%`
  // resolved to its own trans() result, itself already mark-wrapped,
  // pasted whole into the middle of plain text. Same shape a self-
  // contained same-node hit already gets from wrapTextNodes.
  const row = fixture[0];
  const text = 'prefix ' + row.encoded + ' suffix';
  assert.deepEqual(residualMarks(text), { starts: 0, ends: 0, firstEnd: -1 });
});

// ---- the two shapes the spanning guard must tell apart (review finding,
// task-13 fix-up). wrapSpanningNodes sums residualMarks() over every text
// node the candidate ancestor contains and claims it only on an exact
// {starts: 1, ends: 1}. These tests reproduce that same arithmetic over
// hand-built node text, one call per (simulated) text node in document
// order, exactly as the real DOM walk would present them.

test('a nested translated parameter sums to exactly one pair (claim the outer)', () => {
  // web/translations/messages.en.yaml funnel_votable: '... pin. <b>%type%
  // </b> can also be voted on...' with %type% substituted, before marking,
  // by its own trans() call. Rendered and parsed, the outer key's own start
  // sits in the text node before <b>, the parameter's whole self-contained
  // pair sits in the text node <b> wraps, and the outer key's own end sits
  // in the text node after </b> - three text nodes, one ancestor.
  const outer = fixture[0]; // stands in for the outer sentence's mark
  const param = fixture[2]; // stands in for %type%'s own trans() result
  const outerStart = outer.encoded.slice(0, 22); // ⁡ + 21 bits, no text/end
  const outerEnd = '⁢';
  const nodeBeforeB = '... pin. ' + outerStart; // open, no end
  const nodeInsideB = param.encoded; // self-contained: %type%'s own pair
  const nodeAfterB = ' can also be voted on...' + outerEnd; // end, no open

  let totalStarts = 0, totalEnds = 0;
  for (const nodeText of [nodeBeforeB, nodeInsideB, nodeAfterB]) {
    const r = residualMarks(nodeText);
    totalStarts += r.starts;
    totalEnds += r.ends;
  }
  assert.deepEqual({ starts: totalStarts, ends: totalEnds }, { starts: 1, ends: 1 });
});

test('two sibling spanning strings under one parent sum to more than one pair (decline)', () => {
  // Two INDEPENDENT strings, each itself spanning a tag (so each needs the
  // ancestor-claiming pass, not just the same-node one), sharing a parent -
  // translations.md §4.1's remaining limit. Four text nodes: A opens, A
  // closes, B opens, B closes, none of them self-contained.
  const a = fixture[0];
  const b = fixture[2];
  const aStart = a.encoded.slice(0, 22);
  const bStart = b.encoded.slice(0, 22);
  const END = '⁢';
  const nodes = [
    '<i>' + aStart + 'Alpha', // A opens, no end
    'tail-A' + END, // A closes
    '<i>' + bStart + 'Bravo', // B opens, no end
    'tail-B' + END, // B closes
  ];

  let totalStarts = 0, totalEnds = 0;
  for (const nodeText of nodes) {
    const r = residualMarks(nodeText);
    totalStarts += r.starts;
    totalEnds += r.ends;
  }
  assert.deepEqual({ starts: totalStarts, ends: totalEnds }, { starts: 2, ends: 2 });
});
