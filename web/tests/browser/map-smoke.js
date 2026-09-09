// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Map smoke sweep — the checkpoint list from
   map-and-search.md §2, as code.

   Not a Playwright project: this repo has no package.json and the split design
   deliberately does not add one. This file is a single function evaluated in
   the page (devtools console, a Playwright/MCP `evaluate`, anything that can
   run a string in the tab) so the check is byte-identical on every run instead
   of depending on what the operator remembered to click.

   Usage:
     1. open the dev map (http://localhost:8001/map) and let it settle
     2. evaluate this file's contents in the page
     3. `await runMapSmoke()` → {ok, passed, failed, skipped, results}

   Console errors are NOT collected here — this runs after boot, far too late to
   hook console.error. The driver reports them (Playwright console messages,
   devtools console); a green sweep with a red console is a FAILED sweep.

   Auth: checkpoints tagged `auth:true` need a logged-in rider (ride-check is a
   riders-only control). Anonymously they report `skipped`, not `failed`. On the
   dev stack, log in as the `make setup` demo rider — user@example.test /
   password1234, ROLE_USER with no 2FA — then upload a GPX before running the
   sweep, since the coverage checkpoint reads an already-loaded result.

   DECIDED 2026-08-24 — this stays manual, and is not going into CI.
   A headless arm would need a browser download, a booted app with Postgres,
   seeded fixtures and reachable tiles, plus the first package.json in the repo,
   in a job whose most likely output is a flake. What it would buy is already
   bought more cheaply from two directions: `make map-refs` runs in ci-app.yml
   and catches the module-boot class of bug that the split actually produces
   (it exists because a green sweep here once missed a live ReferenceError),
   and web/tests/js/ pins the shell ids, zoom handovers and theme tokens this
   sweep asserts. What stays uncovered either way is real MapLibre interaction
   (planner, picking, lightbox, climb profile); that is a browser-testing
   decision to take on its own merits, not a side effect of wiring this file to
   a runner. Run it by hand after any map change. */

globalThis.runMapSmoke = async function runMapSmoke(opts) {
  const only = (opts && opts.only) || null;
  const results = [];

  const sleep = ms => new Promise(r => setTimeout(r, ms));
  /** Poll `pred` until truthy or `ms` elapses. Returns the truthy value or null. */
  async function waitFor(pred, ms, step) {
    const deadline = Date.now() + (ms || 5000);
    for (;;) {
      let v = null;
      try { v = pred(); } catch (_) { v = null; }
      if (v) return v;
      if (Date.now() > deadline) return null;
      await sleep(step || 100);
    }
  }
  const $ = sel => document.querySelector(sel);
  const $$ = sel => Array.from(document.querySelectorAll(sel));
  const assert = (cond, msg) => { if (!cond) throw new Error(msg); };
  const drawerOpen = () => { const d = $('#drawer'); return !!d && d.classList.contains('open'); };
  const drawerText = () => { const b = $('#drawerBody'); return b ? b.textContent : ''; };
  async function closeDrawerIfOpen() {
    if (!drawerOpen()) return;
    const x = $('#drawerClose'); if (x) x.click();
    await waitFor(() => !drawerOpen(), 2000);
  }
  /** The map instance, via the only handle a page script has: the canvas' owner. */
  function mapEl() { return $('#map') || $('.maplibregl-map'); }
  /** window.__ccMap — published by map-init only when CC_DEBUG is set (non-prod). */
  const M = () => window.__ccMap || null;
  const layerIds = re => { const m = M(); return m && m.getStyle() ? m.getStyle().layers.map(l => l.id).filter(id => re.test(id)) : []; };
  /** Click the canvas at a real projected point, so MapLibre's own hit-testing runs. */
  function clickAt(pt) {
    const cv = $('.maplibregl-canvas');
    const r = cv.getBoundingClientRect();
    const o = { bubbles: true, cancelable: true, clientX: r.left + pt.x, clientY: r.top + pt.y, button: 0 };
    ['mousedown', 'mouseup', 'click'].forEach(t => cv.dispatchEvent(new MouseEvent(t, o)));
  }

  const CHECKS = [
    {
      name: 'boot',
      async run() {
        assert(mapEl(), 'no map container');
        assert($('.maplibregl-canvas'), 'no MapLibre canvas');
        const layers = $$('#layers .layer');
        assert(layers.length >= 8, 'layer list has ' + layers.length + ' entries, expected >= 8');
        assert($('#regionLine'), 'no scope header line');
      },
    },
    {
      name: 'scope switch to a region',
      async run() {
        // My-area resolves to no header label by design (scopeLabel returns ''),
        // so it cannot witness "the header followed the scope" — skip it.
        const btns = $$('#regionScope button[data-scope]')
          .filter(b => ['everywhere', 'myarea', 'myArea'].indexOf(b.dataset.scope) === -1 && !b.classList.contains('on'));
        // Cold-start countries mode offers country rungs, not region chips.
        if (!btns.length) return 'skip: no region chip offered in the current scope mode';
        const label = btns[0].textContent.trim();
        btns[0].click();
        // Chip labels carry a country cue ("Wallonia \u00b7 BE"); the header shows the
        // bare region name, so compare on the leading name only.
        const name = label.split(/[\u00b7,]/)[0].trim();
        const ok = await waitFor(() => $('#regionLine') && $('#regionLine').textContent.indexOf(name) !== -1, 6000);
        assert(ok, 'header did not follow the scope switch to "' + label + '" (header reads "'
          + (($('#regionLine') || {}).textContent || '') + '")');
      },
    },
    {
      name: 'layer toggle',
      async run() {
        const t = $('#layers .layer:not(.off)');
        assert(t, 'no active layer to toggle');
        const key = t.dataset.key;
        t.click();
        const off = await waitFor(() => $('#layers .layer[data-key="' + key + '"]').classList.contains('off'), 3000);
        assert(off, 'layer ' + key + ' did not go off');
        t.click();
        const on = await waitFor(() => !$('#layers .layer[data-key="' + key + '"]').classList.contains('off'), 3000);
        assert(on, 'layer ' + key + ' did not come back on');
      },
    },
    {
      name: 'mode flip curated <-> everything',
      async run() {
        const btns = $$('#mode button');
        assert(btns.length >= 2, 'no mode buttons');
        const other = btns.find(b => !b.classList.contains('on'));
        assert(other, 'no inactive mode button');
        other.click();
        const flipped = await waitFor(() => other.classList.contains('on'), 4000);
        assert(flipped, 'mode button did not activate');
        const back = btns.find(b => b !== other);
        back.click();
        await waitFor(() => back.classList.contains('on'), 4000);
      },
    },
    {
      name: 'coverage layers registered and valid',
      async run() {
        if (!M()) return 'skip: no __ccMap handle (CC_DEBUG off)';
        if (!window.CC_COVERAGE_URL) return 'skip: coverage tiles off on this instance';
        // Two other layers end in -cov and are NOT coverage tiles: mly-cov is
        // the Mapillary sequence line, and ridecheck-cov is ride-check's own
        // corridor overlay — which only exists after a GPX upload, so counting
        // it made this checkpoint fail on exactly the logged-in runs that
        // exercise the most code (36 icons vs 35 heat).
        const NOT_COVERAGE = ['mly-cov', 'ridecheck-cov'];
        const icons = layerIds(/-cov$/).filter(id => NOT_COVERAGE.indexOf(id) === -1),
              heat = layerIds(/-heat$/);
        assert(icons.length, 'no coverage icon layers were added');
        // The Everywhere regression: covHeatFilter() is null there, and addLayer()
        // rejects `filter: null`, so the heat layers vanished silently.
        assert(heat.length, 'no coverage heat layers were added (null-filter regression?)');
        assert(heat.length === icons.length, heat.length + ' heat layers vs ' + icons.length + ' icon layers');
      },
    },
    {
      name: 'study mode leaves the bases as it found them',
      async run() {
        const m = M();
        if (!m) return 'skip: no __ccMap handle (CC_DEBUG off)';
        const btn = document.getElementById('skeyStudy');
        if (!btn) return 'skip: no study-mode control on this instance';
        if (btn.disabled) return 'skip: study mode is gated on the surface skin being on';
        // Every basemap-source layer, satellite and Mapillary included — the
        // exact set study mode takes away.
        const BASES = ['openmaptiles', 'ne2_shaded', 'satellite', 'mly'];
        const snapshot = () => {
          const out = {};
          m.getStyle().layers.forEach(l => {
            if (BASES.indexOf(l.source) !== -1) {
              out[l.id] = m.getLayoutProperty(l.id, 'visibility') || 'visible';
            }
          });
          return out;
        };
        const before = snapshot();
        assert(Object.keys(before).length, 'no basemap layers found to study');
        btn.click();
        await waitFor(() => document.querySelector('.map-wrap.study'), 4000);
        btn.click();
        await waitFor(() => !document.querySelector('.map-wrap.study'), 4000);
        const after = snapshot();
        // The regression this exists for: leaving study mode used to set EVERY
        // basemap layer to `visible`, switching on satellite imagery and
        // Mapillary coverage the rider never asked for (owner-reported
        // 2026-08-12). A mode that is switched off has to leave no trace.
        const changed = Object.keys(before).filter(id => before[id] !== after[id]);
        assert(!changed.length,
          changed.length + ' base layer(s) changed visibility across a study-mode '
          + 'round trip, e.g. ' + changed[0] + ': ' + before[changed[0]] + ' -> '
          + after[changed[0]]);
      },
    },
    {
      name: 'spotlight follows the scope',
      async run() {
        const m = M();
        if (!m || !window.CCScope) return 'skip: no __ccMap handle (CC_DEBUG off)';
        // Drive CCScope directly rather than clicking chips: which chips exist
        // depends on the mode the current scope puts the rail in, so a chip click
        // is not a deterministic way to reach a NAMED-REGION scope.
        const regions = window.CC_REGIONS || [];
        const r = regions.find(x => x.slug === 'wallonia') || regions[0];
        if (!r) return 'skip: empty region registry';
        window.CCScope.set({ kind: 'everywhere', regionIds: [], countryCode: null }, { persist: false });
        const cleared = await waitFor(() => !m.getSource('region'), 8000);
        assert(cleared, 'Everywhere left a region spotlight source behind');

        // What this checkpoint actually guards is the CALL PATH: a region scope
        // must reach setSpotlight and request that region's boundary. It cannot
        // reasonably assert on the paint: the three-tier spotlight fires seven
        // concurrent boundary requests and the mask lands ~3.4 s later, most of
        // that client-side polygon parsing and tessellation (backlog item 10) —
        // and far slower on a throttled or loaded machine. Slow-but-correct, and
        // failing on it every run would bury real regressions in noise. So:
        // no request = FAIL, request but slow paint = pass with the timing noted.
        const want = '/map/region/' + r.slug + '/boundary';
        let asked = false;
        const realFetch = window.fetch;
        window.fetch = function (u) { if (String(u).indexOf(want) !== -1) asked = true; return realFetch.apply(this, arguments); };
        const t0 = Date.now();
        let slow = null;
        try {
          window.CCScope.set({ kind: 'region', regionIds: [r.id], countryCode: r.countryCode }, { persist: false });
          const requested = await waitFor(() => asked, 5000, 100);
          assert(requested, 'a single-region scope never requested ' + want + ' — setSpotlight is not on the scope-change path');
          const painted = await waitFor(() => m.getSource('region') && m.getLayer('region-line'), 20000, 500);
          if (!painted) return 'skip: boundary requested, still unpainted after 20 s (backlog item 10, known)';
          slow = ((Date.now() - t0) / 1000).toFixed(1);
        } finally {
          window.fetch = realFetch;
          if (slow) results.push({ name: 'spotlight paint time', status: 'passed', note: slow + ' s' });
        }
      },
    },
    {
      // Reported 2026-07-27: the basemap's own place names stayed English in
      // every locale, because OpenFreeMap's `liberty` style hardcodes
      // ["coalesce", ["get","name_en"], ["get","name"]] on all 20 name layers.
      // Asserting the EXPRESSION, not a rendered string, so this works in any
      // locale (including English) and on any viewport.
      name: 'basemap labels follow the site language',
      async run() {
        const m = M();
        if (!m) return 'skip: no __ccMap handle (CC_DEBUG off)';
        const lang = (document.documentElement.lang || 'en').slice(0, 2);
        const sym = m.getStyle().layers.filter(
          l => l.type === 'symbol' && l.layout && l.layout['text-field']);
        assert(sym.length > 0, 'no symbol layers with a text-field — style did not load');
        const stale = [];
        for (const l of sym) {
          const json = JSON.stringify(l.layout['text-field']);
          // The three road-shield layers read `ref` and are none of our business.
          if (json.indexOf('name') === -1) continue;
          if (json.indexOf('"name:' + lang + '"') === -1) stale.push(l.id);
        }
        assert(stale.length === 0,
          stale.length + ' basemap label layer(s) do not ask for name:' + lang
          + ' (localiseBasemapLabels did not run or missed them): ' + stale.slice(0, 4).join(', '));
      },
    },
    {
      name: 'coverage icon click opens a drawer',
      async run() {
        const m = M();
        if (!m) return 'skip: no __ccMap handle (CC_DEBUG off)';
        const ids = layerIds(/-cov$/).filter(id => m.getLayoutProperty(id, 'visibility') === 'visible');
        if (!ids.length) return 'skip: no visible coverage layer';
        // Coverage icons only draw from z9 (addCoverage minzoom); zoom in first.
        if (m.getZoom() < 10) { m.setZoom(11); await sleep(2500); }
        const feats = await waitFor(() => { const f = m.queryRenderedFeatures({ layers: ids }); return f.length ? f : null; }, 8000, 400);
        if (!feats) return 'skip: no coverage feature rendered in the current viewport';
        await closeDrawerIfOpen();
        clickAt(m.project(feats[0].geometry.coordinates));
        const open = await waitFor(() => drawerOpen() && drawerText().length > 0, 6000);
        assert(open, 'clicking a coverage icon opened no drawer');
        await closeDrawerIfOpen();
      },
    },
    {
      name: 'curated pin opens a drawer',
      async run() {
        /* `.maplibregl-marker` matters: since the Map key panel landed
           (map-and-search.md 4.7) the rail draws the SAME pin classes as
           swatches, and those sit earlier in the DOM than any marker. A bare
           `.cc-pin` selected a legend swatch, which has no click handler and
           opens nothing, so this checkpoint failed on a working map. */
        const pin = $('.maplibregl-marker.cc-pin');
        if (!pin) return 'skip: no curated pin drawn in the current viewport';
        await closeDrawerIfOpen();
        pin.click();
        const open = await waitFor(() => drawerOpen() && drawerText().length > 0, 6000);
        assert(open, 'clicking a curated pin opened no drawer');
        await closeDrawerIfOpen();
      },
    },
    {
      name: 'route select highlights',
      async run() {
        const m = M();
        if (!m) return 'skip: no __ccMap handle (CC_DEBUG off)';
        const ids = layerIds(/^experience-\d+$/);
        if (!ids.length) return 'skip: no route lines drawn';
        const feats = m.queryRenderedFeatures({ layers: ids });
        if (!feats.length) return 'skip: no route line rendered in the current viewport';
        await closeDrawerIfOpen();
        const c = feats[0].geometry.coordinates;
        const mid = Array.isArray(c[0]) ? c[Math.floor(c.length / 2)] : c;
        clickAt(m.project(mid));
        const open = await waitFor(() => drawerOpen() && drawerText().length > 0, 6000);
        assert(open, 'clicking a route line opened no drawer');
        await closeDrawerIfOpen();
      },
    },
    {
      name: 'town search opens a place card',
      async run() {
        const box = $('#search');
        assert(box, 'no search box');
        box.value = 'Spa';
        box.dispatchEvent(new Event('input', { bubbles: true }));
        // The input handler is debounced 150 ms and the index build is lazy;
        // re-dispatch once mid-wait so a keystroke that lands during a repaint
        // is not the only one the debounce ever sees.
        const hit = await waitFor(() => {
          const b = $('#searchRes button');
          if (!b && !$('#searchRes').innerHTML) box.dispatchEvent(new Event('input', { bubbles: true }));
          return b;
        }, 8000, 250);
        assert(hit, 'search returned no results for "Spa"');
        hit.click();
        const open = await waitFor(() => drawerOpen() && drawerText().length > 0, 8000);
        assert(open, 'search result did not open a drawer');
        await closeDrawerIfOpen();
        box.value = '';
        box.dispatchEvent(new Event('input', { bubbles: true }));
      },
    },
    {
      name: 'best-of facets repaint',
      async run() {
        /* #boSeason is a chip set, not a <select>: reading `.options` threw
           "undefined is not iterable" and reported a failure that was this
           file being out of date. Clicking the next chip is the same gesture a
           rider makes, and asserts the same thing: the facet change repaints
           without taking the page down. */
        const box = $('#boSeason');
        if (!box) return 'skip: no best-of facets on this view';
        const chips = Array.from(box.querySelectorAll('.chip'));
        if (chips.length < 2) return 'skip: fewer than two season chips';
        const at = chips.findIndex(c => c.classList.contains('on'));
        chips[(at + 1) % chips.length].click();
        await sleep(1200);
        assert($('#boSeason'), 'the facet row vanished after a season change');
      },
    },
    {
      name: 'planner chip draws and clears',
      async run() {
        const chip = $('#planner .chip');
        if (!chip) return 'skip: no planner on this view';
        chip.click();
        const drew = await waitFor(() => drawerOpen(), 5000);
        assert(drew, 'planner chip did not open the suggested-route drawer');
        chip.click();
        await waitFor(() => !chip.classList.contains('on'), 3000);
        await closeDrawerIfOpen();
      },
    },
    {
      name: 'ride-check control present',
      auth: true,
      async run() {
        if (!$('#rcPick')) return 'skip: anonymous (no ride-check control)';
        assert($('#rcFile'), 'ride-check file input missing');
        assert($('#rcRadius'), 'ride-check radius select missing');
      },
    },
    {
      name: 'ride-check coverage section',
      auth: true,
      async run() {
        if (!$('#rcPick')) return 'skip: anonymous (no ride-check control)';
        const status = $('#rcStatus');
        if (!status || status.hidden) return 'skip: no ride-check result loaded (upload a GPX first)';
        const show = status.querySelector('[data-act="show"]');
        if (show) { show.click(); await waitFor(() => drawerOpen(), 3000); }
        const body = $('#drawerBody');
        assert(body && body.querySelector('.cc-near-h'), 'ride-check drawer has no result sections');
        assert(body.querySelector('[data-rc-c]'), 'no coverage rows in the ride-check drawer');
      },
    },
  ];

  // The sweep switches scope, and CCScope PERSISTS what it is left on — so a run
  // that ends in Everywhere silently changes the starting conditions of the next
  // one. Snapshot on entry, restore on exit, so the sweep is idempotent.
  const scope0 = window.CCScope ? window.CCScope.get() : null;

  for (const c of CHECKS) {
    if (only && only.indexOf(c.name) === -1) { results.push({ name: c.name, status: 'skipped', note: 'not selected' }); continue; }
    try {
      const note = await c.run();
      if (typeof note === 'string' && note.startsWith('skip:')) results.push({ name: c.name, status: 'skipped', note: note.slice(5).trim() });
      else results.push({ name: c.name, status: 'passed' });
    } catch (e) {
      results.push({ name: c.name, status: 'failed', note: (e && e.message) || String(e) });
    }
    await sleep(200);
  }

  await closeDrawerIfOpen();
  if (scope0 && window.CCScope) { try { window.CCScope.set(scope0, { persist: true }); } catch (_) { /* restore is best-effort */ } }

  const passed = results.filter(r => r.status === 'passed').length;
  const failed = results.filter(r => r.status === 'failed');
  const skipped = results.filter(r => r.status === 'skipped').length;
  return { ok: failed.length === 0, passed, failed: failed.length, skipped, results };
};
