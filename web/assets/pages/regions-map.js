// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* /regions: the world map view, and the list's own small courtesies.

   The list is the page; the map is a second way to pick a country (owner
   2026-09-08: "make a map version where you select the country on a world
   map"). MapLibre and the shapes are fetched only when the reader asks for
   the globe, from the same vendored file the map page uses. One shape per
   country, not its regions (owner 2026-09-08); a click opens that
   country's block in the list below and scrolls to it. A globe, MapLibre's
   globe projection, and only a globe: the flat map was a chip for an hour
   (owner 2026-09-08: "remove the map option"). A spinner shows from the
   click until the globe's first idle frame.

   Also the tab strip (owner 2026-09-08: "regions must open full width as
   some sort of tab"): the count chip under a country is a tab, its regions
   are a panel across the whole row, placed on the grid row under that
   country's row, and one country is open at a time. A country reached by a
   link (#country-NL, from the typeahead or the map) opens the same way. */
(function () {
  'use strict';

  /* Closing plays its short animation only when the reader closes a panel;
     switching to another country swaps at once, so the rows move once. */
  function hide(p, animate) {
    var done = function () { p.classList.remove('open', 'closing'); p.style.gridRow = ''; p.removeEventListener('animationend', done); };
    if (!animate) { done(); return; }
    p.classList.add('closing');
    p.addEventListener('animationend', done);
    setTimeout(done, 220);   // reduced motion: no animationend ever comes
  }
  function closeAll(animate) {
    document.querySelectorAll('.regions.open').forEach(function (p) { hide(p, animate); });
    document.querySelectorAll('.regtab[aria-expanded="true"]').forEach(function (t) { t.setAttribute('aria-expanded', 'false'); });
  }
  /* The row under the country's row: explicit placement of the one panel;
     the countries auto-place around it. The notch sits under the chip. */
  function place(block, panel) {
    var grid = block.parentNode, tab = block.querySelector('.regtab');
    var cols = getComputedStyle(grid).gridTemplateColumns.split(' ').length || 1;
    var i = Array.prototype.indexOf.call(grid.querySelectorAll('.country'), block);
    panel.style.gridRow = String(Math.floor(i / cols) + 2);
    var t = tab.getBoundingClientRect(), g = grid.getBoundingClientRect();
    panel.style.setProperty('--tab-x', Math.round(t.left - g.left + t.width / 2 - 9) + 'px');
  }
  function openCountry(code, scroll) {
    var block = document.getElementById('country-' + code);
    if (!block) { return; }
    var tab = block.querySelector('.regtab'), panel = document.getElementById('regions-' + code);
    closeAll(false);
    if (tab && panel) { tab.setAttribute('aria-expanded', 'true'); panel.classList.add('open'); place(block, panel); }
    if (scroll !== false) { block.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  }
  document.querySelectorAll('.regtab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      if (tab.getAttribute('aria-expanded') === 'true') { closeAll(true); } else { openCountry(tab.getAttribute('data-country'), false); }
    });
  });
  window.addEventListener('resize', function () {
    var open = document.querySelector('.regtab[aria-expanded="true"]');
    if (open) { place(open.closest('.country'), document.getElementById('regions-' + open.getAttribute('data-country'))); }
  });
  function openFromHash() {
    var m = /^#country-([A-Z]{2})$/.exec(location.hash || '');
    if (m) { openCountry(m[1]); }
  }
  openFromHash();
  window.addEventListener('hashchange', openFromHash);

  var row = document.getElementById('regions-viewrow');
  var box = document.getElementById('regions-map');
  if (!row || !box) { return; }
  row.hidden = false;   // the switch exists only where the script runs

  var names = {};
  try {
    var src = document.getElementById('cc-countries');
    JSON.parse(src.textContent).forEach(function (c) { names[c.code] = c.name; });
  } catch (e) { /* names are a courtesy; codes still work */ }

  var map = null, loading = null;
  /* The globe sits closer, so it fills the box the way the owner's
     screenshot had it, turned to a signed-in rider's base when they have one
     (data-home, owner 2026-09-08); the flat map shows the whole world. */
  function frame() {
    var home = (box.getAttribute('data-home') || '').split(',').map(Number);
    var centre = home.length === 2 && isFinite(home[0]) && isFinite(home[1]) ? home : [10, 42];
    return { center: centre, zoom: 2.2 };
  }
  /* Hidden, spinner showing, until the first idle frame as a globe, so the
     reader never sees the flat map jump into the globe. */
  function settle() {
    box.classList.add('loading');
    map.once('idle', function () { box.classList.remove('loading'); });
  }
  function loadOnce(src, css) {
    if (loading) { return loading; }
    loading = new Promise(function (resolve, reject) {
      var link = document.createElement('link');
      link.rel = 'stylesheet'; link.href = css; document.head.appendChild(link);
      var s = document.createElement('script');
      s.src = src; s.onload = resolve; s.onerror = reject; document.head.appendChild(s);
    });
    return loading;
  }

  function buildMap() {
    if (map) { return; }
    /* No zoom at all (owner 2026-09-08: "without a zoom option"): no
       buttons, no wheel, no pinch. The map is a picker; drag turns it. */
    map = new maplibregl.Map({
      container: box, style: box.getAttribute('data-style'),
      center: [10, 25], zoom: 1.3, attributionControl: { compact: true },
      scrollZoom: false, doubleClickZoom: false, touchZoomRotate: false, keyboard: false, boxZoom: false
    });
    map.on('load', function () {
      settle();
      map.setProjection({ type: 'globe' });
      map.jumpTo(frame());
      map.addSource('regions', { type: 'geojson', data: box.getAttribute('data-outlines'), generateId: true });
      map.addLayer({ id: 'regions-fill', type: 'fill', source: 'regions',
        paint: { 'fill-color': '#1C3A2A', 'fill-opacity': ['case', ['boolean', ['feature-state', 'hover'], false], 0.55, 0.28] } });
      map.addLayer({ id: 'regions-line', type: 'line', source: 'regions',
        paint: { 'line-color': '#1C3A2A', 'line-width': 1.2, 'line-opacity': 0.8 } });

      var hovered = null;
      var tip = document.createElement('div');
      tip.className = 'regions-tip'; tip.hidden = true; box.appendChild(tip);
      map.on('mousemove', 'regions-fill', function (e) {
        var f = e.features && e.features[0]; if (!f) { return; }
        map.getCanvas().style.cursor = 'pointer';
        if (hovered !== null && hovered !== f.id) { map.setFeatureState({ source: 'regions', id: hovered }, { hover: false }); }
        hovered = f.id; map.setFeatureState({ source: 'regions', id: hovered }, { hover: true });
        var cc = f.properties.cc;
        tip.textContent = (names[cc] || cc);
        tip.hidden = false;
        tip.style.left = (e.point.x + 12) + 'px'; tip.style.top = (e.point.y + 12) + 'px';
      });
      map.on('mouseleave', 'regions-fill', function () {
        map.getCanvas().style.cursor = '';
        if (hovered !== null) { map.setFeatureState({ source: 'regions', id: hovered }, { hover: false }); hovered = null; }
        tip.hidden = true;
      });
      map.on('click', 'regions-fill', function (e) {
        var f = e.features && e.features[0]; if (!f) { return; }
        var cc = String(f.properties.cc || '').toUpperCase();
        if (/^[A-Z]{2}$/.test(cc)) { history.replaceState(null, '', '#country-' + cc); openCountry(cc); }
      });
    });
  }

  row.querySelectorAll('.chip[data-view]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      var view = chip.getAttribute('data-view');
      row.querySelectorAll('.chip[data-view]').forEach(function (c) {
        var on = c === chip; c.classList.toggle('on', on); c.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      if (view === 'globe') {
        box.hidden = false;
        box.classList.add('loading');
        loadOnce(box.getAttribute('data-maplibre-js'), box.getAttribute('data-maplibre-css'))
          .then(buildMap)
          .catch(function () { box.hidden = true; box.classList.remove('loading'); });
      } else {
        box.hidden = true;
      }
    });
  });
}());
