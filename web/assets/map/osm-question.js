// SPDX-License-Identifier: AGPL-3.0-only
/* The OSM question on a new place, in the map drawer.

   A new place is not admitted until somebody has said which OSM object it is,
   or that there is none (catalog-data-model.md §5b). The decision is made in
   the drawer (moderation-and-contribution.md §5.4), so the question is asked
   there too: the queue card kept the same chip, and a curator who clicked
   Approve first met a refusal with no way to answer where they stood.

   The server sends the state and the stored candidate list on the pending
   payload; this file only draws it. `open` offers every candidate and, last,
   "Not in OSM": that is an answer, and the right one more often than the
   list is. `linked` and `none` are the answered chips, drawn again in place
   the moment the POST returns. */
import { escPend } from './util.js';

export const OSM_BASE = 'https://www.openstreetmap.org/';

/** The answered chip: a link to the object, or the recorded "not in OSM". */
export function osmAnsweredHtml(state, ref, D) {
  if ('linked' === state && ref) {
    return `<div class="cc-mod-osm" data-osm-state="linked"><a class="cc-mod-osm-chip" href="${OSM_BASE}${encodeURI(ref)}" target="_blank" rel="noopener" title="${escPend(D.osmTipLinked||'Linked to OpenStreetMap')}">⌖ ${escPend(ref)}</a></div>`;
  }
  return `<div class="cc-mod-osm" data-osm-state="none"><span class="cc-mod-osm-chip" title="${escPend(D.osmTipNone||'Recorded: this place has no OSM counterpart')}">⌖ ${escPend(D.osmChipNone||'not in OSM')}</span></div>`;
}

/** The block for a pending new place: nothing without a question to draw. */
export function osmQuestionHtml(osm, D) {
  if (!osm || !osm.state) return '';
  if ('open' !== osm.state) return osmAnsweredHtml(osm.state, osm.ref, D);
  const list = Array.isArray(osm.candidates) ? osm.candidates : [];
  const opts = list.map(c => `<button type="button" class="cc-mod-osm-opt" data-osm-answer="${escPend(c.ref)}" title="${escPend(c.ref)}"><b>${escPend(c.name || D.osmUnnamed || 'Unnamed object')}</b> <span>${Math.round(Number(c.distanceM) || 0)} m · ${escPend(c.ref)}</span></button>`).join('');
  return `<div class="cc-mod-osm" data-osm-state="open" role="group" aria-label="${escPend(D.osmQuestion||'Which OSM object is this place?')}">
      <div class="cc-mod-diff-h">⌖ ${escPend(D.osmQuestion||'Which OSM object is this place?')}</div>
      ${list.length ? `<p class="cc-mod-osm-scope">${escPend(D.osmScope||'Same kind of place, in OpenStreetMap, within 250 m of the pin.')}</p>` : ''}
      <div class="cc-mod-osm-opts">${opts}<button type="button" class="cc-mod-osm-opt none" data-osm-answer=""><b>${escPend(D.osmNone||'Not in OSM')}</b></button></div>
    </div>`;
}

/* What approving a new place retires (catalog-data-model.md §5a): the rows
   holding the same OSM point and the similar places the rider left ticked.
   Read-only: it is the approval that retires them, and this says so first.
   An OpenStreetMap point the place takes over is not retired: the place adds
   to it, and it stays the place's OSM reference, so it has a block of its own.
   The place's own answered OSM object is its chip, and the server sends no
   `osmRef` for it. */
export function replacesHtml(replaces, D, osmRef) {
  const list = Array.isArray(replaces) ? replaces : [];
  if (!list.length && !osmRef) return '';
  const fill = (s, v) => String(s).replace(/%(\w+)%/g, (m, k) => (k in v ? String(v[k]) : m));
  const rows = list.map(r => {
    // A place on our map: its provider's, else ours, even when its line came from OSM.
    const from = r.provider || D.replacesOurs || 'Cycling Commons';
    return `<li>${escPend(r.name || (D.osmUnnamed || 'Unnamed'))} · ${escPend(from)} · ${escPend(fill(D.replacesMetres || '%m% m', { m: r.metres }))} · #${Number(r.id)}</li>`;
  }).join('');
  const retires = rows ? `<div class="cc-mod-replaces"><div class="cc-mod-ctx-h">${escPend(D.replacesHead || 'Approving retires')}</div><ul>${rows}</ul></div>` : '';
  const adds = osmRef
    ? `<div class="cc-mod-replaces cc-mod-replaces-osm"><div class="cc-mod-ctx-h">${escPend(D.replacesOsmHead || 'Approving adds to this OpenStreetMap entry')}</div><ul><li>OpenStreetMap · ${escPend(osmRef)} · ${escPend(D.replacesOsm || 'stays the OSM reference')}</li></ul></div>`
    : '';
  return retires + adds;
}

/* A new road on a stretch we hold adds to that road (edit-items/A-road-surface.md): the card's rows are what it
   brings, and with none it says so. */
export function addsToHtml(addsTo, D, changes) {
  if (!addsTo) return '';
  const nothing = Array.isArray(changes) && changes.length ? '' : `<p>${escPend(D.roadNothing || 'It brings no value the road does not hold already.')}</p>`;
  return `<div class="cc-mod-replaces cc-mod-replaces-osm"><div class="cc-mod-ctx-h">${escPend(D.roadHead || 'Approving adds to this road on the map')}</div><ul><li>${escPend(addsTo.name || (D.osmUnnamed || 'Unnamed'))} · #${Number(addsTo.id)}</li></ul>${nothing}</div>`;
}
