// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* /regions: the world map view, and the list's own small courtesies.

   The list is the page; the map is a second way to pick a country (owner
   2026-09-08: "make a map version where you select the country on a world
   map"). MapLibre and the outlines are fetched only when the reader asks for
   the map, from the same vendored file the map page uses. A click on a
   region opens its country's block in the list below and scrolls to it.

   Also: a country block reached by a link (#country-NL, from the typeahead or
   the map) opens its regions, which are closed by default. */
(function () {
  'use strict';

  function openCountry(code) {
    var block = document.getElementById('country-' + code);
    if (!block) { return; }
    var regs = block.querySelector('details.regs');
    if (regs) { regs.open = true; }
    block.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
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
    map = new maplibregl.Map({
      container: box, style: box.getAttribute('data-style'),
      center: [10, 25], zoom: 1.3, minZoom: 1, attributionControl: { compact: true }
    });
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    map.on('load', function () {
      map.addSource('regions', { type: 'geojson', data: box.getAttribute('data-outlines'), generateId: true });
      map.addLayer({ id: 'regions-fill', type: 'fill', source: 'regions',
        paint: { 'fill-color': '#1C3A2A', 'fill-opacity': ['case', ['boolean', ['feature-state', 'hover'], false], 0.55, 0.28] } });
      map.addLayer({ id: 'regions-line', type: 'line', source: 'regions',
        paint: { 'line-color': '#1C3A2A', 'line-width': 0.8, 'line-opacity': 0.7 } });

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
      if (view === 'map') {
        box.hidden = false;
        loadOnce(box.getAttribute('data-maplibre-js'), box.getAttribute('data-maplibre-css'))
          .then(buildMap)
          .catch(function () { box.hidden = true; });
      } else {
        box.hidden = true;
      }
    });
  });
}());
