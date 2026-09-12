// SPDX-License-Identifier: AGPL-3.0-only
/* /coverage's globe: the table's numbers as a painted globe (owner
   2026-09-08: "a cool way to visualize the coverage besides a list").

   Each country is filled by its density class, five steps of one green from
   pale to spruce, decided on the server with the legend
   (CoverageStatsProvider::densityClasses); the page hands the class per
   country on its card (data-cls) and the five colours on the box
   (data-ramp), so the paint and the legend cannot drift apart. A country
   with nothing on file is the palest wash of ink, outside the ramp. Hover
   names the country and its density; a click shows that country's card,
   the same figures its table row holds. Nothing here is a string: every
   word on the page is rendered by Twig.

   This also owns the view chips, because the two jobs are one: MapLibre is
   fetched on the globe's first showing and never before, so a phone that
   opens on the table never pays for the library at all. The chips stay
   hidden until this file runs, so a reader with no JavaScript is not offered
   a switch that cannot move. */
(function () {
  'use strict';

  var box = document.getElementById('coverage-globe');
  if (!box) { return; }

  var root = document.documentElement;
  var row = document.getElementById('coverage-viewrow');
  var built = false;

  function build() {
    if (built || typeof window.ccCountryGlobe !== 'function') { return; }
    built = true;

    var ramp = (box.getAttribute('data-ramp') || '').split(',');
    var none = box.getAttribute('data-none') || 'rgba(20,22,14,0.10)';
    var cards = {};
    document.querySelectorAll('.ccard[data-code]').forEach(function (c) { cards[c.getAttribute('data-code')] = c; });

    var fill = ['match', ['get', 'cc']];
    Object.keys(cards).forEach(function (cc) {
      var cls = parseInt(cards[cc].getAttribute('data-cls') || '0', 10);
      fill.push(cc, cls > 0 && ramp[cls - 1] ? ramp[cls - 1] : none);
    });
    fill.push(none);

    var empty = document.getElementById('ccard-empty');
    function show(cc) {
      Object.keys(cards).forEach(function (k) { cards[k].hidden = k !== cc; });
      if (empty) { empty.hidden = Boolean(cards[cc]); }
    }

    window.ccCountryGlobe(box, {
      fillColor: fill.length > 3 ? fill : none,
      fillOpacity: ['case', ['boolean', ['feature-state', 'hover'], false], 1, 0.85],
      underLabels: true,
      tip: function (cc) { var c = cards[cc]; return c ? c.getAttribute('data-tip') || '' : ''; },
      onPick: show
    }).catch(function () {
      /* A box that cannot draw is worse than no box, so fall back to the
         table rather than leave an empty frame on screen. */
      root.classList.remove('cc-globe');
      mark();
    });
  }

  function mark() {
    if (!row) { return; }
    var globe = root.classList.contains('cc-globe');
    row.querySelectorAll('.chip[data-view]').forEach(function (c) {
      c.setAttribute('aria-pressed', ('globe' === c.getAttribute('data-view')) === globe ? 'true' : 'false');
    });
  }

  if (row) {
    row.hidden = false;
    row.querySelectorAll('.chip[data-view]').forEach(function (chip) {
      chip.addEventListener('click', function () {
        var globe = 'globe' === chip.getAttribute('data-view');
        root.classList.toggle('cc-globe', globe);
        mark();
        if (globe) { build(); }
      });
    });
    mark();
  }

  if (root.classList.contains('cc-globe')) { build(); }
}());
