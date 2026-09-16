// SPDX-License-Identifier: AGPL-3.0-only
//
// The map drawer's pending card names its submitter the way every curator desk
// does (App\Moderation\DeskRider, moderation-and-contribution.md curator-facing
// naming): a public profile is the name linked to /riders/{uuid}; otherwise the
// server already sent the pseudonym and no uuid, and the name is plain text.
import test from 'node:test';
import assert from 'node:assert/strict';
import { deskRiderHtml } from '../../assets/map/util.js';

test('a submitter with a public profile is a link to it', () => {
  assert.equal(deskRiderHtml('Route Rider', '0190-ab'), '<a class="desk-rider" href="/riders/0190-ab">Route Rider</a>');
});

test('no uuid: the name (a pseudonym) is plain text', () => {
  assert.equal(deskRiderHtml('rider#1a2b', ''), 'rider#1a2b');
  assert.equal(deskRiderHtml('rider#1a2b', null), 'rider#1a2b');
});

test('the name and the uuid are escaped', () => {
  assert.equal(deskRiderHtml('<b>x</b>', 'a"b'), '<a class="desk-rider" href="/riders/a%22b">&lt;b&gt;x&lt;/b&gt;</a>');
});
