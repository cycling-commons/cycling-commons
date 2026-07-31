// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Pure helpers for the map front end
: escaping, colour, geometry,
   licence and Wikimedia URL building.

   A leaf: imports nothing — not even i18n.js — so it evaluates first and can be
   required from node --test. Nothing here touches the DOM, MapLibre or any CC_*
   global; anything that needs a locale belongs in i18n.js instead. */

// §13: shared HTML-escaper for real (user-authored) pending-submission text —
// stored-XSS-in-curator-session risk now that submissions come from real users.
export const escPend = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
// Defense-in-depth for interpolated hrefs. A stay's user-editable `web`
// attribute reaches r.links[].href and is interpolated into <a href="…">;
// the server-side Url constraint (App\Form\CatalogFieldConstraints) is the
// primary guard, but this also neutralises any pre-fix `javascript:`/`data:`
// value already persisted. Allow only http(s) and site-relative URLs, then
// attribute-escape; anything else collapses to '#' (review 2026-07-07, #3).
export const safeHref = u => { const s = String(u ?? '').trim(); return (/^https?:\/\//i.test(s) || (s.startsWith('/') && !s.startsWith('//'))) ? escPend(s) : '#'; };
// Star glyphs for 1-5 ratings, shared by schemaRows' 'rating' kind.
export const stars=n=>'★★★★★'.slice(0,n)+'☆☆☆☆☆'.slice(0,5-n);
export const slug = s => s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
// readable text colour on a coloured chip: white on dark backgrounds (e.g. purple), ink on light
export function txtOn(hex){
  const h=hex.replace('#',''); const r=parseInt(h.slice(0,2),16),g=parseInt(h.slice(2,4),16),b=parseInt(h.slice(4,6),16);
  return (0.299*r+0.587*g+0.114*b)/255 < 0.58 ? '#fff' : '#101E16';
}
export const gradColor=p=> p<5?'#D9A6F2':p<8?'#B25BE8':p<12?'#8A2BD0':p<16?'#5E18A0':'#3A0A66';   // purple, wide light→dark range
export const DIFF_PURPLE=['','#D9A6F2','#B25BE8','#8A2BD0','#5E18A0','#3A0A66'];
export function haversine(a,b){ const R=6371,d=Math.PI/180;
  const x=Math.sin((b[0]-a[0])*d/2)**2 + Math.cos(a[0]*d)*Math.cos(b[0]*d)*Math.sin((b[1]-a[1])*d/2)**2;
  return 2*R*Math.asin(Math.sqrt(x)); }
export function featurePoint(f){ return (f.geom&&f.geom.ll) || (f.route&&f.route[0]) || (f.geom&&f.geom.path&&f.geom.path[0]) || null; }
export function currentSeason(){ const m=new Date().getMonth()+1; return m>=3&&m<=5?'spring':m>=6&&m<=8?'summer':m>=9&&m<=11?'autumn':'winter'; }
export const ccUrl = lic => ({
  'CC0':'https://creativecommons.org/publicdomain/zero/1.0/',
  'Public domain':'https://en.wikipedia.org/wiki/Public_domain',
  'CC BY 4.0':'https://creativecommons.org/licenses/by/4.0/',
  'CC BY 3.0':'https://creativecommons.org/licenses/by/3.0/',
  'CC BY 2.0':'https://creativecommons.org/licenses/by/2.0/',
  'CC BY-SA 4.0':'https://creativecommons.org/licenses/by-sa/4.0/',
  'CC BY-SA 3.0':'https://creativecommons.org/licenses/by-sa/3.0/',
  'CC BY-SA 3.0 lu':'https://creativecommons.org/licenses/by-sa/3.0/lu/',
  'CC BY-SA 2.5':'https://creativecommons.org/licenses/by-sa/2.5/',
  'CC BY-SA 2.0':'https://creativecommons.org/licenses/by-sa/2.0/'
}[lic] || 'https://commons.wikimedia.org/wiki/Commons:Licensing');
// Wikimedia Commons photo record: thumbnail, full size, credit and file page.
export const wc = (file, credit, user, license) => {
  const enc = encodeURIComponent(file), page = file.replace(/ /g,'_');
  return { sm:`https://commons.wikimedia.org/wiki/Special:FilePath/${enc}?width=520`,
           lg:`https://commons.wikimedia.org/wiki/Special:FilePath/${enc}?width=1400`,
           credit, creditUrl: user ? `https://commons.wikimedia.org/wiki/User:${user.replace(/ /g,'_')}` : '',
           license, source:`https://commons.wikimedia.org/wiki/File:${page}` };
};

/* Carry a served feature's photo attributes onto its drawer object
   (docs/specs/photo-uploads.md §5).

   Two shapes exist and both must survive. `photos` is the gallery an approved
   rider upload produces — MediaDecisionService appends to it, and it is what
   the drawer's photoList() prefers. `photo` is the legacy singular an imported
   Wikimedia record still uses. Either may arrive as a JSON *string*, because
   these are importable item attributes; an unparseable one is dropped rather
   than handed to the <img> sink.

   The pool drawers (osmDrawer/waterDrawer) build their objects field by field
   instead of passing the raw properties through, so without this they silently
   drop `photos` — which is every rider upload on letters C/D/E/G/H/I/J/M. */
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
