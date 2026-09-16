// SPDX-License-Identifier: AGPL-3.0-only
/* "Climbs on this route" in the route drawer (docs/specs/map-and-search.md §6.3,
   docs/specs/route-domain.md §6). A leaf: no DOM, no map, no globals of its own.
   The server decides which climbs the route rides (GET /map/route/{id}/climbs,
   RouteClimbService); this writes the rows. Opening and hovering a row go
   through the normal map path (listed-place.js). */
import { escPend } from './util.js';
import { uKmValue, uDistUnit } from './units.js';
import { listWaitHtml } from './along-list.js';

/** The slot the drawer renders for a route with a database id, showing `waitLabel` until the list arrives (listed-place.js hydrateRouteClimbs fills it, or removes it when the route rides no climb). */
export function routeClimbsSlot(f, waitLabel){
  return f && f.id != null ? `<div class="cc-d-climbs" id="cc-d-climbs-slot" data-route="${escPend(f.id)}" aria-busy="true">${listWaitHtml(waitLabel)}</div>` : '';
}

/** "km 112" in the rider's unit: the distance along the route to where it meets the climb, whole units. */
export function climbAt(alongKm, label){
  return String(label || '{u} {n}').replace('{u}', uDistUnit()).replace('{n}', String(uKmValue(alongKm, 0)));
}

/**
 * The section: heading and one row per climb, in the order the server sends
 * (along the route). Each row reads "Name · km 112 · ▲ 8.7% avg": the average
 * gradient is the climb's headline figure, the one its drawer and hover line
 * lead with (util.js featureSummary). Empty list, empty string: no section.
 * `labels` = {heading, at, avg}.
 */
export function routeClimbsHtml(climbs, labels){
  const list = Array.isArray(climbs) ? climbs.filter(c => c && c.id != null) : [];
  if(!list.length) return '';
  const L = labels || {};
  const rows = list.map(c => {
    const avg = c.avgGradient ? ` · ▲ ${escPend(c.avgGradient)} ${escPend(L.avg || 'avg')}` : '';
    return `<li><button type="button" class="cc-near" data-route-climb="${escPend(c.id)}">`
      + `<span class="cc-near-nm">${escPend(c.name || '')}</span>`
      + `<em>${escPend(climbAt(c.alongKm, L.at))}${avg}</em></button></li>`;
  }).join('');
  return `<h4 class="cc-near-h">${escPend(L.heading || 'Climbs on this route')}</h4><ul class="cc-near-list">${rows}</ul>`;
}
