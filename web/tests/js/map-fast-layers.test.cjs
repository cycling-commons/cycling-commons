// SPDX-License-Identifier: AGPL-3.0-only
//
// Our layers and sources skip MapLibre's per-call style check (map-init.js,
// docs/specs/map-and-search.md section 2): the check serializes the whole style
// for every addLayer and addSource, and the planner's preset added about 300
// layers, seconds in Firefox (owner 2026-10-17). The wrapper leans on three
// inside parts of the MapLibre we ship; a version that changes them fails here.
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const ROOT = path.join(__dirname, '..', '..');
const read = rel => fs.readFileSync(path.join(ROOT, rel), 'utf8');
const init = read('assets/map/map-init.js');
const lib = read('public/lib/maplibre-gl/6.8.0/maplibre-gl.mjs');

test('the MapLibre we ship takes validate:false and builds addLayer and addSource the way the wrapper does', () => {
  assert.ok(lib.includes('addLayer(e,t){return this._lazyInitEmptyStyle(),this.style.addLayer(e,t),this._update(!0)}'), 'Map.addLayer changed shape');
  assert.ok(lib.includes('addSource(e,t){return this._lazyInitEmptyStyle(),this.style.addSource(e,t),this._update(!0)}'), 'Map.addSource changed shape');
  assert.ok(lib.includes('_validate(e,t,n,r,i={}){return i.validate!==!1&&'), 'Style._validate no longer honours validate:false');
});

test('the map adds our layers and sources without the style check, and keeps the checked path before a style exists', () => {
  const calls = [];
  const fake = {
    style: { addLayer: (l, b, o) => calls.push(['layer', l.id, b, o]), addSource: (id, s, o) => calls.push(['source', id, o]) },
    _update: x => { calls.push(['update', x]); return 'map'; },
    addLayer: () => calls.push(['checked layer']), addSource: () => calls.push(['checked source']),
  };
  const body = init.slice(init.indexOf('const addLayerChecked'), init.indexOf('// Non-prod test handle'));
  vm.runInNewContext(body, { map: fake });
  assert.equal(fake.addLayer({ id: 'surftile-nl' }, 'pins'), 'map');
  assert.equal(fake.addSource('surf-nl', { type: 'vector' }), 'map');
  // The options object comes from the sandbox's realm: compare as JSON.
  assert.equal(JSON.stringify(calls), JSON.stringify([['layer', 'surftile-nl', 'pins', { validate: false }], ['update', true], ['source', 'surf-nl', { validate: false }], ['update', true]]));
  calls.length = 0; fake.style = null;
  fake.addLayer({ id: 'x' }); fake.addSource('y', {});
  assert.equal(JSON.stringify(calls), JSON.stringify([['checked layer'], ['checked source']]));
});
