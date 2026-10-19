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

test('the map page hands the script its endpoints and tokens, and nobody a save endpoint', () => {
  assert.ok(twig.includes('window.CC_PLAN ='), 'no CC_PLAN blob in the map template');
  for (const needle of ["path('map_plan_leg')", "csrf_token('plan-leg')", "path('map_plan_along')", "csrf_token('plan-along')"]) {
    assert.ok(twig.includes(needle), `the CC_PLAN blob lacks ${needle}`);
  }
  const start = twig.indexOf('window.CC_PLAN =');
  const blob = twig.slice(start, twig.indexOf('</script>', start));
  // Nothing of a ride is kept on our server (route-planner.md section 6, owner 2026-10-15).
  for (const gone of ['map_plan_save', 'map_plan_ride', 'account_rides', 'plan-save']) {
    assert.ok(!blob.includes(gone), `the CC_PLAN blob still carries ${gone}`);
  }
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


test('the day: the time tile, the time bar, the date and what makes it tough, with the forecast through our server', () => {
  for (const id of ['planTime', 'planTimeSub', 'planDayFx', 'planDaySvg', 'planDate', 'planTougher']) assert.ok(twig.includes(`id="${id}"`), `no ${id} in the map template`);
  assert.ok(/rideTime\(dayLegs\(\), ride\.bike\)/.test(plan), 'the figures do not run the day\'s model');
  for (const needle of ['steepestKm(', 'longestClimb(', 'waterGap(', 'sunTimes(', 'windShares(']) assert.ok(plan.includes(needle), `plan.js does not use ${needle}`);
  assert.ok(plan.includes('CC_PLAN.weather'), 'the forecast is not asked through our server');
  assert.ok(!/api\.open-meteo|fetch\(['"`]https?:/i.test(plan + model), 'the browser reaches the forecast service itself: it must only ever talk to our server');
  // open-meteo.com appears once, as the page the rider opens from the credit, never fetched.
  assert.deepEqual((plan + model).match(/open-meteo\.com[^'"`]*/gi), ['open-meteo.com/en/docs?'], 'open-meteo.com is named anywhere but the credit\'s link');
  assert.match(plan, /<a href="\$\{escPend\(href\)\}" target="_blank" rel="noopener noreferrer">Open-Meteo<\/a>/, 'the credit does not link Open-Meteo in a new tab');
  // Every planner duration reads through durationText(): the riding time, the parts and the daylight.
  assert.match(plan, /tpl\(P\(\)\.daylight \|\| 'Daylight \{d\}, \{from\} to \{to\}', \{ d: durationText\(x\.sun\.daylightS, durWords\(\), 1\)/, 'the daylight has a duration format of its own');
  assert.ok(!/String\(m\)\.padStart|\{h\} h \{m\}/.test(plan), 'a duration is still written "h mm"');
  for (const l of ['en', 'fr', 'nl', 'de', 'es']) assert.match(read(`translations/messages.${l}.yaml`), /^ {2}plan_daylight: ".*\{d\}.*"$/m, `messages.${l}.yaml writes the daylight in hours and minutes of its own`);
  for (const needle of ["path('map_plan_weather')", "csrf_token('plan-weather')"]) assert.ok(twig.includes(needle), `the CC_PLAN blob lacks ${needle}`);
  assert.ok(!twig.includes('id="planTough"') && !twig.includes('planToughFx'), 'the 1 to 5 level is gone from the template');
});

test('the controller hands every planner string to the script', () => {
  assert.ok(controller.includes("'railPlan' => $t->trans('map.rail_plan')"), 'the rail title is not translated');
  for (const key of ['plan_no_road', 'plan_max_stops', 'plan_already', 'plan_rate_limited', 'plan_engine_down', 'plan_opened', 'plan_open_not_gpx', 'plan_converted', 'plan_open_no_stops', 'plan_open_too_many', 'plan_bar_stops']) {
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
  const planAt = lift.indexOf("['plan-gap','plan-alt','plan-casing','plan-line','plan-straight','plan-hl-casing','plan-hl','plan-rain','plan-wind','plan-arrows']");
  const iconsAt = lift.indexOf('coverageIconLayerIds(');
  assert.ok(planAt > 0, 'the stacking authority does not lift the ride layers');
  assert.ok(lift.indexOf("['mly-cov','mly-img']") < planAt && planAt < iconsAt, 'the ride layers go above the lines and below the coverage icons');
  assert.ok(/map\.addLayer\(\{ id: 'plan-straight'[\s\S]*?liftInfoLayersAboveRoutes\(\);/.test(plan), 'plan.js does not lift its layers once added');
});

test('with the profile strip open, the surface legend sits just above it, not a bug-button gap higher (owner 2026-10-15)', () => {
  const css = read('assets/styles/map.css');
  assert.match(css, /\.map-wrap\.cc-strip-on \.legend\{bottom:calc\(140px \+ 3\.4rem\)\}/, 'the legend does not clear the credits line on the strip');
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
  assert.match(plan, /\['plan-casing', 'plan-line', 'plan-straight', 'plan-arrows'\]\.forEach\(id => \{ if\(map\.getLayer\(id\)\) map\.setLayoutProperty\(id, 'visibility', lineShown \? 'visible' : 'none'\); \}\);/);
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
  assert.match(plan, /if\(!ride\.stops\.length && !\(ride\.name \|\| ''\)\.trim\(\)\)\{ askName\(\); return false; \}/, 'the first stop does not wait for a name');
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

test('the legend and the height strip fold with an animation, a short soft one for riders who ask for less motion (owner 2026-10-18)', () => {
  const css = read('assets/styles/map.css');
  assert.match(css, /\.legend \.lg-body\{overflow:hidden;max-height:80vh;max-width:17\.5rem;transition:max-height \.28s ease,max-width \.28s ease,opacity \.2s ease\}/);
  assert.match(css, /\.legend\.collapsed \.lg-body\{max-height:0;max-width:0;opacity:0;visibility:hidden\}/);
  assert.ok(!/\.legend\.collapsed \.lg-body\{display:none\}/.test(css), 'the legend still snaps shut');
  assert.match(css, /\.cc-plan-strip\{[^}]*transition:transform \.3s ease/);
  assert.match(css, /\.cc-plan-strip\.folded\{transform:translateY\(100%\)\}/);
  // Less motion is a 0.12 s change over the page-wide stop, never a jump.
  assert.match(css, /@media \(prefers-reduced-motion: reduce\)\{\n    \.cc-plan-strip\{transition:transform \.12s ease!important\}/);
  assert.match(css, /\.map-wrap \.legend\{transition:bottom \.12s ease,padding \.12s ease!important\}/);
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
  assert.match(plan, /a\.download = \(ride\.name \|\| 'Ride'\) \+ '\.gpx';/, 'the file is not named after the name at the top');
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

/* Ctrl+Z did nothing after a stop was dragged (owner 2026-10-15): a drag or a
   right-click on the map leaves the keyboard where it was, so with the ride's
   name field still focused the key undid the field's text, not the ride. */
test('a change to the stops takes the keyboard off a text field, so Ctrl+Z undoes the ride', () => {
  const m = plan.match(/function remember\(whole\)\{([\s\S]*?)\n\}/);
  assert.ok(m, 'remember() not found');
  assert.match(m[1], /const a = document\.activeElement; if\(a && a\.blur && a\.matches && a\.matches\('input, textarea, select, \[contenteditable\]'\)\) a\.blur\(\);/);
  assert.ok(m[1].indexOf('a.blur()') < m[1].indexOf('history.push(step(whole))'), 'the field lets go before the copy is kept');
});

test('redo: Ctrl+Shift+Z, the command key with shift and Z on a Mac, or Ctrl+Y; never inside a field (owner 2026-10-15)', () => {
  const m = plan.match(/document\.addEventListener\('keydown', e => \{\n {4}if\(!\(e\.ctrlKey \|\| e\.metaKey\) \|\| e\.altKey\) return;([\s\S]*?)\n {2}\}\);/);
  assert.ok(m, 'the undo and redo key handler was not found');
  assert.match(m[1], /const isUndo = k === 'z' && !e\.shiftKey, isRedo = \(k === 'z' && e\.shiftKey\) \|\| \(k === 'y' && !e\.shiftKey && !e\.metaKey\);/);
  assert.match(m[1], /closest\('input, textarea, select, \[contenteditable\]'\)\) return;/);
  assert.match(m[1], /if\(isRedo\) redo\(\); else undo\(\);/);
  assert.match(plan, /const history = createHistory\(30, 5\);/, 'redo is capped at five steps');
  assert.match(plan, /function redo\(\)\{ const next = history\.redo\(step\(true\)\); if\(next\) applyStep\(next\); \}/);
});

test('the draft and its undo and redo steps survive a closed tab for seven days (owner 2026-10-15)', () => {
  assert.match(plan, /const HISTORY_KEY = 'cc-plan-history';/);
  const save = (plan.match(/function saveDraft\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(save, /at: Date\.now\(\)/, 'the draft carries the time of the last change');
  assert.match(save, /localStorage\.setItem\(HISTORY_KEY, JSON\.stringify\(Object\.assign\(\{ at: Date\.now\(\) \}, history\.state\(\)\)\)\)/);
  const load = (plan.match(/function loadDraft\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(load, /workFresh\(d\.at, Date\.now\(\)\)/, 'a draft older than seven days is dropped');
  const hist = (plan.match(/function loadHistory\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(hist, /workFresh\(h\.at, Date\.now\(\)\)/, 'undo steps older than seven days are dropped');
  // Read before the draft is restored: restoring saves, and an empty history would overwrite the kept one.
  assert.ok(plan.indexOf('const keptHistory = loadHistory();') < plan.indexOf('const d = draft;'));
  assert.match(plan, /if\(keptHistory\)\{ history\.restore\(keptHistory\); saveDraft\(\); \}/);
});

/* A ride is kept as a file the rider downloads and opens again; nothing of it
   is kept on our server (owner 2026-10-15, route-planner.md section 6.1). */
test('the GPX is the ride file: Download GPX writes it, Open a GPX reads it back in the browser', () => {
  assert.match(twig, /<button type="button" class="cc-ride-btn cc-plan-go" id="planGpx">/);
  assert.match(twig, /<button type="button" class="cc-ride-btn" id="planOpen">\{\{ 'map\.plan_open'\|trans \}\}<\/button>/);
  assert.match(twig, /<input type="file" id="planOpenFile" accept="\.gpx,application\/gpx\+xml" hidden>/);
  assert.match(read('translations/messages.en.yaml'), /^ {2}plan_guide_privacy: "We keep no copy of your ride\./m, 'the guide does not say we keep no copy');
  assert.ok(!twig.includes('id="planSave"') && !twig.includes('plan_save_login'), 'a save to the account is still offered');
  assert.match(plan, /gpxText\(\{ name: ride\.name, bike: ride\.bike, stops: ride\.stops, legs: ride\.legs \}/, 'the file does not carry the bike');
  const open = (plan.match(/function openRideFile\(file\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(open, /const r = rideFromGpx\(String\(reader\.result \|\| ''\)\);/, 'the file is not read with the one parser');
  assert.match(open, /reader\.readAsText\(file\);/, 'the file is read in the browser');
  assert.ok(!/fetch\(/.test(open), 'opening a file sends it somewhere');
  // What a file puts on the map, after the question when a ride is being planned (plan-open-replace.test.cjs).
  const applied = (plan.match(/function applyRideFile\(r, fileName\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.ok(applied.indexOf("remember(true);") >= 0 && applied.indexOf("remember(true);") < applied.indexOf("ride.stops = r.stops"), "opening is not kept for undo");
  assert.match(applied, /if\(r\.bike && BIKES\.includes\(r\.bike\)\)/, 'an unknown bike from a file reaches the engine');
  assert.ok(!/beforeunload/.test(plan), 'leaving the page still warns about a ride the browser keeps');
  assert.ok(!/get\('ride'\)|openSaved|CC_PLAN\.save/.test(plan), 'a saved ride by its link is still read');
});

/* Opening a file, or clearing the ride, changes its name and bike too; undo
   gave back the stops and kept the file's name and bike (owner 2026-10-15).
   Those two keep the whole ride; a stop change keeps the stops alone, so undo
   never takes back a bike the rider picked. */
test('opening a file and clearing the ride are undone whole: stops, name and bike', () => {
  assert.match(plan, /function step\(whole\)\{ return whole \? \{ stops: ride\.stops, name: ride\.name, bike: ride\.bike, whole: true \} : \{ stops: ride\.stops \}; \}/);
  const apply = (plan.match(/function applyStep\(st\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(apply, /if\(Array\.isArray\(st\)\) st = \{ stops: st \};/, 'a step kept as a bare list (before the whole steps) is still read');
  assert.match(apply, /if\(st\.whole\)\{/, 'name and bike come back only from a whole step');
  assert.match(apply, /BIKES\.includes\(st\.bike\)/, 'an unknown bike from storage reaches the engine');
  assert.match(plan, /function undo\(\)\{ const prev = history\.undo\(step\(true\)\); if\(prev\) applyStep\(prev\); \}/);
  const open = (plan.match(/function applyRideFile\(r, fileName\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(open, /remember\(true\);/, 'opening a file is not kept whole');
  const clear = (plan.match(/function clearRide\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(clear, /remember\(true\);/, 'clearing is not kept whole');
});

/* The ways to plan with the mouse and the keys sit in one info box beside
   Stops, as the bike's explanation does, not as three lines under the list
   (owner 2026-10-15). A click on the map adds a stop; a right-click on a stop
   removes it; a click on a place opens it, with Add to ride; a right-click on
   a place adds it at once. */
test('the stops\' info box says how to plan with the mouse and the keys', () => {
  const h3 = (twig.match(/<h3>\{\{ 'map\.plan_stops_h'\|trans \}\}[\s\S]*?<\/h3>/) || [''])[0];
  assert.match(h3, /<span class="cc-info-wrap" id="planHelpWrap"><button type="button" class="cc-info" id="planHelpBtn" aria-expanded="false" aria-controls="planHelp" aria-label="\{\{ 'map\.plan_help_aria'\|trans \}\}">i<\/button><span class="cc-info-pop cc-info-list" id="planHelp" role="tooltip">/);
  for (const key of ['plan_help_click', 'plan_help_remove', 'plan_help_place', 'plan_help_undo', 'plan_help_mac']) {
    assert.ok(h3.includes(`<span>{{ 'map.${key}'|trans }}</span>`) || h3.includes(`<span>{{ 'map.${key}'|trans|rich }}</span>`), `the info box lacks ${key}`);
    for (const l of ['en', 'fr', 'nl', 'de', 'es']) {
      assert.match(read(`translations/messages.${l}.yaml`), new RegExp(`^ {2}${key}: "`, 'm'), `messages.${l}.yaml lacks map.${key}`);
    }
  }
  assert.ok(!/class="hint cc-plan-undo"/.test(twig), 'the three hint lines are still under the stops');
  for (const gone of ['plan_undo_hint', 'plan_place_hint']) assert.ok(!twig.includes(gone), `${gone} is still on the page`);
  assert.match(plan, /document\.querySelectorAll\('#p-plan \.cc-info-wrap, #planSide \.cc-info-wrap'\)\.forEach\(w => \{/, 'one binding for every info box of the panel and of the side');
  const en = read('translations/messages.en.yaml');
  assert.match(en, /^ {2}plan_help_click: "Click the map to add a checkpoint\."/m);
  assert.match(en, /^ {2}plan_help_remove: "Right-click a checkpoint to remove it\."/m);
});

/* Fill the water gap in one click (owner 2026-10-15): the water-gap line
   offers the water stop nearest the middle of the stretch, from our own data
   (/map/coverage/nearby), in at its km along like Add to ride. */
test('the water-gap line adds the nearest water stop in one click', () => {
  assert.match(twig, /nearby: path\('map_coverage_nearby'\),/, 'the planner is not told where to ask');
  const render = (plan.match(/function renderTougher\(day, f\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(render, /data-plan-fill-water/, 'the water-gap line has no button');
  assert.match(render, /waterGap\(nearRows\(\)\.concat\(stopRows\(ride\.stops, ride\.legs\)\), f\.distM\)/, 'a water stop in the ride does not split the gap, and water past the 250 m corridor is not along it');
  const fill = (plan.match(/async function fillWater\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(fill, /waterGapSpan\(nearRows\(\)\.concat\(stopRows\(ride\.stops, ride\.legs\)\), f\.distM\)/, 'the middle is not the middle of the longest stretch');
  assert.match(fill, /fetch\(`\$\{CC_PLAN\.nearby\}\?lat=\$\{mid\[1\]\}&lng=\$\{mid\[0\]\}&km=\$\{FILL_WATER_KM\}`/);
  // Measured to the dry stretch and put in where it lies (owner 2026-10-15: the stop went into another leg and the gap stayed).
  assert.match(fill, /for\(let d = fromM; d <= toM; d \+= 200\)\{ const p = pointAt\(ride\.legs, d\); if\(p\) points\.push\(\{ lat: p\[1\], lng: p\[0\], alongM: d \}\); \}/);
  assert.match(fill, /const stop = bestWaterStop\(d\.groups, points, fromM, toM\);/, 'the place that leaves the shortest dry stretch is not the one chosen');
  assert.match(fill, /const at = stopIndexAt\(ride\.legs, stop\.alongM\);/, 'the stop does not go where the dry stretch is');
  assert.match(fill, /if\(stop\.leftM >= toM - fromM\)\{ notice\(P\(\)\.fillWaterH \|\| 'No water stop added', P\(\)\.fillWaterUseless/, 'a place that would not shorten the dry stretch is still added');
  assert.match(fill, /if\(ride\.stops\.some\(s => s\.item === place\.item\)\)/, 'a water stop already in the ride is added twice');
  assert.match(fill, /if\(!addStop\(place, at\)\) return;/);
  assert.match(plan, /const FILL_WATER_KM = 5;/);
  for (const key of ['plan_fill_water', 'plan_fill_water_none', 'plan_fill_water_name', 'plan_fill_water_useless']) {
    assert.ok(controller.includes(`'map.${key}'`), `the controller does not translate map.${key}`);
    for (const l of ['en', 'fr', 'nl', 'de', 'es']) assert.match(read(`translations/messages.${l}.yaml`), new RegExp(`^ {2}${key}: "`, 'm'), `messages.${l}.yaml lacks ${key}`);
  }
});

/* Why this way? (owner 2026-10-15, route-planner.md section 4.2a): a leg that
   clearly goes round asks the shortest way of the same bike once, on a click,
   and says in one line why it goes round. */
test('a leg that goes round answers "Why this way?" on a click, asking once', () => {
  assert.match(plan, /async function routeLeg\(a, b, bike, shortest\)\{/);
  assert.match(plan, /body: JSON\.stringify\(\{ a: \[a\.lng, a\.lat\], b: \[b\.lng, b\.lat\], bike, \.\.\.\(shortest \? \{ shortest: true \} : \{\}\) \}\)/, 'the comparison does not ask for the shortest way');
  const stops = (plan.match(/function renderStops\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(stops, /legGoesRound\(leg, ride\.stops\[i\], ride\.stops\[i \+ 1\]\)/, 'every leg offers the question');
  assert.match(stops, /data-why="\$\{i\}"/);
  const explain = (plan.match(/async function explainLeg\(i\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(explain, /const alt = await routeLeg\(a, b, ride\.bike, true\);/);
  assert.match(explain, /whyThisWay\(cur, alt\)/);
  assert.match(explain, /whyCache\.set\(key, /, 'the answer is not kept per leg');
  assert.match(plan, /const why = e\.target\.closest\('\[data-why\]'\); if\(why\)\{ explainLeg\(Number\(why\.dataset\.why\)\); return; \}/);
  for (const key of ['plan_way_ask', 'plan_way_asking', 'plan_way_shortest', 'plan_way_surface', 'plan_way_climb', 'plan_way_busy', 'plan_way_other', 'plan_way_failed']) {
    assert.ok(controller.includes(`'map.${key}'`), `the controller does not translate map.${key}`);
    for (const l of ['en', 'fr', 'nl', 'de', 'es']) assert.match(read(`translations/messages.${l}.yaml`), new RegExp(`^ {2}${key}: "`, 'm'), `messages.${l}.yaml lacks ${key}`);
  }
});

/* The batch of 2026-10-15 late evening (owner): the water button an icon, the
   gap shown on the map on hover, the seven days said in the panel, "Why this
   way?" findable with the stops folded, and arrows along the ride. */
test('the water-gap line: an icon button, and its stretch on the map on hover', () => {
  const render = (plan.match(/function renderTougher\(day, f\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(render, /class="cc-info cc-plan-fillwater" data-plan-fill-water title="\$\{escPend\(label\)\}" aria-label="\$\{escPend\(label\)\}"><svg/, 'the button is not an icon with its words as title and name');
  assert.match(render, /<li class="cc-plan-gap" tabindex="0" data-part="water">/, 'the gap line cannot be hovered or focused');
  assert.match(plan, /map\.addSource\('plan-gap',/);
  assert.match(plan, /map\.addLayer\(\{ id: 'plan-gap', type: 'line', source: 'plan-gap'/);
  assert.match(plan, /function showGap\(on\)\{/);
  assert.match(plan, /sliceAlong\(ride\.legs, span\.fromKm \* 1000, span\.toKm \* 1000\)/);
  for (const ev of ['mouseover', 'focusin', 'mouseout', 'focusout']) assert.ok(plan.includes(`t.addEventListener('${ev}'`), `the gap line does not answer ${ev}`);
});

test('the panel says the ride is kept seven days in this browser, with its history', () => {
  // Said once, in the guide (owner 2026-10-17), not again in the panel.
  assert.ok(!twig.includes('plan_keep_hint') && !twig.includes('plan_keep_days'), 'the keep line is said twice');
  assert.match(read('translations/messages.en.yaml'), /^ {2}plan_guide_privacy: "We keep no copy of your ride\. Our server only handles the requests that draw it[^"]*Your ride stays in this browser for 7 days after your last change\. Use <i>Download GPX<\/i> to keep it\."/m);
  // On its own at the top of the guide, before the steps (owner 2026-10-17).
  assert.ok(twig.indexOf("'map.plan_guide_privacy'|trans|rich") < twig.indexOf("'map.plan_guide_1'|trans|rich"), 'privacy is not first');
});

test('with the stops folded, a line says how many legs go round and opens the first answer', () => {
  assert.match(twig, /<button type="button" class="cc-plan-roundsum" id="planRoundSum" hidden><\/button>/);
  const stops = (plan.match(/function renderStops\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(stops, /const round = ride\.legs\.map\(\(leg, i\) => i < n - 1 && legGoesRound\(leg, ride\.stops\[i\], ride\.stops\[i \+ 1\]\) \? i : -1\)\.filter\(i => i >= 0\);/);
  assert.match(plan, /const rs = \$\('planRoundSum'\); if\(rs\) rs\.addEventListener\('click', \(\) => \{ stopsOpen = true; renderStops\(\); const first = Number\(rs\.dataset\.first\); if\(Number\.isFinite\(first\)\) explainLeg\(first\); \}\);/);
  for (const key of ['plan_way_round_one', 'plan_way_round_many']) {
    assert.ok(controller.includes(`'map.${key}'`), `the controller does not translate map.${key}`);
    for (const l of ['en', 'fr', 'nl', 'de', 'es']) assert.match(read(`translations/messages.${l}.yaml`), new RegExp(`^ {2}${key}: "`, 'm'));
  }
});

test('arrows along the ride line show the way it is ridden', () => {
  assert.match(plan, /map\.addLayer\(\{ id: 'plan-arrows', type: 'symbol', source: 'plan', minzoom: 11,/);
  assert.match(plan, /'symbol-placement': 'line'/);
  assert.match(plan, /'icon-image': 'plan-arrow'/);
  assert.match(plan, /function addArrowImage\(\)\{/);
  assert.match(plan, /\['plan-casing', 'plan-line', 'plan-straight', 'plan-arrows'\]\.forEach\(id => \{ if\(map\.getLayer\(id\)\) map\.setLayoutProperty\(id, 'visibility'/, 'the eye does not hide the arrows with the line');
  const render = read('assets/map/render.js');
  assert.match(render, /\['plan-gap','plan-alt','plan-casing','plan-line','plan-straight','plan-hl-casing','plan-hl','plan-rain','plan-wind','plan-arrows'\]\.forEach/, 'the arrows, the gap and the shorter way do not keep their place over the other lines');
});

/* See the shorter way and choose it (owner 2026-10-15): hovering the answer
   draws it, Show on map keeps it and frames it, Ride the shortest way makes
   the leg take it, and the leg row offers the way back. */
test('the shorter way is shown on the map and can be ridden, and undone', () => {
  assert.match(plan, /map\.addSource\('plan-alt',/);
  assert.match(plan, /map\.addLayer\(\{ id: 'plan-alt', type: 'line', source: 'plan-alt'[^\n]*'line-dasharray'/);
  assert.match(plan, /routeLeg\(ride\.stops\[i\], ride\.stops\[i \+ 1\], ride\.bike, ride\.stops\[i \+ 1\]\.way === 'shortest'\)/, 'a leg set to the shortest way is not routed so');
  const explain = (plan.match(/async function explainLeg\(i\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(explain, /whyCache\.set\(key, \{ text: wayText\(w\), alt: w\.kind === 'shortest' \? null : alt \}\);/, 'the shorter line is not kept with the answer');
  const stops = (plan.match(/function renderStops\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  for (const a of ['data-way-show', 'data-way-take', 'data-way-back', 'data-way-leg']) assert.ok(stops.includes(a), `the stops list lacks ${a}`);
  const take = (plan.match(/function takeShortest\(i\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.ok(take.indexOf('remember();') < take.indexOf("way: 'shortest'"), 'taking the shorter way is not undoable');
  assert.match(take, /legCache\.set\(/, 'the line already fetched is asked for again');
  const back = (plan.match(/function backToBikeWay\(i\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.ok(back.indexOf('remember();') < back.indexOf('delete s.way;'));
  assert.match(plan, /function paintAlt\(key\)\{/);
  for (const key of ['plan_way_show', 'plan_way_hide', 'plan_way_take', 'plan_way_taken', 'plan_way_back']) {
    assert.ok(controller.includes(`'map.${key}'`), `the controller does not translate map.${key}`);
    for (const l of ['en', 'fr', 'nl', 'de', 'es']) assert.match(read(`translations/messages.${l}.yaml`), new RegExp(`^ {2}${key}: "`, 'm'));
  }
});

/* The planner's guide (owner 2026-10-15, route-planner.md section 3.1): shown
   when the planner opens, until the rider unticks "Show this guide"; the
   choice is kept on the account (a closed map hint) and in the browser, and
   How it works opens it again at any time. */
test('the guide opens with the planner, remembers the rider\'s choice, and opens again on demand', () => {
  assert.match(twig, /<div class="cc-plan-guide" id="planGuide" role="dialog" aria-modal="false" aria-labelledby="planGuideH" hidden>/);
  const mapWrap = twig.slice(twig.indexOf('<main class="map-wrap">'));
  assert.ok(mapWrap.includes('id="planGuide"'), 'the guide is not on the map, in its middle (owner 2026-10-15)');
  assert.match(read('assets/styles/map.css'), /\.cc-plan-guide\{position:absolute;left:50%;top:50%;transform:translate\(-50%,-50%\)/);
  assert.match(twig, /<button type="button" class="dl-all cc-head-plan" id="planGuideOpen">\{\{ 'map\.plan_guide_open'\|trans \}\}<\/button>/);
  const head = twig.slice(twig.indexOf('<div class="dwr-head">'), twig.indexOf('id="dwr-x"'));
  assert.ok(head.includes('id="planGuideOpen"'), 'How it works is not in the title row (owner 2026-10-17)');
  assert.match(twig, /<input type="checkbox" id="planGuideShow" checked>/);
  for (const n of [1, 2, 3, 4, 5, 6, 7, 8, 9]) assert.ok(twig.includes(`{{ 'map.plan_guide_${n}'|trans }}`) || twig.includes(`{{ 'map.plan_guide_${n}'|trans|rich }}`), `the guide lacks step ${n}`);
  assert.match(twig, /open: path\('map_hint_open', \{hint: 'HINT'\}\)/, 'the page does not say where to open a hint again');
  assert.match(plan, /const GUIDE_KEY = 'cc-plan-guide';/);
  const off = (plan.match(/function guideOff\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(off, /\(h\.closed \|\| \[\]\)\.includes\('plan_guide'\)/, 'the account\'s choice is not read');
  assert.match(off, /localStorage\.getItem\(GUIDE_KEY\) === 'off'/, 'the browser\'s choice is not read');
  const set = (plan.match(/function setGuideShown\(show\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(set, /\(show \? h\.open : h\.url\)\.replace\('HINT', 'plan_guide'\)/, 'the account is not told');
  assert.match(plan, /if\(key === 'plan' && !guideShownOnce && !guideOff\(\)\)\{ guideShownOnce = true; showGuide\(true\); \}/);
  assert.match(plan, /e\.stopImmediatePropagation\(\); showGuide\(false\); \} \}, true\);/, 'Escape on the guide also closes the planner panel');
  for (const k of ['plan_guide_h', 'plan_guide_1', 'plan_guide_2', 'plan_guide_3', 'plan_guide_4', 'plan_guide_5', 'plan_guide_6', 'plan_guide_7', 'plan_guide_8', 'plan_guide_9', 'plan_guide_privacy_h', 'plan_guide_privacy', 'plan_guide_show', 'plan_guide_close', 'plan_guide_open']) {
    for (const l of ['en', 'fr', 'nl', 'de', 'es']) assert.match(read(`translations/messages.${l}.yaml`), new RegExp(`^ {2}${k}: "`, 'm'), `messages.${l}.yaml lacks ${k}`);
  }
});

/* With every leg already known (a way taken, undo, redo), the planner skipped
   routing and left the stops list and the line one step behind (2026-10-15). */
test('a change whose legs are all known still redraws the line and the stops', () => {
  const all = (plan.match(/async function routeAll\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(all, /if\(!needed\.length\)\{ drawRide\(\); renderStops\(\); if\(await avoidRepeats\(myGen\)\) finishRouting\(\); return; \}/);
});

/* Reaching a water stop off the line can change the route itself, so the dry
   stretch may not shrink (2026-10-15: the way to a bakery 1.4 km off the line
   skipped the tap at km 5). The planner checks once the new route and its
   along list are in: shorter, it says so; not shorter, it takes the stop out. */
test('a water stop that did not make the dry stretch shorter is taken out again, and says why', () => {
  const fill = (plan.match(/async function fillWater\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(fill, /if\(!addStop\(place, at\)\) return;\n\s*pendingFill = \{ beforeKm: span\.km, name: place\.name, item: place\.item, steps: history\.size\(\), stop \};/);
  assert.match(plan, /alongRows = rows; renderFigures\(\);[^\n]*\n\s*if\(pendingFill\) checkFill\(\);/);
  const check = (plan.match(/function checkFill\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(check, /if\(gap != null && gap < p\.beforeKm - 0\.5\)/);
  assert.match(check, /history\.discard\(\)/, 'a stop that did not help stays in the ride');
  assert.match(plan, /function afterChange\(\)\{ pendingFill = null;/, 'a later change by the rider is checked against the old stretch');
  for (const key of ['plan_fill_water_done', 'plan_fill_water_undone']) {
    assert.ok(controller.includes(`'map.${key}'`), `the controller does not translate map.${key}`);
    for (const l of ['en', 'fr', 'nl', 'de', 'es']) assert.match(read(`translations/messages.${l}.yaml`), new RegExp(`^ {2}${key}: "`, 'm'));
  }
});

/* "Checkpoint", not "stop" (owner 2026-10-16: "we zeggen checkpoint", in Dutch too): one word across the planner. */
test('the planner calls a point of the ride a checkpoint, in every language', () => {
  const words = { en: /checkpoint/i, nl: /checkpoint/i, de: /Checkpoint/, fr: /points? de contrôle/i, es: /puntos? de control/i };
  for (const [l, word] of Object.entries(words)) {
    const y = read(`translations/messages.${l}.yaml`);
    for (const key of ['plan_stops_h', 'plan_no_stops', 'plan_add_stop', 'plan_stops_sum', 'plan_bar_stops', 'plan_help_click', 'plan_help_remove', 'plan_guide_5', 'plan_guide_6', 'plan_guide_7']) {
      const line = (y.match(new RegExp(`^ {2}${key}: "([^"]*)"`, 'm')) || [])[1] || '';
      assert.match(line, word, `${l} ${key} does not say checkpoint: ${line}`);
    }
  }
});

/* The height strip covered the map's credits and the zoom buttons (owner 2026-10-16: "legal text missing in
   the planner. Esri, OpenStreetMap"): with the strip open they sit on top of it. */
test('with the height strip open, the map credits and the zoom buttons stay visible above it', () => {
  const css = read('assets/styles/map.css');
  assert.match(css, /\.map-wrap\.cc-strip-on \.maplibregl-ctrl-bottom-right,\.map-wrap\.cc-strip-on \.maplibregl-ctrl-bottom-left\{bottom:140px\}/);
  assert.match(css, /\.map-wrap\.cc-strip-on \.cc-plan-bar\{bottom:calc\(140px \+ 3\.4rem\)\}/);
  const init = read('assets/map/map-init.js');
  assert.match(init, /'© OpenStreetMap contributors · ODbL · Elevation: Copernicus WorldDEM-30 '/, 'the credits line lost OpenStreetMap or the elevation notice');
  assert.match(init, /attribution:'Imagery © Esri, Maxar, Earthstar Geographics'/, 'the satellite map lost its provider');
});

/* Button names in the guide and the hints are in italics, so they read as the button and not as a sentence
   (owner 2026-10-16); the lines go through the sanitising |rich filter, never |raw. */
test('the guide and the hints name their buttons in italics', () => {
  // plan_guide_1 names Open a GPX: a ride can start from a file on the device (owner 2026-10-16).
  for (const k of ['plan_guide_1', 'plan_guide_8', 'plan_guide_privacy', 'plan_help_place']) {
    assert.ok(twig.includes(`{{ 'map.${k}'|trans|rich }}`), `${k} is not rendered through |rich`);
    for (const l of ['en', 'fr', 'nl', 'de', 'es']) {
      const line = (read(`translations/messages.${l}.yaml`).match(new RegExp(`^ {2}${k}: "([^"]*)"`, 'm')) || [])[1] || '';
      assert.match(line, /<i>[^<]+<\/i>/, `${l} ${k} names no button in italics`);
    }
  }
});

/* The along request's line fits what the server takes (owner 2026-10-16, with the 50-checkpoint cap): one
   number on both sides, and the line is thinned to it before it is sent. */
test('the ride line sent for what is along it fits the server\'s point limit', () => {
  const limit = Number((controller.match(/MAX_LINE_POINTS = (\d+);/) || read('src/Controller/PlanController.php').match(/MAX_LINE_POINTS = (\d+);/) || [])[1]);
  assert.equal(Number((model.match(/export const ALONG_MAX_POINTS = (\d+);/) || [])[1]), limit);
  assert.match(plan, /const d = await fetchAlong\(thinLine\(line, ALONG_MAX_POINTS\)\);/);
});

test('a refusal that names the checkpoint cap is filled from MAX_STOPS, never a number in the copy', () => {
  assert.match(plan, /setStatus\(tpl\(refused\[r\.error\] \|\| refused\.not_gpx \|\| 'This file holds no ride we can open\.', \{ n: MAX_STOPS \}\)\)/);
  for (const l of ['en', 'fr', 'nl', 'de', 'es']) {
    const line = (read(`translations/messages.${l}.yaml`).match(/^ {2}plan_open_too_many: "([^"]*)"/m) || [])[1] || '';
    assert.match(line, /\{n\}/, `${l} plan_open_too_many names a number of its own`);
  }
});

/* "Why this way?" says only what it measured (owner 2026-10-16): the cycle paths and the traffic lights have their own
   lines, and a leg with no measured reason says so instead of naming roads that "suit your bike less". */
test('the way text names what was measured, and the plain answer claims nothing', () => {
  assert.match(plan, /if\(w\.kind === 'cycle'\) return tpl\(P\(\)\.wayCycle/);
  assert.match(plan, /if\(w\.kind === 'signals'\) return tpl\(P\(\)\.waySignals/);
  assert.match(controller, /'wayCycle' => \$t->trans\('map\.plan_way_cycle'\)/);
  assert.match(controller, /'waySignals' => \$t->trans\('map\.plan_way_signals'\)/);
  for (const l of ['en', 'fr', 'nl', 'de', 'es']) {
    const y = read(`translations/messages.${l}.yaml`);
    for (const k of ['plan_way_cycle', 'plan_way_signals', 'plan_way_other']) assert.match(y, new RegExp(`^ {2}${k}: "`, 'm'), `${l} ${k}`);
  }
  assert.doesNotMatch(read('translations/messages.en.yaml'), /suit your bike less/);
});

/* A ride outside the map's scope moves it, and the map goes to the ride (owner 2026-10-16: a GPX of North Holland
   opened in Wallonia neither panned nor showed places). */
test('a planned ride outside the scope widens it for as long as it is planned, and the map goes to a file at once', () => {
  assert.match(plan, /import \{ rideScopeFor, scopeKey, scopeCovers \} from '\.\/ride-scope\.js';/);
  const along = (plan.match(/async function renderAlong\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(along, /followRideScope\(d\.regions\);/);
  const follow = (plan.match(/function followRideScope\(regions\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(follow, /if\(scopeCovers\(cur, regions\)\) return;/);
  assert.match(follow, /keepCameraForNextScope\(\);/, 'the scope change keeps the camera on the ride');
  assert.match(follow, /S\.set\(next, \{ persist: false \}\);/, 'never stored as the rider\'s own scope');
  const clear = (plan.match(/function clearRide\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(clear, /restoreRideScope\(\);/);
  const restore = (plan.match(/function restoreRideScope\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(restore, /scopeKey\(S\.get\(\)\) === scopeKey\(scopeSet\)/, 'a scope the rider picked since stays');
  const fit = (plan.match(/function fitRide\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(fit, /ride\.stops\.map\(s => \[s\.lng, s\.lat\]\)/, 'before any leg is routed, the checkpoints frame the ride');
});

/* Owner 2026-10-16: on a converted ride of 50 checkpoints the water fill could not add its stop, yet left its check
   behind; the next map move ran it, and it undid the rider's own last change, then Ctrl+Z took the opened file away.
   The fill now says a full ride is full, and the check takes back only its own stop, without a redo step. */
test('the water fill never takes back anything but its own stop', () => {
  const body = name => (plan.match(new RegExp(`(?:async )?function ${name}\\([^)]*\\)\\{([\\s\\S]*?)\\n\\}`)) || [])[1] || '';
  const add = body('addStop'), fill = body('fillWater'), check = body('checkFill');
  assert.match(add, /return true;\s*$/, 'addStop says whether it added');
  assert.match(fill, /if\(ride\.stops\.length >= MAX_STOPS\)\{ notice\(P\(\)\.fillWaterH \|\| 'No water stop added', tpl\(P\(\)\.fillWaterFull/);
  assert.match(fill, /if\(!addStop\(place, at\)\) return;\s*pendingFill = \{ beforeKm: span\.km, name: place\.name, item: place\.item, steps: history\.size\(\), stop \};/);
  assert.match(check, /if\(!p \|\| history\.size\(\) !== p\.steps \|\| !ride\.stops\.some\(s => s\.item === p\.item\)\) return;/, 'a check whose stop is gone, or not the newest change, does nothing');
  assert.match(check, /const prev = history\.discard\(\); if\(prev\) applyStep\(prev\);/);
  assert.doesNotMatch(check, /undo\(\)/, 'never the rider\'s undo');
  assert.match(controller, /'fillWaterFull' => \$t->trans\('map\.plan_fill_water_full'\)/);
  for (const l of ['en', 'fr', 'nl', 'de', 'es']) assert.match(read(`translations/messages.${l}.yaml`), /^ {2}plan_fill_water_full: "[^"]*\{n\}/m, l);
});

test('the forecast is asked only at forecastPoint: rounded in the browser, never near the start or the finish', () => {
  const fn = plan.slice(plan.indexOf('async function fetchWeather'), plan.indexOf('/* The profile in the panel'));
  assert.ok(fn.includes('forecastPoint(ride.legs)'), 'fetchWeather does not take its point from forecastPoint');
  assert.ok(!/pointAt\(/.test(fn), 'fetchWeather picks a point of its own instead of forecastPoint');
  assert.ok(fn.includes('JSON.stringify({ lat: at[1], lng: at[0], date })'), 'the body does not carry the rounded point');
  assert.ok(/const fc = forecast && forecast\.date === rideDate\(\) && forecastPoint\(ride\.legs\)/.test(plan), 'the panel shows a forecast for a ride that asks none');
});

test('the start search sends Photon a rounded middle of the map, and the scope follows the start picked', () => {
  const fn = plan.slice(plan.indexOf('async function searchStart'), plan.indexOf('function setBike'));
  assert.ok(fn.includes("'&lat=' + c.lat.toFixed(1) + '&lon=' + c.lng.toFixed(1)"), 'the start search sends Photon more than a 0.1° point');
  assert.ok(!/toFixed\([2-9]\)/.test(fn), 'the start search sends a finer point than the privacy notice says');
  assert.ok(/regionOfPointPrecise\(r\.lng, r\.lat\)\.then\(reg => \{ if\(reg\) followRideScope\(\[reg\]\); \}\)/.test(fn), 'a start picked outside the scope leaves the scope where it was');
});

test('the scope follows the stops as soon as they are on the map, and a stop clicked in the list', () => {
  assert.match(plan, /routeAll\(\); followStopsScope\(ride\.stops\); saveDraft\(\);/, 'a new stop leaves the scope where it was until the legs are routed');
  assert.match(plan, /flyToPin\(\[s\.lng, s\.lat\]\); followStopsScope\(\[s\]\);/, 'a stop clicked in the list does not take the scope to its region');
  const fn = plan.slice(plan.indexOf('function followStopsScope'), plan.indexOf('function restoreRideScope'));
  assert.ok(fn.includes('followRideScope(regions)'), 'the stops do not use the ride\'s own scope rule');
});

test('another rail panel, or the drawer closing, takes the guide away without changing its tick', () => {
  assert.match(plan, /if\(key !== 'plan'\)\{ const g = \$\('planGuide'\); if\(g && !g\.hidden\) showGuide\(false\); \}/);
  const fn = plan.slice(plan.indexOf('function showGuide'), plan.indexOf('function setGuideShown'));
  assert.ok(!fn.includes('setGuideShown'), 'hiding the guide must not untick "Show this guide"');
});

test('the profile strip folds down by an arrow, comes back up by one, and carries a height scale', () => {
  const x = twig.slice(twig.indexOf('id="planStripX"'), twig.indexOf('</button>', twig.indexOf('id="planStripX"')));
  assert.match(x, /<path d="m6 9 6 6 6-6"\/>/, 'the close button is an arrow pointing down');
  const tab = twig.slice(twig.indexOf('id="planStripTab"'), twig.indexOf('</button>', twig.indexOf('id="planStripTab"')));
  assert.match(tab, /<path d="m6 15 6-6 6 6"\/>/, 'the closed strip leaves an arrow pointing up');
  const draw = plan.slice(plan.indexOf('function drawProfile'), plan.indexOf('function scrubTo'));
  assert.match(draw, /heightTicks\(minH \* perM, \(minH \+ span\) \* perM\)/, 'the scale is in the rider\'s unit');
  assert.match(draw, /<line class="hgrid"/);
  assert.match(draw, /svg \+= grid;/, 'the lines are drawn over the profile');
});

test('the along list looks 250 m to 5 km as the rider picks, kept with the draft (owner 2026-10-17)', () => {
  assert.match(twig, /<select id="planAlongR" class="cc-plan-reach" aria-label="\{\{ 'map\.plan_along_reach'\|trans \}\}"><\/select>/);
  assert.match(plan, /const ALONG_STEPS = \[250, 500, 1000, 2000, 5000\];/);
  assert.match(plan, /body: JSON\.stringify\(\{ line: \{ type: 'LineString', coordinates: line \}, radius: alongM \}\)/, 'the pick never reaches the server');
  assert.match(plan, /along: alongM, at: Date\.now\(\)/, 'the pick is not kept with the draft');
  assert.match(plan, /const nearRows = \(\) => \(alongRows \|\| \[\]\)\.filter\(r => r\.distM <= CORRIDOR_M\);/);
});

test('the forecast of the hours ridden always shows: what is not tougher reads under Weather (owner 2026-10-17)', () => {
  assert.match(twig, /<div class="cc-plan-tougher" id="planWeatherGrp" hidden>/);
  const w = (plan.match(/function renderWeather\(fc, tough, feel\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(w, /if\(!tough\.has\('heat'\)\)/);
  assert.match(w, /if\(!tough\.has\('wind'\)\)/);
  assert.match(w, /if\(!tough\.has\('rain'\)\)/);
  assert.match(plan, /rideHours\(forecast, startMin\(\), day\.seconds\)/, 'the forecast is the whole day, not the hours ridden');
  for (const key of ['plan_weather_h', 'plan_dry', 'plan_along_reach', 'plan_start_time', 'plan_finish_h', 'plan_finish_note', 'plan_forecast_hours', 'plan_lead_days', 'plan_lead_far']) {
    assert.ok(controller.includes(`'map.${key}'`) || twig.includes(`'map.${key}'`), `map.${key} is never translated`);
  }
});

test('the ride kept from the last visit sleeps until the planner opens (owner 2026-10-17)', () => {
  assert.match(plan, /paintBikes\(\); asleep = true; \}/, 'the kept ride is routed and drawn as the map page opens');
  assert.doesNotMatch(plan, /d\.stops\.length\)\{[^\n]*afterChange\(\); \}/, 'the kept ride still wakes on page load');
  assert.match(plan, /if\(activePanel\(\) === 'plan'\)\{ everOpened = true; askLegsLeft\(\); if\(asleep\)\{ asleep = false; afterChange\(\); return; \} \}/, 'opening the planner does not wake the ride');
  assert.match(plan, /function planning\(\)\{ return activePanel\(\) === 'plan' \|\| \(!asleep && ride\.stops\.length > 0\); \}/, 'a sleeping ride still takes map clicks');
  assert.match(plan, /function drawRide\(\)\{\n  if\(asleep\) return;/);
  assert.match(plan, /const show = !asleep && activePanel\(\) !== 'plan'/, 'the ride bar shows for a sleeping ride');
});

test('on a wide screen the ride details sit on the right, and a place drawer takes that side while open (owner 2026-10-17)', () => {
  assert.match(twig, /<aside class="cc-ride-side" id="planSide" hidden aria-label="\{\{ 'map\.plan_ride_h'\|trans \}\}">/);
  assert.ok(twig.indexOf('id="planSide"') < twig.indexOf('id="drawer"'), 'the side is not under the place drawer');
  const sync = (plan.match(/function syncSide\(\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(sync, /const on = WIDE\.matches && !asleep && !sideClosed && ride\.stops\.length >= 2 && !spotOpen\(\);/);
  assert.match(sync, /body\.insertBefore\(figs, \$\('planSideKey'\)\)/, 'the details do not move to the side, above a docked key');
  assert.match(sync, /figsHome\.parentNode\.insertBefore\(figs, figsHome\.nextSibling\)/, 'the details do not come back to the panel');
  assert.match(plan, /const WIDE = window\.matchMedia\('\(min-width:821px\)'\);/, 'the phone breakpoint is 820 px');
  assert.match(plan, /new MutationObserver\(syncSide\)\.observe\(spot, \{ attributes: true, attributeFilter: \['class'\] \}\)/, 'closing a place does not bring the details back');
  assert.match(plan, /const MAP_COVERS = \[[^\]]*'#planSide'\]/, 'fitting the ride ignores the side');
  assert.match(read('assets/styles/map.css'), /\.map-wrap\.cc-side-on \.cc-plan-strip\{right:var\(--side-w\)\}/, 'the strip runs under the side');
  assert.ok(controller.includes("'map.plan_side_hide'") || twig.includes("'map.plan_side_hide'"));
});

test('details closed off the right side come back with one click in their heading (owner 2026-10-17)', () => {
  assert.match(twig, /<button type="button" class="dl-all ico" id="planSideBack" hidden title="\{\{ 'map\.plan_side_show'\|trans \}\}"/);
  assert.match(plan, /const back = \$\('planSideBack'\); if\(back\) back\.hidden = !\(WIDE\.matches && sideClosed && !asleep\);/);
  assert.match(plan, /\$\('planSideBack'\)\?\.addEventListener\('click', \(\) => \{ sideClosed = false; syncSide\(\); \}\);/);
  for (const l of ['en', 'fr', 'nl', 'de', 'es']) assert.match(read(`translations/messages.${l}.yaml`), /^ {2}plan_side_show: "/m);
});

test('the map key drags into the ride details and snaps in, and back out (owner 2026-10-17)', () => {
  const dock = read('assets/map/legend-dock.js');
  assert.match(twig, /<div class="cc-ride-side-body" id="planSideBody"><div class="cc-ride-side-key" id="planSideKey"><\/div><\/div>/);
  assert.match(plan, /legendSync = initLegendDock\(\$\('planSide'\), \$\('planSideKey'\), /);
  assert.match(plan, /if\(legendSync\) legendSync\(on\);/, 'a docked key is lost while the side is away');
  assert.match(dock, /const into = e\.type === 'pointerup' && over\(e\);/, 'a drop over the side does not dock');
  assert.match(dock, /home\.parentNode\.insertBefore\(legend, home\.nextSibling\)/, 'the key does not go back to its corner');
  assert.match(dock, /grip\.hidden = !shown;/, 'the grip shows without a side to dock into');
  assert.match(dock, /localStorage\.setItem\(KEY, on \? '1' : '0'\)/);
  for (const l of ['en', 'fr', 'nl', 'de', 'es']) for (const k of ['plan_key_dock', 'plan_key_undock']) assert.match(read(`translations/messages.${l}.yaml`), new RegExp(`^ {2}${k}: "`, 'm'));
});

test('pointing at the wind draws its direction along the ride; cold, humidity and ice get their lines (owner 2026-10-17)', () => {
  assert.match(plan, /map\.addLayer\(\{ id: 'plan-wind', type: 'symbol', source: 'plan-wind', layout: \{ 'icon-image': WIND_IMAGE,/);
  assert.match(plan, /windArrows\(h \? h\.fromDeg : null, h \? h\.windKmh : null\);/, 'the hour pointed at in the chart does not turn the arrows');
  assert.match(plan, /windArrows\(\.\.\.\(cls && cls\.startsWith\('wind-'\) \? ridingWind\(\) : \[null, null\]\)\);/, 'the wind bar does not draw the arrows');
  // The harder the wind, the longer and bigger its arrows (owner 2026-10-17, longer 2026-10-18).
  assert.match(plan, /const WIND_ARROWS = \[\[0, 6\], \[12, 14\], \[20, 24\], \[29, 34\], \[39, 46\]\];/);
  assert.match(plan, /const WIND_IMAGE = \['step', \['get', 'kmh'\], 'plan-wind-arrow-0', \.\.\.WIND_ARROWS\.slice\(1\)\.flatMap/);
  assert.match(plan, /, H = 16 \+ shaft, /, 'a stronger wind does not get a longer shaft');
  assert.match(plan, /const WIND_SCALE = \['interpolate', \['linear'\], \['get', 'kmh'\], 0, 0\.8, 20, 1, 40, 1\.2, 60, 1\.4\];/);
  assert.match(plan, /'icon-size': \['interpolate', \['linear'\], \['zoom'\], 8, \['\*', 0\.85, WIND_SCALE\], 14, \['\*', 1\.25, WIND_SCALE\]\]/);
  assert.match(plan, /const feel = rideFeel\(fc, day\.seconds > 0 \? f\.distM \/ 1000 \/ \(day\.seconds \/ 3600\) : 0\);/, 'the wind chill does not count the riding pace');
  for (const k of ['plan_cold_feel', 'plan_heat_humid', 'plan_ice', 'plan_ice_freezing', 'plan_ice_snow', 'plan_ice_frost']) {
    assert.ok(controller.includes(`'map.${k}'`), `map.${k} is not translated`);
    for (const l of ['en', 'fr', 'nl', 'de', 'es']) assert.match(read(`translations/messages.${l}.yaml`), new RegExp(`^ {2}${k}: "`, 'm'));
  }
});

test('the day and the start time sit under the name; the finish is a time with 5 % for traffic (owner 2026-10-17)', () => {
  const when = twig.slice(twig.indexOf('id="planWhen"'), twig.indexOf('cc-plan-bike'));
  assert.ok(when.includes('id="planDate"') && when.includes('id="planStartTime"'), 'the day and the start time are not base facts at the top');
  assert.ok(twig.indexOf('id="planWhen"') < twig.indexOf('id="planFigsGrp"'));
  assert.match(plan, /const FINISH_SLACK = 1\.05;/);
  assert.match(plan, /at\.textContent = '~ ' \+ clockText\(new Date\(startAt\(\)\.getTime\(\) \+ roundedS\(day\.seconds \* FINISH_SLACK\) \* 1000\)\)/);
  assert.match(plan, /\+ ' · ' \+ leadText\(fc\.date\)/, 'the forecast does not say how far ahead it is');
});

test('the riding time and the finish stand side by side, each explained behind an info icon (owner 2026-10-17)', () => {
  const figs = twig.slice(twig.indexOf('id="planFigs"'), twig.indexOf('id="planDayFx"'));
  assert.match(figs, /<div class="cc-plan-fig cc-plan-fig-half" data-part="all" tabindex="0"><b id="planTime"><\/b>/);
  assert.match(figs, /<div class="cc-plan-fig cc-plan-fig-half" id="planArrive" hidden><b id="planArriveT"><\/b>/);
  assert.match(figs, /aria-controls="planTimeTip"/);
  assert.match(figs, /aria-controls="planFinishTip"/);
  assert.match(read('assets/styles/map.css'), /\.map-wrap:has\(#planGuide:not\(\[hidden\]\)\) #map\{filter:blur\(3px\)/, 'the guide does not blur the map');
});

test('the forecast says how far ahead in plain facts, never a verdict of its own on nearer days (owner 2026-10-17)', () => {
  const lead = (plan.match(/function leadText\(date\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(lead, /return days >= LEAD_FAR_DAYS \? when \+ ', ' \+ \(P\(\)\.leadFar/);
  assert.doesNotMatch(read('translations/messages.en.yaml'), /usually right/);
});

test('the side keeps only its close button, in the heading beside the eye; the guide veils the map and takes its clicks (owner 2026-10-17)', () => {
  assert.ok(!twig.includes('planSideOpen') && !twig.includes('planSideClear'), 'Open and Clear are still on the side');
  const h3 = twig.slice(twig.indexOf('id="planLineToggle"'), twig.indexOf('</h3>', twig.indexOf('id="planLineToggle"')));
  assert.match(h3, /id="planSideX" hidden/);
  assert.match(plan, /const x = \$\('planSideX'\); if\(x\) x\.hidden = !on;/);
  assert.match(twig, /<div class="cc-plan-veil" id="planGuideVeil" hidden><\/div>/);
  assert.match(plan, /const guide = \$\('planGuide'\); if\(guide && !guide\.hidden\) return;/, 'a click under the guide still adds a checkpoint');
  assert.match(plan, /const veil = \$\('planGuideVeil'\); if\(veil\) veil\.hidden = !show;/);
});

test('the weather watches wind chill, humidity and ice, struck through while there is nothing to say (owner 2026-10-17)', () => {
  assert.match(twig, /<div class="cc-wx-watch" id="planWatch"><\/div>/);
  const w = (plan.match(/function renderWatch\(fc, feel\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(w, /<path class="cc-wx-strike" d="M4 20 20 4"\/>/);
  for (const k of ["item('chill'", "item('humid'", "item('ice'"]) assert.ok(w.includes(k), k);
  for (const k of ['plan_watch_chill', 'plan_watch_humid', 'plan_watch_ice', 'plan_watch_chill_off', 'plan_watch_humid_off', 'plan_watch_ice_off', 'plan_watch_no_hours']) {
    for (const l of ['en', 'fr', 'nl', 'de', 'es']) assert.match(read(`translations/messages.${l}.yaml`), new RegExp(`^ {2}${k}: "`, 'm'));
  }
});

test('pointing at a stat or the weather veils the map; an hour lights where the rider is then, with its rain as drops (owner 2026-10-18)', () => {
  assert.match(plan, /map\.addLayer\(\{ id: 'plan-veil', type: 'background', paint: \{ 'background-color': '#F3EBD8', 'background-opacity': 0, 'background-opacity-transition': \{ duration: still \? VEIL_FADE_STILL_MS : VEIL_FADE_MS, delay: 0 \} \} \}\);/, 'the veil does not fade (owner 2026-10-18: "so flashy")');
  assert.match(plan, /const VEIL_FADE_STILL_MS = 120;/, 'reduced motion jumps instead of a short fade');
  assert.match(plan, /const VEIL_OPACITY = 0\.62, VEIL_FADE_MS = 300, VEIL_HOLD_MS = 150;/);
  assert.match(plan, /veilLiftTimer = setTimeout\(\(\) => \{ if\(!focused\) liftInfoLayersAboveRoutes\(\); \}, VEIL_FADE_MS \+ 20\);/, 'the ride drops under the veil before it has faded');
  assert.match(plan, /function lightPart\(cls\)\{\n  focusMap\(!!cls\);/, 'a stat does not veil the map');
  const hour = (plan.match(/function lightHour\(hr\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(hour, /hourSpan\(startMin\(\), day\.seconds, total, hr\)/);
  assert.match(hour, /rainDrops\(span \? \[span\] : null, h \? h\.rainMm : 0\);/);
  assert.match(hour, /focusMap\(!!h\);/);
});

test('a click on the ride line puts the checkpoint between the two of that stretch (owner 2026-10-18)', () => {
  assert.match(plan, /const at = onRideLine\(e\.point\) \? insertIndex\(ride\.stops, ride\.legs, raw\) : undefined;/);
  const on = (plan.match(/function onRideLine\(pt\)\{([\s\S]*?)\n\}/) || [])[1] || '';
  assert.match(on, /\['plan-line', 'plan-casing'\]/, 'only the ride\'s own line counts');
  assert.match(on, /if\(ride\.stops\.length < 2 \|\| !lineShown\) return false;/);
  assert.match(plan, /map\.getContainer\(\)\.classList\.toggle\('cc-insert', over\);/, 'the pointer does not say it inserts');
  assert.match(read('assets/styles/map.css'), /\.cc-insert \.maplibregl-canvas\{cursor:copy!important\}/, 'a layer\'s hover pointer beats the insert cursor');
});

test('the guide runs in the order a ride is made, each step its own box (owner 2026-10-18)', () => {
  const en = read('translations/messages.en.yaml');
  assert.match(en, /^ {2}plan_guide_1: "Name your ride, or load one from your device with <i>Open a GPX<\/i>\."/m);
  assert.match(en, /^ {2}plan_guide_2: "Set the start date and time if you want the weather forecast\."/m);
  assert.match(en, /^ {2}plan_guide_7: "Click the line between two checkpoints to add a checkpoint there\."/m);
  assert.match(en, /^ {2}plan_guide_4: "Pick your bike\./m, 'the bike has no step (owner 2026-10-18)');
  assert.match(twig, /<ol class="cc-plan-guide-steps">/);
  assert.match(en, /^ {2}plan_date: "Start date"/m);
});

test('the side\'s close button and the arrow back never show together; the guide is 39 rem wide (owner 2026-10-18)', () => {
  const css = read('assets/styles/map.css');
  assert.match(css, /#planSideX\[hidden\],#planSideBack\[hidden\]\{display:none\}/, 'an id rule beats the hidden attribute');
  assert.match(css, /\.cc-plan-guide\{[^}]*width:min\(39rem,calc\(100% - 2rem\)\)/);
});

test('the start date opens our own calendar, in the site style, the forecast days marked (owner 2026-10-18)', () => {
  const cal = read('assets/map/date-pick.js');
  assert.match(plan, /initDatePick\(show, dateIn, \{ prev: P\(\)\.calPrev, next: P\(\)\.calNext, aria: P\(\)\.calAria, forecast: P\(\)\.calForecast \}, FORECAST_DAYS\)/);
  assert.ok(!plan.includes('showPicker'), 'the browser\'s calendar is still used');
  assert.match(cal, /input\.dispatchEvent\(new Event\('change', \{ bubbles: true \}\)\)/, 'a picked day does not reach the planner');
  assert.match(cal, /new Date\(2024, 0, 1 \+ i\)/, 'the week does not start on Monday');
  assert.match(cal, /shut = k < min/, 'days before today can be picked');
  for (const k of ['plan_cal_prev', 'plan_cal_next', 'plan_cal_aria', 'plan_cal_forecast']) {
    assert.ok(controller.includes(`'map.${k}'`));
    for (const l of ['en', 'fr', 'nl', 'de', 'es']) assert.match(read(`translations/messages.${l}.yaml`), new RegExp(`^ {2}${k}: "`, 'm'));
  }
});

test('each line of what makes it tougher lights its stretches under the veil (owner 2026-10-18)', () => {
  assert.match(plan, /const TOUGH_PART = \{ long: 'all', steep: 'steep', climb: 'climbrun', rough: 'rough', busy: 'busy', water: 'water', daylight: 'dark', wind: 'wind-into', rain: 'rain', heat: 'hour-hot', cold: 'hour-cold', ice: 'hour-ice' \};/);
  assert.match(plan, /parts\[i\] \? `<li data-part="\$\{parts\[i\]\}" tabindex="0">/);
  assert.match(plan, /<li class="cc-plan-gap" tabindex="0" data-part="water">/, 'the water gap does not veil the map');
  assert.match(plan, /const FOCUS_LAYERS = \['plan-gap', /, 'the water gap goes under the veil');
  assert.match(plan, /busyRuns: d\.busyRuns \|\| null/);
});

test('a part under 50 m reads "under 0.1 km", never "0 km" (owner 2026-10-18)', () => {
  assert.match(plan, /function partDist\(m\)\{ if\(m > 0 && m < 50\) return tpl\(P\(\)\.underDist \|\| 'under \{d\}', \{ d: uKm\(0\.1\) \}\); return fmtKm\(m\); \}/);
  assert.match(plan, /const value = b => byDist \? partDist\(b\.seconds\) : partText\(b\.seconds\);/);
});

test('a step of the guide frames what it speaks of, and the place search stays (owner 2026-10-18)', () => {
  for (let n = 1; n <= 9; n++) assert.match(twig, new RegExp(`<li data-guide="${n}" tabindex="0">`));
  assert.match(plan, /const GUIDE_TARGETS = \{ 1: \['#planRideName', '#planOpen'\], 2: \['#planWhen'\], 3: \['#planStartBox'\], 4: \['\.cc-plan-bike'\], 5: \['#map'\], 6: \['\.cc-plan-mk'\], 7: \['#map'\], 8: \['spots'\]/);
  assert.match(plan, /if\(!show\) guideGlow\(null\);/, 'a frame outlives the guide');
  assert.match(plan, /box\.hidden = !\(ride\.name \|\| ''\)\.trim\(\);/, 'the search still hides once the ride has checkpoints');
  for (const l of ['en', 'fr', 'nl', 'de', 'es']) assert.match(read(`translations/messages.${l}.yaml`), /^ {2}plan_goto_h: "/m);
});

test('the place search is the third card, after the name and the day (owner 2026-10-18)', () => {
  const at = id => twig.indexOf(`id="${id}"`);
  assert.ok(at('planStart') < at('planWhen') && at('planWhen') < at('planSearchGrp') && at('planSearchGrp') < twig.indexOf('cc-plan-bike'), 'the cards are out of order');
  assert.ok(twig.slice(at('planSearchGrp'), twig.indexOf('cc-plan-bike')).includes('id="planStartBox"'), 'the search is not in its card');
  assert.match(plan, /const grp = \$\('planSearchGrp'\); if\(grp\) grp\.hidden = box\.hidden;/);
});

test('a map point moves onto the road a bike would take before it becomes a checkpoint (owner 2026-10-18)', () => {
  assert.match(twig, /snap: path\('map_plan_snap'\)/);
  assert.match(plan, /snapPoint\(raw\.lat, raw\.lng\)\.then\(p => addStop\(\{ \.\.\.raw, lat: p\.lat, lng: p\.lng \}, at\)\);/, 'a clicked point is not snapped');
  assert.match(plan, /m\.on\('dragend', async \(\) => \{ const ll = m\.getLngLat\(\); const p = await snapPoint\(ll\.lat, ll\.lng\);/, 'a dragged point is not snapped');
  assert.match(plan, /return d && d\.snapped && Array\.isArray\(d\.point\) \? \{ lat: d\.point\[1\], lng: d\.point\[0\] \} : \{ lat, lng \};/, 'a failed snap loses the point');
});

test('the guide stays the top layer: frames go under it and no ring lies on it (owner 2026-10-18)', () => {
  assert.match(read('assets/styles/map.css'), /\.cc-guide-glow\{position:fixed;z-index:1295;/);
  assert.match(plan, /for\(const \[x, y\] of spotPoints\(\)\.filter\(\(\[x, y\]\) => !under\(x, y\)\)\)\{/);
});
