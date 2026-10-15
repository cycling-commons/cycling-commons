// SPDX-License-Identifier: AGPL-3.0-only
/* Signing out forgets the ride being planned in this browser: the draft and
   its undo and redo steps (docs/specs/route-planner.md section 6.0). The
   server marks the way home with #signed-out (SignedOutMarkSubscriber); this
   removes the two keys the planner writes (assets/map/plan.js) and then the
   mark from the address bar. Nothing else in the browser's storage is
   touched: the display and map preferences stay. */
(function () {
  if (location.hash !== '#signed-out') return;
  try {
    localStorage.removeItem('cc-plan-draft');
    localStorage.removeItem('cc-plan-history');
  } catch (e) { /* storage blocked: there is nothing to forget */ }
  history.replaceState(null, '', location.pathname + location.search);
})();
