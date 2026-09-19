// SPDX-License-Identifier: AGPL-3.0-only
/* The map's door to the site-wide dropdown (assets/js/select-box.js, loaded
   as a plain script before these modules). One implementation for every
   page; this file only lets modules import it. */
const box = () => (window.Cc && window.Cc.selectBox) || null;

/** Draw one select as our box. `decorate(option)` may return extra nodes for a row. */
export function enhanceSelect(sel, opts = {}) {
  const b = box();
  return b ? b.enhance(sel, opts) : null;
}

/** Draw every select under `root`, now and whenever more are added. */
export function initSelectBoxes(root = document.body) {
  const b = box();
  if (b) b.init(root);
}
