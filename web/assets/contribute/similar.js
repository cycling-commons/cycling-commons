// SPDX-License-Identifier: AGPL-3.0-only
/* "Similar places within 250 m", under the wizard's pin.

   Everyone adding or correcting a place sees what is already there that may
   be the same place: our own places, a provider's record (RIVM, say) and
   OpenStreetMap points, of the same kind (catalog-data-model.md §5a). Each
   has a tick, "same place as mine". What stays ticked rides in the hidden
   `replaces` field and is retired only when a curator approves the new
   place (ReplacedPlaces), never here.

   Places within 50 m start ticked, the distance a provider's own match
   radius treats as the same thing; farther ones start unticked, because a
   second tap across a square is a real second tap.

   On the rider's own new place while it waits, the list is asked again:
   `data-item` and `data-ref` keep the place and its OSM point off its own
   list, and `data-ticks` brings back what the rider left ticked. Without
   `data-ticks` (a place sent before the list existed) the distance rule
   above applies.

   The list follows the pin: `cc:loc` rebuilds it, and a stale answer from a
   pin that has since moved is dropped. Nothing here blocks Next. */
(function () {
  'use strict';

  var box = document.getElementById('wz-similar');
  var head = document.getElementById('wz-similar-head');
  var list = document.getElementById('wz-similar-list');
  var field = document.querySelector('[name$="[replaces]"]');
  if (!box || !head || !list || !field) { return; }

  var d = box.dataset;
  var ref = d.ref || new URLSearchParams(location.search).get('ref') || '';
  var prior = null;
  try { prior = d.ticks ? JSON.parse(d.ticks) : null; } catch (e) { prior = null; }
  var seq = 0;
  var last = '';

  function fill(template, vars) {
    return template.replace(/%(\w+)%/g, function (m, k) { return k in vars ? String(vars[k]) : m; });
  }

  function write() {
    var ticks = [];
    list.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
      if (cb.checked) { ticks.push(cb.value); }
    });
    field.value = ticks.join(',');
  }

  function from(place) {
    if ('osm' === place.from) { return d.tOsm; }
    if ('provider' === place.from && place.provider) { return place.provider; }
    return d.tOurs;
  }

  function render(places) {
    list.textContent = '';
    if (!places.length) {
      box.hidden = true;
      field.value = '';
      return;
    }
    head.textContent = fill(1 === places.length ? d.tOne : d.tMany, { n: places.length, m: d.radius });
    places.forEach(function (place) {
      var li = document.createElement('li');
      var label = document.createElement('label');
      var cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.value = place.tick;
      cb.checked = Array.isArray(prior) ? prior.indexOf(place.tick) >= 0 : !!place.ticked;
      cb.addEventListener('change', write);
      var what = document.createElement('span');
      what.className = 'simq-what';
      what.textContent = (place.name || d.tUnnamed) + ' · ' + from(place) + ' · ' + fill(d.tMetres, { m: place.metres });
      var same = document.createElement('span');
      same.className = 'simq-same';
      same.textContent = d.tSame;
      label.appendChild(cb);
      label.appendChild(what);
      label.appendChild(same);
      li.appendChild(label);
      list.appendChild(li);
    });
    box.hidden = false;
    write();
  }

  document.addEventListener('cc:loc', function (e) {
    var loc = e.detail || {};
    if ('point' !== loc.type || typeof loc.lat !== 'number' || typeof loc.lng !== 'number') {
      render([]);
      return;
    }
    var key = loc.lat.toFixed(6) + ',' + loc.lng.toFixed(6);
    if (key === last) { return; }
    last = key;

    var mine = ++seq;
    var q = new URLSearchParams({ type: d.type, lat: String(loc.lat), lng: String(loc.lng) });
    if (ref) { q.set('ref', ref); }
    if (d.item) { q.set('item', d.item); }
    fetch(d.url + '?' + q.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : { places: [] }; })
      .then(function (out) {
        if (mine !== seq) { return; }   // the pin moved while this was on the way
        render((out && out.places) || []);
      })
      .catch(function () { if (mine === seq) { render([]); } });
  });
})();
