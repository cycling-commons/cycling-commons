// SPDX-License-Identifier: MIT
// SPDX-FileCopyrightText: 2026 BikeCoders
/* Scout's FIT reader, vendored verbatim from the Scout repository's
   `tools/fit-viewer.html` (MIT, same owner as this project).

   Vendored rather than rewritten, and copied between the file's own
   ===PARSER-START=== / ===PARSER-END=== markers, because those markers exist
   for exactly this: Scout's node test harness extracts the same span. Keeping
   the span byte-identical is what makes a re-sync a copy rather than a merge,
   so two implementations of a binary format cannot drift into disagreeing about
   somebody's ride.

   DO NOT EDIT the body below. Fix it in Scout and re-copy. The only addition is
   the export list at the end, which the original does not need because it runs
   as a classic script inside one page.

   What it gives us: a Scout-tagged activity decoded IN THE BROWSER — the tags,
   their coordinates, the moment each was dropped, the surface segments that a
   start/END pair describes, and the radar count. The file never leaves the
   machine it was recorded on (docs/specs/moderation-and-contribution.md,
   "Scout intake"). */

const FIT_EPOCH = 631065600; // 1989-12-31T00:00:00Z, in unix seconds

// Indexed by base type number (low 5 bits of the base type byte).
const BASE_TYPES = [
  { name: 'enum',    size: 1, invalid: 0xFF },
  { name: 'sint8',   size: 1, invalid: 0x7F },
  { name: 'uint8',   size: 1, invalid: 0xFF },
  { name: 'sint16',  size: 2, invalid: 0x7FFF },
  { name: 'uint16',  size: 2, invalid: 0xFFFF },
  { name: 'sint32',  size: 4, invalid: 0x7FFFFFFF },
  { name: 'uint32',  size: 4, invalid: 0xFFFFFFFF },
  { name: 'string',  size: 1, invalid: null },
  { name: 'float32', size: 4, invalid: null },
  { name: 'float64', size: 8, invalid: null },
  { name: 'uint8z',  size: 1, invalid: 0x00 },
  { name: 'uint16z', size: 2, invalid: 0x0000 },
  { name: 'uint32z', size: 4, invalid: 0x00000000 },
  { name: 'byte',    size: 1, invalid: 0xFF },
  { name: 'sint64',  size: 8, invalid: null },
  { name: 'uint64',  size: 8, invalid: null },
  { name: 'uint64z', size: 8, invalid: null },
];

const MESG = { FILE_ID: 0, RECORD: 20, EVENT: 21, LAP: 19, SESSION: 18,
               FIELD_DESCRIPTION: 206, DEVELOPER_DATA_ID: 207 };

const CRC_TABLE = [0x0000, 0xCC01, 0xD801, 0x1400, 0xF001, 0x3C00, 0x2800, 0xE401,
                   0xA001, 0x6C00, 0x7800, 0xB401, 0x5000, 0x9C01, 0x8801, 0x4400];

function crc16(bytes, start, end) {
  let crc = 0;
  for (let i = start; i < end; i++) {
    const b = bytes[i];
    let t = CRC_TABLE[crc & 0xF];
    crc = ((crc >> 4) & 0x0FFF) ^ t ^ CRC_TABLE[b & 0xF];
    t = CRC_TABLE[crc & 0xF];
    crc = ((crc >> 4) & 0x0FFF) ^ t ^ CRC_TABLE[(b >> 4) & 0xF];
  }
  return crc;
}

function readField(dv, pos, baseNum, size, le) {
  const bt = BASE_TYPES[baseNum];
  if (!bt) return null;
  if (bt.name === 'string') {
    const arr = [];
    for (let i = 0; i < size; i++) {
      const c = dv.getUint8(pos + i);
      if (c === 0) break;
      arr.push(c);
    }
    return new TextDecoder().decode(new Uint8Array(arr));
  }
  const n = Math.max(1, Math.floor(size / bt.size));
  const vals = [];
  for (let i = 0; i < n; i++) {
    const o = pos + i * bt.size;
    let v;
    switch (bt.name) {
      case 'enum': case 'uint8': case 'uint8z': case 'byte': v = dv.getUint8(o); break;
      case 'sint8':   v = dv.getInt8(o); break;
      case 'uint16': case 'uint16z': v = dv.getUint16(o, le); break;
      case 'sint16':  v = dv.getInt16(o, le); break;
      case 'uint32': case 'uint32z': v = dv.getUint32(o, le); break;
      case 'sint32':  v = dv.getInt32(o, le); break;
      case 'float32': v = dv.getFloat32(o, le); break;
      case 'float64': v = dv.getFloat64(o, le); break;
      case 'sint64':  v = Number(dv.getBigInt64(o, le)); break;
      case 'uint64': case 'uint64z': v = Number(dv.getBigUint64(o, le)); break;
      default: v = null;
    }
    if (bt.invalid !== null && v === bt.invalid) v = null;
    vals.push(v);
  }
  return n === 1 ? vals[0] : vals;
}

