// SPDX-License-Identifier: AGPL-3.0-only
/* Map helpers: escaping, colour, geometry, photo attach.
   @see docs/specs/security-architecture.md §4.2; docs/specs/photo-uploads.md §5 */
import { uKm } from './units.js';

// docs/specs/security-architecture.md §4.2 — every interpolated value through escPend.
export const escPend = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
// docs/specs/security-architecture.md §4.2 — http(s) or site-relative only; else '#'.
export const safeHref = u => { const s = String(u ?? '').trim(); return (/^https?:\/\//i.test(s) || (s.startsWith('/') && !s.startsWith('//'))) ? escPend(s) : '#'; };
/* A person named on the pending card as the curator desks name them
   (App\Moderation\DeskRider): the name linked to their profile when the
   server sent a uuid (a public profile), plain text otherwise. */
export const deskRiderHtml = (name, uuid) => uuid
  ? `<a class="desk-rider" href="/riders/${encodeURIComponent(uuid)}">${escPend(name)}</a>`
  : escPend(name);
// docs/specs/map-and-search.md §7.4: a pasted point. The search row's chip and
// the place card's badge are the same thing and must not drift apart.
export const COORD_COLOR='#556070';
export const stars=n=>'★★★★★'.slice(0,n)+'☆☆☆☆☆'.slice(0,5-n);
export const slug = s => s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
export function txtOn(hex){
  const h=hex.replace('#',''); const r=parseInt(h.slice(0,2),16),g=parseInt(h.slice(2,4),16),b=parseInt(h.slice(4,6),16);
  return (0.299*r+0.587*g+0.114*b)/255 < 0.58 ? '#fff' : '#101E16';
}
// docs/specs/climb-elevation.md §6b — purple up, blue down; same |gradient| bands.
const UP_BANDS=['#D9A6F2','#B25BE8','#8A2BD0','#5E18A0','#3A0A66'];
const DOWN_BANDS=['#A6CFF2','#5BA0E8','#2B76D0','#184F9F','#0A2A66'];
export const gradColor=p=>{
  const g=Math.abs(p), bands=p<0?DOWN_BANDS:UP_BANDS;
  return g<5?bands[0]:g<8?bands[1]:g<12?bands[2]:g<16?bands[3]:bands[4];
};
export const DIFF_PURPLE=['','#D9A6F2','#B25BE8','#8A2BD0','#5E18A0','#3A0A66'];
export function haversine(a,b){ const R=6371,d=Math.PI/180;
  const x=Math.sin((b[0]-a[0])*d/2)**2 + Math.cos(a[0]*d)*Math.cos(b[0]*d)*Math.sin((b[1]-a[1])*d/2)**2;
  return 2*R*Math.asin(Math.sqrt(x)); }
export function featurePoint(f){ return (f.geom&&f.geom.ll) || (f.route&&f.route[0]) || (f.geom&&f.geom.path&&f.geom.path[0]) || null; }
// [lat, lng] of a catalogue feature, whichever shape it carries: a pool
// feature's own point, a GeoJSON point, or featurePoint() for a climb, a
// surface stretch or a route (its first point), so a deep link to a line can
// move the scope to the line's country (docs/specs/map-and-search.md §8).
export function featureLL(f){
  if(!f) return null;
  if(Array.isArray(f.ll) && f.ll.length===2) return [+f.ll[0], +f.ll[1]];
  const c=f.geometry && f.geometry.coordinates;
  if(Array.isArray(c) && c.length>=2 && typeof c[0]==='number') return [+c[1], +c[0]];
  const p=featurePoint(f);
  return Array.isArray(p) && p.length>=2 ? [+p[0], +p[1]] : null;
}
// Pin at the foot (`route[0]`); featurePoint() prefers the stored summit. One helper, both readers.
export function pinPoint(f){ return (f.route&&f.route[0]) || (f.geom&&f.geom.ll) || (f.geom&&f.geom.path&&f.geom.path[0]) || null; }
export function currentSeason(){ const m=new Date().getMonth()+1; return m>=3&&m<=5?'spring':m>=6&&m<=8?'summer':m>=9&&m<=11?'autumn':'winter'; }
export const ccUrl = lic => ({
  'CC0':'https://creativecommons.org/publicdomain/zero/1.0/',
  'Public domain':'https://en.wikipedia.org/wiki/Public_domain',
  'CC BY 4.0':'https://creativecommons.org/licenses/by/4.0/',
  'CC BY 3.0':'https://creativecommons.org/licenses/by/3.0/',
  'CC BY 2.5':'https://creativecommons.org/licenses/by/2.5/',
  'CC BY 2.0':'https://creativecommons.org/licenses/by/2.0/',
  'CC BY-SA 4.0':'https://creativecommons.org/licenses/by-sa/4.0/',
  'CC BY-SA 3.0':'https://creativecommons.org/licenses/by-sa/3.0/',
  'CC BY-SA 3.0 lu':'https://creativecommons.org/licenses/by-sa/3.0/lu/',
  'CC BY-SA 2.5':'https://creativecommons.org/licenses/by-sa/2.5/',
  'CC BY-SA 2.0':'https://creativecommons.org/licenses/by-sa/2.0/',
  // Jurisdiction ports, common on older Belgian and German Commons uploads.
  'CC BY-SA 2.0 be':'https://creativecommons.org/licenses/by-sa/2.0/be/',
  'CC BY-SA 2.0 de':'https://creativecommons.org/licenses/by-sa/2.0/de/'
}[lic] || 'https://commons.wikimedia.org/wiki/Commons:Licensing');

// docs/specs/photo-uploads.md §5 — `photos[]` (gallery) and legacy singular `photo`; JSON strings parsed; drop unparseable.
export function attachPhotos(target, props){
  const parsed = raw => {
    if(typeof raw !== 'string') return raw;
    try{ return JSON.parse(raw); }catch(e){ return null; }
  };
  const photos = parsed(props.photos);
  if(Array.isArray(photos) && photos.length) target.photos = photos;
  const photo = parsed(props.photo);
  if(photo && !Array.isArray(photo)) target.photo = photo;
  return target;
}

// Hover/aria line from measured values; headline is retired for climbs (docs/specs/climb-elevation.md §4).
export function featureSummary(f, D){
  if(!f) return '';
  const bits = [];
  const km = Number(f.length) > 0 ? Number(f.length)/1000 : 0;
  if(km) bits.push(uKm(km));
  const avg = f.avgGradient == null ? null : String(f.avgGradient).match(/-?\d+(\.\d+)?/);
  if(avg) bits.push(`${avg[0]}% ${(D && D.avgShort) || 'avg'}`);
  if(bits.length) return bits.join(' · ');
  return f.headline || '';
}

/* docs/specs/map-and-search.md §7.2: the name Photon returns. Photon speaks
   default, de, en and fr; `default` is the place's own name (Antwerpen, not
   Antwerp), which is the right answer for every language Photon lacks. */
export const photonLang = htmlLang => { const l = String(htmlLang || '').slice(0, 2).toLowerCase(); return ['en', 'fr', 'de'].includes(l) ? l : 'default'; };

/* Where a clicked spot should land: the middle of the map the drawer leaves
   uncovered, as a MapLibre flyTo offset. On a desktop the drawer sits on the
   right, so the spot moves left. On a phone (the 820px layout flip) the drawer
   is a bottom sheet that opens at half the screen, so the spot moves up into
   the top half instead (owner 2026-09-15: a pin opened from the ride list
   landed under the sheet). docs/specs/map-and-search.md §6. */
export function pinOffset(viewportWidth, viewportHeight, mapTop, mapHeight){
  // Half the room the drawer takes on the right (drawerFitPadding's 400), so a
  // spot lands in the middle of the free part and never under the panel.
  if(viewportWidth > 820) return [-200, 0];
  const visibleMiddle = (mapTop + viewportHeight * 0.5) / 2;
  return [0, Math.round(visibleMiddle - (mapTop + mapHeight / 2))];
}

/* fitBounds padding that frames a shape (a route, a ride, a town and its
   neighbours) in the part of the map the drawer leaves free: clear of the
   right-hand panel on a desktop, above the bottom sheet on a phone, which
   opens at half the screen (pinOffset). docs/specs/map-and-search.md §6, §8. */
export function drawerFitPadding(viewportWidth, viewportHeight){
  if(viewportWidth > 820) return {top:70, bottom:70, left:70, right:400};
  return {top:70, bottom:Math.max(300, Math.round(viewportHeight * 0.5) + 16), left:70, right:70};
}

/* [[west, south], [east, north]] of a path of [lat, lng] points, as fitBounds
   takes it; null when the path has no usable point. */
export function pathBounds(path){
  if(!Array.isArray(path) || !path.length) return null;
  let s=90, n=-90, w=180, e=-180;
  for(const p of path){
    if(!Array.isArray(p) || !Number.isFinite(p[0]) || !Number.isFinite(p[1])) return null;
    if(p[0]<s) s=p[0]; if(p[0]>n) n=p[0]; if(p[1]<w) w=p[1]; if(p[1]>e) e=p[1];
  }
  return [[w, s], [e, n]];
}

/* Where the Locate me dot lands (docs/specs/map-and-search.md §4.0): the middle
   of the map when no drawer covers any of it, otherwise the same free part a
   clicked spot uses. */
export function locateOffset(drawerOpen, viewportWidth, viewportHeight, mapTop, mapHeight){
  return drawerOpen ? pinOffset(viewportWidth, viewportHeight, mapTop, mapHeight) : [0, 0];
}

/* A flyTo offset as fitBounds padding that lands the point in the same spot.
   MapLibre's fitBounds applies an `offset` twice (once when it computes the
   camera, again in the flyTo it hands that camera to) and `padding` once, so
   the Locate me camera says where to land as padding: shifting the centre
   left by 200 px is 400 px of padding on the right. */
export function offsetAsPadding([x, y]){
  return {left:Math.max(0, 2*x), right:Math.max(0, -2*x), top:Math.max(0, 2*y), bottom:Math.max(0, -2*y)};
}

/* The toast for a Locate me lookup that did not work. Code 1 is the browser's
   PERMISSION_DENIED (the rider said no, or the site is blocked in settings);
   every other code is a lookup that failed. */
export function locateErrorMessage(code, strings){
  const s = strings || {};
  return code === 1
    ? (s.locateDenied || 'Location is blocked for this site. Allow it in your browser settings, then reload the page.')
    : (s.locateFailed || 'Your location could not be found. Try again in a moment.');
}
