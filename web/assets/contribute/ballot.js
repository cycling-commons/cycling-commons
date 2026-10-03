// SPDX-License-Identifier: AGPL-3.0-only
/* The season ballot without page reloads where they get in the way
   (docs/specs/route-domain.md §8d, owner 2026-10-02):
   - choosing a region opens its list at once, no "Show" press;
   - the up and down arrows reorder the ballot with one background call.
   Without this script every form still works as a plain POST. */
(function () {
  'use strict';

  var region = document.querySelector('form.vregion');
  if (region) {
    var select = region.querySelector('select');
    var show = region.querySelector('button[type="submit"]');
    if (show) show.hidden = true;
    if (select) select.addEventListener('change', function () { region.submit(); });
  }

  /* "Find a place": the list is A to Z and complete, so a long one narrows
     by name, accents and case ignored. */
  var find = document.querySelector('[data-cand-find]');
  if (find) {
    var input = find.querySelector('input');
    var fold = function (t) { return String(t).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); };
    var cands = Array.prototype.slice.call(document.querySelectorAll('#cands .cand'));
    if (cands.length > 8) find.hidden = false;
    input.addEventListener('input', function () {
      var q = fold(input.value.trim());
      cands.forEach(function (c) {
        var h = c.querySelector('h4');
        c.hidden = '' !== q && fold(h ? h.textContent : '').indexOf(q) === -1;
      });
    });
  }

  var box = document.getElementById('ballot');
  if (!box) return;

  var points = [];
  try { points = JSON.parse(box.getAttribute('data-points') || '[]'); } catch (e) { points = []; }
  var many = box.getAttribute('data-pts-many') || '%n% points';
  var one = box.getAttribute('data-pts-one') || '1 point';

  function rows() {
    return Array.prototype.slice.call(box.querySelectorAll('.bitem'));
  }

  /* Ranks, points and arrows follow the order on the page. */
  function renumber() {
    var list = rows();
    list.forEach(function (row, i) {
      var n = points[i] || 0;
      var rank = row.querySelector('.brank');
      var pts = row.querySelector('.bpts');
      var up = row.querySelector('button[value="up"]');
      var down = row.querySelector('button[value="down"]');
      if (rank) rank.textContent = String(i + 1);
      if (pts) pts.textContent = 1 === n ? one : many.replace('%n%', String(n));
      if (up) up.hidden = 0 === i;
      if (down) down.hidden = i === list.length - 1;
    });
  }

  function swap(row, other, up) {
    if (up) other.parentNode.insertBefore(row, other);
    else other.parentNode.insertBefore(other, row);
    renumber();
  }

  box.addEventListener('click', function (e) {
    var button = e.target.closest('button.mv');
    if (!button || !button.form) return;
    e.preventDefault();
    var up = 'up' === button.value;
    var row = button.closest('.bitem');
    var other = up ? row.previousElementSibling : row.nextElementSibling;
    if (!other || !other.classList.contains('bitem')) return;

    var data = new FormData(button.form);
    data.set('do', button.value);
    // The list answers the click at once; the server confirms behind it.
    swap(row, other, up);
    var still = row.querySelector('button[value="' + button.value + '"]');
    (still && !still.hidden ? still : row.querySelector('button.mv:not([hidden])') || row.querySelector('button.x')).focus();

    fetch(button.form.action, {
      method: 'POST',
      body: data,
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    }).then(function (r) {
      return r.ok ? r.json() : { moved: false };
    }).catch(function () {
      return { moved: false };
    }).then(function (d) {
      if (d && d.moved) return;
      // Put it back and let the plain form show the server's answer.
      swap(row, other, !up);
      button.hidden = false;
      button.form.requestSubmit(button);
    });
  });
})();