// Walks the whole file. Returns { header, crcOk, devFields, messages, counts }.
// devFields maps "<devIdx>-<fieldNum>" -> { name, units, baseType, ... } as
// declared by the field_description messages the app emitted.
function parseFit(buffer) {
  const dv = new DataView(buffer);
  const bytes = new Uint8Array(buffer);
  if (buffer.byteLength < 14) throw new Error('File is too small to be a FIT file.');

  const headerSize = dv.getUint8(0);
  if (headerSize !== 12 && headerSize !== 14) {
    throw new Error('Bad FIT header size: ' + headerSize + ' (expected 12 or 14).');
  }
  const sig = String.fromCharCode(...bytes.slice(8, 12));
  if (sig !== '.FIT') throw new Error('Not a FIT file — missing ".FIT" signature.');

  const header = {
    headerSize,
    protocolVersion: dv.getUint8(1),
    profileVersion: dv.getUint16(2, true),
    dataSize: dv.getUint32(4, true),
  };

  const dataEnd = headerSize + header.dataSize;
  if (dataEnd + 2 > buffer.byteLength) {
    throw new Error('Truncated file: header claims ' + header.dataSize +
                    ' data bytes but only ' + (buffer.byteLength - headerSize - 2) + ' are present.');
  }
  const fileCrc = dv.getUint16(dataEnd, true);
  const crcOk = crc16(bytes, 0, dataEnd) === fileCrc;

  const localDefs = {};
  const devFields = {};
  const messages = [];
  const counts = {};
  let pos = headerSize;
  let lastTimestamp = null;

  while (pos < dataEnd) {
    const rh = dv.getUint8(pos); pos++;
    let localType, timeOffset = null;

    if (rh & 0x80) {                       // compressed timestamp header
      localType = (rh >> 5) & 0x03;
      timeOffset = rh & 0x1F;
    } else {
      localType = rh & 0x0F;
      if (rh & 0x40) {                     // definition message
        const hasDev = (rh & 0x20) !== 0;
        pos++;                             // reserved
        const le = dv.getUint8(pos) === 0; pos++;
        const globalNum = dv.getUint16(pos, le); pos += 2;
        const nFields = dv.getUint8(pos); pos++;
        const fields = [];
        for (let i = 0; i < nFields; i++) {
          fields.push({
            num: dv.getUint8(pos),
            size: dv.getUint8(pos + 1),
            baseNum: dv.getUint8(pos + 2) & 0x1F,
          });
          pos += 3;
        }
        const dev = [];
        if (hasDev) {
          const nDev = dv.getUint8(pos); pos++;
          for (let i = 0; i < nDev; i++) {
            dev.push({
              num: dv.getUint8(pos),
              size: dv.getUint8(pos + 1),
              devIdx: dv.getUint8(pos + 2),
            });
            pos += 3;
          }
        }
        localDefs[localType] = { globalNum, le, fields, dev };
        continue;
      }
    }

    const def = localDefs[localType];
    if (!def) throw new Error('Data message at byte ' + (pos - 1) +
                              ' uses local type ' + localType + ' before it was defined.');

    const msg = { globalNum: def.globalNum, fields: {}, devFields: {} };
    for (const f of def.fields) {
      msg.fields[f.num] = readField(dv, pos, f.baseNum, f.size, def.le);
      pos += f.size;
    }
    for (const f of def.dev) {
      const meta = devFields[f.devIdx + '-' + f.num];
      const baseNum = meta ? (meta.baseType & 0x1F) : 2; // default uint8
      msg.devFields[f.devIdx + '-' + f.num] = readField(dv, pos, baseNum, f.size, def.le);
      pos += f.size;
    }

    if (timeOffset !== null && lastTimestamp !== null) {
      // 5-bit rollover against the last full timestamp seen.
      const rolled = (lastTimestamp & ~0x1F) + timeOffset;
      msg.fields[253] = rolled < lastTimestamp ? rolled + 0x20 : rolled;
    }
    if (msg.fields[253] != null) lastTimestamp = msg.fields[253];

    if (def.globalNum === MESG.FIELD_DESCRIPTION) {
      const idx = msg.fields[0], num = msg.fields[1];
      devFields[idx + '-' + num] = {
        devIdx: idx,
        fieldNum: num,
        baseType: msg.fields[2],
        name: msg.fields[3],
        scale: msg.fields[6],
        offset: msg.fields[7],
        units: msg.fields[8],
        nativeMesgNum: msg.fields[14],
      };
    }

    counts[def.globalNum] = (counts[def.globalNum] || 0) + 1;
    messages.push(msg);
  }

  return { header, crcOk, fileCrc, devFields, messages, counts };
}

