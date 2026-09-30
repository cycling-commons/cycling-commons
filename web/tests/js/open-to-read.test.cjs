// SPDX-License-Identifier: AGPL-3.0-only
//
// assets/js/open-to-read.js: a message or a room post is read when its reader
// OPENS it (moderation-and-contribution.md §7.5a, §13.7). Loading the page
// posts nothing; opening one item posts once, unwraps it, takes its unseen bar
// off and takes exactly one off every counter of its kind. A refused post
// leaves the bar and the counters alone. A desk row that opens on a page of
// its own loses its bar when one of its [data-opens] links is followed
// (moderation-and-contribution.md §5.2f).
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SRC = fs.readFileSync(path.join(__dirname, '..', '..', 'assets/js/open-to-read.js'), 'utf8');

/* Just enough DOM for the script: elements with attributes, classes,
   children, and the few selectors it asks for. */
class El {
  constructor(tag, attrs = {}, children = []) {
    this.tagName = tag.toUpperCase();
    this.attrs = { ...attrs };
    this.classes = new Set((attrs.class || '').split(/\s+/).filter(Boolean));
    this.children = [];
    this.parentNode = null;
    this.listeners = {};
    this.hidden = 'hidden' in attrs;
    this.text = '';
    this.focused = false;
    this.open = false;
    const self = this;
    this.classList = {
      add: c => self.classes.add(c),
      remove: c => self.classes.delete(c),
      contains: c => self.classes.has(c),
    };
    children.forEach(c => this.appendChild(c));
  }
  appendChild(c) { c.parentNode = this; this.children.push(c); return c; }
  insertBefore(c, ref) {
    if (c.parentNode) c.parentNode.removeChild(c);
    c.parentNode = this;
    this.children.splice(this.children.indexOf(ref), 0, c);
    return c;
  }
  removeChild(c) { this.children.splice(this.children.indexOf(c), 1); c.parentNode = null; return c; }
  get firstChild() { return this.children[0] || null; }
  get firstElementChild() { return this.children[0] || null; }
  get textContent() { return this.text; }
  set textContent(v) { this.text = v; }
  getAttribute(n) { return n in this.attrs ? this.attrs[n] : null; }
  setAttribute(n, v) { this.attrs[n] = String(v); }
  removeAttribute(n) { delete this.attrs[n]; }
  hasAttribute(n) { return n in this.attrs; }
  addEventListener(t, fn) { (this.listeners[t] = this.listeners[t] || []).push(fn); }
  fire(t) { (this.listeners[t] || []).forEach(fn => fn({ target: this })); }
  focus() { this.focused = true; }
  matches(sel) { return match(this, sel); }
  closest(sel) { let n = this; while (n) { if (n.matches && n.matches(sel)) return n; n = n.parentNode; } return null; }
  descendants() { return this.children.flatMap(c => [c, ...c.descendants()]); }
  querySelectorAll(sel) { return this.descendants().filter(e => e.matches(sel)); }
  querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
}

