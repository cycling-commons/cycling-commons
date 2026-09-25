// SPDX-License-Identifier: AGPL-3.0-only
/* Controls for the rider's steepest point, beside the measured one
   (docs/specs/climb-elevation.md). Optional: + STEEPEST POINT arms the mode,
   CANCEL or Escape leaves it. Text only, never innerHTML. */
(function () {
  'use strict';

  /* ClimbGeometry::steepPoint's pattern; null when the server would refuse it. */
  function normalizePct(raw) {
    var s = String(raw == null ? '' : raw).replace(/\s+/g, '').replace(',', '.');
    if ('' === s) return '';
    if (!/^~?\d{1,2}(\.\d{1,2})?%?$/.test(s)) return null;
    return /%$/.test(s) ? s : s + '%';
  }

  function mount(editor, els, doc) {
    var point = null;
    var placing = false;

    function press(el, fn) {
      el.addEventListener('click', fn);
      el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fn(); }
      });
    }

    function commit() {
      if (!point) return;
      var pct = normalizePct(els.pct.value);
      if (null === pct) {
        els.pct.setCustomValidity(els.pct.dataset.invalid || 'invalid');
        return;
      }
      els.pct.setCustomValidity('');
      editor.setSteepestPoint(point.at, pct, els.note.value.trim());
    }

    press(els.mark, function () { editor.markSteepestPoint(); });
    press(els.cancel, function () { editor.cancelSteepestPoint(); });
    press(els.remove, function () { editor.clearSteepestPoint(); });
    [els.pct, els.note].forEach(function (f) {
      f.addEventListener('input', commit);
      // Enter would submit the wizard form mid-sentence.
      f.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
    });
    doc.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && placing) editor.cancelSteepestPoint();
    });

    function render(st) {
      point = st.steepPoint || null;
      placing = !!st.placingRider;
      els.box.hidden = !(st.start && st.summit);
      els.mark.hidden = !!point || placing;
      els.cancel.hidden = !placing;
      els.fields.hidden = !point;
      if (!point) return;
      if (doc.activeElement !== els.pct) els.pct.value = point.pct || '';
      if (doc.activeElement !== els.note) els.note.value = point.note || '';
    }

    return { render: render };
  }

  var API = { normalizePct: normalizePct, mount: mount };

  if (typeof window !== 'undefined') {
    window.Cc = window.Cc || {};
    window.Cc.riderSteep = API;
  }
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
})();
