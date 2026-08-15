// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
//
// itemLinks() (web/assets/map/links.js): the drawer-side half of the
// outbound-links shape. Every DESTINATION renders; the url picked for it is
// the reader's locale, else the entry's locale-less default, else English,
// else the first - an entry with no variant for the reader appears rather
// than vanishing. https-only even here: a non-https url that slipped past
// the server must still never land in an <a href>.
import test from 'node:test';
import assert from 'node:assert/strict';
import { itemLinks, LINKS_MAX_ENTRIES } from '../../assets/map/links.js';

const WIKI = {
  label: 'Wikipedia',
  urls: [
    { url: 'https://en.wikipedia.org/wiki/Muiderslot', locale: 'en' },
    { url: 'https://nl.wikipedia.org/wiki/Muiderslot', locale: 'nl' },
  ],
};

test('resolves the reader locale, falls back to en, never vanishes', () => {
  assert.equal(itemLinks([WIKI], 'nl')[0].href, 'https://nl.wikipedia.org/wiki/Muiderslot');
  assert.equal(itemLinks([WIKI], 'de')[0].href, 'https://en.wikipedia.org/wiki/Muiderslot');
  const noEn = { urls: [{ url: 'https://fr.example.org/x', locale: 'fr' }] };
  assert.equal(itemLinks([noEn], 'de')[0].href, 'https://fr.example.org/x', 'first url is the last resort');
});

test('a locale-less url is the entry default', () => {
  const entry = { label: 'Official site', urls: [{ url: 'https://muiderslot.nl' }, { url: 'https://fr.muiderslot.nl', locale: 'fr' }] };
  assert.equal(itemLinks([entry], 'de')[0].href, 'https://muiderslot.nl');
  assert.equal(itemLinks([entry], 'fr')[0].href, 'https://fr.muiderslot.nl');
});

test('carries the bare domain and falls back to it as the label', () => {
  const got = itemLinks([{ urls: [{ url: 'https://www.kasteel.example/nl/home' }] }], 'en')[0];
  assert.equal(got.domain, 'kasteel.example');
  assert.equal(got.label, 'kasteel.example');
});

test('accepts the JSON-string form attributes travel in', () => {
  assert.equal(itemLinks(JSON.stringify([WIKI]), 'nl').length, 1);
  assert.deepEqual(itemLinks('not json', 'nl'), []);
  assert.deepEqual(itemLinks(42, 'nl'), []);
});

test('drops non-https urls and capped entries', () => {
  assert.deepEqual(itemLinks([{ urls: [{ url: 'javascript:alert(1)' }] }], 'en'), []);
  assert.deepEqual(itemLinks([{ urls: [{ url: 'http://plain.example' }] }], 'en'), []);
  const many = Array.from({ length: 6 }, (_, i) => ({ urls: [{ url: `https://site${i}.example` }] }));
  assert.equal(itemLinks(many, 'en').length, LINKS_MAX_ENTRIES);
});
