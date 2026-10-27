// SPDX-License-Identifier: AGPL-3.0-only
/* The links of the right-click "Add here" list (map-and-search.md, "Add here from the map"): each place type's add
   form at the spot, the pin set there and the map at this zoom. None for a visitor (no CC_ADD_HERE). */
export function addHereHtml(cfg, lat, lng, zoom){
  if(!cfg || !Array.isArray(cfg.types) || !cfg.types.length) return '';
  const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
  const at = `&lat=${lat.toFixed(6)}&lng=${lng.toFixed(6)}&z=${Math.max(14, Math.round(zoom))}`;
  const links = cfg.types.map(t => `<li><a href="${esc(`${cfg.href}?type=${encodeURIComponent(t.type)}&mode=add${at}`)}">${esc(t.label)}</a></li>`).join('');
  return `<div class="pop-add"><div class="pop-co">${esc(cfg.head || 'Add here')}</div><ul>${links}</ul></div>`;
}
