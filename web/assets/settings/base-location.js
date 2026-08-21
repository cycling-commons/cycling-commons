// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Settings base location (docs/specs/map-and-search.md §4.5): Photon typeahead
// + radius slider. Map-free — pin-drop is the map page's "Set my area".
(function () {
  'use strict';

  var input = document.querySelector('[data-base-query]');
  if (!input) return;

  var radiusInput = document.querySelector('[data-base-radius]');
  var radiusOutput = document.querySelector('[data-radius-output]');
  if (radiusInput && radiusOutput) {
    var syncRadiusFill = function () {
      var min = parseFloat(radiusInput.min) || 0;
      var max = parseFloat(radiusInput.max) || 100;
      var val = parseFloat(radiusInput.value) || min;
      var pct = max > min ? ((val - min) / (max - min)) * 100 : 0;
      radiusInput.style.setProperty('--fill', pct + '%');
    };
    radiusInput.addEventListener('input', function () {
      // Stored value is km; the readout is the rider's unit (docs/specs/account-and-auth.md §9).
      radiusOutput.textContent = window.ccKm ? window.ccKm(radiusInput.value, 0) : radiusInput.value + ' km';
      syncRadiusFill();
    });
    syncRadiusFill();
  }

  var results = document.getElementById('baseLocRes');
  var latField = document.getElementById('settings_baseLat');
  var lngField = document.getElementById('settings_baseLng');
  var placeField = document.getElementById('settings_basePlace');
  if (!results || !latField || !lngField || !placeField) return;

  var PH_BASE = 'https://photon.komoot.io/api/?limit=6'
    + '&osm_tag=place:city&osm_tag=place:town&osm_tag=place:village&osm_tag=place:hamlet&osm_tag=place:municipality';

  var abortCtl = null;
  var hits = [];

  function escHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[c];
    });
  }

  function closeResults() {
    results.hidden = true;
    results.innerHTML = '';
    input.setAttribute('aria-expanded', 'false');
  }

  function renderResults() {
    if (!hits.length) {
      closeResults();
      return;
    }
    results.innerHTML = hits.map(function (h, i) {
      return '<li role="option"><button type="button" data-i="' + i + '">'
        + escHtml(h.name)
        + (h.admin ? '<span class="sub">' + escHtml(h.admin) + '</span>' : '')
        + '</button></li>';
    }).join('');
    results.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }

  function pick(i) {
    var h = hits[i];
    if (!h) return;
    input.value = h.name;
    latField.value = String(h.lat);
    lngField.value = String(h.lng);
    placeField.value = h.name;
    closeResults();
  }

  function runPhoton(qRaw) {
    var q = qRaw.trim();
    // Abort even when the query drops below 3 chars, or a stale reply reopens the list.
    if (abortCtl) abortCtl.abort();
    if (q.length < 3) {
      hits = [];
      closeResults();
      return;
    }
    var ctl = new AbortController();
    abortCtl = ctl;
    var url = PH_BASE + '&q=' + encodeURIComponent(q);
    fetch(url, { signal: ctl.signal })
      .then(function (r) {
        if (!r.ok) throw new Error(String(r.status));
        return r.json();
      })
      .then(function (d) {
        if (ctl.signal.aborted) return;
        hits = (d.features || [])
          .filter(function (f) {
            return f && f.properties && f.properties.name && f.geometry && Array.isArray(f.geometry.coordinates);
          })
          .map(function (f) {
            var c = f.geometry.coordinates;
            var p = f.properties;

            return {
              name: p.name,
              admin: [p.state, p.country].filter(Boolean).join(', '),
              lat: +c[1],
              lng: +c[0],
            };
          });
        renderResults();
      })
      .catch(function () { /* abort / network / quota — degrade silently */ });
  }

  var debounceId = null;
  input.addEventListener('input', function () {
    // Manual edit invalidates picked coords until the next pick.
    latField.value = '';
    lngField.value = '';
    placeField.value = '';
    clearTimeout(debounceId);
    debounceId = setTimeout(function () { runPhoton(input.value); }, 350);
  });

  results.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-i]');
    if (!btn) return;
    pick(parseInt(btn.getAttribute('data-i'), 10));
  });

  input.addEventListener('keydown', function (e) {
    if (e.key !== 'ArrowDown' || results.hidden) return;
    var first = results.querySelector('button[data-i]');
    if (first) {
      e.preventDefault();
      first.focus();
    }
  });
  results.addEventListener('keydown', function (e) {
    var btn = e.target.closest('button[data-i]');
    if (!btn) return;
    if (e.key === 'Escape') {
      closeResults();
      input.focus();
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      var items = Array.prototype.slice.call(results.querySelectorAll('button[data-i]'));
      var idx = items.indexOf(btn) + (e.key === 'ArrowDown' ? 1 : -1);
      if (idx >= 0 && idx < items.length) items[idx].focus();
      else if (idx < 0) input.focus();
    }
  });

  document.addEventListener('click', function (e) {
    if (e.target !== input && !results.contains(e.target)) closeResults();
  });
})();