const semiToDeg = (s) => s == null ? null : s * (180 / Math.pow(2, 31));
const fitToDate = (t) => t == null ? null : new Date((t + FIT_EPOCH) * 1000);

// --- poi-tagger specific decoding (must match PoiTaggerView.mc) ---
const POI_TYPE = { 1: 'NOTICE', 2: 'SCENERY', 3: 'WATER', 4: 'OTHER',
                   5: 'CLOSURE', 6: 'SURFACE', 7: 'FOOD', 8: 'MECHANICAL', 9: 'RESUPPLY' };
const POI_COLOR = { 1: '#d1421f', 2: '#2e8b57', 3: '#1e7fc0', 4: '#b58900',
                    5: '#8e44ad', 6: '#8e5a2b', 7: '#e67e22', 8: '#7f8c8d', 9: '#1e7fc0' };
// Legacy resupply leaves (pre-1.3): separate poi_types with detail 0 — normalize for display.
const LEGACY_RESUPPLY = { 3: 1, 7: 2, 8: 3 };
const POI_DETAIL = { 1: 'TODAY', 2: 'DAYS', 3: 'WEEKS', 4: 'MONTHS', 5: 'UNKNOWN' };
// Hazard kind, in poi_detail when poi_type === 1 (DANGER / NOTICE). 0 = legacy / unspecified.
const POI_DANGER = { 1: 'Potholes', 2: 'Crossing', 3: 'Corner', 4: 'Other', 5: 'Unknown' };
// Scenery kind, in poi_detail when poi_type === 2 (SCENERY). 0 = legacy / unspecified.
const POI_SCENERY = { 1: 'Nature', 2: 'History', 3: 'Culture', 4: 'View',
                      5: 'Architecture', 6: 'Unknown' };
// Resupply kind, in poi_detail when poi_type === 9 (RESUPPLY). 0 = legacy / unspecified.
const POI_RESUPPLY = { 1: 'Water', 2: 'Food', 3: 'Repair' };
// Surface type, in poi_detail when poi_type === 6 (SURFACE). Smooth -> rough,
// aligned to OSM surface= values. 0/absent = unspecified surface point.
const POI_SURFACE = { 1: 'Asphalt', 2: 'Concrete', 3: 'Paving (klinkers)',
                      4: 'Sett', 5: 'Cobbles', 6: 'Gravel', 7: 'Dirt', 8: 'Sand',
                      9: 'End' };
const OSM_SURFACE = { 1: 'asphalt', 2: 'concrete', 3: 'paving_stones', 4: 'sett',
                      5: 'cobblestone', 6: 'gravel', 7: 'ground', 8: 'sand' };
// Per-surface segment colours, matching the device's picker tiles.
const SURF_COLOR = { 1: '#555555', 2: '#8a8a8a', 3: '#c0392b', 4: '#9b7653',
                     5: '#6e4b3a', 6: '#b58900', 7: '#8e5a2b', 8: '#d2b48c' };
const SURF_TYPE = 6, SURF_END = 9;   // poi_type for SURFACE; poi_detail for "stretch ends"

