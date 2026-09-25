// SPDX-License-Identifier: AGPL-3.0-only
//
// The rider's steepest point in the shared climb editor
// (web/assets/contribute/climb-editor.js, docs/specs/climb-elevation.md).
// Placing one is a mode a rider arms and may leave again without a tap.
// climb-editor.js is a browser IIFE, so it runs here in a vm context with a
// stub map and marker; a hydrated route means no network call is made.
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SRC = fs.readFileSync(path.join(__dirname, '../../assets/contribute/climb-editor.js'), 'utf8');

function el() {
  const label = { innerHTML: '' };
  return {
    className: '', dataset: {}, style: {}, innerHTML: '',
    querySelector: () => label,
    label,
  };
}

function mount(extra) {
  const markers = [];
  class Marker {
    constructor(o) { this.el = o.element; this.ll = null; markers.push(this); }
    setLngLat(ll) { this.ll = ll; return this; }
    addTo() { return this; }
    remove() { this.removed = true; }
    on() { return this; }
    getElement() { return this.el; }
    getLngLat() { return { lng: this.ll[0], lat: this.ll[1] }; }
  }
  const handlers = {};
  const map = {
    on: (ev, fn) => { handlers[ev] = fn; },
    off: () => {},
    once: () => {},
    getSource: () => ({ setData() {} }),
    addSource() {}, addLayer() {}, getLayer: () => null,
    fitBounds() {},
  };
  const window = {};
  const ctx = vm.createContext({
    window, maplibregl: { Marker },
    document: { createElement: el },
    setTimeout, clearTimeout, AbortController,
    fetch: () => { throw new Error('no network in this test'); },
  });
  vm.runInContext(SRC, ctx);
  const hidden = { steepPoint: { value: '' }, steep: { value: '' } };
  const seen = [];
  const editor = window.Cc.mountClimbEditor(Object.assign({
    map, hidden,
    labels: { foot: 'FOOT', summit: 'SUMMIT', steepest: 'STEEPEST 250 m', riderSteep: 'STEEPEST POINT' },
    // Stored [lat,lng]: a short line up the Keutenberg.
    initial: { route: [[50.8360, 5.8250], [50.8370, 5.8260], [50.8380, 5.8270]] },
    onChange: (st) => seen.push(st),
  }, extra || {}));
  const click = (lng, lat) => handlers.click({ lngLat: { lng, lat } });
  return { editor, hidden, seen, click, markers, last: () => seen[seen.length - 1] };
}

test('arming the mode is reported, and the next tap places the rider point', () => {
  const m = mount();
  m.editor.markSteepestPoint();
  assert.equal(m.last().placingRider, true);

  m.click(5.8255, 50.8365);
  assert.equal(m.last().placingRider, false);
  assert.deepEqual(JSON.parse(m.hidden.steepPoint.value), { at: [50.8365, 5.8255], pct: '', note: '' });
});

test('cancel leaves the mode without placing anything', () => {
  const m = mount();
  m.editor.markSteepestPoint();
  m.editor.cancelSteepestPoint();
  assert.equal(m.last().placingRider, false);
  assert.equal(m.hidden.steepPoint.value, '');

  // A tap after cancel is the ordinary third tap again: the measured marker.
  m.click(5.8255, 50.8365);
  assert.equal(m.hidden.steepPoint.value, '');
  assert.equal(JSON.parse(m.hidden.steep.value).manual, true);
});

test('gradient and note are stored with the point, and remove clears it', () => {
  const m = mount();
  m.editor.markSteepestPoint();
  m.click(5.8255, 50.8365);
  m.editor.setSteepestPoint(m.last().steepPoint.at, '20%', 'right after the chapel');
  assert.deepEqual(JSON.parse(m.hidden.steepPoint.value),
    { at: [50.8365, 5.8255], pct: '20%', note: 'right after the chapel' });
  const rider = m.markers.filter((k) => k.el.dataset.mkType === 'rider' && !k.removed);
  assert.equal(rider.length, 1);
  assert.equal(rider[0].el.label.innerHTML, 'STEEPEST POINT · 20%');

  m.editor.clearSteepestPoint();
  assert.equal(m.hidden.steepPoint.value, '');
});

test('reset leaves placing mode too', () => {
  const m = mount();
  m.editor.markSteepestPoint();
  m.editor.reset();
  assert.equal(m.last().placingRider, false);
});

test('reset removes the rider point, and undo brings it back', () => {
  const m = mount();
  m.editor.markSteepestPoint();
  m.click(5.8255, 50.8365);
  m.editor.setSteepestPoint(m.last().steepPoint.at, '21%', '');
  const live = () => m.markers.filter((k) => k.el.dataset.mkType === 'rider' && !k.removed);

  m.editor.reset();
  assert.equal(m.hidden.steepPoint.value, '');
  assert.equal(m.last().steepPoint, null);
  assert.equal(live().length, 0);

  m.editor.undo();
  assert.equal(JSON.parse(m.hidden.steepPoint.value).pct, '21%');
  assert.equal(live().length, 1);
});
