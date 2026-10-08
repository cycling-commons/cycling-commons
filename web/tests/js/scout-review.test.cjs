// SPDX-License-Identifier: AGPL-3.0-only
/* Scout ride review: the sub-menu answer a rider picked on the bike
   (NOTICE · POTHOLES) travels with the tag and shows on its card
   (docs/specs/moderation-and-contribution.md, Scout intake). */
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const read = rel => fs.readFileSync(path.join(__dirname, '..', '..', rel), 'utf8');
const review = read('assets/map/scout-review.js');
const page = read('templates/map/index.html.twig');

/** The source of one top-level function, up to the next one. */
function fn(src, name) {
  const start = src.search(new RegExp('(async )?function ' + name + '\\('));
  assert.ok(start >= 0, name + ' exists');
  const next = src.slice(start + 1).search(/\n(async )?function |\nexport /);
  return next < 0 ? src.slice(start) : src.slice(start, start + 1 + next);
}

test('a point tag sends the sub-menu answer, so the server can fill its field', () => {
  assert.match(fn(review, 'sendOne'), /detail: entry\.detail \|\| undefined/);
});

test('the card and the fallback name say what the rider picked on the bike', () => {
  assert.match(page, /window\.CC_SCOUT_ANSWERS = /);
  assert.match(fn(review, 'answerFor'), /CC_SCOUT_ANSWERS/);
  assert.match(review, /head\.textContent = [^;]*answerFor\(entry\)/);
  assert.match(fn(review, 'fallbackName'), /answerFor\(entry\)/);
});

test('a scenic or history tag asks for its kind; the pick decides which come first', () => {
  assert.match(page, /window\.CC_SCOUT_KINDS = /);
  const choices = fn(review, 'kindChoices');
  assert.match(choices, /CC_SCOUT_KINDS/);
  assert.match(choices, /byPick/);
  assert.match(fn(review, 'kindSelect'), /entry\.kind = sel\.value/);
  /* The rider can still change the letter; the kind list follows it. */
  assert.match(review, /renderKind\(\)/);
});

test('a tag without its kind is not sent, and says why', () => {
  const send = fn(review, 'sendOne');
  assert.match(send, /needsKind\(entry\)/);
  assert.match(send, /scoutNeedKind/);
  assert.match(send, /type: entry\.kind/);
  assert.match(fn(review, 'sendAll'), /needsKind\(entry\)/);
});

test('VIEW arrives with its kind already picked', () => {
  assert.match(fn(review, 'show'), /kind: filledKind\(/);
});

test('the rest of the kind list is in alphabetical order of the rider\'s language', () => {
  assert.match(fn(review, 'kindChoices'), /\.sort\(\(a, b\) => all\[a\]\.localeCompare\(all\[b\]\)\)/);
});
