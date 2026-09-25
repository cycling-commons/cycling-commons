// SPDX-License-Identifier: AGPL-3.0-only
//
// The controls under the climb map for the rider's steepest point
// (web/assets/contribute/rider-steep.js, docs/specs/climb-elevation.md):
// + STEEPEST POINT arms the mode, CANCEL leaves it, and once a point exists
// its optional gradient and note are typed in two fields.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');

const RS = require('../../assets/contribute/rider-steep.js');

test('a gradient is normalised to the form the server accepts', () => {
  assert.equal(RS.normalizePct('20'), '20%');
  assert.equal(RS.normalizePct(' 20 % '), '20%');
  assert.equal(RS.normalizePct('18,5'), '18.5%');
  assert.equal(RS.normalizePct('~22%'), '~22%');
  assert.equal(RS.normalizePct(''), '');
  // ClimbGeometry::steepPoint refuses these, so the field must too.
  assert.equal(RS.normalizePct('steep'), null);
  assert.equal(RS.normalizePct('120'), null);
});

function node() {
  const on = {};
  return {
    hidden: false, value: '', dataset: {}, validity: '',
    addEventListener: (ev, fn) => { on[ev] = fn; },
    setCustomValidity(msg) { this.validity = msg; },
    fire(ev, e) { on[ev](Object.assign({ key: '', preventDefault() {} }, e || {})); },
  };
}

function setup() {
  const calls = [];
  const editor = {
    markSteepestPoint: () => calls.push(['mark']),
    cancelSteepestPoint: () => calls.push(['cancel']),
    clearSteepestPoint: () => calls.push(['clear']),
    setSteepestPoint: (at, pct, note) => calls.push(['set', at, pct, note]),
  };
  const els = { box: node(), mark: node(), cancel: node(), fields: node(), pct: node(), note: node(), remove: node() };
  const doc = { activeElement: null, addEventListener: (ev, fn) => { doc.onKey = fn; } };
  const ctl = RS.mount(editor, els, doc);
  return { calls, els, doc, ctl };
}

const LINE = { start: [5.825, 50.836], summit: [5.827, 50.838] };

test('nothing shows until the climb has a foot and a summit', () => {
  const s = setup();
  s.ctl.render({ start: [5.825, 50.836], summit: null, steepPoint: null, placingRider: false });
  assert.equal(s.els.box.hidden, true);
  s.ctl.render(Object.assign({ steepPoint: null, placingRider: false }, LINE));
  assert.equal(s.els.box.hidden, false);
  assert.equal(s.els.mark.hidden, false);
  assert.equal(s.els.cancel.hidden, true);
  assert.equal(s.els.fields.hidden, true);
});

test('the button arms the mode and cancel (or Escape) leaves it', () => {
  const s = setup();
  s.els.mark.fire('click');
  assert.deepEqual(s.calls.pop(), ['mark']);

  s.ctl.render(Object.assign({ steepPoint: null, placingRider: true }, LINE));
  assert.equal(s.els.mark.hidden, true);
  assert.equal(s.els.cancel.hidden, false);
  s.els.cancel.fire('keydown', { key: 'Enter' });
  assert.deepEqual(s.calls.pop(), ['cancel']);

  s.doc.onKey({ key: 'Escape' });
  assert.deepEqual(s.calls.pop(), ['cancel']);
  s.ctl.render(Object.assign({ steepPoint: null, placingRider: false }, LINE));
  s.doc.onKey({ key: 'Escape' });
  assert.equal(s.calls.length, 0, 'Escape does nothing when not placing');
});

test('a placed point shows its fields, and typing stores gradient and note', () => {
  const s = setup();
  const at = [5.8255, 50.8365];
  s.ctl.render(Object.assign({ steepPoint: { at, pct: '', note: '' }, placingRider: false }, LINE));
  assert.equal(s.els.fields.hidden, false);
  assert.equal(s.els.mark.hidden, true);

  s.els.pct.value = '20';
  s.els.pct.fire('input');
  assert.deepEqual(s.calls.pop(), ['set', at, '20%', '']);

  s.els.note.value = 'right after the chapel';
  s.els.note.fire('input');
  assert.deepEqual(s.calls.pop(), ['set', at, '20%', 'right after the chapel']);

  s.els.pct.value = 'steep';
  s.els.pct.fire('input');
  assert.equal(s.calls.length, 0, 'an invalid gradient is not stored');
  assert.notEqual(s.els.pct.validity, '');

  s.els.remove.fire('click');
  assert.deepEqual(s.calls.pop(), ['clear']);
});

test('a field being typed in is not overwritten by a re-render', () => {
  const s = setup();
  const at = [5.8255, 50.8365];
  s.ctl.render(Object.assign({ steepPoint: { at, pct: '18%', note: 'chapel' }, placingRider: false }, LINE));
  assert.equal(s.els.pct.value, '18%');
  s.els.pct.value = '2';
  s.doc.activeElement = s.els.pct;
  s.ctl.render(Object.assign({ steepPoint: { at, pct: '18%', note: 'chapel' }, placingRider: false }, LINE));
  assert.equal(s.els.pct.value, '2');
  assert.equal(s.els.note.value, 'chapel');
});

test('Enter in a field does not submit the wizard form', () => {
  const s = setup();
  let prevented = false;
  s.els.note.fire('keydown', { key: 'Enter', preventDefault() { prevented = true; } });
  assert.equal(prevented, true);
});
