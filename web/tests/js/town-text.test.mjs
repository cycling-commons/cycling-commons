// SPDX-License-Identifier: AGPL-3.0-only
//
// The town card's text line (assets/map/town-text.js): anyone signed in can
// suggest an edit and a curator of the region approves it, said in one
// sentence with the link; a visitor gets a sign-in link that comes back to
// the form; the credit line carries a ringed pencil after its "!" that goes
// where the link goes; the credit names a rider whose text a curator approved,
// and credits Wikipedia only while the text is based on the article
// (moderation-and-contribution.md §3.1b).
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { townTextHref, townCredit, townCitesWiki, townEditHtml, townPenHtml, townEditTarget } from '../../assets/map/town-text.js';

const D = {
  textEdit: 'Edit this text', textAdd: 'Write a text for this town', textEditSignin: 'Sign in to edit this text',
  textEditNote: 'Anyone signed in can suggest an edit to this text. A curator of this region approves it.',
  wikiText: 'Text CC BY-SA 4.0', wikiEdited: 'Edited by our curators, after Wikipedia CC BY-SA 4.0',
  wikiEditedBy: 'Edited by {name}, approved by our curators, after Wikipedia CC BY-SA 4.0',
  textWritten: 'Written by our curators, CC BY-SA 4.0', textWrittenBy: 'Written by {name}, approved by our curators, CC BY-SA 4.0',
  riderRemoved: 'a removed rider',
};
const META = { osm: 'node/59518', ll: [51.2194, 4.4025] };
const TEXT = { text: { extract: 'Antwerp is a city.', url: 'https://en.wikipedia.org/wiki/Antwerp' } };

test('the form address names the element, the card language, the point, the name and the way back', () => {
  const href = townTextHref(META, 'Antwerpen', 'nl', '/nl/map?town=x');
  assert.ok(href.startsWith('/nl/town/node/59518/text?'), href);
  const q = new URL(href, 'https://x.test').searchParams;
  assert.equal(q.get('lang'), 'nl');
  assert.equal(q.get('name'), 'Antwerpen');
  assert.equal(q.get('lat'), '51.21940');
  assert.equal(q.get('lng'), '4.40250');
  assert.equal(q.get('from'), '/nl/map?town=x');
  assert.ok(townTextHref(META, 'A', 'en').startsWith('/town/'), 'English is the unprefixed path');
});

test('no element, no link', () => {
  assert.equal(townTextHref({ osm: 'bogus' }, 'x', 'en'), '');
  assert.equal(townEditHtml(TEXT, { osm: '' }, 'x', { signedIn: true, lang: 'en' }, D), '');
});

test('a signed-in reader gets the sentence and "Edit this text"', () => {
  const html = townEditHtml(TEXT, META, 'Antwerpen', { signedIn: true, lang: 'en' }, D);
  assert.match(html, /Anyone signed in can suggest an edit to this text\. A curator of this region approves it\./);
  assert.match(html, /<a class="cc-town-edit-link" href="\/town\/node\/59518\/text\?[^"]*">Edit this text<\/a>/);
});

test('a card with no text yet invites one', () => {
  const html = townEditHtml({ text: null }, META, 'Hamlet', { signedIn: true, lang: 'en' }, D);
  assert.match(html, />Write a text for this town</);
});

test('a visitor gets a sign-in link that comes back to the form', () => {
  const html = townEditHtml(TEXT, META, 'Antwerpen', { signedIn: false, lang: 'fr' }, D);
  assert.match(html, /Anyone signed in can suggest an edit/);
  const href = /href="([^"]+)"/.exec(html)[1].replace(/&amp;/g, '&');
  assert.ok(href.startsWith('/fr/login?_target_path='), href);
  assert.ok(decodeURIComponent(href.split('_target_path=')[1]).startsWith('/fr/town/node/59518/text?'));
  assert.match(html, />Sign in to edit this text</);
});

test('the pencil goes where the link goes, labelled "Edit this text"', () => {
  const opts = { signedIn: true, lang: 'en', from: '/map?town=x' };
  const pen = townPenHtml(META, 'Antwerpen', opts, D);
  const m = /^<a class="cc-pen" href="([^"]+)" title="Edit this text" aria-label="Edit this text">✎<\/a>$/.exec(pen);
  assert.ok(m, pen);
  assert.equal(m[1].replace(/&amp;/g, '&'), townTextHref(META, 'Antwerpen', 'en', '/map?town=x'));
  const link = /class="cc-town-edit-link" href="([^"]+)"/.exec(townEditHtml(TEXT, META, 'Antwerpen', opts, D))[1];
  assert.equal(m[1], link, 'the same target as the "Edit this text" link');
});

