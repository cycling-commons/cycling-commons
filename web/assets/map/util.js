// SPDX-License-Identifier: AGPL-3.0-only
/* Map helpers: escaping, colour, geometry, photo attach.
   @see docs/specs/security-architecture.md §4.2; docs/specs/photo-uploads.md §5 */
import { uKm } from './units.js';

// docs/specs/security-architecture.md §4.2 — every interpolated value through escPend.
export const escPend = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
// docs/specs/security-architecture.md §4.2 — http(s) or site-relative only; else '#'.
export const safeHref = u => { const s = String(u ?? '').trim(); return (/^https?:\/\//i.test(s) || (s.startsWith('/') && !s.startsWith('//'))) ? escPend(s) : '#'; };
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
export const wc = (file, credit, user, license) => {
  const enc = encodeURIComponent(file), page = file.replace(/ /g,'_');
  return { sm:`https://commons.wikimedia.org/wiki/Special:FilePath/${enc}?width=520`,
           lg:`https://commons.wikimedia.org/wiki/Special:FilePath/${enc}?width=1400`,
           credit, creditUrl: user ? `https://commons.wikimedia.org/wiki/User:${user.replace(/ /g,'_')}` : '',
           license, source:`https://commons.wikimedia.org/wiki/File:${page}` };
};

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
