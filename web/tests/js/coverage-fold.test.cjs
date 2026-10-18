// SPDX-License-Identifier: AGPL-3.0-only
//
// The fold (docs/specs/coverage-provider.md section 3 step 5, owner
// 2026-10-15): five or more coverage points of one kind close together ride
// the tiles as one feature with `grp`, the pin shows the count, the drawer
// says what it stands for. Structural pins over the pipeline, the contract
// and the map modules.
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');
const coverage = read('assets/map/coverage.js');
const drawer = read('assets/map/drawer.js');
const controller = read('src/Controller/MapController.php');
const contract = JSON.parse(read('../pipeline/contract/coverage-contract.json'));
const tiles = read('../pipeline/coverage/tiles.py');

test('the export folds in SQL: five or more of a kind within 100 Mercator metres, the member nearest the centre carries grp', () => {
  assert.match(tiles, /^FOLD_EPS = 100$/m);
  assert.match(tiles, /^FOLD_MIN = 5$/m);
  assert.match(tiles, /^FOLD_MIN_WATER = 2$/m, 'water folds from two (owner 2026-10-17)');
  assert.match(tiles, /"ST_ClusterDBSCAN\(ST_Transform\(geom, 3857\), eps := " \+ str\(FOLD_EPS\) \+ ", minpoints := \{n\}\) "/);
  assert.match(tiles, /OVER \(PARTITION BY country_code, \{kind_key\}\)/, 'a fold never spans a border or a kind');
  assert.match(tiles, /return dbscan\.format\(n=FOLD_MIN\)/);
  assert.match(tiles, /CASE WHEN \(\{_FOOD_SQL\}\) THEN \{dbscan\.format\(n=FOLD_MIN\)\} ELSE \{dbscan\.format\(n=FOLD_MIN_WATER\)\} END/, 'B: food keeps five, water folds from two');
  assert.match(tiles, /COALESCE\(\{_POTABLE_SQL\}, ''\)/, 'a drinkable tap never folds with an undrinkable one');
  assert.match(tiles, /ORDER BY g\.country_code, g\.kind_key, g\.fold, ST_Distance\(p\.geom, g\.centre\), p\.ref/, 'the representative is the member nearest the centre');
  assert.ok(contract.universalTileProps.includes('grp'), 'grp is a universal tile prop');
});

test('the pin shows the count, the tip and the drawer say what it stands for', () => {
  assert.match(coverage, /'text-field': \['case', \['has','grp'\], \['to-string',\['get','grp'\]\], ''\]/);
  assert.match(coverage, /if\(tp\.grp!=null && Number\(tp\.grp\) > 1\) p\.grp=Number\(tp\.grp\);/);
  assert.match(coverage, /p\.grp \? `\$\{what\} ×\$\{p\.grp\}` : what/);
  assert.match(drawer, /if\(p\.grp\) rec\.push\(\{label:D\.covGroup/);
  assert.match(controller, /'covGroup' => 'd_cov_group', 'covGroupValue' => 'd_cov_group_value'/);
});