test('a visitor\'s pencil goes to sign in, like the link', () => {
  const opts = { signedIn: false, lang: 'nl' };
  const pen = townPenHtml(META, 'Antwerpen', opts, D);
  assert.match(pen, /aria-label="Sign in to edit this text"/);
  const href = /href="([^"]+)"/.exec(pen)[1].replace(/&amp;/g, '&');
  assert.equal(href, townEditTarget(META, 'Antwerpen', opts));
  assert.ok(href.startsWith('/nl/login?_target_path='), href);
  assert.equal(townPenHtml({ osm: '' }, 'x', opts, D), '', 'no element, no pencil');
});

test('the name the card was opened with is escaped', () => {
  const html = townEditHtml(TEXT, META, '<b>x</b>', { signedIn: true, lang: 'en' }, D);
  assert.doesNotMatch(html, /<b>x<\/b>/);
});

test('the credit follows who wrote the text', () => {
  assert.equal(townCredit({ edited: false, ...TEXT }, D), 'Text CC BY-SA 4.0');
  assert.equal(townCredit({ edited: true, derived: true, editedBy: null, ...TEXT }, D), 'Edited by our curators, after Wikipedia CC BY-SA 4.0');
  assert.equal(townCredit({ edited: true, derived: true, editedBy: { name: 'Dorpsfietser' }, ...TEXT }, D),
    'Edited by Dorpsfietser, approved by our curators, after Wikipedia CC BY-SA 4.0');
  assert.equal(townCredit({ edited: true, editedBy: { name: null }, text: { extract: 'x', url: null } }, D),
    'Written by a removed rider, approved by our curators, CC BY-SA 4.0');
  assert.equal(townCredit({ edited: true, editedBy: null, text: { extract: 'x', url: null } }, D), 'Written by our curators, CC BY-SA 4.0');
});

test('a text the curator approved as written fresh credits its writer alone, with no Wikipedia link', () => {
  const fresh = { edited: true, derived: false, editedBy: { name: 'Dorpsfietser' }, ...TEXT };
  assert.equal(townCredit(fresh, D), 'Written by Dorpsfietser, approved by our curators, CC BY-SA 4.0');
  assert.equal(townCitesWiki(fresh), false, 'no Wikipedia link');
  assert.equal(townCredit({ ...fresh, editedBy: null }, D), 'Written by our curators, CC BY-SA 4.0');
});

test('a text based on the article keeps the Wikipedia link and credit', () => {
  const kept = { edited: true, derived: true, editedBy: { name: 'Dorpsfietser' }, ...TEXT };
  assert.equal(townCitesWiki(kept), true);
  assert.equal(townCitesWiki({ edited: false, ...TEXT }), true, 'the fetched article itself');
  assert.equal(townCitesWiki({ edited: true, ...TEXT }), true, 'no flag: the credit stays, the licence-safe reading');
  assert.equal(townCitesWiki({ edited: true, derived: true, text: { extract: 'x', url: null } }), false, 'no article, nothing to link');
});

test('the town card renders the line and the pencil after the "!", and the signed-in signal is the riders\' confirm token', () => {
  const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
  const places = fs.readFileSync(path.join(root, 'assets', 'map', 'places.js'), 'utf8');
  assert.match(places, /import \{ townCredit, townCitesWiki, townEditHtml, townPenHtml \} from '\.\/town-text\.js';/);
  assert.match(places, /const editOpts = \{ signedIn: !!window\.CC_CONFIRM_TOKEN,/);
  assert.match(places, /townEditHtml\(d, meta, name, editOpts, D\)/);
  assert.match(places, /const pen = townPenHtml\(meta, name, editOpts, D\);/);
  assert.match(places, /const wiki = townCitesWiki\(d\) \?/);
  // The pencil sits right after the "!" report mark on the credit line.
  assert.match(places, /\$\{credit\}<\/a> \$\{report\}\$\{pen\}<\/div>/);
});
