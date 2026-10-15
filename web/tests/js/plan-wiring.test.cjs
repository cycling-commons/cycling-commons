// SPDX-License-Identifier: AGPL-3.0-only
//
// The ride planner's wiring (docs/specs/route-planner.md): structural pins over
// the source files, the pattern rail-shell.test.cjs uses. The browser never
// talks to the routing engine, the map page hands the script its endpoints
// and tokens, the drawer writes the Add to ride button, and the scope click
// stands aside while a ride is planned.
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const ROOT = path.join(__dirname, '..', '..');
const read = p => fs.readFileSync(path.join(ROOT, p), 'utf8');
const twig = read('templates/map/index.html.twig');
const plan = read('assets/map/plan.js');
const model = read('assets/map/plan-model.js');
const drawer = read('assets/map/drawer.js');
const scopeUi = read('assets/map/scope-ui.js');
const entry = read('assets/map/map.js');
const controller = read('src/Controller/MapController.php');

test('the planner never names the routing engine: every leg goes through our server', () => {
  for (const src of [plan, model]) {
    assert.ok(!/valhalla|:8002|openrouteservice|osrm/i.test(src), 'a planner module names a routing engine');
  }
  assert.ok(plan.includes('CC_PLAN.leg'), 'plan.js does not post legs to the server endpoint');
  assert.ok(plan.includes('CC_PLAN.along'), 'plan.js does not ask the server what is along the ride');
  assert.ok(plan.includes("'X-CC-Token'"), 'plan.js sends no stateless token');
});

