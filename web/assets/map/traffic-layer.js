// SPDX-License-Identifier: AGPL-3.0-only
/* The curator's measured-traffic layer (docs/specs/traffic-measurements.md §4.6).
   Road pieces from the road-piece tiles, coloured by quiet, moderate or busy for
   the chosen time group; the server sends bands, never numbers. Only groups that passed the disclosure rules come from the
   server; a piece without one stays undrawn, which says nothing about it. */
import { map } from './map-init.js';
import { D, tpl } from './i18n.js';
import { familyConfigured, mountInView } from './tile-sources.js';
import { colourFor, perWay } from '../lib/traffic-colours.js';

const el = id => document.getElementById(id);
const t = (k, fallback) => (D && D[k]) || fallback;
const MIN_ZOOM = 11;

let visible = false;
let data = null;          // {groups: [], shown: []}
let group = null;
let byWay = new Map();
const layerIds = [];
let popup = null;

export function trafficLayerAvailable() {
  return !!window.CC_IS_CURATOR && familyConfigured('roadpieces', 'roadpieces');
}

export function trafficLayerVisible() {
  return visible;
}

function groupLabel(g) {
  const [day, band] = String(g).split('|');
  const dayText = { all: t('trafficGroupAll', 'All times'), workday: t('trafficGroupWorkday', 'Workdays'),
    weekend: t('trafficGroupWeekend', 'Weekends') }[day] || day;
  if (!band) return dayText;
  const bandText = { night: t('trafficBandNight', 'night (00-06)'), morning: t('trafficBandMorning', 'morning rush (06-09)'),
    day: t('trafficBandDay', 'day (09-16)'), evening: t('trafficBandEvening', 'evening rush (16-19)'),
    late: t('trafficBandLate', 'evening (19-24)') }[band] || band;
  return dayText + ', ' + bandText;
}

/** Colour, and the pattern that repeats the label: path dotted, lane dashed, road solid. */
function paint(sourceId, sourceLayer) {
  const id = sourceId + '-traffic';
  if (map.getLayer(id)) return;
  map.addLayer({
    id, type: 'line', source: sourceId, 'source-layer': sourceLayer, minzoom: MIN_ZOOM,
    filter: ['boolean', ['feature-state', 'shown'], false],
    layout: { 'line-cap': 'round', 'line-join': 'round', visibility: visible ? 'visible' : 'none' },
    paint: {
      'line-color': ['coalesce', ['feature-state', 'colour'], 'rgba(0,0,0,0)'],
      'line-width': ['interpolate', ['linear'], ['zoom'], 11, 2, 16, 6],
      'line-opacity': ['case', ['boolean', ['feature-state', 'shown'], false], 0.9, 0],
      'line-dasharray': ['match', ['get', 'l'], 'p', ['literal', [1, 1.5]], 'l', ['literal', [3, 1.5]], ['literal', [1, 0]]],
    },
  });
  layerIds.push({ id, sourceId, sourceLayer });
  map.on('click', id, onClick);
  applyStates();
}

function mount() {
  mountInView(map, 'roadpieces', 'roadpieces', MIN_ZOOM, (key, sourceId) => {
    paint(sourceId, 'roadpieces_' + key);
  });
}

function applyStates() {
  layerIds.forEach(({ sourceId, sourceLayer }) => {
    if (!map.getSource(sourceId)) return;
    map.removeFeatureState({ source: sourceId, sourceLayer });
    byWay.forEach((entry, way) => {
      map.setFeatureState({ source: sourceId, sourceLayer, id: way }, { shown: true, colour: colourFor(entry.traffic) });
    });
  });
}

function onClick(e) {
  const f = e.features && e.features[0];
  const entry = f && byWay.get(f.id);
  if (!entry) return;
  const lines = entry.directions.map(d => {
    /* A cycle path: no car passed its riders. The cars beside it drove on the
       road next to it, so they show as nearby (noise, not safety). */
    const band = b => t('trafficLevel' + b.charAt(0).toUpperCase() + b.slice(1), b);
    const parts = ['p' === d.label
      ? tpl(t('trafficNearbyBand', '{band} on the road beside the path'), { band: band(d.nearby || 'quiet') })
      : band(d.traffic)];
    if (d.carSpeedBand !== null) {
      // The last band is open-ended: 150 and faster.
      parts.push(d.carSpeedBand >= 150
        ? tpl(t('trafficCarSpeedTop', 'cars at {v} km/h or faster'), { v: d.carSpeedBand })
        : tpl(t('trafficCarSpeed', 'cars at {v}-{w} km/h'), { v: d.carSpeedBand, w: d.carSpeedBand + 9 }));
    }
    parts.push(tpl(t('trafficDays', 'on {days} days'), { days: d.days }));
    return ('f' === d.dir ? t('trafficDirF', 'One way') : t('trafficDirB', 'Other way')) + ': ' + parts.join(', ');
  });
  if (popup) popup.remove();
  const box = document.createElement('div');
  box.className = 'traffic-pop';
  const head = document.createElement('b');
  head.textContent = groupLabel(group);
  box.appendChild(head);
  lines.forEach(text => { const p = document.createElement('p'); p.textContent = text; box.appendChild(p); });
  popup = new maplibregl.Popup({ closeButton: true, maxWidth: '18rem' }).setLngLat(e.lngLat).setDOMContent(box).addTo(map);
}

function fillGroups() {
  const select = el('trafficGroup');
  if (!select || !data) return;
  select.textContent = '';
  data.groups.forEach(g => {
    const o = document.createElement('option');
    o.value = g;
    o.textContent = groupLabel(g);
    select.appendChild(o);
  });
  if (!data.groups.includes(group)) group = data.groups[0] || null;
  select.value = group || '';
}

function chooseGroup(g) {
  group = g;
  byWay = perWay(data ? data.shown : [], group);
  const note = el('trafficNote');
  if (note) {
    note.hidden = byWay.size > 0;
    note.textContent = byWay.size ? '' : t('trafficNone', 'No road has enough rides in this group yet.');
  }
  applyStates();
}

async function load() {
  const res = await fetch('/map/traffic', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
  if (!res.ok) throw new Error('traffic ' + res.status);
  data = await res.json();
  fillGroups();
  chooseGroup(group);
}

/** Turn the layer on or off; returns the new state. */
export async function setTrafficLayer(on) {
  visible = !!on;
  const ctl = el('trafficCtl');
  if (ctl) ctl.hidden = !visible;
  if (visible && !data) {
    try { await load(); } catch (e) { visible = false; if (ctl) ctl.hidden = true; return false; }
  }
  if (visible) mount();
  layerIds.forEach(({ id }) => { if (map.getLayer(id)) map.setLayoutProperty(id, 'visibility', visible ? 'visible' : 'none'); });
  if (!visible && popup) { popup.remove(); popup = null; }
  return visible;
}

export function initTrafficLayer() {
  if (!trafficLayerAvailable()) return;
  const select = el('trafficGroup');
  if (select) select.addEventListener('change', () => chooseGroup(select.value));
  map.on('moveend', () => { if (visible) mount(); });
  map.on('sourcedata', e => { if (visible && e.isSourceLoaded && layerIds.some(l => l.sourceId === e.sourceId)) applyStates(); });
}
