// SPDX-License-Identifier: AGPL-3.0-only
/* Per-country tile archives (docs/specs/coverage-provider.md §4).
   window.CC_TILES maps family -> cc | '*' -> {tiles: {arm: url}, bounds, stamp}.
   A country's source is added when its bounds meet the viewport, once, and
   never removed. '*' is one archive serving every country (a v1 manifest or a
   pin), so its source feeds every country's layers. */

const WORLD = [-180, -85.0511, 180, 85.0511];
let protocolAdded = false;

/** Register the pmtiles protocol once for the page. */
export function ensureProtocol() {
  if (protocolAdded || typeof pmtiles === 'undefined' || typeof maplibregl === 'undefined') return;
  maplibregl.addProtocol('pmtiles', new pmtiles.Protocol().tile);
  protocolAdded = true;
}

export function familyEntries(family) {
  const t = window.CC_TILES && window.CC_TILES[family];
  return t && typeof t === 'object' ? t : {};
}

export function familyConfigured(family, arm) {
  return Object.values(familyEntries(family))
    .some(e => e && e.tiles && typeof e.tiles[arm] === 'string' && !!e.tiles[arm]);
}

/** The entry serving `cc`: its own, else '*', else null. */
export function entryKeyFor(family, cc) {
  const entries = familyEntries(family);
  const k = String(cc || '').toLowerCase();
  if (k && entries[k]) return k;
  return entries['*'] ? '*' : null;
}

export function sourceIdFor(family, arm, cc) {
  const key = entryKeyFor(family, cc);
  return key === null ? null : `${family}-${arm}-${key === '*' ? 'all' : key}`;
}

/** `surface_be` -> 'be'; an unsplit layer name -> null. */
export function ccOfSourceLayer(sourceLayer) {
  const m = /_([a-z]{2})$/.exec(String(sourceLayer || ''));
  return m ? m[1] : null;
}

/** The view as one or two boxes inside [-180, 180], split at the antimeridian. */
export function viewBoxes(west, south, east, north) {
  if (east - west >= 360) return [[-180, south, 180, north]];
  const wrap = x => ((((x + 180) % 360) + 360) % 360) - 180;
  const w = wrap(west), e = wrap(east);
  return w <= e ? [[w, south, e, north]] : [[w, south, 180, north], [-180, south, e, north]];
}

const meets = (a, b) => a[0] <= b[2] && a[2] >= b[0] && a[1] <= b[3] && a[3] >= b[1];
const padded = (b, f) => {
  const dx = (b[2] - b[0]) * f, dy = (b[3] - b[1]) * f;
  return [b[0] - dx, b[1] - dy, b[2] + dx, b[3] + dy];
};

/** Entry keys whose bounds meet any of the (padded) view boxes. */
export function keysInView(entries, boxes, pad = 0.25) {
  const views = boxes.map(b => padded(b, pad));
  return Object.keys(entries).filter(k => {
    const b = entries[k] && Array.isArray(entries[k].bounds) && entries[k].bounds.length === 4 ? entries[k].bounds : WORLD;
    return views.some(v => meets(b, v));
  });
}

/** Add the sources this view needs for one family arm; onAdd(key, sourceId) per new one. */
export function mountInView(map, family, arm, minzoom, onAdd) {
  if (map.getZoom() < minzoom - 1) return;
  const b = map.getBounds();
  const entries = familyEntries(family);
  keysInView(entries, viewBoxes(b.getWest(), b.getSouth(), b.getEast(), b.getNorth())).forEach(key => {
    const url = entries[key].tiles && entries[key].tiles[arm];
    const id = `${family}-${arm}-${key === '*' ? 'all' : key}`;
    if (!url || map.getSource(id)) return;
    ensureProtocol();
    map.addSource(id, { type: 'vector', url: 'pmtiles://' + url });
    onAdd(key, id);
  });
}

/** The newest build stamp in a family, for the data-version readout. */
export function newestStamp(family) {
  const stamps = Object.values(familyEntries(family)).map(e => (e && e.stamp) || '').filter(Boolean).sort();
  return stamps.length ? stamps[stamps.length - 1] : '';
}