test('the map page hands the script its endpoints and tokens, and a visitor gets no save endpoint', () => {
  assert.ok(twig.includes('window.CC_PLAN ='), 'no CC_PLAN blob in the map template');
  for (const needle of ["path('map_plan_leg')", "csrf_token('plan-leg')", "path('map_plan_along')", "csrf_token('plan-along')"]) {
    assert.ok(twig.includes(needle), `the CC_PLAN blob lacks ${needle}`);
  }
  const start = twig.indexOf('window.CC_PLAN =');
  const blob = twig.slice(start, twig.indexOf('</script>', start));
  assert.ok(/merge\(app\.user \? \{[\s\S]*map_plan_save/.test(blob), 'the save endpoint is not behind app.user');
});

test('the model is a leaf and the entry wires the planner', () => {
  assert.ok(!/^import /m.test(model), 'plan-model.js imports something: it must stay a leaf for node tests');
  assert.ok(entry.includes("from './plan.js'") && /initPlan\(\)/.test(entry), 'map.js does not wire initPlan()');
});

test('the drawer writes Add to ride for a place with a point, and the scope click stands aside while planning', () => {
  assert.ok(drawer.includes('data-plan-add'), 'drawer.js writes no Add to ride button');
  // planPlace() is the one rule, for the drawer button and a right-click on a pin alike.
  const rule = (drawer.match(/export function planPlace\(layer, f\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.ok(/layer\.key === 'experience' \|\| layer\.key === 'surface'\) return null;/.test(rule), 'a route or a surface stretch must never become a stop');
  assert.ok(scopeUi.includes("from './plan-state.js'") && /if\(isPlanning\(\)\) return;/.test(scopeUi), 'click-to-scope ignores planning');
});

test('the drawer repaint writes a button only when its label changes, or the observer loops forever', () => {
  // paintDrawerButtons runs from a MutationObserver on the drawer. Setting
  // textContent is itself a childList mutation, so an unconditional write
  // re-triggers the observer without end and the page hangs (owner-reported
  // 2026-10-14 on a climb's drawer).
  const fn = plan.slice(plan.indexOf('function paintDrawerButtons'), plan.indexOf('\n}', plan.indexOf('function paintDrawerButtons')));
  assert.ok(/if\(b\.textContent !== label\) b\.textContent = label;/.test(fn), 'the label is written without checking it changed');
  assert.ok(!/b\.textContent = inRide \?/.test(fn), 'the label is written unconditionally');
});

test('every stop row, a map point too, takes the map to its stop when its name is clicked', () => {
  // Owner 2026-10-14: a click on a stop in the list pans and zooms to it.
  assert.ok(plan.includes('<b class="lnk" data-fly="1">'), 'the stop name is not a fly-to link for every stop');
  assert.ok(!plan.includes(`<b\${s.item ? ' class="lnk" data-fly="1"' : ''}>`), 'only place stops fly to their point');
  assert.ok(/data-fly[\s\S]*flyToPin\(\[s\.lng, s\.lat\]\)/.test(plan), 'the click does not fly to the stop');
});


test('the day: the time tile, the time bar, the date and what makes it tougher, with the forecast through our server', () => {
  for (const id of ['planTime', 'planTimeSub', 'planDayFx', 'planDaySvg', 'planDate', 'planTougher']) assert.ok(twig.includes(`id="${id}"`), `no ${id} in the map template`);
  assert.ok(/rideTime\(dayLegs\(\), ride\.bike\)/.test(plan), 'the figures do not run the day\'s model');
  for (const needle of ['steepestKm(', 'longestClimb(', 'waterGap(', 'sunTimes(', 'windShares(']) assert.ok(plan.includes(needle), `plan.js does not use ${needle}`);
  assert.ok(plan.includes('CC_PLAN.weather'), 'the forecast is not asked through our server');
  assert.ok(!/open-meteo\.com|api\.open-meteo|fetch\(['"]https?:/i.test(plan + model), 'the browser reaches the forecast service itself: it must only ever talk to our server');
  for (const needle of ["path('map_plan_weather')", "csrf_token('plan-weather')"]) assert.ok(twig.includes(needle), `the CC_PLAN blob lacks ${needle}`);
  assert.ok(!twig.includes('id="planTough"') && !twig.includes('planToughFx'), 'the 1 to 5 level is gone from the template');
});

test('the controller hands every planner string to the script', () => {
  assert.ok(controller.includes("'railPlan' => $t->trans('map.rail_plan')"), 'the rail title is not translated');
  for (const key of ['plan_no_road', 'plan_max_stops', 'plan_already', 'plan_rate_limited', 'plan_engine_down', 'plan_saved', 'plan_too_many', 'plan_bar_stops', 'plan_leave']) {
    assert.ok(controller.includes(`'map.${key}'`), `the controller does not translate map.${key}`);
  }
  assert.ok(controller.includes("'planAdd' => 'd_plan_add'"), 'the drawer button label is not in the drawer dictionary');
});

test('the rail shows the Plan icon with the dot while a ride is on the map, as Layers does for a filter', () => {
  const css = read('assets/styles/map.css');
  const start = twig.indexOf('id="ib-plan"');
  const button = twig.slice(start, twig.indexOf('</button>', start));
  assert.ok(button.includes('class="fdot"'), 'the Plan rail button carries no dot span');
  assert.ok(/function syncPlanning\(\)\{[\s\S]*?getElementById\('ib-plan'\)[\s\S]*?classList\.toggle\('riding', ride\.stops\.length > 0\)/.test(plan), 'syncPlanning does not light the dot from the stops on the map');
  assert.ok(css.includes('.ib.riding .fdot{display:block}'), 'map.css does not show the dot for a riding Plan button');
});

test('the ride line lies over every other line layer and under the icons (owner 2026-10-15)', () => {
  const render = read('assets/map/render.js');
  const lift = (render.match(/export function liftInfoLayersAboveRoutes\(\)\{[\s\S]*?\n\}/) || [''])[0];
  const planAt = lift.indexOf("['plan-casing','plan-line','plan-straight']");
  const iconsAt = lift.indexOf('coverageIconLayerIds(');
  assert.ok(planAt > 0, 'the stacking authority does not lift the ride layers');
  assert.ok(lift.indexOf("['mly-cov','mly-img']") < planAt && planAt < iconsAt, 'the ride layers go above the lines and below the coverage icons');
  assert.ok(/map\.addLayer\(\{ id: 'plan-straight'[\s\S]*?liftInfoLayersAboveRoutes\(\);/.test(plan), 'plan.js does not lift its layers once added');
});

test('with the profile strip open, the surface legend sits just above it, not a bug-button gap higher (owner 2026-10-15)', () => {
  const css = read('assets/styles/map.css');
  assert.match(css, /\.map-wrap\.cc-strip-on \.legend\{bottom:calc\(140px \+ \.6rem\)\}/);
});

test('the surface legend is compact vertically (owner 2026-10-15)', () => {
  const css = read('assets/styles/map.css');
  assert.match(css, /\/\* the legend, compact \(owner 2026-10-15\) \*\/\n  \.legend\{padding:\.6rem \.9rem\}\n  \.legend \.skey\{gap:\.15rem\}\n  \.legend \.skey-row\{min-height:1\.55rem\}/);
});

test('the ride line is thin, so the surface band under it stays readable (owner 2026-10-15)', () => {
  assert.match(plan, /id: 'plan-casing'[^\n]*'line-width': \['interpolate', \['linear'\], \['zoom'\], 10, 3, 18, 4\.5\], 'line-opacity': \.7/);
  assert.match(plan, /id: 'plan-line'[^\n]*'line-width': \['interpolate', \['linear'\], \['zoom'\], 10, 1\.8, 18, 3\]/);
});

test('an eye beside Your ride shows or hides the ride line, the stops stay, the choice is kept in the browser (owner 2026-10-15)', () => {
  assert.match(twig, /<h3>\{\{ 'map\.plan_ride_h'\|trans \}\} <button type="button" class="dl-all ico" id="planLineToggle" aria-pressed="true"/);
  assert.match(plan, /const LINE_KEY = 'cc-plan-line';/);
  assert.match(plan, /\['plan-casing', 'plan-line', 'plan-straight'\]\.forEach\(id => \{ if\(map\.getLayer\(id\)\) map\.setLayoutProperty\(id, 'visibility', lineShown \? 'visible' : 'none'\); \}\);/);
  assert.match(controller, /'lineHide' => \$t->trans\('map\.plan_line_hide'\), 'lineShow' => \$t->trans\('map\.plan_line_show'\)/);
});

test('the day graphic draws two bars, by surface and by gradient, and a tiny part reads under 1 min (owner 2026-10-15)', () => {
  assert.ok(plan.includes('surfaceBars(src)') && plan.includes('gradientBars(src)') && plan.includes('day.metres : day.parts'), 'the graphic does not draw both splits, by time or by distance');
  assert.match(plan, /if\(seconds > 0 && seconds < 30\) return P\(\)\.underMin \|\| 'under 1 min';/);
});

test('the panel no longer draws its own height profile: the strip at the foot of the map is the one (owner 2026-10-15)', () => {
  assert.ok(!twig.includes('id="planProfGrp"'), 'the panel still has its Height profile group');
  assert.ok(twig.includes('id="planStrip"'), 'the strip is gone too');
});

test('a new ride starts with its name, then a town or street to start from, then the first click (owner 2026-10-15)', () => {
  const panel = twig.slice(twig.indexOf('id="p-plan"'), twig.indexOf('id="planBikes"'));
  assert.ok(panel.includes('id="planRideName"'), 'the name is not the first thing in the panel');
  assert.ok(panel.includes('id="planStartSearch"') && panel.includes('id="planStartResults"'), 'no start search above the bikes');
  assert.match(plan, /if\(!ride\.stops\.length && !\(ride\.name \|\| ''\)\.trim\(\)\)\{ askName\(\); return; \}/, 'the first stop does not wait for a name');
  assert.match(plan, /photon\.komoot\.io\/api\/\?limit=6&layer=city&layer=district&layer=locality&layer=street/, 'the start search does not take towns and streets');
  assert.match(plan, /map\.flyTo\(\{ center: \[r\.lng, r\.lat\], zoom: r\.street \? 16 : 13 \}\)/, 'a picked place does not take the map there');
});

test('a closed profile strip folds into a tab on the map that opens it again (owner 2026-10-15)', () => {
  assert.match(twig, /<button type="button" class="cc-plan-striptab" id="planStripTab" hidden>/);
  assert.match(plan, /const tab = \$\('planStripTab'\); if\(tab\) tab\.hidden = !stripClosed;/);
  assert.match(plan, /\['planStripOpen', 'planStripTab'\]\.forEach\(id => \{ const b = \$\(id\); if\(b\) b\.addEventListener\('click', \(\) => \{ stripClosed = false; renderProfile\(\); \}\); \}\);/);
});

test('the ride reads the surface lines on the map where they are drawn, and the last stop is a finish flag (owner 2026-10-15)', () => {
  assert.match(plan, /map\.queryRenderedFeatures\(\[\[pt\.x - 4, pt\.y - 4\], \[pt\.x \+ 4, pt\.y \+ 4\]\], \{ layers: surfLayers \}\)/, 'the ride does not read the drawn surface lines');
  assert.match(plan, /mergeSurfaces\(leg\.surfaces, tileMetres\(leg\), leg\.distM\)/);
  assert.match(plan, /el\.innerHTML = FINISH_FLAG;/, 'the map marker of the last stop is not the finish flag');
  assert.ok(!/: 'E'\)/.test(plan), 'an E is still written for the last stop');
});

test('the legend and the height strip fold with an animation, and not for riders who ask for less motion (owner 2026-10-15)', () => {
  const css = read('assets/styles/map.css');
  assert.match(css, /\.legend \.lg-body\{overflow:hidden;max-height:80vh;max-width:17\.5rem;transition:max-height \.28s ease,max-width \.28s ease,opacity \.2s ease\}/);
  assert.match(css, /\.legend\.collapsed \.lg-body\{max-height:0;max-width:0;opacity:0;visibility:hidden\}/);
  assert.ok(!/\.legend\.collapsed \.lg-body\{display:none\}/.test(css), 'the legend still snaps shut');
  assert.match(css, /\.cc-plan-strip\{[^}]*transition:transform \.3s ease/);
  assert.match(css, /\.cc-plan-strip\.folded\{transform:translateY\(100%\)\}/);
  assert.match(css, /@media \(prefers-reduced-motion: reduce\)\{[^}]*\.cc-plan-strip,\.legend,\.legend \.lg-body/);
  assert.match(plan, /function showStrip\(on\)\{/);
});

test('a map point stop shows its street, and the profile hover says the street there (owner 2026-10-15)', () => {
  assert.match(plan, /const street = s\.item \? null : stopStreet\(ride\.legs, i\);/);
  assert.match(plan, /streetAt\(ride\.legs, d\)/, 'the profile hover does not name the street');
  assert.match(plan, /streets: d\.streets \|\| null/, 'the leg does not keep its streets');
});

test('the batch of 2026-10-15 afternoon: road kind on hover, fit on open, stops folded, one save, bars by time or distance', () => {
  assert.match(plan, /const st = streetAt\(ride\.legs, d\) \|\| roadLabel\(roadAt\(ride\.legs, d\)\);/, 'an unnamed road says nothing on hover');
  assert.match(plan, /if\(key === 'plan' && ride\.stops\.length >= 2\) fitRide\(\);/, 'opening the planner does not take the map to the ride');
  assert.match(twig, /id="planStopsToggle" aria-expanded="false"/, 'the stops are not folded by default');
  assert.ok(!twig.includes('id="planSaveRow"') && !twig.includes('id="planName"'), 'the second save button and its name field are still there');
  assert.match(plan, /const name = \(ride\.name \|\| ''\)\.trim\(\); if\(!name\)\{ askName\(\); return; \}/, 'save does not use the name at the top');
  assert.match(twig, /id="planDayUnit"/, 'no time or distance switch for the bars');
  const css = read('assets/styles/map.css');
  assert.match(css, /\.cc-plan-daylegend\{[^}]*font-size:\.74rem/, 'the bar legend is still SVG text too small to read');
});

test('the folded stops are a block of their own colour that opens on a click (owner 2026-10-15)', () => {
  const css = read('assets/styles/map.css');
  assert.match(twig, /<button type="button" class="cc-plan-stopsum" id="planStopsSum" aria-controls="planStops" hidden>/);
  assert.match(css, /\.cc-plan-stopsum\{[^}]*background:rgb\(var\(--chrome-fg\) \/ \.08\);[^}]*border:1px solid rgb\(var\(--chrome-fg\) \/ \.18\)/);
  assert.match(css, /\.cc-plan-stops\{[^}]*background:rgb\(var\(--chrome-fg\) \/ \.05\)/, 'the open list is not the same block');
  assert.match(plan, /const sm = \$\('planStopsSum'\); if\(sm\) sm\.addEventListener\('click', \(\) => \{ stopsOpen = true; renderStops\(\); \}\);/);
});

test('the legend folds without rewrapping: its content keeps one width, the fold only clips it (owner 2026-10-15)', () => {
  const css = read('assets/styles/map.css');
  assert.match(css, /\.legend \.lg-body > \*\{width:min\(15\.7rem, calc\(44vw - 1\.8rem\)\)\}/);
  assert.match(css, /\.legend \.lg-body > \*\{width:min\(15\.7rem, calc\(72vw - 1\.8rem\)\)\}/, 'the phone legend rewraps');
});

test('the bike explanation sits behind an info icon beside Your bike: hover shows it, a click keeps it open, the next closes it (owner 2026-10-15)', () => {
  const css = read('assets/styles/map.css');
  assert.match(twig, /<h3>\{\{ 'map\.plan_bike_h'\|trans \}\} <span class="cc-info-wrap" id="planWhyWrap"><button type="button" class="cc-info" id="planWhyBtn" aria-expanded="false" aria-controls="planWhy"/);
  assert.ok(!twig.includes('class="hint cc-plan-why" id="planWhy"'), 'the explanation still sits as a paragraph under the chips');
  assert.match(css, /\.cc-info-wrap:hover \.cc-info-pop,\.cc-info-wrap:focus-within \.cc-info-pop,\.cc-info-wrap\.open \.cc-info-pop\{display:block\}/);
  assert.match(plan, /const open = !w\.classList\.contains\('open'\); w\.classList\.toggle\('open', open\); b\.setAttribute\('aria-expanded', String\(open\)\);/);
});

test('the info bubble stays inside the drawer: it hangs from the heading, not from the icon (owner 2026-10-15)', () => {
  const css = read('assets/styles/map.css');
  assert.match(css, /\.cc-plan h3:has\(\.cc-info-wrap\)\{position:relative\}/);
  assert.match(css, /\.cc-info-wrap\{position:static;/);
  assert.match(css, /\.cc-info-pop\{display:none;position:absolute;left:0;right:0;top:calc\(100% \+ \.35rem\);/);
});

test('From your settings sits in the bubble, and the bike list has a lasting show-all / show-fewer switch (owner 2026-10-15)', () => {
  const pop = twig.slice(twig.indexOf('id="planWhy"'), twig.indexOf('</span></span></h3>', twig.indexOf('id="planWhy"')));
  assert.ok(pop.includes("'map.plan_bike_settings'|trans") && pop.includes('id="planWhyText"'), 'From your settings is not in the bubble');
  const hint = twig.slice(twig.indexOf('id="planBikeHint"'), twig.indexOf('</div>', twig.indexOf('id="planBikeHint"')));
  assert.ok(!hint.includes("'map.plan_bike_settings'|trans"), 'From your settings still sits under the chips');
  assert.match(plan, /function toggleAllBikes\(\)\{/);
  assert.ok(!/all\.remove\(\)/.test(plan), 'the switch still disappears after one use');
  assert.match(plan, /all\.textContent = allBikes \? \(P\(\)\.bikeFewer \|\| 'Show fewer bike types'\) : \(P\(\)\.bikeAll \|\| 'Show every bike type'\);/);
});


test('The along-the-line hover rings the pin body, not its tip, and the ring is easy to see (owner 2026-10-15)', () => {
  assert.match(plan, /import \{ pinElFor \} from '\.\/pin-fan\.js';/);
  assert.match(plan, /highlightAt\(\[lat, lng\], pinElFor\(row\.dataset\.planKey\) \? \[0, -16\] : \[0, 0\], row\.dataset\.planKey\)/);
  const css = fs.readFileSync(path.join(__dirname, '../../assets/styles/map.css'), 'utf8');
  const rule = css.slice(css.indexOf('.cc-highlight{'), css.indexOf('}', css.indexOf('.cc-highlight{')));
  assert.match(rule, /width:30px;height:30px/, 'the ring is still the small one');
  assert.match(rule, /inset 0 0 0 3px/, 'the ring line is still thin');
});
