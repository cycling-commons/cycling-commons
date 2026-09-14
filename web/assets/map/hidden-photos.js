// SPDX-License-Identifier: AGPL-3.0-only
/* The curator's block of rider photos a scenic view hides (photo-uploads.md
   §5g, scenic-views.md §8). A scenic view shows a rider photo only when it was
   taken within 250 m of the pin, and a file with no GPS can never be measured
   afterwards, so a curator who knows the spot can confirm it with "Taken here".

   A rider photo also hides when the pin moved after it was measured or
   confirmed: its distance, or 0 m for a confirmation, plus how far the pin now
   is from the pin it was recorded at is over 250 m. The reason says so, and a
   suggested pin move on the curator's pending card says how many photos it
   would hide.

   A leaf: imports only the escaping and unit helpers, so node tests load it
   without a map. drawer.js does the fetching and the posting. */
import { escPend, safeHref } from './util.js';
import { uM } from './units.js';

/* Only a curator, only a live scenic item with a real id. */
export function wantsHiddenPhotos(letter, f, isCurator){
  return !!isCurator && letter === 'P' && !!f && f.id != null && !f.pending;
}

/* The reason in plain words. `distanceM` on `pin_moved` is the farthest the
   photo may now have been taken from the pin. */
export function hiddenPhotoReason(p, D){
  const d = D || {};
  const hasDistance = !!p && p.distanceM != null && Number.isFinite(Number(p.distanceM));
  if(p && p.reason === 'pin_moved'){
    return hasDistance
      ? String(d.photoHiddenPinMoved || 'The pin moved; taken up to {d} from it').replace('{d}', uM(Number(p.distanceM)))
      : (d.photoHiddenPinMovedConfirmed || 'The pin moved after a curator confirmed this photo');
  }
  if(p && p.reason === 'too_far' && hasDistance){
    return String(d.photoHiddenTooFar || 'Taken {d} from the pin').replace('{d}', uM(Number(p.distanceM)));
  }
  return d.photoHiddenNoGps || 'No location in the file';
}

/* On a pending pin move: how many rider photos it hides until a curator
   confirms them (SubmissionQueue `photosHiddenByMove`). Nothing for none. */
export function pinMoveHidesHtml(n, D){
  if(typeof n !== 'number' || !Number.isInteger(n) || n < 1) return '';
  const d = D || {};
  const text = n === 1
    ? (d.pinMoveHidesOne || 'This move hides 1 rider photo until a curator confirms it was taken at the new spot.')
    : String(d.pinMoveHidesMany || 'This move hides {n} rider photos until a curator confirms they were taken at the new spot.').replace('{n}', String(n));
  return `<div class="cc-mod-pinphotos" role="note">${escPend(text)}</div>`;
}

export function hiddenPhotosHtml(list, D){
  if(!Array.isArray(list) || !list.length) return '';
  const d = D || {};
  const open = escPend(d.photoOpen || 'Open full size');
  const rows = list.map((p, i) => `<li class="cc-d-hidden-row">
      <button type="button" class="cc-mod-photo-zoom cc-d-hidden-zoom" data-hidden-photo="${i}" aria-label="${open}" title="${open}">
        <img src="${safeHref(p.sm)}" alt="${escPend(p.alt || '')}" loading="lazy" />
      </button>
      <span class="cc-d-hidden-reason">${escPend(hiddenPhotoReason(p, d))}</span>
      <button type="button" class="cc-d-act cc-d-taken-here" data-taken-here="${escPend(p.id)}">✓ ${escPend(d.photoHiddenTakenHere || 'Taken here')}</button>
    </li>`).join('');
  return `<div class="cc-d-hidden">
    <div class="cc-d-hidden-h">${escPend(d.photoHiddenTitle || 'Hidden photos')}</div>
    <p class="cc-d-hidden-why">${escPend(d.photoHiddenWhy || 'A scenic view shows a photo only when it was taken within 250 m of the pin. Only curators see these.')}</p>
    <ul class="cc-d-hidden-list">${rows}</ul>
  </div>`;
}

/* The gallery with a just-confirmed photo added, in the shape the drawer
   renders: the hidden-list fields minus the reason, plus locationConfirmed.
   A photo already in the gallery is not added twice. */
export function galleryWithConfirmed(f, p){
  const current = (f && (f.photos || (f.photo ? [f.photo] : []))) || [];
  if(!p || current.some(e => e && e.id === p.id)) return current.slice();
  const entry = {};
  for(const k of ['id', 'sm', 'lg', 'credit', 'license', 'alt', 'takenAt']){
    if(p[k] !== undefined) entry[k] = p[k];
  }
  entry.distanceM = p.distanceM != null ? p.distanceM : null;
  entry.locationConfirmed = true;
  return [...current, entry];
}