// Display row for the tag table: legacy WATER/FOOD/MECHANICAL → RESUPPLY + kind.
function tagRowDisplay(t) {
  if (t.type === 9) {
    return {
      name: 'RESUPPLY',
      color: POI_COLOR[9],
      detail: POI_RESUPPLY[t.detail] ?? (t.detail ? 'code ' + t.detail : 'unspecified'),
    };
  }
  const legacy = LEGACY_RESUPPLY[t.type];
  if (legacy) {
    return {
      name: 'RESUPPLY',
      color: POI_COLOR[9],
      detail: POI_RESUPPLY[legacy] + ' (legacy)',
    };
  }
  return {
    name: POI_TYPE[t.type] ?? ('UNKNOWN (' + t.type + ')'),
    color: POI_COLOR[t.type] ?? '#777',
    detail: null,
  };
}

// Finds a developer field by name, whatever index the device assigned it.
function findDevKey(devFields, name) {
  for (const [key, meta] of Object.entries(devFields)) {
    if (meta.name === name) return key;
  }
  return null;
}

// Undo rule — the device does none of this, it just logs what was tapped.
// Two tags of the SAME type inside UNDO_WINDOW_S cancel each other out: the
// rider tapped a tile, saw the wrong one flash, and tapped it again. A tag of
// any other type never interacts, at any spacing, so burst-tagging a spot with
// two different categories is untouched.
//
// Marks rather than removes, so the viewer can show what was retracted.
// Assumes `tags` is in ascending time order (record order in the FIT).
//
// Two-tap tiles commit after their picker; SURFACE is exempt entirely — a second
// surface tag is a segment transition, not a retraction (see buildSurfaceSegments).
// MUST stay in step with undoMsFor() on the device.
const UNDO_WINDOW_S = 3;
const undoWindowFor = () => UNDO_WINDOW_S;

function applyUndoRule(tags, baseWindowS = UNDO_WINDOW_S) {
  const out = tags.map(t => ({ ...t, cancelled: false }));
  for (let i = 0; i < out.length; i++) {
    if (out[i].cancelled || !out[i].time || out[i].type === SURF_TYPE) continue;
    const windowS = baseWindowS;
    for (let j = i + 1; j < out.length; j++) {
      if (!out[j].time) continue;
      const dt = (out[j].time - out[i].time) / 1000;
      if (dt >= windowS) break;              // ordered by time: nothing later can match
      if (out[j].cancelled) continue;
      if (out[j].type === out[i].type) {     // a pair — both go
        out[i].cancelled = true;
        out[j].cancelled = true;
        break;
      }
    }
  }
  return out;
}

// Joins the SURFACE transition tags into segments. Each surface tag is a
// transition: a real type (1-8) opens a stretch (closing any open one at that
// point), SURF_END (9) closes the open stretch and opens nothing (road back to
// normal). SURF_NONE (0, a bare tap / timed-out picker) is an unspecified point,
// not a segment boundary, so it's skipped here. Returns segments in time order;
// an unterminated stretch is closed at `rideEnd` and flagged.
function buildSurfaceSegments(tags, rideEnd) {
  const surf = tags.filter(t => t.type === SURF_TYPE && !t.cancelled && t.time)
                   .sort((a, b) => a.time - b.time);
  const segments = [];
  let open = null;
  const close = (t, ended) => {
    open.endTime = t ? t.time : null;
    open.endLat = t ? t.lat : null;
    open.endLon = t ? t.lon : null;
    open.ended = ended;                      // true = explicit END, false = switch/ride-end
    segments.push(open);
    open = null;
  };
  for (const t of surf) {
    if (t.detail >= 1 && t.detail <= 8) {    // a type — open (closing any current)
      if (open) close(t, false);             // switch: previous ends where this starts
      open = { type: t.detail, startTime: t.time, startLat: t.lat, startLon: t.lon };
    } else if (t.detail === SURF_END) {      // explicit end
      if (open) close(t, true);              // else stray END — ignore
    }
  }
  if (open) { open.endTime = rideEnd; open.endLat = null; open.endLon = null;
              open.ended = false; open.unterminated = true; segments.push(open); }
  return segments;
}

