// SPDX-License-Identifier: AGPL-3.0-only
// The curator rulebook's tabs (templates/moderate/rulebook.html.twig). The
// markup and keys follow the settings Profile/Security tabs: role=tablist,
// role=tab buttons with aria-selected and a roving tabindex, role=tabpanel
// panels, ArrowLeft/ArrowRight/Home/End, and ?tab=<key> in the address.
//
// The page renders every panel and a hidden tab list, so without this script
// it reads as one long page. This script shows the tab list, marks the page
// tabbed (.rb-tabbed, which hides every panel but the open one on screen;
// print still shows them all) and picks the open tab:
//   - a #hash naming an element inside a panel (a section id such as
//     #takedowns, or a panel id) opens that panel and scrolls to it, on load
//     and on every hash change, so links from the desks and between sections
//     keep landing;
//   - otherwise ?tab=<key> opens that tab;
//   - otherwise the first tab.
// Choosing a tab rewrites the address to ?tab=<key> (the bare path for the
// first tab) and drops any hash, so a reload reopens the same tab.
(function () {
  'use strict';

  /* The tab key a location opens. `hash` and `search` are location.hash and
     location.search; `panelKeyOf(id)` gives the key of the panel holding the
     element with that id, or null; `keys` lists the tab keys in order. */
  function pickTab(hash, search, panelKeyOf, keys) {
    var id = '';
    try { id = decodeURIComponent(String(hash || '').replace(/^#/, '')); } catch (e) { id = ''; }
    if (id) {
      var fromHash = panelKeyOf(id);
      if (fromHash && keys.indexOf(fromHash) !== -1) return fromHash;
    }
    var m = /[?&]tab=([^&#]*)/.exec(String(search || ''));
    if (m) {
      var fromQuery = '';
      try { fromQuery = decodeURIComponent(m[1]); } catch (e) { fromQuery = ''; }
      if (keys.indexOf(fromQuery) !== -1) return fromQuery;
    }
    return keys[0];
  }

  /* The address a chosen tab leaves behind. */
  function urlFor(key, keys, pathname) {
    return key === keys[0] ? pathname : pathname + '?tab=' + encodeURIComponent(key);
  }

  if (typeof document === 'undefined') {
    if (typeof module !== 'undefined' && module.exports) {
      module.exports = { pickTab: pickTab, urlFor: urlFor };
    }
    return;
  }

  var list = document.querySelector('[data-rb-tabs]');
  if (!list) return;
  var tabs = Array.prototype.slice.call(list.querySelectorAll('[role=tab]'));
  var keys = tabs.map(function (t) { return t.getAttribute('data-tab'); });
  var panels = tabs.map(function (t) { return document.getElementById(t.getAttribute('aria-controls')); });
  var root = list.parentNode;

  function panelKeyOf(id) {
    var el = document.getElementById(id);
    if (!el) return null;
    for (var i = 0; i < panels.length; i++) {
      if (panels[i] && (panels[i] === el || panels[i].contains(el))) return keys[i];
    }
    return null;
  }

  function show(key) {
    tabs.forEach(function (t, i) {
      var on = keys[i] === key;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      if (panels[i]) panels[i].classList.toggle('on', on);
    });
  }

  function choose(i, focus) {
    show(keys[i]);
    history.replaceState(null, '', urlFor(keys[i], keys, location.pathname));
    if (focus) tabs[i].focus();
  }

  /* Opens the tab the address names; scrolls to the hash target when there is one. */
  function follow() {
    var key = pickTab(location.hash, location.search, panelKeyOf, keys);
    show(key);
    var id = '';
    try { id = decodeURIComponent(location.hash.replace(/^#/, '')); } catch (e) { id = ''; }
    var target = id && panelKeyOf(id) ? document.getElementById(id) : null;
    if (target) target.scrollIntoView();
  }

  tabs.forEach(function (btn, i) {
    btn.addEventListener('click', function () { choose(i, false); });
    btn.addEventListener('keydown', function (e) {
      var idx;
      if (e.key === 'ArrowRight') idx = (i + 1) % tabs.length;
      else if (e.key === 'ArrowLeft') idx = (i - 1 + tabs.length) % tabs.length;
      else if (e.key === 'Home') idx = 0;
      else if (e.key === 'End') idx = tabs.length - 1;
      else return;
      e.preventDefault();
      choose(idx, true);
    });
  });

  list.hidden = false;
  if (root) root.classList.add('rb-tabbed');
  follow();
  window.addEventListener('hashchange', follow);
})();
