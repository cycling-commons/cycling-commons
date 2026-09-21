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
