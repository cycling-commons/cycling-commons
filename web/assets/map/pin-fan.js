// SPDX-License-Identifier: AGPL-3.0-only
/* Pins on one spot fan out, on the map. Every bottom-anchored place pin
   (render.js catalog pins, osm-pools.js leaf pins, the reveal pin) is handed
   to fanPin(); after each render, each pool update and each camera move a
   pass groups the pins in view by screen distance (fan-out.js) and moves
   each member of a group onto its seat with Marker.setOffset. A leader marker
   at the pin's own point draws a thin line from that point to the moved tip,
   and a dot on the point. The pin's lngLat never changes: a click, a fly-to
   and a deep link still answer the true point.
   @see docs/specs/map-and-search.md (Pins on one spot fan out) */
import { map } from './map-init.js';
import { fanLayout } from './fan-out.js';

const SVGNS = 'http://www.w3.org/2000/svg';
const EASE_MS = 180;
const VIEW_MARGIN = 80;   // px past the canvas edge still laid out, so a group across the edge stays whole

const pins = new Map();       // pin element -> entry
const seatOf = new Map();     // `letter:id` -> last offset, so a pin redrawn by render() takes its seat at once
let _raf = 0, _bound = false;
const ZERO = [0, 0];

const isZero = o => !o || (o[0] === 0 && o[1] === 0);
const same = (a, b) => a[0] === b[0] && a[1] === b[1];
const reducedMotion = () => typeof window.matchMedia === 'function'
  && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Hand a place pin to the fan-out. `key` is the pin's `letter:id` (render.js
 * placeKey), or null for a pin with no record; seats follow the key. A pin
 * that held a seat before a redraw takes it straight away, so a re-render
 * does not spread the group again. Answers the marker.
 */
export function fanPin(marker, key){
  const el = marker.getElement();
  const ll = marker.getLngLat();
  const e = {marker, el, key: key == null ? null : String(key),
    sortKey: key == null ? '~' + ll.lng.toFixed(6) + ',' + ll.lat.toFixed(6) : String(key),
    off: ZERO, target: ZERO, leader: null, anim: null};
  pins.set(el, e);
  const seat = e.key != null ? seatOf.get(e.key) : null;
  if(seat){ e.target = seat; setOff(e, seat); }
  bind();
  scheduleFanOut();
  return marker;
}

/** Lay the pins out again on the next frame (coalesced). */
export function scheduleFanOut(){
  if(_raf || !pins.size) return;
  _raf = requestAnimationFrame(layout);
}

/** Where a fanned pin's tip sits, as a pixel offset from its point; [0, 0] when it is not moved. */
export function fanOffsetOf(el){
  const e = pins.get(el);
  return e && e.el.isConnected ? e.target : ZERO;
}

/**
 * The offset a ring meant for the pin at `ll` ([lat, lng]) must add to land on
 * that pin, found by `key` (`letter:id`) first and by the point second. Null
 * when the pin is not moved, or nothing fanned sits there. 'point' when several
 * fanned pins share the point and none is named: the ring then sits on the
 * point itself, where the leaders meet.
 */
/** The pin element drawn for `letter:id`, or null when none is on screen. */
export function pinElFor(key){
  if(key == null) return null;
  for(const e of pins.values()){
    if(e.el.isConnected && e.key === String(key)) return e.el;
  }
  return null;
}
export function fanRingFor(key, ll){
  let byPoint = null, n = 0;
  for(const e of pins.values()){
    if(!e.el.isConnected) continue;
    if(key != null && e.key === String(key)) return isZero(e.target) ? null : e.target;
    if(!ll || isZero(e.target)) continue;
    const p = e.marker.getLngLat();
    if(Math.abs(p.lat - ll[0]) < 1e-7 && Math.abs(p.lng - ll[1]) < 1e-7){ byPoint = e; n++; }
  }
  if(n === 1) return byPoint.target;
  return n > 1 ? 'point' : null;
}

function bind(){
  if(_bound) return;
  _bound = true;
  map.on('moveend', scheduleFanOut);
}

function layout(){
  _raf = 0;
  const cv = map.getCanvas();
  const w = cv.clientWidth, h = cv.clientHeight;
  const pts = [], ents = [];
  for(const [el, e] of pins){
    if(!el.isConnected){ drop(e); pins.delete(el); continue; }
    const p = map.project(e.marker.getLngLat());
    if(p.x < -VIEW_MARGIN || p.y < -VIEW_MARGIN || p.x > w + VIEW_MARGIN || p.y > h + VIEW_MARGIN) continue;
    pts.push({key: e.sortKey, x: p.x, y: p.y});
    ents.push(e);
  }
  const offs = fanLayout(pts);
  let changed = false;
  ents.forEach((e, i) => {
    const t = offs[i] || ZERO;
    if(e.key != null){ if(offs[i]) seatOf.set(e.key, t); else seatOf.delete(e.key); }
    if(same(t, e.target)) return;
    e.target = t;
    changed = true;
    moveTo(e, t);
  });
  /* drawer.js keeps its halo on a moved pin by listening for this. */
  if(changed) document.dispatchEvent(new CustomEvent('cc:fanout'));
}

/* A short ease from where the pin is to its seat; a cut under reduced motion. */
function moveTo(e, t){
  if(e.anim) cancelAnimationFrame(e.anim);
  e.anim = null;
  if(reducedMotion() || document.hidden){ setOff(e, t); return; }
  const from = e.off, t0 = performance.now();
  const step = now => {
    const k = Math.min(1, (now - t0) / EASE_MS), q = 1 - Math.pow(1 - k, 3);
    setOff(e, k >= 1 ? t : [Math.round(from[0] + (t[0] - from[0]) * q), Math.round(from[1] + (t[1] - from[1]) * q)]);
    e.anim = k >= 1 ? null : requestAnimationFrame(step);
  };
  e.anim = requestAnimationFrame(step);
}

function setOff(e, o){
  e.off = o;
  e.marker.setOffset(o);
  drawLeader(e, o);
}

/* The leader: a marker of its own at the pin's point, slotted in just above
   the map canvas so every pin paints over it. Line from the point to the
   moved tip, a dot on the point. */
function drawLeader(e, o){
  if(isZero(o)){ if(e.leader){ e.leader.remove(); e.leader = null; } return; }
  if(!e.leader){
    const el = document.createElement('div');
    el.className = 'cc-fan-leg';
    el.setAttribute('aria-hidden', 'true');
    const svg = document.createElementNS(SVGNS, 'svg');
    svg.setAttribute('width', '1'); svg.setAttribute('height', '1');
    ['case', 'ink'].forEach(c => {
      const l = document.createElementNS(SVGNS, 'line');
      l.setAttribute('class', c); l.setAttribute('x1', '0'); l.setAttribute('y1', '0');
      svg.appendChild(l);
    });
    const dot = document.createElementNS(SVGNS, 'circle');
    dot.setAttribute('cx', '0'); dot.setAttribute('cy', '0'); dot.setAttribute('r', '3');
    svg.appendChild(dot);
    el.appendChild(svg);
    e.leader = new maplibregl.Marker({element: el, anchor: 'center'}).setLngLat(e.marker.getLngLat()).addTo(map);
    map.getCanvas().after(el);
  }
  e.leader.getElement().querySelectorAll('line').forEach(l => {
    l.setAttribute('x2', String(o[0])); l.setAttribute('y2', String(o[1]));
  });
}

function drop(e){
  if(e.anim) cancelAnimationFrame(e.anim);
  e.anim = null;
  if(e.leader){ e.leader.remove(); e.leader = null; }
}
