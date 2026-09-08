// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* One globe, one shape per country, for the pages that pick or paint
   countries: /regions picks one, /coverage?view=globe paints each by its
   density. MapLibre and the shapes load only when a page asks, from the
   vendored file the map page uses; the box says where (data-maplibre-js,
   data-maplibre-css, data-outlines, data-style, data-home for a signed-in
   rider's base, and data-zoom when the page wants it closer than 2.2).

   A globe and only a globe (owner 2026-09-08), no zoom at all: no buttons,
   no wheel, no pinch; drag turns it. The box stays hidden behind its spinner
   until the first idle frame as a globe, so nobody sees the flat map jump
   into shape.

     ccCountryGlobe(box, {
       fillColor:   MapLibre colour expression or string  (default spruce)
       fillOpacity: MapLibre number expression            (default hover-lift)
       underLabels: true puts the fills under the basemap's place names
       tip:         function (cc, feature) -> text for the hover tip, '' for none
       onPick:      function (cc) on a click
     }) -> Promise<maplibregl.Map>, rejected when MapLibre cannot load. */
(function () {
  'use strict';

  var loading = null;
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

  /* Turned to the rider's base when they have one, closer than the whole
     world, so the globe fills its box the way the owner's screenshot had it.
     A page can ask for closer still on data-zoom: the regions picker does
     (owner 2026-09-08: "zoom in a bit more"), so a small country is a target
     a pointer can hit. */
  function frame(box) {
    var home = (box.getAttribute('data-home') || '').split(',').map(Number);
    var centre = home.length === 2 && isFinite(home[0]) && isFinite(home[1]) ? home : [10, 42];
    var zoom = parseFloat(box.getAttribute('data-zoom') || '');
    return { center: centre, zoom: isFinite(zoom) ? zoom : 2.2 };
  }

  /* The first symbol layer of the basemap: fills inserted before it sit under
     the place names, so a painted country still says where it is. */
  function firstLabelLayer(map) {
    var layers = map.getStyle().layers || [];
    for (var i = 0; i < layers.length; i++) { if (layers[i].type === 'symbol') { return layers[i].id; } }
    return undefined;
  }

  /* The basemap, made here rather than fetched: one colour for the rest of
     the world, the sea, and the country borders, no names and no relief
     (owner 2026-09-08: "more simple, rest of the world one colour"). The
     vector tiles are the same planet the liberty style reads; the box names
     them on data-tiles, else they are derived from data-style's host. */
  function simpleStyle(box) {
    var tiles = box.getAttribute('data-tiles');
    if (!tiles) {
      var style = box.getAttribute('data-style') || 'https://tiles.openfreemap.org/styles/liberty';
      tiles = style.replace(/\/styles\/[^/]+$/, '/planet');
    }
    return {
      version: 8,
      sources: { omt: { type: 'vector', url: tiles } },
      layers: [
        { id: 'land', type: 'background', paint: { 'background-color': '#F3ECDD' } },
        { id: 'water', type: 'fill', source: 'omt', 'source-layer': 'water',
          filter: ['!=', ['get', 'brunnel'], 'tunnel'], paint: { 'fill-color': '#D5DFDC' } },
        { id: 'borders', type: 'line', source: 'omt', 'source-layer': 'boundary',
          filter: ['all', ['==', ['get', 'admin_level'], 2], ['!=', ['get', 'maritime'], 1], ['!=', ['get', 'disputed'], 1], ['!', ['has', 'claimed_by']]],
          paint: { 'line-color': 'rgba(20,22,14,0.22)', 'line-width': 0.7 } }
      ]
    };
  }

  function build(box, opts) {
    var map = new maplibregl.Map({
      container: box, style: simpleStyle(box),
      center: [10, 25], zoom: 1.3, attributionControl: { compact: true },
      scrollZoom: false, doubleClickZoom: false, touchZoomRotate: false, keyboard: false, boxZoom: false
    });
    return new Promise(function (resolve) {
      map.on('load', function () {
        box.classList.add('loading');
        map.once('idle', function () { box.classList.remove('loading'); });
        map.setProjection({ type: 'globe' });
        map.jumpTo(frame(box));
        map.addSource('countries', { type: 'geojson', data: box.getAttribute('data-outlines'), generateId: true });
        var before = opts.underLabels ? firstLabelLayer(map) : undefined;
        map.addLayer({ id: 'countries-fill', type: 'fill', source: 'countries',
          paint: { 'fill-color': opts.fillColor || '#1C3A2A',
                   'fill-opacity': opts.fillOpacity || ['case', ['boolean', ['feature-state', 'hover'], false], 0.55, 0.28] } }, before);
        map.addLayer({ id: 'countries-line', type: 'line', source: 'countries',
          paint: { 'line-color': '#1C3A2A', 'line-width': 1.2, 'line-opacity': 0.8 } }, before);
        /* Flat, on purpose: raised blocks were tried on 2026-09-08 and hid the
           pointer's target ("raised effect is too much, revert"). */
        var hit = 'countries-fill';

        var hovered = null;
        var tip = document.createElement('div');
        tip.className = 'globe-tip'; tip.hidden = true; box.appendChild(tip);
        map.on('mousemove', hit, function (e) {
          var f = e.features && e.features[0]; if (!f) { return; }
          map.getCanvas().style.cursor = 'pointer';
          if (hovered !== null && hovered !== f.id) { map.setFeatureState({ source: 'countries', id: hovered }, { hover: false }); }
          hovered = f.id; map.setFeatureState({ source: 'countries', id: hovered }, { hover: true });
          var cc = String(f.properties.cc || '').toUpperCase();
          var text = opts.tip ? opts.tip(cc, f) : cc;
          tip.textContent = text; tip.hidden = !text;
          tip.style.left = (e.point.x + 12) + 'px'; tip.style.top = (e.point.y + 12) + 'px';
        });
        map.on('mouseleave', hit, function () {
          map.getCanvas().style.cursor = '';
          if (hovered !== null) { map.setFeatureState({ source: 'countries', id: hovered }, { hover: false }); hovered = null; }
          tip.hidden = true;
        });
        map.on('click', hit, function (e) {
          var f = e.features && e.features[0]; if (!f) { return; }
          var cc = String(f.properties.cc || '').toUpperCase();
          if (/^[A-Z]{2}$/.test(cc) && opts.onPick) { opts.onPick(cc); }
        });
        resolve(map);
      });
    });
  }

  window.ccCountryGlobe = function (box, opts) {
    opts = opts || {};
    box.hidden = false;
    box.classList.add('loading');
    return loadOnce(box.getAttribute('data-maplibre-js'), box.getAttribute('data-maplibre-css'))
      .then(function () { return build(box, opts); })
      .catch(function (err) { box.hidden = true; box.classList.remove('loading'); throw err; });
  };
}());
