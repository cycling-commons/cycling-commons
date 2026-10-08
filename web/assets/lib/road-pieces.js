// SPDX-License-Identifier: AGPL-3.0-only
// SPDX-FileCopyrightText: 2026 BikeCoders
//
// Road pieces in the browser (docs/specs/traffic-measurements.md §2).
//
// The traffic step matches a ride against the road-piece tiles: every way a
// bike may ride, labelled cycle path (p), cycle lane (l) or shared road (r).
// This module answers two questions without touching the DOM, so node can test
// it: which z14 tiles a ride touches, and which lines one decoded tile holds.
//
// The decoder reads only what a road-piece tile carries (line features, an id,
// string properties), which keeps it a page instead of a dependency.

export const PIECE_ZOOM = 14;

function lonToTileX(lon, n) { return Math.floor((lon + 180) / 360 * n); }
function latToTileY(lat, n) {
  const r = lat * Math.PI / 180;
  return Math.floor((1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2 * n);
}

/**
 * The tiles a ride touches, each once, in the order first met.
 * A long straight leg between two fixes is walked in short steps so no tile it
 * crosses is skipped.
 *
 * @param {Array<[number, number]>} points [lon, lat] in ride order
 * @returns {Array<{z: number, x: number, y: number}>}
 */
export function tilesForTrack(points, zoom = PIECE_ZOOM) {
  const n = 2 ** zoom;
  const step = 360 / n / 4;       // a quarter tile width, in degrees
  const seen = new Set();
  const out = [];
  const add = (lon, lat) => {
    const x = lonToTileX(lon, n);
    const y = latToTileY(lat, n);
    const key = x + '/' + y;
    if (!seen.has(key)) { seen.add(key); out.push({ z: zoom, x, y }); }
  };
  for (let i = 0; i < points.length; i++) {
    const [lon, lat] = points[i];
    if (i > 0) {
      const [plon, plat] = points[i - 1];
      const parts = Math.ceil(Math.max(Math.abs(lon - plon), Math.abs(lat - plat)) / step);
      for (let k = 1; k < parts; k++) {
        add(plon + (lon - plon) * k / parts, plat + (lat - plat) * k / parts);
      }
    }
    add(lon, lat);
  }
  return out;
}

// ---- protobuf, as much as a vector tile needs ----------------------------

class Reader {
  constructor(bytes) { this.buf = bytes; this.pos = 0; this.end = bytes.length; }
  varint() {
    // Multiplication, not bit shifts: way ids pass 2^32, and a shift would
    // wrap them into a different way.
    let result = 0;
    let mul = 1;
    for (;;) {
      if (this.pos >= this.end) throw new Error('truncated varint');
      const b = this.buf[this.pos++];
      result += (b & 0x7f) * mul;
      if (b < 0x80) return result;
      mul *= 128;
    }
  }
  bytes() {
    const len = this.varint();
    const start = this.pos;
    this.pos += len;
    if (this.pos > this.end) throw new Error('truncated field');
    return this.buf.subarray(start, this.pos);
  }
  skip(wire) {
    if (wire === 0) this.varint();
    else if (wire === 1) this.pos += 8;
    else if (wire === 2) this.bytes();
    else if (wire === 5) this.pos += 4;
    else throw new Error('unknown wire type ' + wire);
  }
}

const utf8 = new TextDecoder();

function readValue(bytes) {
  const r = new Reader(bytes);
  let value = null;
  while (r.pos < r.end) {
    const tag = r.varint();
    const field = Math.floor(tag / 8);
    const wire = tag & 7;
    if (field === 1 && wire === 2) value = utf8.decode(r.bytes());
    else if ((field === 4 || field === 5) && wire === 0) value = r.varint();
    else if (field === 6 && wire === 0) { const z = r.varint(); value = z % 2 ? -(z + 1) / 2 : z / 2; }
    else if (field === 7 && wire === 0) value = r.varint() !== 0;
    else r.skip(wire);
  }
  return value;
}

function packed(bytes) {
  const r = new Reader(bytes);
  const out = [];
  while (r.pos < r.end) out.push(r.varint());
  return out;
}

function readFeature(bytes) {
  const r = new Reader(bytes);
  const f = { id: null, tags: [], type: 0, geometry: [] };
  while (r.pos < r.end) {
    const tag = r.varint();
    const field = Math.floor(tag / 8);
    const wire = tag & 7;
    if (field === 1 && wire === 0) f.id = r.varint();
    else if (field === 2 && wire === 2) f.tags = packed(r.bytes());
    else if (field === 3 && wire === 0) f.type = r.varint();
    else if (field === 4 && wire === 2) f.geometry = packed(r.bytes());
    else r.skip(wire);
  }
  return f;
}

function readLayer(bytes) {
  const r = new Reader(bytes);
  const layer = { name: '', features: [], keys: [], values: [], extent: 4096 };
  while (r.pos < r.end) {
    const tag = r.varint();
    const field = Math.floor(tag / 8);
    const wire = tag & 7;
    if (field === 1 && wire === 2) layer.name = utf8.decode(r.bytes());
    else if (field === 2 && wire === 2) layer.features.push(r.bytes());
    else if (field === 3 && wire === 2) layer.keys.push(utf8.decode(r.bytes()));
    else if (field === 4 && wire === 2) layer.values.push(readValue(r.bytes()));
    else if (field === 5 && wire === 0) layer.extent = r.varint();
    else r.skip(wire);
  }
  return layer;
}

/** The parts of a line geometry, in tile units (one array per MoveTo). */
function lineParts(geometry) {
  const parts = [];
  let x = 0;
  let y = 0;
  let current = null;
  let i = 0;
  const zig = n => (n % 2 ? -(n + 1) / 2 : n / 2);
  while (i < geometry.length) {
    const cmd = geometry[i] & 7;
    const count = Math.floor(geometry[i] / 8);
    i++;
    if (cmd === 7) continue;
    for (let k = 0; k < count; k++) {
      x += zig(geometry[i++]);
      y += zig(geometry[i++]);
      if (cmd === 1) { current = [[x, y]]; parts.push(current); } else if (current) current.push([x, y]);
    }
  }
  return parts.filter(p => p.length >= 2);
}

/**
 * Every road piece in one decoded (already decompressed) tile. A way clipped
 * into several parts inside the tile comes back once per part, same id.
 *
 * @returns {Array<{id: number, label: 'p'|'l'|'r', side: boolean, highway: string, region: number|null, cc: string|null, coords: Array<[number, number]>}>}
 */
export function decodePieces(bytes, z, x, y, cc = null) {
  const r = new Reader(bytes);
  const out = [];
  const n = 2 ** z;
  while (r.pos < r.end) {
    const tag = r.varint();
    if (Math.floor(tag / 8) !== 3 || (tag & 7) !== 2) { r.skip(tag & 7); continue; }
    const layer = readLayer(r.bytes());
    if (!layer.name.startsWith('roadpieces_')) continue;
    for (const raw of layer.features) {
      const f = readFeature(raw);
      if (f.type !== 2 || f.id === null) continue;
      const props = {};
      for (let t = 0; t + 1 < f.tags.length; t += 2) props[layer.keys[f.tags[t]]] = layer.values[f.tags[t + 1]];
      const label = props.l;
      if (label !== 'p' && label !== 'l' && label !== 'r') continue;
      for (const part of lineParts(f.geometry)) {
        out.push({
          id: f.id,
          label,
          side: props.s === 1 || props.s === true,
          highway: typeof props.h === 'string' ? props.h : '',
          // The operational region that owns the piece, when the build knew it.
          region: Number.isInteger(props.g) && props.g > 0 ? props.g : null,
          cc,
          coords: part.map(([gx, gy]) => {
            const lon = (x + gx / layer.extent) / n * 360 - 180;
            const merc = Math.PI * (1 - 2 * (y + gy / layer.extent) / n);
            return [lon, Math.atan(Math.sinh(merc)) * 180 / Math.PI];
          }),
        });
      }
    }
  }
  return out;
}

function tileCentre(z, x, y) {
  const n = 2 ** z;
  const lon = (x + 0.5) / n * 360 - 180;
  const lat = Math.atan(Math.sinh(Math.PI * (1 - 2 * (y + 0.5) / n))) * 180 / Math.PI;
  return [lon, lat];
}

/* Every archive whose bounds hold the tile: country boxes overlap along
   borders, and the roads of a border tile may be in either archive. */
function archivesFor(entries, z, x, y) {
  const [lon, lat] = tileCentre(z, x, y);
  const out = [];
  for (const [cc, entry] of Object.entries(entries || {})) {
    const b = entry && entry.bounds;
    const url = entry && entry.tiles && entry.tiles.roadpieces;
    if (url && Array.isArray(b) && lon >= b[0] && lat >= b[1] && lon <= b[2] && lat <= b[3]) out.push({ url, cc });
  }
  return out;
}

/** Reads one tile through the page's pmtiles library; null when the archive has none there. */
const archives = new Map();
export async function pmtilesTile(url, z, x, y) {
  if (typeof pmtiles === 'undefined') throw new Error('pmtiles is not loaded');
  if (!archives.has(url)) archives.set(url, new pmtiles.PMTiles(url));   // eslint-disable-line no-undef
  const res = await archives.get(url).getZxy(z, x, y);
  return res ? new Uint8Array(res.data) : null;
}

/**
 * The road pieces under a ride. `entries` is CC_TILES.roadpieces. A tile no
 * archive covers, or one that fails to load, is counted in `missingTiles` so
 * the review can say how much of the ride it could not place.
 *
 * @returns {Promise<{pieces: Array, missingTiles: number}>}
 */
// Decoded tiles per fetcher, so bulk mode reads each tile once however many
// rides cross it. Bounded: the oldest entry goes first.
const TILE_CACHE_MAX = 4000;
const decodedByFetcher = new WeakMap();

export async function loadPieces(points, entries, fetchTile = pmtilesTile) {
  if (!decodedByFetcher.has(fetchTile)) decodedByFetcher.set(fetchTile, new Map());
  const cache = decodedByFetcher.get(fetchTile);
  const pieces = [];
  let missingTiles = 0;
  for (const { z, x, y } of tilesForTrack(points)) {
    const archives = archivesFor(entries, z, x, y);
    let loaded = 0;
    for (const archive of archives) {
      const key = archive.url + '|' + z + '/' + x + '/' + y;
      if (!cache.has(key)) {
        try {
          const bytes = await fetchTile(archive.url, z, x, y);
          cache.set(key, bytes && bytes.length ? decodePieces(bytes, z, x, y, archive.cc) : []);
          if (cache.size > TILE_CACHE_MAX) cache.delete(cache.keys().next().value);
        } catch (e) {
          continue;
        }
      }
      pieces.push(...cache.get(key));
      loaded++;
    }
    if (!loaded) missingTiles++;
  }
  return { pieces, missingTiles };
}