function match(el, sel) {
  let m;
  if ((m = sel.match(/^\[([\w-]+)~="([^"]+)"\]$/))) return (el.getAttribute(m[1]) || '').split(/\s+/).includes(m[2]);
  if ((m = sel.match(/^\[([\w-]+)\]$/))) return el.hasAttribute(m[1]);
  if ((m = sel.match(/^(\w+)\[([\w-]+)\]$/))) return el.tagName === m[1].toUpperCase() && el.hasAttribute(m[2]);
  if ((m = sel.match(/^(\w+)$/))) return el.tagName === m[1].toUpperCase();
  throw new Error('selector not in the fake: ' + sel);
}

function count(kind, n, extra = {}) {
  const el = new El('span', { 'data-count-of': kind, ...extra });
  el.text = String(n);
  return el;
}

/* A list holding one unread message and one read one, and the counters. */
function page({ hash = '', respond } = {}) {
  const body = new El('p', { class: 'q-body' });
  const summary = new El('summary');
  const details = new El('details', { 'data-open-read': '' }, [summary, body]);
  const unreadMark = new El('span', { 'data-unread-only': '' });
  const readMark = new El('span', { 'data-read-only': '', hidden: '' });
  const unread = new El('li', {
    id: 'msg-7', class: 'q-item msg-row msg-new is-unseen',
    'data-read-url': '/account/messages/7/read', 'data-unread-class': 'msg-new is-unseen',
  }, [unreadMark, readMark, details]);
  const read = new El('li', { id: 'msg-6', class: 'q-item msg-row' });
  const list = new El('ul', { 'data-read-token': 'tok', 'data-read-kind': 'messages' }, [unread, read]);
  // A desk row: its own page opens it, so it has a link and no read url.
  const deskNote = new El('span', { class: 'unseen-note', 'data-unread-only': '' });
  const deskLink = new El('a', { href: '/moderate/bugs/4', 'data-opens': '' });
  const deskOther = new El('a', { href: '/map?item=4' });
  const desk = new El('div', { class: 'bugrow is-unseen', 'data-unread-class': 'is-unseen' }, [deskLink, deskNote, deskOther]);
  const bulb = count('messages', 3, { title: '3 open item(s)', 'aria-label': '3 open item(s)' });
  const row = count('messages', 1);
  const chip = count('messages', 1, { 'data-count-keep-zero': '' });
  const room = count('room', 4);
  const root = new El('body', {}, [list, desk, bulb, row, chip, room]);
  const docListeners = {};

  const posts = [];
  const context = {
    window: { location: { hash } },
    document: {
      querySelectorAll: sel => root.querySelectorAll(sel),
      getElementById: id => root.descendants().find(e => e.getAttribute('id') === id) || null,
      addEventListener: (t, fn) => { (docListeners[t] = docListeners[t] || []).push(fn); },
    },
    URLSearchParams,
    fetch: (url, opts) => {
      posts.push({ url, method: opts.method, token: opts.body.get('_token') });
      const r = respond ? respond(url) : { ok: true, body: { read: true, unread: 0 } };
      return Promise.resolve({ ok: r.ok, json: () => Promise.resolve(r.body) });
    },
    Promise,
  };
  vm.runInNewContext(SRC, context);
  const click = (target, type = 'click') => (docListeners[type] || []).forEach(fn => fn({ target }));
  return { unread, details, body, unreadMark, readMark, bulb, row, chip, room, posts, desk, deskNote, deskLink, deskOther, click };
}

const settle = () => new Promise(r => setTimeout(r, 10));

test('loading the page posts nothing', async () => {
  const p = page();
  await settle();
  assert.equal(p.posts.length, 0);
  assert.equal(p.row.textContent, '1');
});

test('opening an unread message posts once and lowers each counter by one', async () => {
  const p = page();
  p.details.open = true;
  p.details.fire('toggle');
  await settle();

  assert.deepEqual(p.posts, [{ url: '/account/messages/7/read', method: 'POST', token: 'tok' }]);
  assert.equal(p.bulb.textContent, '2');
  assert.equal(p.bulb.getAttribute('aria-label'), '2 open item(s)', 'the label repeats the number and follows it');
  assert.equal(p.row.textContent, '0');
  assert.equal(p.row.hidden, true, 'a badge at zero goes');
  assert.equal(p.chip.textContent, '0');
  assert.equal(p.chip.hidden, false, 'the Unread chip keeps its zero');
  assert.equal(p.room.textContent, '4', 'another kind of counter is left alone');

  assert.ok(!p.unread.classList.contains('msg-new'));
  assert.ok(!p.unread.classList.contains('is-unseen'), 'the unseen bar goes');
  assert.ok(p.unread.classList.contains('is-open'));
  assert.equal(p.unreadMark.hidden, true);
  assert.equal(p.readMark.hidden, false);
  assert.equal(p.details.parentNode, null, 'the disclosure is unwrapped');
  assert.equal(p.body.parentNode, p.unread);
  assert.ok(p.body.focused, 'focus lands on what was opened');

  // Opening it again is not a second step down.
  p.details.fire('toggle');
  await settle();
  assert.equal(p.posts.length, 1);
  assert.equal(p.bulb.textContent, '2');
});

test('an item the server already had as read changes no counter', async () => {
  const p = page({ respond: () => ({ ok: true, body: { read: false, unread: 1 } }) });
  p.details.open = true;
  p.details.fire('toggle');
  await settle();
  assert.equal(p.posts.length, 1);
  assert.equal(p.row.textContent, '1');
  assert.ok(!p.unread.classList.contains('msg-new'));
});

test('a refused open leaves the item unread and the counters as they were', async () => {
  const p = page({ respond: () => ({ ok: false, body: null }) });
  p.details.open = true;
  p.details.fire('toggle');
  await settle();
  assert.equal(p.row.textContent, '1');
  assert.ok(p.unread.classList.contains('msg-new'));
  assert.ok(p.unread.classList.contains('is-unseen'), 'the bar stays');
  assert.equal(p.unreadMark.hidden, false);
});

test('a link to the message opens it', async () => {
  const p = page({ hash: '#msg-7' });
  assert.equal(p.details.open, true);
  p.details.fire('toggle');
  await settle();
  assert.equal(p.posts.length, 1);
});

test('following a desk row\'s link takes its bar off, and posts nothing', async () => {
  const p = page();
  p.click(p.deskOther);
  assert.ok(p.desk.classList.contains('is-unseen'), 'a link that does not open the item leaves the bar');
  p.click(p.deskLink);
  assert.ok(!p.desk.classList.contains('is-unseen'));
  assert.equal(p.deskNote.hidden, true, 'its words go with it');
  await settle();
  assert.equal(p.posts.length, 0, 'the page it leads to records the opening');
  assert.equal(p.row.textContent, '1', 'no counter moves');
});

test('a middle click on a desk row\'s link counts too', () => {
  const p = page();
  p.click(p.deskLink, 'auxclick');
  assert.ok(!p.desk.classList.contains('is-unseen'));
});
