// SPDX-License-Identifier: AGPL-3.0-only
/* "Is this place already in OpenStreetMap?", asked in the wizard.

   The moderation card asks it before it will approve a new place
   (catalog-data-model.md §5b). A curator's own place is approved at submit
   time, so without this the person filling the form would queue behind
   themselves and then answer their own question on the desk a moment later
   (owner 2026-09-06).

   Only a curator loads this file, and only when adding a place that did not
   come from an OSM node, which has answered the question by construction.

   The candidates depend on where the pin is, so the list is rebuilt whenever
   `cc:loc` says the point moved. The answer itself rides in a hidden form
   field: `none` for "not in OpenStreetMap", otherwise the object's ref. An
   unanswered form is still submittable; it simply queues, which is what it
   did before this existed. */
(function () {
  'use strict';

  var box = document.getElementById('wz-osmq');
  var list = document.getElementById('wz-osmq-list');
  var field = document.querySelector('[name$="[osmAnswer]"]');
  if (!box || !list || !field) { return; }

  var D = window.CC_OSMQ_I18N || {};
  var TYPE = new URLSearchParams(location.search).get('type') || '';
  var last = '';
  var seq = 0;

  function promise() {
    var queued = document.getElementById('lc-queued');
    var curator = document.getElementById('lc-curator');
    if (!queued || !curator) { return; }

    var answered = '' !== field.value;
    queued.hidden = answered;
    curator.hidden = !answered;
  }

  /* Everything that changes the answer ends here, so the step's Next button is
     re-asked in one place. Answering is not optional (owner 2026-09-12: "user
     must choose"), and the first render is as much a change as a click: a page
     arriving with a pin already set must not start with the way out open. */
  function settled() {
    promise();
    if (window.CC_WZ_REGATE) { window.CC_WZ_REGATE(); }
  }

  function choose(value) {
    field.value = value;
    list.querySelectorAll('[data-osm-choice]').forEach(function (el) {
      var on = el.getAttribute('data-osm-choice') === value;
      el.classList.toggle('on', on);
      el.setAttribute('aria-checked', on ? 'true' : 'false');
    });
    settled();
  }

  function option(value, label, hint) {
    var el = document.createElement('button');
    el.type = 'button';
    el.className = 'osmq-opt';
    el.setAttribute('role', 'radio');
    el.setAttribute('aria-checked', 'false');
    el.setAttribute('data-osm-choice', value);
    el.appendChild(Object.assign(document.createElement('b'), { textContent: label }));
    if (hint) { el.appendChild(Object.assign(document.createElement('span'), { textContent: hint })); }
    el.addEventListener('click', function () { choose(value); });
    return el;
  }

  /* An object this atlas already holds is not a choice to refuse, it is the
     entry the person was about to duplicate. So it leads there instead of
     sitting greyed out (owner 2026-09-12: "I should be able to say use the
     existing object"). A link, not a button: it leaves this form. */
  function existing(c) {
    var el = document.createElement('a');
    el.className = 'osmq-opt osmq-opt--existing';
    el.href = '/improve?item=' + encodeURIComponent(c.itemId) + '&type=' + encodeURIComponent(TYPE);
    el.appendChild(Object.assign(document.createElement('b'), { textContent: D.use_existing || 'Use the existing entry' }));
    el.appendChild(Object.assign(document.createElement('span'), {
      textContent: (c.name || D.unnamed || '') + ' · ' + Math.round(c.distanceM) + ' m',
    }));
    return el;
  }

  function render(candidates) {
    // Nothing of this kind within the radius, so the only possible answer is
    // "not in OpenStreetMap". A question with one answer is not a question:
    // it answers itself and stays out of the way (owner 2026-09-12). The pin
    // moving into a populated spot brings it back, because every move clears
    // the answer and asks again.
    if (0 === candidates.length) {
      box.hidden = true;
      field.value = 'none';
      settled();

      return;
    }

    list.textContent = '';
    candidates.forEach(function (c) {
      if (c.taken && c.itemId) {
        list.appendChild(existing(c));

        return;
      }
      list.appendChild(option(c.ref, c.name || (D.unnamed || 'Unnamed'),
        Math.round(c.distanceM) + ' m · ' + c.ref));
    });
    // Always offered, and last: "not in OpenStreetMap" is an answer, and it is
    // the right one more often than the list is.
    list.appendChild(option('none', D.none || 'Not in OpenStreetMap', D.none_hint || ''));
    box.hidden = false;
    settled();

    // The way out of a duplicate is the default action when there is one: it
    // takes the focus, so Enter goes to the entry rather than deeper into a
    // form nobody should be filling in. Only when nothing else has the focus,
    // because stealing it from somebody mid-typing is worse than any default.
    var first = list.querySelector('.osmq-opt--existing');
    if (first && (!document.activeElement || document.body === document.activeElement)) {
      first.focus({ preventScroll: true });
    }
  }

  function load(loc) {
    if (!loc || 'point' !== loc.type) {
      box.hidden = true;
      choose('');

      return;
    }
    var key = loc.lat.toFixed(5) + ',' + loc.lng.toFixed(5);
    if (key === last) { return; }
    last = key;
    // A moved pin is a different question, so an answer to the old one goes.
    choose('');

    var mine = ++seq;
    fetch('/contribute/osm-nearby?type=' + encodeURIComponent(TYPE)
      + '&lat=' + loc.lat.toFixed(6) + '&lng=' + loc.lng.toFixed(6), { headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : { candidates: [] }; })
      .catch(function () { return { candidates: [] }; })
      .then(function (data) {
        // A slower earlier request must not overwrite a newer answer.
        if (mine === seq) { render(data.candidates || []); }
      });
  }

  document.addEventListener('cc:loc', function (e) { load(e.detail); });
}());