// Counts vehicles from the per-second radar_count channel.
//
// The Varia gives no target ids, so "how many distinct cars" is always an
// inference. Rises are ignored. When the count *falls*, credit only if:
//   stretch ≥2 s, valid previous closing speed, nearest got within 10 m during
//   the stretch, and previous nearest range was ≤20 m (last second before pass).
// A mid-range turn-away must not count, and must not hand its closeness to the
// next car still behind. Matches VehicleCounter / writeRadar; see DATA-FORMAT.md.
function countVehicles(parsed) {
  const key = findDevKey(parsed.devFields, 'radar_count');
  const nearKey = findDevKey(parsed.devFields, 'radar_near');
  const speedKey = findDevKey(parsed.devFields, 'radar_speed');
  if (!key) return null;

  const PASS_CONFIRM_M = 10;
  const PASS_LEAVE_MAX_M = 20;
  let total = 0, prev = 0, covered = 0, records = 0, maxConcurrent = 0;
  const passes = [];
  let prevSp = null;
  let prevNear = null;
  let prevRider = null;
  let prevTime = null;
  let prevLat = null;
  let prevLon = null;
  let stretch = 0;
  let minRange = Infinity;
  for (const m of parsed.messages) {
    if (m.globalNum !== MESG.RECORD) continue;
    records++;
    const c = m.devFields[key];
    if (c == null) {
      prev = 0; stretch = 0; minRange = Infinity;
      prevSp = null; prevNear = null; prevRider = null;
      prevTime = null; prevLat = null; prevLon = null;
      continue;
    }
    covered++;
    if (c > maxConcurrent) maxConcurrent = c;

    const spNow = speedKey ? m.devFields[speedKey] : null;
    const nearNow = nearKey ? m.devFields[nearKey] : null;
    const riderRaw = m.fields[73] != null ? m.fields[73] : m.fields[6];
    const riderNow = riderRaw != null ? Math.round(riderRaw / 1000 * 3.6) : null;

    const leaveOk =
      c < prev &&
      stretch >= 2 &&
      prevSp != null && prevSp !== 255 &&
      minRange <= PASS_CONFIRM_M &&
      prevNear != null && prevNear !== 255 && prevNear <= PASS_LEAVE_MAX_M;
    if (leaveOk) {
      const departed = prev - c;
      total += departed;
      const ground = (prevRider != null) ? prevSp + prevRider : null;
      for (let i = 0; i < departed; i++) {
        passes.push({
          time: prevTime, speed: prevSp, range: prevNear,
          riderSpeed: prevRider, ground: ground,
          lat: prevLat, lon: prevLon,
        });
      }
    }

    if (c === 0) {
      stretch = 0;
      minRange = Infinity;
    } else {
      stretch = prev === 0 ? 1 : stretch + 1;
      if (nearNow != null && nearNow !== 255) {
        if (c < prev) {
          minRange = nearNow; // fresh nearest after departure
        } else if (nearNow < minRange) {
          minRange = nearNow;
        }
      }
    }
    prevSp = spNow;
    prevNear = nearNow;
    prevRider = riderNow;
    prevTime = fitToDate(m.fields[253]);
    prevLat = semiToDeg(m.fields[0]);
    prevLon = semiToDeg(m.fields[1]);
    prev = c;
  }
  return { total, covered, records, maxConcurrent, passes,
           coverage: records ? covered / records : 0 };
}

// Pulls out every record carrying a non-zero value in `typeName`.
function extractTags(parsed, typeName, detailName) {
  const typeKey = findDevKey(parsed.devFields, typeName);
  const detailKey = findDevKey(parsed.devFields, detailName);
  const tags = [];
  let totalRecords = 0;

  for (const m of parsed.messages) {
    if (m.globalNum !== MESG.RECORD) continue;
    totalRecords++;
    if (!typeKey) continue;
    const t = m.devFields[typeKey];
    if (t == null || t === 0) continue;
    tags.push({
      time: fitToDate(m.fields[253]),
      lat: semiToDeg(m.fields[0]),
      lon: semiToDeg(m.fields[1]),
      type: t,
      detail: detailKey ? m.devFields[detailKey] : null,
    });
  }
  return { tags: applyUndoRule(tags), totalRecords, typeKey, detailKey };
}

/* ── the export surface (added for Cycling Commons; not in the original) ──── */
export {
  parseFit, extractTags, buildSurfaceSegments, applyUndoRule, countVehicles,
  findDevKey, semiToDeg, fitToDate, tagRowDisplay,
  POI_TYPE, POI_DETAIL, POI_DANGER, POI_SCENERY, POI_RESUPPLY, POI_SURFACE,
  OSM_SURFACE, LEGACY_RESUPPLY, MESG, SURF_TYPE, SURF_END, UNDO_WINDOW_S,
};
