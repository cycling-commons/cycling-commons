// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* /regions: the world map view, and the list's own small courtesies.

   The list is the page; the map is a second way to pick a country (owner
   2026-09-08: "make a map version where you select the country on a world
   map"). MapLibre and the shapes are fetched only when the reader asks for
   the globe, from the same vendored file the map page uses. One shape per
   country, not its regions (owner 2026-09-08); a click opens that
   country's block in the list below and scrolls to it. The globe itself is
   country-globe.js, shared with /coverage.

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
  if (!row || !box || typeof window.ccCountryGlobe !== 'function') { return; }
  row.hidden = false;   // the switch exists only where the script runs

  var names = {};
  try {
    var src = document.getElementById('cc-countries');
    JSON.parse(src.textContent).forEach(function (c) { names[c.code] = c.name; });
  } catch (e) { /* names are a courtesy; codes still work */ }

  /* The globe itself lives in country-globe.js, shared with /coverage; this
     page only says what a hover shows and what a click does. Built once, on
     the first press of the chip; the list chip hides the box again. */
  var built = null;
  function showGlobe() {
    if (built) { box.hidden = false; return; }
    built = window.ccCountryGlobe(box, {
      tip: function (cc) { return names[cc] || cc; },
      onPick: function (cc) { history.replaceState(null, '', '#country-' + cc); openCountry(cc); }
    }).catch(function () { built = null; });
  }

  /* A wide screen opens on the globe (owner 2026-09-08: "should open on the
     globe page if not mobile"); a phone keeps the list, MapLibre unloaded. */
  var globeChip = row.querySelector('.chip[data-view="globe"]');
  if (globeChip && window.matchMedia && window.matchMedia('(min-width: 900px)').matches) {
    setTimeout(function () { globeChip.click(); }, 0);
  }
  row.querySelectorAll('.chip[data-view]').forEach(function (chip) {
    chip.addEventListener('click', function () {
      var view = chip.getAttribute('data-view');
      row.querySelectorAll('.chip[data-view]').forEach(function (c) {
        var on = c === chip; c.classList.toggle('on', on); c.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      if (view === 'globe') { showGlobe(); } else { box.hidden = true; }
    });
  });
}());
