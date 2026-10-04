// SPDX-License-Identifier: AGPL-3.0-only
//
// The town card's text line (assets/map/town-text.js): a card with a text
// carries a ringed pencil after its ringed "!" on the credit line, which opens the
// form; a card with no text carries one plain link to write one; a visitor's
// pencil and link go to sign-in, which comes back to the form; the credit
// names a rider whose text a curator approved, and credits Wikipedia only
// while the text is based on the article (moderation-and-contribution.md
// §3.1b).
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { townTextHref, townCredit, townCitesWiki, townAddHtml, townActionsHtml, townEditTarget, townReportHref } from '../../assets/map/town-text.js';

const D = {
  textEdit: 'Edit this text', textAdd: 'Write a text for this town', textEditSignin: 'Sign in to edit this text',
  textAddSignin: 'Sign in to write a text for this town', reportText: 'Report this text',
  wikiText: 'Text CC BY-SA 4.0', wikiEdited: 'Edited by our curators, after Wikipedia CC BY-SA 4.0',
  wikiEditedBy: 'Edited by {name}, after Wikipedia CC BY-SA 4.0',
  textWritten: 'Written by our curators, CC BY-SA 4.0', textWrittenBy: 'Written by {name}, CC BY-SA 4.0',
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
  assert.equal(townAddHtml({ text: null }, { osm: '' }, 'x', { signedIn: true, lang: 'en' }, D), '');
});

test('a card with a text has no sentence and no link under it: the pencil on the credit line is the way in', () => {
  assert.equal(townAddHtml(TEXT, META, 'Antwerpen', { signedIn: true, lang: 'en' }, D), '');
  assert.equal(townAddHtml(TEXT, META, 'Antwerpen', { signedIn: false, lang: 'en' }, D), '');
});

test('a card with no text yet carries one plain link to write one', () => {
  const html = townAddHtml({ text: null }, META, 'Hamlet', { signedIn: true, lang: 'en' }, D);
  assert.match(html, /^<p class="cc-town-add"><a href="\/town\/node\/59518\/text\?[^"]*">Write a text for this town<\/a><\/p>$/);
  assert.doesNotMatch(html, /Anyone signed in/, 'the link alone, no sentence');
});

test('a visitor gets the same link to sign in, which comes back to the form', () => {
  const html = townAddHtml({ text: null }, META, 'Hamlet', { signedIn: false, lang: 'fr' }, D);
  const href = /href="([^"]+)"/.exec(html)[1].replace(/&amp;/g, '&');
  assert.ok(href.startsWith('/fr/login?_target_path='), href);
  assert.ok(decodeURIComponent(href.split('_target_path=')[1]).startsWith('/fr/town/node/59518/text?'));
  assert.match(html, />Sign in to write a text for this town</);
});

test('the credit line ends in the ringed "!" to the town report and the pencil to the form', () => {
  const opts = { signedIn: true, lang: 'en', from: '/map?town=x' };
  const html = townActionsHtml(META, 'Antwerpen', opts, D);
  const m = /^<span class="tc-acts"><a class="ring-ico ring-ico--report" href="([^"]+)" title="Report this text" aria-label="Report this text">!<\/a><a class="ring-ico ring-ico--edit" href="([^"]+)" title="Edit this text" aria-label="Edit this text">✎<\/a><\/span>$/.exec(html);
  assert.ok(m, html);
  assert.equal(m[1].replace(/&amp;/g, '&'), '/report/town/node-59518?from=%2Fmap%3Ftown%3Dx');
  assert.equal(m[1].replace(/&amp;/g, '&'), townReportHref(META, '/map?town=x'));
  assert.equal(m[2].replace(/&amp;/g, '&'), townTextHref(META, 'Antwerpen', 'en', '/map?town=x'));
});

test('a visitor\'s pencil goes to sign in', () => {
  const opts = { signedIn: false, lang: 'nl' };
  const html = townActionsHtml(META, 'Antwerpen', opts, D);
  assert.match(html, /aria-label="Sign in to edit this text"/);
  const href = /class="ring-ico ring-ico--edit" href="([^"]+)"/.exec(html)[1].replace(/&amp;/g, '&');
  assert.equal(href, townEditTarget(META, 'Antwerpen', opts));
  assert.ok(href.startsWith('/nl/login?_target_path='), href);
  assert.equal(townActionsHtml({ osm: '' }, 'x', opts, D), '', 'no element, no icons');
});

test('the name the card was opened with is escaped', () => {
  const html = townAddHtml({ text: null }, META, '<b>x</b>', { signedIn: true, lang: 'en' }, D);
  assert.doesNotMatch(html, /<b>x<\/b>/);
});

test('the credit follows who wrote the text', () => {
  assert.equal(townCredit({ edited: false, ...TEXT }, D), 'Text CC BY-SA 4.0');
  assert.equal(townCredit({ edited: true, derived: true, editedBy: null, ...TEXT }, D), 'Edited by our curators, after Wikipedia CC BY-SA 4.0');
  assert.equal(townCredit({ edited: true, derived: true, editedBy: { name: 'Dorpsfietser' }, ...TEXT }, D),
    'Edited by Dorpsfietser, after Wikipedia CC BY-SA 4.0');
  assert.equal(townCredit({ edited: true, editedBy: { name: null }, text: { extract: 'x', url: null } }, D),
    'Written by a removed rider, CC BY-SA 4.0');
  assert.equal(townCredit({ edited: true, editedBy: null, text: { extract: 'x', url: null } }, D), 'Written by our curators, CC BY-SA 4.0');
});

test('a text the curator approved as written fresh credits its writer alone, with no Wikipedia link', () => {
  const fresh = { edited: true, derived: false, editedBy: { name: 'Dorpsfietser' }, ...TEXT };
  assert.equal(townCredit(fresh, D), 'Written by Dorpsfietser, CC BY-SA 4.0');
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

test('the town card renders the line and the pencil after the "!", the link only without a text, and the signed-in signal is the riders\' confirm token', () => {
  const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
  const places = fs.readFileSync(path.join(root, 'assets', 'map', 'places.js'), 'utf8');
  assert.match(places, /import \{ townCredit, townCitesWiki, townAddHtml, townActionsHtml \} from '\.\/town-text\.js';/);
  assert.match(places, /const editOpts = \{ signedIn: !!window\.CC_CONFIRM_TOKEN,/);
  assert.match(places, /const edit = townAddHtml\(d, meta, name, editOpts, D\);/);
  assert.match(places, /const actions = townActionsHtml\(meta, name, editOpts, D\);/);
  assert.match(places, /const wiki = townCitesWiki\(d\) \?/);
  // The "!" and the pencil end the credit line, which takes the shared credit style.
  assert.match(places, /<div class="cc-city-links tc-line">\$\{wiki\}<a [^>]+>\$\{credit\}<\/a>\$\{actions\}<\/div>/);
});
