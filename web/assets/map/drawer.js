// SPDX-License-Identifier: AGPL-3.0-only
/* Item drawer: registry-driven rows, history, open/close.
   @see docs/specs/map-and-search.md §6 */
import { I18N, D, tpl, trVal, sourceLabel, isRiderSource, DIFF_LABELS } from './i18n.js';
import { drawerSource, drawerOrigin } from './origin.js';
import { escPend, safeHref, stars, txtOn, gradColor, DIFF_PURPLE, ccUrl, attachPhotos, haversine } from './util.js';
import { openClimbProfile } from './climb-profile.js';
import { uKm, uM, uElev, uKmValue, uElevValue, uDistUnit } from './units.js';
import { map } from './map-init.js';
import { CATALOG, CITIES } from './catalog.js';
import { layerGlyph, pinEl, waterKind } from './icons.js';
import { itemLinks } from './links.js';
import { sheet } from './sheet.js';
import { openLightbox } from './lightbox.js';
import { highlightRoute, clearRouteHighlight, showSurfaceSelection, clearSurfaceSelection } from './render.js';
import { clearRouteSelection } from './routes-tiles.js';
import { clearSelectedCoverageIcon, invalidateCoverageDrawer } from './coverage.js';
import { osmMetres, osmRefUrl } from './osm-tags.js';
import { shareQuery } from './share-links.js';
import { watchCommonsPhoto, photoWaitRef } from './commons-photo.js';
import { wantsHiddenPhotos, hiddenPhotosHtml, galleryWithConfirmed, pinMoveHidesHtml } from './hidden-photos.js';
import { setSurfaceTiles, surfaceTilesVisible, surfaceTilesConfigured } from './surface-tiles.js';
import { isPicking, cancelPicking } from './picking.js';
import { openCity, bumpPlaceReq } from './places.js';
import { CC_VOTABLE, CC_CONFIRMABLE, CC_BREAKABLE, routeCommunityPanel, hydrateRouteCommunity,
         hydrateItemConfirm, setPendingShape } from './community.js';
import { showPendingShape, fitPendingShape, clearPendingShape } from './pending-shape.js';
import { clearCorrections } from './corrections.js';


// Race-guard: bumped on every openDrawer() so a slow history fetch cannot paint a stale drawer.
let _historyReq = 0;

// docs/specs/map-and-search.md §6.2 — registry-driven rows; skip = structural; fixed = assumed default.
export function schemaRows(letter, src, id, opts){
  const schema = (window.CC_FIELD_SCHEMA || {})[letter] || [];
  const skip = (opts && opts.skip) || [];
  const fixed = (opts && opts.fixed) || {};   // opts.fixed: assumed default when UNSET; a stored value wins (docs/specs/map-and-search.md §6.2).
  /* opts.proposed: pending values in place of current, marked. */
  const proposed = (opts && opts.proposed) || null;
  const rows = [];
  schema.forEach(f=>{
    if(skip.indexOf(f.key) >= 0) return;
    /* A `links` field is rendered below, one row per destination, and it has no
       branch in this loop. Left here it fell through to the generic value row
       and the drawer printed the raw structure at the rider: "[object Object]"
       when it arrived parsed, and the whole JSON array when it arrived as a
       string. Either way the properly formatted links appeared underneath it,
       so the place always showed both (reported 2026-08-25). */
    if(f.kind === 'links') return;
    if(proposed && Object.prototype.hasOwnProperty.call(proposed, f.key)){
      rows.push({label:f.label, value:proposed[f.key], changed:true});
      return;
    }
    const v = src[f.key];
    const has = Array.isArray(v) ? v.length > 0 : (v != null && v !== '');
    // Stored values are canonical English; f.choices maps them for display.
    const cv = f.choices || {};
    const tv = x => cv[x] != null ? cv[x] : x;
    if(has){
      if(f.kind === 'multiselect'){
        const list = Array.isArray(v) ? v : [v];
        rows.push({label:f.label, html:true, value:list.map(t=>`<span class="cc-chip">${escPend(tv(t))}</span>`).join('')});
      } else if(f.kind === 'rating'){
        rows.push({label:f.label, value: /^[1-5]$/.test(String(v)) ? stars(Number(v)) : v});
      } else if(f.kind === 'url'){
        rows.push({label:f.label, html:true, value:linkValue(v, String(v).replace(/^https?:\/\//,'').replace(/\/$/,''))});
      } else if(f.kind === 'textarea'){
        // Textarea-kind: full-width prose, no squeezed label.
        rows.push({wide:true, label:f.label, value:tv(v)});
      } else {
        rows.push({label:f.label, value:steepValue(letter, f.key, tv(v), src)});
      }
    } else if(fixed[f.key] != null){
      rows.push({label:f.label, value:tv(fixed[f.key])});
    } else if(id != null){
      const href = `/improve?item=${encodeURIComponent(id)}&type=${encodeURIComponent(letter)}&field=${encodeURIComponent(f.key)}`;
      rows.push({label:f.label, html:true, empty:true, value:`<a class="cc-d-add" href="${href}">＋ ${D.add||'add'}</a>`});
    }
  });
  /* docs/specs/catalog-data-model.md §7 — one row per destination, locale-resolved. */
  // Official site is the `web` attribute, not a links entry.
  const KNOWN_LINK_LABELS = {'Wikipedia': D.lnkWikipedia};
  itemLinks(src.links, document.documentElement.lang || 'en').forEach(l => {
    rows.push({label: KNOWN_LINK_LABELS[l.label] || l.label, html:true, value: linkValue(l.href, l.domain)});
  });
  return rows;
}

/* docs/specs/security-architecture.md §4.2 — domain is the anchor; escaped/sanitised here (html:true). */
function linkValue(href, text){
  return `<a class="cc-d-a" href="${safeHref(href)}" target="_blank" rel="noopener noreferrer nofollow">${escPend(text)} ↗</a>`;
}
/* docs/specs/climb-elevation.md §5 — steepest figure carries the window it was measured over. */
function steepValue(letter, name, value, src){
  if(letter !== 'N') return value;
  // Ascent is stored in metres; write it in the reader's unit.
  if(name === 'gain') return uElev(value);
  if(name !== 'maxGradient') return value;
  const w = uM((src && src.steepWindowM) ? Number(src.steepWindowM) : 100);
  return tpl(D.steepOver || '{v} over {w}', {v:value, w:w});
}

/* Off-schema labels: climb geometry fields and correction (display:false). */
const OFF_SCHEMA_LABELS = {
  route: () => D.fRoute || 'Climb line',
  grad: () => D.fGrad || 'Gradient profile',
  steep: () => D.fSteep || 'Steepest ramp',
  correction: () => D.fCorrection || 'Anything to correct?',
  // Moved pin is Item::LOCATION_FIELD — no display-schema entry.
  location: () => D.location || 'Location',
};

/** A field's localised label from the display schema, or the raw name. */
export function fieldLabelFor(letter, name){
  const f = ((window.CC_FIELD_SCHEMA||{})[letter]||[]).find(x => x.key === name);
  if(f && f.label) return f.label;
  // A description suggested for one photo: `photoAlt:<uuid>` (photo-uploads.md 5e). The uuid is not a label.
  if(String(name).startsWith('photoAlt:')) return D.photoDesc || 'Photo description';
  const off = OFF_SCHEMA_LABELS[name];
  return off ? off() : name;
}

/** A change's value as the reader should see it: a stored code such as `station` shows as its label, "Repair stand" (schema choice labels); everything else verbatim. */
function changeValueFor(letter, name, value){
  const f = ((window.CC_FIELD_SCHEMA||{})[letter]||[]).find(x => x.key === name);
  const cv = (f && f.choices) || {};
  return cv[value] != null ? cv[value] : value;
}

/* A metres-valued OSM tag in the rider's unit; anything else stays verbatim. */
function elevValue(raw){
  const m = osmMetres(raw);
  return m == null ? String(raw) : uElev(m);
}

/* Provenance line: geometry sources plus per-climb demSource. */
function srcLine(f, osmHref){
  const link='style="color:var(--glacier);text-decoration:underline;text-underline-offset:2px"';
  let s=escPend(f.source)
    .replace(/^(OpenStreetMap|OSM)/, `<a href="${osmHref}" target="_blank" rel="noopener" ${link}>$1</a>`)
    .replace(/(Géoportail de la Wallonie)/, `<a href="https://geoportail.wallonie.be/catalogue/91721175-5f01-410c-8c78-37c1d1893ba2.html" target="_blank" rel="noopener" ${link}>$1</a>`);
  /* Scout link only when provenance is scout, not when the citation merely contains the word. */
  if(f.srcType==='scout'){
    s=s.replace(/\bScout\b/, `<a href="/scout" ${link}>Scout</a>`);
  }
  if(f.demSource){
    s += ' · ' + tpl(D.elevFrom||'elevation from {s}',
      {s:`<a href="https://dataspace.copernicus.eu/explore-data/data-collections/copernicus-contributing-missions/collections-description/COP-DEM" target="_blank" rel="noopener" ${link}>${escPend(f.demSource)}</a>`});
  }
  return s;
}

/** {fieldName -> proposed value}, for schemaRows' `proposed` option. */
export function proposedMap(changes){
  const out = {};
  (changes||[]).forEach(c => { if(c && c.key != null) out[c.key] = c.now; });
  return out;
}

/* Pending item context for the Before/After switch; cleared on close. */
let _ctx = null;
export function setPendingContext(ctx){ _ctx = ctx || null; }

/** Repaint the item rows for one side of the Before/After switch. */
export function renderPendingContext(side){
  if(!_ctx) return;
  const list = document.querySelector('#drawerBody [data-ctx-rows]');
  if(!list) return;
  const after = side !== 'before';
  list.innerHTML = recRowsHtml(schemaRows(_ctx.letter, _ctx.target, null,
    after ? {proposed: proposedMap(_ctx.changes)} : {}));
  const h = document.querySelector('#drawerBody [data-ctx-h]');
  if(h) h.textContent = after ? (D.itemProposed||'This item, as proposed') : (D.itemToday||'This item today');
}

/** Record-row <li> markup — same escaping as the drawer (docs/specs/security-architecture.md §4.2). */
function recRowsHtml(recs){
  return recs.map(r => {
    const links = r.links ? ' ' + r.links.map(l=>`<a class="cc-d-link" href="${safeHref(l.href)}" target="_blank" rel="noopener noreferrer nofollow">${escPend(l.label)} ↗</a>`).join('') : '';
    // r.html is trusted markup only: every interpolation already escPend-escaped (docs/specs/security-architecture.md §4.2).
    /* r.assumed is not a fact — tap/click the badge; no hover-only tooltip. */
    const assumed = r.assumed
      ? `<span class="m as" role="button" tabindex="0" aria-expanded="false" aria-label="${escPend(D.assumedAria||'How we worked this out')}">!</span>`
      : '';
    const why = r.assumed ? `<p class="cc-d-why" hidden>${escPend(r.assumed)}</p>` : '';
    if(r.wide){
      return `<li class="wide${r.changed?' chg':''}"><span class="v">${r.html?r.value:escPend(r.value)}${assumed}${links}</span>${why}</li>`;
    }
    return `<li class="${r.empty?'empty':''}${r.changed?' chg':''}"><span class="k">${escPend(r.label)}</span><span class="v${r.warn?' warn':''}">${r.html?r.value:escPend(r.value)}${r.method?`<span class="m">${r.method}</span>`:''}${assumed}${links}</span>${why}</li>`;
  }).join('');
}

/* The registry row that published this feature, or null.
   `pk` is on the feature; the map of providers came with the payload
   (docs/specs/data-provider-hierarchy.md §7). A feature with no `pk`, or a
   `pk` the payload does not carry, is simply not credited to anybody: the
   drawer falls back to OSM rather than inventing a name. */
/* The full provenance line for an authority row, composed from the registry
   fields rather than written out here. This was one hardcoded string naming
   one publisher, which is the thing the registry exists to end
   (docs/specs/data-provider-hierarchy.md 7). */
function providerSource(prov){
  const parts=[prov.name];
  if(prov.licence) parts.push(prov.licence);
  const head=parts.join(' - ');
  const tail=[];
  if(prov.fullName && prov.fullName!==prov.name) tail.push(prov.fullName);
  // The person who made the dataset, when that is not the publisher (owner
  // 2026-09-05: the desk had the field and the drawer never showed it).
  if(prov.creator) tail.push(prov.creator);
  return tail.length ? head+' \u00b7 '+tail.join(' \u00b7 ') : head;
}

function providerOf(p){
  if(!p || !p.pk) return null;
  const all = (typeof window!=='undefined' && window.CC_PROVIDERS) || {};
  return all[p.pk] || null;
}

export function osmDrawer(layer, p, ll, src){
  const lbl=(layer||{}).label||D.place||'Place';
  // An authority row: its publisher is the body of record, so the drawer
  // credits them and not OSM. The name comes from the payload's own provider
  // map, so a provider added at the desk is credited without a deploy and a
  // licence corrected there is corrected here (data-provider-hierarchy.md §7).
  const provider = providerOf(p);
  const community = isRiderSource(p.srcType);
  const originLbl = provider ? provider.name : (community?sourceLabel(p.srcType):drawerOrigin(p.srcType, sourceLabel));
  // serviceKind label wins over raw OSM p.t when present.
  const kindLbl = p.serviceKind && ({shop:D.kindShop, station:D.kindStation, pump:D.kindPump}[p.serviceKind] || lbl);
  const typeLbl = kindLbl || p.t || lbl;
  let rec=[{label:D.type||'Type', value:typeLbl, method: provider ? provider.name : drawerOrigin(p.srcType, sourceLabel)}];
  if(p.town && layer.letter!=='O') rec.push({label:D.town||'Town', value:p.town});  // docs/specs/coverage-provider.md §2 — no province row when region_id is null (no Wallonia fallback).
  if(p.prov) rec.push({label:D.province||'Province', value:p.prov});
  // Scenic-view facts OSM already holds (docs/specs/coverage-provider.md §5):
  // altitude, which way a viewpoint faces, how far a waterfall drops. Written
  // in the rider's own unit, so an imperial reader gets feet.
  if(p.ele!=null) rec.push({label:D.elevation||'Elevation', value:elevValue(p.ele)});
  if(p.viewDir) rec.push({label:D.viewDirection||'View direction', value:p.viewDir});
  if(p.drop!=null) rec.push({label:D.drop||'Drop', value:elevValue(p.drop)});
  // The row says WHAT this is (an official register entry) and the method
  // says WHOSE. The wording carried the publisher's name until 2026-09-04,
  // which stopped being true the moment a second authority existed.
  if(provider) rec.push({label:D.listed||'Listed', value:D.officialRegistry||'Official registry entry', method:provider.name});
  // docs/specs/map-and-search.md §12 — simulated flags die. Skip structural `web` when the letter schema already declares it.
  const schemaHasWeb = (((window.CC_FIELD_SCHEMA||{})[(layer||{}).letter])||[]).some(f=>f.key==='web');
  if(p.web && !schemaHasWeb) rec.push({label:D.website||'Website', html:true, value:linkValue(p.web, p.web.replace(/^https?:\/\//,'').replace(/\/$/,''))});
  // docs/specs/map-and-search.md §6.2 — unmanned station/pump: Opening hours · 24/7, not an add prompt.
  const unmanned = p.serviceKind==='station' || p.serviceKind==='pump';
  const attrRows = schemaRows((layer||{}).letter, p, p.id, unmanned ? {fixed:{openingHours:'24/7'}} : undefined);
  const attrLabels = new Set(attrRows.filter(r=>!r.empty).map(r=>r.label));
  rec = rec.filter(r=>!attrLabels.has(r.label)).concat(attrRows);
  const d={name:p.n||p.t||lbl, headline:typeLbl+' · '+originLbl, cur:!!p.cur, geom:{ll:[ll.lat,ll.lng]}, record:rec,
    source: provider?providerSource(provider)
      :(community?sourceLabel(p.srcType):drawerSource(p.srcType, src, sourceLabel))};
  // Provenance rides along so srcLine can link Scout.
  if(p.srcType) d.srcType=p.srcType;
  // Carry contributor fields: bulk-OSM layers otherwise drop the rider who added the place.
  if(p.by!=null){ d.by=p.by; if(p.byName) d.byName=p.byName; if(p.byUuid) d.byUuid=p.byUuid; }
  if(p.id!=null) d.id=p.id;          // real DB item id — the edit-bridge's `?item=` target
  else if(p.ref) d.osmRef=p.ref;     // uncurated coverage POI — materialize-on-edit (docs/specs/osm-data-architecture.md §6)
  if(p.n) d.shareName=p.n;           // the mapper's own name; `name` above may be the category word
  // coverage-provider.md §7 - spinner until our own copy exists; a catalog item borrows its OSM point's photo (photoRef).
  const photoRef = photoWaitRef(p);
  if(photoRef) d.photoPending=photoRef;
  if(p.desc) d.desc=p.desc;
  if(p.descTr) d.descTr=1;
  attachPhotos(d, p);
  return d;
}
// Per-country tap-water links; countries without a vetted reference get none.
// NL is hidden until Drinkwaterkaart.nl's developer has been asked (owner,
// 2026-08-26): the drawer sends riders there and the credits page names them,
// and neither should keep happening before that conversation. A country with no
// entry falls through to [] below, so the Verify row simply carries no links.
// Its credits row is commented out with it. See docs/TODO.md.
const WATER_CHECK_LINKS={
  BE:[{label:'SWDE · Wallonia',href:'https://www.swde.be'},{label:'eaupotable.info',href:'https://eaupotable.info/nl/be-belgie'}],
  // NL:[{label:'Drinkwaterkaart · NL',href:'https://www.drinkwaterkaart.nl'}],
};
/* What OSM calls this place, from its own tag, when nothing better is known.
   "bakery" to "Bakery": the tag value IS the answer, and inventing a
   translation table for every shop value in OSM would be a second vocabulary
   to keep in step with theirs. */
function osmKindLabel(p){
  const raw=String(p.osmShop||p.osmAmenity||'').replace(/_/g,' ').trim();
  return raw ? raw.charAt(0).toUpperCase()+raw.slice(1) : (D.drinkingWater||'Drinking water');
}

/* The OSM tags this water-layer pin actually carries, for the source line.
   Empty parentheses would read worse than none, so a pin we know nothing
   specific about is credited to OpenStreetMap flat. */
function osmWaterSource(p){
  const tags=[];
  if(p.osmAmenity) tags.push('amenity='+p.osmAmenity);
  else if(p.osmShop) tags.push('shop='+p.osmShop);
  if(p.osmPotableTagged) tags.push('drinking_water='+(p.osmPotable?'yes':'no'));
  return 'OpenStreetMap'+(tags.length?' ('+tags.join(' / ')+')':'');
}

export function waterDrawer(p, ll){
  // Rider-set potable/type win over OSM; v:1 is verification, not potability (docs/specs/map-and-search.md §12).
  //
  // FOUR answers, and they are the pin's own two colours split by how we
  // know. The pipeline draws a tap blue when OSM says `drinking_water=yes` OR
  // when it is an `amenity=drinking_water` node with nothing said against it
  // (pipeline/coverage/tiles.py). Only the first of those was ever TAGGED
  // drinkable, and until 2026-09-04 the panel called both of them tagged, on
  // 194,751 of the 201,049 taps in the cache. Worse, the deep-link and search
  // paths build their properties by hand and carried no potability at all, so
  // every water POI opened that way claimed to be tagged drinkable, including
  // ones tagged drinking_water=no.
  const potable = p.potable
    ? {label:D.potable||'Potable', value:trVal(p.potable)}
    : (p.osmPotable===true
        ? (p.osmPotableTagged===false
            ? {label:D.potable||'Potable', value:D.potableOsmImplied||'Mapped in OSM as a drinking-water tap, and nothing says otherwise; confirm on the spot', method:'unverified'}
            : {label:D.potable||'Potable', value:D.potableOsm||'Tagged drinkable in OSM — not utility-verified; confirm on the spot', method:'unverified'})
        : (p.osmPotable===false
            ? {label:D.potable||'Potable', value:D.potableOsmNo||'Tagged not drinkable in OSM — not utility-verified; avoid unless confirmed on the spot', method:'unverified'}
            : {label:D.potable||'Potable', value:D.potableOsmUnknown||'Nobody has tagged whether this is drinkable, so treat it as unknown', method:'unknown'}));
  const community = isRiderSource(p.srcType);
  // An authority row (a RIVM tap): its publisher is the body of record, so
  // the drawer credits them and not OSM, exactly as osmDrawer does. Until
  // 2026-09-05 every RIVM tap read "Type: Drinking water, OSM" and
  // "Source: OpenStreetMap", which was false twice on the first pin opened.
  const provider = providerOf(p);
  // What this pin IS: the same rule that picks its glyph (icons.js). A food
  // stop is not asked about drinking water unless it claims to give some.
  const kind = waterKind(p);
  const food = kind==='food' || kind==='food_water';
  // Letter B is water AND food, and 44% of it is shops and eateries. The type
  // fell back to "Drinking water" whenever the tile properties were missing,
  // which is every deep link and every search hit, so a bakery opened as a
  // drinking-water point. Fall back to what OSM calls it before claiming that.
  const typeValue = (p.type||p.t) ? trVal(p.type||p.t)
    : (p.osmShop||p.osmAmenity ? osmKindLabel(p) : (D.drinkingWater||'Drinking water'));
  const rec=[{label:D.type||'Type', value:typeValue, method: p.type?undefined:(provider?provider.name:'OSM')}];
  if(kind!=='food'){
    rec.push(potable);
    // "Cross-check with the regional utility" is advice for an OSM tap nobody
    // vouches for. An authority row IS the utility's register (RIVM), so the
    // row would tell a rider to check RIVM against RIVM (owner 2026-09-05).
    if(!provider) rec.push({label:D.verify||'Verify', value:D.verifyWater||'Cross-check tap-water quality with the regional utility / fountain directory', links:WATER_CHECK_LINKS[p.cc]||[]});
  }
  // The row says WHAT this is (an official register entry) and the method
  // says WHOSE; the town is the one handle a register row without a name
  // gives a rider who wants to report it (RIVM names no tap).
  if(provider) rec.push({label:D.listed||'Listed', value:D.officialRegistry||'Official registry entry', method:provider.name});
  if(p.town) rec.push({label:D.town||'Town', value:p.town});
  // Remaining WaterFood fields; type and potable are structural above.
  rec.push(...schemaRows('B', p, p.id, {skip:['type','potable']}));
  const headline = kind==='food' ? (D.headlineFood||'food stop')
    : kind==='food_water' ? (D.headlineFood||'food stop')+' + '+(D.headlineDrinking||'drinking water')
    : (D.headlineDrinking||'drinking water');
  const origin = provider ? provider.name : (community?sourceLabel(p.srcType):'OSM');
  const d={name:p.n||p.t||(food ? osmKindLabel(p) : (D.drinkingWater||'Drinking water')), headline:headline+' · '+origin, cur:!!p.cur, geom:{ll:[ll.lat,ll.lng]},
    waterKind:kind,
    record:rec,
    // Names what the row ACTUALLY carries, tag by tag. It printed
    // "amenity=drinking_water / drinking_water=yes" for every pin in this
    // layer, which is wrong twice over: letter B is water AND food, so 44% of
    // it is bakeries and cafes that carry neither tag.
    source: provider ? providerSource(provider) : (community?sourceLabel(p.srcType):osmWaterSource(p))};
  // Provenance rides along so srcLine can link Scout.
  if(p.srcType) d.srcType=p.srcType;
  // Carry contributor fields: bulk-OSM layers otherwise drop the rider who added the place.
  if(p.by!=null){ d.by=p.by; if(p.byName) d.byName=p.byName; if(p.byUuid) d.byUuid=p.byUuid; }
  if(p.id!=null) d.id=p.id;          // real DB item id — the edit-bridge's `?item=` target
  else if(p.ref) d.osmRef=p.ref;     // uncurated coverage POI — materialize-on-edit (docs/specs/osm-data-architecture.md §6)
  if(p.n) d.shareName=p.n;           // the mapper's own name; `name` above may be the category word
  // coverage-provider.md §7 - spinner until our own copy exists; a catalog item borrows its OSM point's photo (photoRef).
  const photoRef = photoWaitRef(p);
  if(photoRef) d.photoPending=photoRef;
  attachPhotos(d, p);
  return d;
}

function photoList(f){ return f.photos || (f.photo ? [f.photo] : []); }

// docs/specs/photo-uploads.md §5 — device picks 520/1400; never offer orig.
export function srcsetAttrs(p, sizes){
  if(!p || !p.sm || !p.lg) return '';
  return `srcset="${safeHref(p.sm)} 520w, ${safeHref(p.lg)} 1400w" sizes="${sizes}"`;
}

// 'YYYY-MM' → localized month; day is never published.
function monthLabel(takenAt){
  const m = /^(\d{4})-(\d{2})$/.exec(String(takenAt||''));
  if(!m) return String(takenAt||'');
  const d = new Date(Number(m[1]), Number(m[2])-1, 1);
  // docs/specs/account-and-auth.md §9 — same month formatter as the rest of the site.
  if(window.ccMonth) return window.ccMonth(d);
  try{
    return d.toLocaleDateString(document.documentElement.lang||undefined, {year:'numeric', month:'short'});
  }catch(e){ return String(takenAt); }
}

// docs/specs/security-architecture.md §4.2 — credit/license via escPend; URLs via safeHref. Omit missing parts.
export function photoCap(p){
  const name = p.credit ? escPend(p.credit) : escPend(D.anonCredit||'Anonymous rider');
  const credit = p.creditUrl ? `<a href="${safeHref(p.creditUrl)}" target="_blank" rel="noopener">${name}</a>` : name;
  const license = p.license ? ` · <a href="${ccUrl(p.license)}" target="_blank" rel="noopener">${escPend(p.license)}</a>` : '';
  const source = p.source ? ` · <a href="${safeHref(p.source)}" target="_blank" rel="noopener">Wikimedia Commons ↗</a>` : '';
  // docs/specs/photo-uploads.md §5 — month-granular capture date.
  const taken = p.takenAt ? ` · ${escPend(monthLabel(p.takenAt))}` : '';
  return `© ${credit}${license}${source}${taken}`;
}
/* coverage-provider.md §7 - the wait, made visible. Never a Commons URL: the
   pixels only ever come from our own storage, so on a first view there is
   genuinely nothing to show yet. */
function waitingPhoto(ref){
  return `<div class="cc-d-photo-wait" data-photo-ref="${escPend(ref)}">
    <span class="cc-d-spin" aria-hidden="true"></span>
    <span role="status">${escPend(D.photoLoading||'Loading image…')}</span>
  </div>`;
}
/* A photo taller than it is wide is shown whole in the 4:3 frame rather than
   cropped to fill it: a statue or a tower loses exactly the part that makes it
   (owner 2026-09-15, Jan Pieterszoon Coen). Decided on load, because the size
   is only known then, and again whenever a thumbnail swaps the source. */
export function isTallPhoto(width, height){
  return width > 0 && height > width;
}
document.addEventListener('load', (e) => {
  const img = e.target;
  if(!(img instanceof HTMLImageElement) || !img.parentElement || !img.parentElement.classList.contains('cc-d-photo')) return;
  img.classList.toggle('is-tall', isTallPhoto(img.naturalWidth, img.naturalHeight));
}, true);

/* The same <figure> the rider photos use, so a cached Commons photo and a
   rider's upload look like one thing. photoCap already renders credit, licence
   and the Commons link. */
export function commonsPhotoHtml(p, name){
  return `<figure class="cc-d-photo">
    <img src="${safeHref(p.sm)}" ${srcsetAttrs(p, '(max-width: 560px) 100vw, 480px')} alt="${escPend(p.alt||name||'')}" data-i="0" />
    <figcaption id="cc-d-cap">${photoCap(p)}</figcaption>
  </figure>`;
}
function buildRecord(layer, f){
  const cur = f.cur ? `<div class="cc-d-cur">▲ ${I18N.curated||'Best of'}</div>` : '';
  const pl = photoList(f);
  // Add-photo CTA only with a real DB id (docs/specs/map-and-search.md §6).
  const addPhoto = (f.id!=null && layer.key!=='experience') ? `<a class="cc-d-addphoto" href="/improve?item=${f.id}&name=${encodeURIComponent(f.name)}&type=${layer.letter}&add=photo" aria-label="Add a photo of ${escPend(f.name)}">
    <svg class="cc-ap-cam" viewBox="0 0 48 36" width="42" height="31" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2">
      <rect x="1.5" y="7.5" width="45" height="27" rx="4"/><path d="M16 7.5l3-4h10l3 4" stroke-linejoin="round"/><circle cx="24" cy="21.5" r="8"/><path d="M40.5 13h.01" stroke-width="3" stroke-linecap="round"/>
    </svg>
    <span class="cc-ap-t">${D.noPhoto||'No photo yet'}</span>
    <span class="cc-ap-b">＋ ${D.addPhoto||'Add the first photo'}</span>
  </a>` : '';
  const photo = pl.length ? `<figure class="cc-d-photo">
    <img src="${safeHref(pl[0].sm)}" ${srcsetAttrs(pl[0], '(max-width: 560px) 100vw, 480px')} alt="${escPend(pl[0].alt||f.name)}" data-i="0" />
    <figcaption id="cc-d-cap">${photoCap(pl[0])}</figcaption>
    ${/* Thumbs are ~64px: sm only; a srcset here would fetch lg for nothing. */''}
    ${pl.length>1 ? `<div class="cc-d-thumbs">${pl.map((p,i)=>`<img class="cc-d-thumb${i===0?' on':''}" src="${safeHref(p.sm)}" data-i="${i}" alt="${escPend(p.alt||f.name)}, ${i+1}" />`).join('')}</div>` : ''}
  </figure>` : (f.photoPending ? waitingPhoto(f.photoPending) : addPhoto);
  let recs = f.record || [];
  if(layer.key==='climbs'){
    // docs/specs/map-and-search.md §6.2 — climb attributes from CC_FIELD_SCHEMA[N]; filled rows replace stale pre-baked ones.
    const attrRows = schemaRows('N', f, f.id);
    const attrLabels = new Set(attrRows.filter(r=>!r.empty).map(r=>r.label));
    recs = recs.filter(r=>!attrLabels.has(r.label)).concat(attrRows);
  }
  const rows = recRowsHtml(recs);
  const freshState = f.freshness ? ({fresh:D.freshFresh, ageing:D.freshAgeing, stale:D.freshStale}[f.freshness.state] || escPend(f.freshness.state)) : '';
  const fresh = f.freshness
    ? `<div class="cc-d-fresh ${f.freshness.state}">${freshState} · ${D.lastConfirmed||'last confirmed'} ${f.freshness.lastConfirmed==='this season'?(D.thisSeason||'this season'):escPend(f.freshness.lastConfirmed)}</div>` : '';
  /* data-provider-hierarchy.md §6.7.3: the provider's survey date is public
     and rides the cacheable body; the "You confirmed" sentence is a private
     fragment loadMine() fills in, for a signed-in rider only. */
  const survey = f.reclaimed
    ? `<div class="cc-d-fresh cc-d-survey">${tpl(D.providerSurvey||'The register published a newer survey on {when}.',{when:escPend(f.reclaimed)})}</div>` : '';
  const mine = (f.id!=null && window.CC_CONFIRM_TOKEN) ? `<div class="cc-d-fresh cc-d-mine" id="cc-d-mine-slot" data-item="${f.id}"></div>` : '';
  const diffLabel = f.difficulty?.label ?? (typeof f.difficulty === 'string' ? f.difficulty : undefined);
  const diffScore = f.difficulty?.score ?? null;
  const diff = diffLabel
    ? `<div class="cc-diff" title="${D.difficulty||'Difficulty'} 1–5: ${DIFF_LABELS.slice(1).join(' · ')}">${D.difficulty||'Difficulty'}
        <div class="cc-diff-scale">${[1,2,3,4,5].map(n=>`<span class="cc-diff-dot${n===diffScore?' on':''}" style="--p:${DIFF_PURPLE[n]}" title="${n} · ${DIFF_LABELS[n]}">${n}</span>`).join('')}</div>
        <b class="cc-diff-lbl">${trVal(diffLabel)}</b></div>` : '';
  // The band writes its unit once, on the upper figure: "1,200–1,850 m".
  const elev = f.elev ? `<div class="cc-elev-cap">${D.elevation||'Elevation'} · ${uElevValue(Math.min(...f.elev),0)}–${uElev(Math.max(...f.elev))}`
    + (f.gain?` · ${tpl(D.mClimbing||'{n} climbing',{n:uElev(f.gain)})}`:'') + ` <em>${D.fromGpx||'(from GPX)'}</em></div>` + elevSvg(f.elev) : '';
  const grad = f.grad ? gradStrip(f.grad, f) : '';
  // Length from the drawn line (not a form field). Prefer measured length to the summit.
  /* docs/specs/climb-elevation.md §4a — measured length stops at the summit; routeLengthKm walks the whole line. */
  const climbKm = (f.length ? Number(f.length) / 1000 : 0) || routeLengthKm(f.route);
  const len = climbKm ? `<div class="cc-elev-cap">${D.climbLength||'Length'} · ${uKm(climbKm)}</div>` : '';
  /* Who shared it: f.uploader (routes) or f.by/byName/byUuid (points). by:0 is anonymous-but-a-rider. */
  const byUuid = f.uploader ? null : f.byUuid;
  const byName = f.uploader ? (f.uploader.public ? f.uploader.name : null)
                            : (f.by ? f.byName : null);
  const hasBy = f.uploader ? true : (f.by != null);
  // Person is the source; provenance sits quieter underneath. Profile is /riders/<uuid>.
  const who = byName
    ? (byUuid ? `<a href="/riders/${encodeURIComponent(byUuid)}">${escPend(byName)}</a>` : escPend(byName))
    : (hasBy ? (D.sharedAnon||'Shared anonymously') : '');
  const up = '';   // Edit-bridge: real DB id only (docs/specs/map-and-search.md §6, §8).
  const ell = f.geom && f.geom.ll;                    
  let edit = '';
  if(f.id!=null){
    if(layer.key==='experience'){
      // docs/specs/route-domain.md §6 — community panel; GPX download stays.
      edit = routeCommunityPanel(f.id, f.state);
    } else {
      // f.letter wins: mixed-letter layers must open each item's own form.
      const editQ = `item=${f.id}&name=${encodeURIComponent(f.name)}`
        + `&type=${f.letter || layer.letter}`
        + (ell ? `&lat=${ell[0]}&lng=${ell[1]}` : '');
      edit = `<a class="cc-d-act edit" href="/improve?${editQ}">✎ ${D.editItem||'Edit this item'}</a>`;
      /* Fix location only for point items with coords, never climbs (editor is live on open). */
      if(ell && 'N' !== layer.letter){
        edit += `<a class="cc-d-act fixloc" href="/improve?${editQ}&fix=location">◎ ${D.fixLocation||'Fix location'}</a>`;
      }
    }
  } else if(f.osmRef){
    // docs/specs/osm-data-architecture.md §6 — materialize-on-edit from osmRef.
    let refQ = `ref=${encodeURIComponent(f.osmRef)}&type=${layer.letter}`
      + (ell ? `&lat=${ell[0]}&lng=${ell[1]}` : '');
    // Known segment ends: wizard opens with both pins on the stretch.
    if(f.segmentEnds){
      const p2 = c => `${c[0].toFixed(6)},${c[1].toFixed(6)}`;
      refQ += `&sa=${encodeURIComponent(p2(f.segmentEnds.a))}&sb=${encodeURIComponent(p2(f.segmentEnds.b))}`;
    }
    // Spanned way refs (capped) so every covered dash retires, not just the clicked one.
    if(Array.isArray(f.spannedRefs) && f.spannedRefs.length > 1){
      refQ += `&srefs=${encodeURIComponent(f.spannedRefs.slice(0, 120).join(','))}`;
    }
    // Prefill OSM surface on the edit link too, not only confirm.
    if(f.confirmClass) refQ += `&surface=${encodeURIComponent(f.confirmClass)}`;
    /* The OSM baseline, so the submission can say what the rider changed FROM.
       Sent even when it equals the prefill: `surface` above is a form default a
       rider may overwrite, these two are a record of what the map held. */
    if(f.osmSurface) refQ += `&osm_surface=${encodeURIComponent(f.osmSurface)}`;
    if(f.osmHighway) refQ += `&osm_highway=${encodeURIComponent(f.osmHighway)}`;
    if(f.osmName) refQ += `&name=${encodeURIComponent(f.osmName)}`;
    edit = `<a class="cc-d-act edit" href="/improve?${refQ}">✎ ${D.editItem||'Edit this item'}</a>`;
    // Confirm is the same submission, one step shorter (location already confirmed).
    if(f.confirmClass){
      edit += `<a class="cc-d-act confirm-surface" href="/improve?${refQ}&confirm=1">✓ ${D.surfaceConfirm||'This is correct'}</a>`;
    }
  }
  let moderate = '';
  if(layer.pendingLayer && f.pending){
    // docs/specs/security-architecture.md §4.2 — escape every interpolated submission field before innerHTML.
    const s=f.pending;
    /* A brand-new item comes with its would-be feature (`s.preview`, server-side,
       curators only): it is rendered below like a live item, so the raw field dump
       that used to stand in for it is not shown twice. */
    const previewed = 'new' === s.type && !!(s.preview && (s.preview.climb || s.preview.feature));
    // Edit-bridge binds to s.itemId (target catalog item), never the submission id.
    edit = (s.itemId != null)
      ? `<a class="cc-d-act edit" href="/improve?type=${encodeURIComponent(s.letter)}&item=${encodeURIComponent(s.itemId)}&name=${encodeURIComponent(s.title)}&lat=${encodeURIComponent(s.lat)}&lng=${encodeURIComponent(s.lng)}">✎ ${D.editItem||'Edit this item'}</a>`
      : '';
    const body = s.body ? `<p class="cc-mod-body">${escPend(s.body)}</p>` : '';
    // Proposed change: this submission, not item history. Gate on `now`, not `was && now`.
    /* One row per changed field; labels from the same schema as the item rows. */
    const chList = Array.isArray(s.changes) ? s.changes : [];
    const diff = (chList.length && !previewed)
      ? `<div class="cc-mod-diff"><div class="cc-mod-diff-h">${D.proposedChange||'Proposed change'}</div>
          <dl class="cc-mod-chg">${chList.map(c=>`
            <dt>${escPend(fieldLabelFor(s.letter, c.key))}</dt>
            <dd>${c.was!=null?`<span class="was">${escPend(changeValueFor(s.letter, c.key, c.was))}</span><span class="arw">→</span>`:''}<span class="now">${escPend(changeValueFor(s.letter, c.key, c.now))}</span></dd>`).join('')}
          </dl></div>`
      : (s.now
        ? `<div class="cc-mod-diff"><div class="cc-mod-diff-h">${D.proposedChange||'Proposed change'}</div>${s.was?`<div class="cc-mod-was">${escPend(s.was)}</div>`:''}<div class="cc-mod-now">${escPend(s.now)}</div></div>`
        : '');
    /* Before/after switch when a shape exists; unrecorded Before is a valid side for new surface. */
    const hasBefore = !!(s.shape && s.shape.before);
    const hasAfter = !!(s.shape && s.shape.after);
    const shapeSwitch = (hasBefore || hasAfter)
      ? `<div class="cc-mod-shape">
           <span class="cc-mod-shape-h">${D.shapeOnMap||'Shape on the map'}</span>
           <div class="cc-mod-shape-btns" role="group">
             <button type="button" class="cc-shape-btn" data-shape-side="before" aria-pressed="false"${hasBefore?'':' disabled'}>${D.shapeBefore||'Before'}</button>
             <button type="button" class="cc-shape-btn on" data-shape-side="after" aria-pressed="true"${hasAfter?'':' disabled'}>${D.shapeAfter||'After'}</button>
           </div>
         </div>`
      : '';
    // docs/specs/photo-uploads.md §5 — pending photos, default Keep; thumbnail is outside the label (opens lightbox at lg).
    const photos = Array.isArray(s.photos) ? s.photos : [];
    const modPhotos = photos.length ? `<div class="cc-mod-photos">${photos.map((p, i) => `
      <div class="cc-mod-photo">
        <button type="button" class="cc-mod-photo-zoom" data-mod-photo="${i}" aria-label="${escPend(D.photoOpen||'Open full size')}" title="${escPend(D.photoOpen||'Open full size')}">
          <img src="${safeHref(p.sm)}" alt="${escPend(p.alt||D.photoAlt||'Submitted photo')}" loading="lazy" />
        </button>
        <span class="cc-mod-photo-meta">${escPend(
          (p.distanceM != null ? (D.photoDistance||'~{d} from the pin').replace('{d}', uM(p.distanceM)) : (D.photoNoGps||'No location in the file'))
          + (p.takenAt ? ' \u00b7 ' + p.takenAt : '')
        )}</span>
        <label class="cc-mod-photo-keep"><input type="checkbox" class="cc-mod-photo-cb" data-media="${escPend(p.id)}" checked /> ${escPend(D.photoKeep||'Keep')}</label>
      </div>`).join('')}</div>` : '';
    const modHist = 'new' === s.type
      ? `<div class="cc-d-hist cc-d-hist-initial"><h4 class="cc-d-hist-h">${D.history||'History'}</h4><p class="cc-mod-initial">${D.initialEntry||'Initial entry — new item'}</p></div>`
      : (s.itemId != null ? `<div class="cc-d-hist" id="cc-d-hist-slot" data-item="${s.itemId}"></div>` : '');
    const asked = ('needs_info' === s.status && s.asked)
      ? `<div class="cc-mod-asked"><span class="cc-mod-asked-h">${D.youAsked||'You asked'}</span> ${escPend(s.asked)}</div>` : '';
    const replied = s.riderReply
      ? `<div class="cc-mod-replied"><span class="cc-mod-asked-h">${D.riderReplied||'Rider replied'}</span> ${escPend(s.riderReply)}</div>` : '';
    /* Prior rejection on a revived place — curator must see it before overturning. */
    const prior = s.priorRejection
      ? `<div class="cc-mod-prior"><span class="cc-mod-asked-h">${D.priorRejected||'Previously rejected'}</span>${
          s.priorRejection.when && window.ccDate ? ' · ' + escPend(window.ccDate(s.priorRejection.when)) : ''}${
          s.priorRejection.note ? ' · ' + escPend(s.priorRejection.note) : ''}</div>` : '';
    /* docs/specs/catalog-data-model.md §7 — flag unsafe/unknown links, never silent-reject. */
    const linkFlag = ('unsafe' === s.linkFlag || 'unknown' === s.linkFlag)
      ? `<div class="cc-mod-linkflag cc-mod-linkflag-${s.linkFlag}">${
          escPend(('unsafe' === s.linkFlag ? D.linksUnsafe : D.linksUnknown) || '')}</div>`
      : '';
    /* Edit submissions carry the target item's own rows (same renderer/escaping). */
    let context = '';
    if(previewed){
      const target = s.preview.climb || s.preview.feature;
      const src = target.properties ? Object.assign({}, target.properties, {geom:{ll:target.geometry && target.geometry.coordinates ? [target.geometry.coordinates[1], target.geometry.coordinates[0]] : null}}) : target;
      const rows = recRowsHtml(schemaRows(s.letter, src, null));
      const grad = src.grad ? gradStrip(src.grad, src) : '';
      const km = (src.length ? Number(src.length)/1000 : 0) || routeLengthKm(src.route);
      const len = km ? `<div class="cc-elev-cap">${D.climbLength||'Length'} · ${uKm(km)}</div>` : '';
      context = `<div class="cc-mod-ctx"><div class="cc-mod-ctx-h">${D.itemProposed||'This item, as proposed'}</div>${grad}${len}${rows?`<ul class="cc-d-rec">${rows}</ul>`:''}</div>`;
    }
    if('new' !== s.type && s.itemId != null){
      const lyr = CATALOG.find(l => l.letter === s.letter);
      const target = lyr && (lyr.features||[]).find(x => x.id != null && String(x.id) === String(s.itemId));
      if(target){

        setPendingContext({letter: s.letter, target, changes: chList});
        const ctxRows = recRowsHtml(schemaRows(s.letter, target, null, {proposed: proposedMap(chList)}));
        if(ctxRows) context = `<div class="cc-mod-ctx"><div class="cc-mod-ctx-h" data-ctx-h>${D.itemProposed||'This item, as proposed'}</div><ul class="cc-d-rec" data-ctx-rows>${ctxRows}</ul></div>`;
      }
    }
    const badge = 'needs_info' === s.status
      ? `<div class="cc-mod-badge waiting">? ${D.waitingOnRider||'Waiting on the rider'}</div>`
      : `<div class="cc-mod-badge">⚑ ${I18N.pendingReview||'Pending review'}</div>`;
    /* Also-confirm on approve (not water, not K, not absence). Unticked by default. */
    const NEGATIVE_NOW = ['Out of order', 'Closed', 'Not there anymore', 'Gone — clear now', 'Reduced'];
    const assertsAbsence = chList.some(c => NEGATIVE_NOW.includes(c.now));
    const alsoConfirm = ('B' !== s.letter && 'R' !== s.letter && !assertsAbsence)
      ? `<label class="cc-mod-also"><input type="checkbox" class="cc-mod-confirm-cb"> ${D.alsoConfirm||'Also confirm — I know this place (counts as verified)'}</label>`
      : '';
    /* Curator-only decide chrome; a rider sees a preview of their own pending pin. */
    moderate = window.CC_IS_CURATOR
      ? `<div class="cc-mod" data-id="${escPend(s.id)}">
      ${badge}${prior}${linkFlag}${body}${diff}${pinMoveHidesHtml(s.photosHiddenByMove, D)}${shapeSwitch}${context}${asked}${replied}${modPhotos}
      <textarea class="cc-mod-note" placeholder="${D.modNotePh||'Optional note — a reason, or context…'}"></textarea>
      ${alsoConfirm}
      <div class="cc-mod-acts">
        <button class="cc-mod-btn approve" data-decision="approve">✓ ${D.approve||'Approve'}</button>
        <button class="cc-mod-btn info" data-decision="needs_info">? ${D.needsInfo||'Needs info'}</button>
        <button class="cc-mod-btn reject" data-decision="reject">✕ ${D.reject||'Reject'}</button>
      </div>
      <div class="cc-mod-preview">${D.modKeys||'A · approve · R · reject — recorded, not yet persisted.'}</div>
      ${modHist}
    </div>`
      : `<div class="cc-mod" data-id="${escPend(s.id)}">
      ${badge}${body}${diff}${shapeSwitch}${context}${asked}${replied}
    </div>`;
  }
  // Vote CTA: votable types only, and only while community.voting_live.
  const vote = (window.CC_VOTING_LIVE && f.cur && CC_VOTABLE.has(layer.key)) ? `<a class="cc-d-act cc-d-vote" href="/vote">▲ ${D.voteRound||'Vote for it in this round'}</a>` : '';
  // docs/specs/moderation-and-contribution.md §10 — confirmation panel, hydrated on open.
  const confirmPanel = (CC_CONFIRMABLE.has(layer.key) && f.id!=null)
    ? `<div class="cc-cf" data-item="${f.id}"><div class="cc-cf-body" data-cf-body></div><div class="cc-cf-login" hidden>${D.loginConfirm||'Log in to confirm'} · <a href="/login">${I18N.login||'Log in'}</a></div></div>`
    : '';
  /* Condition reports (closed/gone/out of order) as an edit, not a confirmation. Signed-in only. */
  // Not for surface: closures ride seasonalClosure on the ordinary edit form.
  const stateRow = (CC_CONFIRMABLE.has(layer.key) && layer.key!=='surface' && f.id!=null && window.CC_CONFIRM_TOKEN)
    ? `<div class="cc-osmcf" data-item-cond="${f.id}">`
        + (CC_BREAKABLE.has(layer.key)
            ? `<button type="button" class="cc-d-act confirm-osm-warn" data-osm-stance="out_of_order">⚠ ${D.osmBroken||'Out of order'}</button>`
            : '')
        + `<button type="button" class="cc-d-act confirm-osm-warn" data-osm-stance="closed">⌀ ${D.osmClosed||'Closed'}</button>`
        + `<button type="button" class="cc-d-act confirm-osm-gone" data-osm-stance="gone">✕ ${D.osmGone||'Not there anymore'}</button>`
      + '</div>'
    : '';
  /* docs/specs/moderation-and-contribution.md §10 — one-tap OSM confirm (POST /osm/confirm). */
  // Surface tile lines keep their own confirm flow (mints the item).
  const osmConfirm = (CC_CONFIRMABLE.has(layer.key) && layer.key!=='surface' && f.id==null && f.osmRef)
    ? (!window.CC_CONFIRM_TOKEN
        /* Anonymous: login line, not a dead button. */
        ? `<div class="cc-osmcf"><span class="cc-cf-login">${D.loginConfirm||'Log in to confirm'} · <a href="/login">${I18N.login||'Log in'}</a></span></div>`
        : `<div class="cc-osmcf" data-osm-ref="${escPend(f.osmRef)}">`
        // A food stop is confirmed as present, not as drinkable.
        + ('water' === layer.key && f.waterKind !== 'food'
            ? `<button type="button" class="cc-d-act confirm-osm" data-osm-stance="potable">✓ ${D.waterA||'Drinking water'}</button>`
              + `<button type="button" class="cc-d-act confirm-osm-no" data-osm-stance="not_potable">✕ ${D.notPotable||'Not potable'}</button>`
            : `<button type="button" class="cc-d-act confirm-osm" data-osm-stance="exists">✓ ${D.confirmHere||'Confirm it\u2019s here'}</button>`)

        + (CC_BREAKABLE.has(layer.key)
            ? `<button type="button" class="cc-d-act confirm-osm-warn" data-osm-stance="out_of_order">⚠ ${D.osmBroken||'Out of order'}</button>`
            : '')
        + `<button type="button" class="cc-d-act confirm-osm-warn" data-osm-stance="closed">⌀ ${D.osmClosed||'Closed'}</button>`
        + `<button type="button" class="cc-d-act confirm-osm-gone" data-osm-stance="gone">✕ ${D.osmGone||'Not there anymore'}</button>`
      + '</div>')
    : '';

  const grp = (html, cls) => html ? `<div class="cc-d-grp${cls?' '+cls:''}">${html}</div>` : '';
  const act = grp(confirmPanel + vote, 'cc-d-grp-community')
    + grp(edit, 'cc-d-grp-edit')
    + grp(osmConfirm + stateRow, 'cc-d-grp-state');
  const desc = f.desc ? `<p class="cc-d-desc">${escPend(f.desc)}${f.descTr?` <span class="cc-d-tr">· auto-translated</span>`:''}</p>` : '';
  // History slot: real DB ids only; filled async by loadItemHistory (race-guarded).
  const histSlot = f.id!=null ? `<div class="cc-d-hist" id="cc-d-hist-slot" data-item="${f.id}"></div>` : '';
  // Curator-only: rider photos this scenic view hides, filled by loadHiddenPhotos (photo-uploads.md §5g).
  const hiddenSlot = wantsHiddenPhotos(f.letter || layer.letter, f, window.CC_IS_CURATOR)
    ? `<div id="cc-d-hidden-slot" data-item="${escPend(f.id)}"></div>` : '';
  // OSM source link: the exact node/way whenever we hold its id, and only then
  // a coordinate query. A coverage POI carries `osmRef` (drawer.js sets it from
  // the tile `ref`), so sending a rider to "what is here?" threw away an id we
  // already had and made them pick their viewpoint out of a list.
  const osmHref = f.osmUrl
    || osmRefUrl(f.osmRef)
    || ((f.geom && f.geom.ll)
      ? `https://www.openstreetmap.org/query?lat=${f.geom.ll[0]}&lon=${f.geom.ll[1]}#map=18/${f.geom.ll[0]}/${f.geom.ll[1]}`
      : 'https://www.openstreetmap.org');
  /* docs/specs/map-and-search.md §8: id first, then the name as a slug so the
     link is readable before it is clicked. The name alone was the weakest key:
     every unnamed viewpoint is called "Viewpoint", so ?feature=Viewpoint was
     one link for thousands of places and opened whichever the search hit
     first. */
  const shareQ = shareQuery(f);
  /* Icon-only share: aria-label + title; svg aria-hidden so it announces once. */
  const shareLbl = escPend(D.share||'Share');
  /* Only published items; pending/gone links would open nothing. */
  const share = (shareQ && !layer.pendingLayer)
    ? `<button type="button" class="cc-d-share" data-share="${escPend(shareQ)}"
         aria-label="${shareLbl}" title="${escPend(D.shareHint||'Copy a link that opens this place')}">
         <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" fill="none"
              stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
           <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
           <path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>
         </svg></button>`
    : '';

  /* Notice and action, DSA Article 16 (docs/specs/content-reports.md §5).
     Real DB ids only: a coverage POI we do not store has nothing of ours to
     report, and its words belong to OpenStreetMap, not to us. `experience` is
     the routes layer, and a route is its own kind of target with its own
     author. No account needed, so it renders the same signed in or not. */
  const reportKind = layer.key === 'experience' ? 'route' : 'item';
  /* `from` carries the map URL the rider is actually on, which is the only way
     a curator can tell WHICH view they meant: on the map the path alone says
     nothing, and the scope and layers live in the query string.
     `location.pathname + search`, never the hash, and the server keeps the
     path only (ContentReportController::cleanPath). */
  const reportFrom = encodeURIComponent(location.pathname + location.search);
  const reportLink = (f.id != null && !layer.pendingLayer)
    ? `<p class="cc-d-report"><a href="/report/${reportKind}/${encodeURIComponent(f.id)}?from=${reportFrom}"><span class="cc-bang" aria-hidden="true">!</span>${
        escPend(D.reportPage || 'Report this page')}</a></p>`
    : '';

  return `<div class="cc-d-head"><span class="cc-d-type" style="--c:${layer.color};color:${txtOn(layer.color)}"><i class="cc-g">${layerGlyph(layer)}</i> ${layer.label}</span>${share}</div>
    <div class="cc-d-name">${escPend(f.name)}</div>${cur}${photo}${hiddenSlot}${desc}${diff}${elev}${len}${grad}
    <ul class="cc-d-rec">${rows}</ul>${fresh}${survey}${mine}${up}
    <div class="cc-d-src">${D.source||'Source'} · ${who || srcLine(f, osmHref)}${
      who ? `<div class="cc-d-prov">${srcLine(f, osmHref)}</div>` : ''}</div>${act}${moderate}${histSlot}${reportLink}`;
}
// docs/specs/security-architecture.md §4.2 — every history field through escPend. docs/specs/photo-uploads.md §5 — gallery history is a count.
function photoCount(n){
  const c = Number(n) || 0;
  if(c === 0) return D.photosNone || 'no photos';
  return c === 1 ? (D.photosOne || '1 photo')
                 : (D.photosMany || '{n} photos').replace('{n}', String(c));
}

/* docs/specs/map-and-search.md §12 — state vocabulary translated for riders; the panel above is what is true now. */
function stateWord(v){
  if(v === 'submitted') return D.stateSubmitted || 'waiting for review';
  if(v === 'unverified') return D.stateUnverified || 'on the map, not confirmed yet';
  if(v === 'verified') return D.stateVerified || 'confirmed by riders';
  if(v === 'rejected') return D.stateRejected || 'not accepted';
  return v;
}

// History endpoint is cached: resolve the literal token `system` here.
function whoLabel(who){
  return who === 'system' ? (D.historyAuto || 'automatically') : who;
}

function historyRow(h){
  const isEmpty = v => v===null || v===undefined || v==='';
  const isPhotoField = h.field === 'photos' || h.field === 'photo';
  if(h.field === 'state'){
    return `<li class="cc-h-row">
      <span class="cc-h-field">${escPend(D.stateField||'Status')}</span>
      <span class="cc-h-diff"><span class="cc-mod-was">${escPend(stateWord(h.oldValue))}</span><span class="arw">→</span><span class="cc-mod-now">${escPend(stateWord(h.newValue))}</span></span>
      <span class="cc-h-meta">${escPend(whoLabel(h.who))} · <time datetime="${escPend(h.changedAt)}">${escPend(h.when)}</time></span>
    </li>`;
  }
  const ov = isPhotoField ? escPend(photoCount(h.oldValue))
    : (isEmpty(h.oldValue) ? '—' : escPend(h.oldValue));
  const nv = isPhotoField ? escPend(photoCount(h.newValue))
    : (isEmpty(h.newValue) ? '—' : escPend(h.newValue));
  return `<li class="cc-h-row">
    <span class="cc-h-field">${escPend(h.field)}</span>
    <span class="cc-h-diff"><span class="cc-mod-was">${ov}</span><span class="arw">→</span><span class="cc-mod-now">${nv}</span></span>
    <span class="cc-h-meta">${escPend(whoLabel(h.who))} · <time datetime="${escPend(h.changedAt)}">${escPend(h.when)}</time></span>
  </li>`;
}
// Empty history is silence, not a section.
function renderHistoryList(history){
  if(!Array.isArray(history) || !history.length) return '';
  /* Heading opens the full log; the drawer list stays clamped. */
  _histCache = history;
  return `<button type="button" class="cc-d-hist-h" data-hist-all
            aria-haspopup="dialog">${D.recentChanges||'Recent changes'} <span aria-hidden="true">↗</span></button>`
    + `<ul class="cc-d-hist-list">${history.map(historyRow).join('')}</ul>`;
}

/* Last-rendered rows; one item open at a time, so one cache slot. */
let _histCache = null;

/* Native <dialog> for the full log. */
function openHistoryDialog(){
  if(!Array.isArray(_histCache) || !_histCache.length) return;
  let dlg = document.getElementById('cc-hist-dlg');
  if(!dlg){
    dlg = document.createElement('dialog');
    dlg.id = 'cc-hist-dlg';
    dlg.className = 'cc-hist-dlg';
    document.body.appendChild(dlg);
    dlg.addEventListener('click', (e) => { if(e.target === dlg) dlg.close(); });
  }
  dlg.innerHTML = `<div class="cc-hist-dlg-in">
      <div class="cc-hist-dlg-top">
        <h4>${escPend(D.recentChanges||'Recent changes')}</h4>
        <button type="button" class="cc-hist-dlg-x" data-hist-close
                aria-label="${escPend(D.close||'Close')}">✕</button>
      </div>
      <ul class="cc-d-hist-list cc-hist-full">${_histCache.map(historyRow).join('')}</ul>
    </div>`;
  dlg.showModal();
}
/* The personal sentence (data-provider-hierarchy.md §6.7.3). Never for an
   anonymous visitor: they hold no confirmations and pay nothing. Memoised per
   item id, so reopening a drawer costs no request. A failed fetch renders
   nothing: the drawer is still correct, only shorter. */
const _mineCache = new Map();
function loadMine(f){
  if(f.id==null || !window.CC_CONFIRM_TOKEN) return;
  const id = f.id;
  const paint = data => {
    const slot = document.getElementById('cc-d-mine-slot');
    if(!slot || String(slot.dataset.item)!==String(id) || !data || !data.confirmed_at) return;
    const yours = escPend(data.confirmed_at);
    slot.textContent = '';
    slot.innerHTML = (f.reclaimed && data.confirmed_at < f.reclaimed)
      ? tpl(D.personalReclaimed||'You confirmed this on {yours}. The register published a newer survey on {theirs}.', {yours, theirs:escPend(f.reclaimed)})
      : tpl(D.personalConfirmed||'You confirmed this on {yours}.', {yours});
  };
  if(_mineCache.has(id)){ paint(_mineCache.get(id)); return; }
  fetch('/items/'+id+'/mine', {credentials:'same-origin', headers:{'Accept':'application/json'}})
    .then(r => r.ok ? r.json() : null)
    .catch(() => null)
    .then(data => { if(data) _mineCache.set(id, data); paint(data); });
}
// Race-guarded history fetch: myReq vs _historyReq; failure is silent (enhancement).
function loadItemHistory(itemId){
  const myReq = ++_historyReq;
  fetch('/map/item/' + itemId + '/history')
    .then(r => r.ok ? r.json() : null)
    .catch(()=>null)   // network/HTTP failure — enhancement only, stays silent (no exception to report)
    .then(data => {
      if(myReq !== _historyReq || !data) return;   // stale response — a newer drawer has since opened
      const slot = document.getElementById('cc-d-hist-slot');
      if(!slot) return;                              // drawer content changed/closed under us. Render errors must console.error, not look like empty history.
      try {
        slot.innerHTML = renderHistoryList(data.history);
      } catch(e){
        console.error('Recent-changes history failed to render', e);
      }
    });
}

/* photo-uploads.md §5g: the rider photos a scenic view hides, for a curator.
   Never memoised: after "Taken here" the list must be asked again. A failed
   fetch renders nothing, and the drawer is still correct. */
let _hiddenReq = 0;
let _hiddenCtx = null;
function loadHiddenPhotos(layer, f){
  const myReq = ++_hiddenReq;
  _hiddenCtx = null;
  fetch('/map/item/' + encodeURIComponent(f.id) + '/hidden-photos', {credentials:'same-origin', headers:{'Accept':'application/json'}})
    .then(r => r.ok ? r.json() : null)
    .catch(() => null)
    .then(data => {
      if(myReq !== _hiddenReq || !data || !Array.isArray(data.photos)) return;
      const slot = document.getElementById('cc-d-hidden-slot');
      if(!slot || String(slot.dataset.item) !== String(f.id)) return;
      _hiddenCtx = {layer, f, photos: data.photos};
      slot.innerHTML = hiddenPhotosHtml(data.photos, D);
      // The thumbnail opens the photo full size, as a pending photo does.
      slot.querySelectorAll('[data-hidden-photo]').forEach(btn => btn.addEventListener('click',
        () => openLightbox(data.photos, +btn.dataset.hiddenPhoto || 0, f.name)));
    });
}
document.addEventListener('click', (e) => {
  const btn = e.target && e.target.closest ? e.target.closest('[data-taken-here]') : null;
  if(!btn || !_hiddenCtx) return;
  e.preventDefault();
  const ctx = _hiddenCtx;
  const uuid = btn.getAttribute('data-taken-here');
  const photo = ctx.photos.find(p => p && p.id === uuid);
  if(!photo || !window.CC_PHOTO_TAKEN_HERE_TOKEN) return;
  btn.disabled = true;
  const body = new URLSearchParams({_token: window.CC_PHOTO_TAKEN_HERE_TOKEN});
  fetch('/moderate/photo/' + encodeURIComponent(uuid) + '/taken-here', {
    method:'POST', credentials:'same-origin', body,
    headers:{'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'},
  })
    .then(r => r.ok ? r.json() : null)
    .catch(() => null)
    .then(data => {
      if(!data || data.ok !== true){
        btn.disabled = false;
        mapToast(D.photoHiddenFailed || 'Could not confirm the photo. Try again.');
        return;
      }
      /* The catalog payload is cached, so the open record learns the photo
         here; the next payload version carries it for everyone. */
      ctx.f.photos = galleryWithConfirmed(ctx.f, photo);
      delete ctx.f.photo;
      const slot = document.getElementById('cc-d-hidden-slot');
      if(slot && String(slot.dataset.item) === String(ctx.f.id)) renderDrawerBody(ctx.layer, ctx.f);
    });
});

/* Share: delegated copy of the deep link; survives drawer re-render. */
// History dialog is delegated: the heading is rebuilt on refetch.
document.addEventListener('click', (e) => {
  if (!e.target || !e.target.closest) return;
  if (e.target.closest('[data-hist-all]')) { e.preventDefault(); openHistoryDialog(); return; }
  const x = e.target.closest('[data-hist-close]');
  if (x) { e.preventDefault(); const d = document.getElementById('cc-hist-dlg'); if (d) d.close(); }
});

document.addEventListener('click', (e) => {
  const btn = e.target && e.target.closest ? e.target.closest('[data-share]') : null;
  if (!btn) return;
  e.preventDefault();
  const url = location.origin + location.pathname + '?' + btn.getAttribute('data-share');
  const done = () => mapToast((D && D.shareCopied) || 'Link copied');
  if (navigator.share) { navigator.share({ url }).then(done, () => {}); return; }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(url).then(done, () => window.prompt((D && D.share) || 'Share', url));
    return;
  }
  window.prompt((D && D.share) || 'Share', url);
});

/* opts.center: confirmation acknowledgements; routine toasts stay low. */
export function mapToast(msg, opts){
  let t=document.getElementById('cc-toast');
  if(!t){ t=document.createElement('div'); t.id='cc-toast'; t.className='cc-toast'; document.body.appendChild(t); }
  t.textContent=msg;
  t.classList.toggle('center', !!(opts && opts.center));
  t.classList.add('show');
  clearTimeout(mapToast._t); mapToast._t=setTimeout(()=>t.classList.remove('show'),3200);
}

/** Total length of a climb's drawn line, in km; 0 when there is no geometry. */
function routeLengthKm(route){
  if(!Array.isArray(route) || route.length < 2) return 0;
  let km = 0;
  for(let i = 1; i < route.length; i++){
    const a = route[i-1], b = route[i];
    if(!Array.isArray(a) || !Array.isArray(b)) return 0;
    km += haversine(a, b);
  }
  return km;
}

/* Numbers beside the bars are the item's stated avg/max (docs/specs/map-and-search.md §4.3a), not recomputed from samples. */
function gradStrip(grad, f){
  const barMax=Math.max(...grad);
  const stated=v=>{
    if(v==null || v==='') return null;
    const m=String(v).match(/-?\d+(\.\d+)?/);
    return m ? m[0] : null;
  };
  const avg = stated(f && f.avgGradient) ?? String(Math.round(grad.reduce((a,b)=>a+b,0)/grad.length));
  const max = stated(f && f.maxGradient) ?? String(barMax);
  /* Negative bins hang below the baseline; one scale for both directions (docs/specs/climb-elevation.md §6a). */
  const binM = (f && f.binM) ? Number(f.binM)
    : Math.round(((f && f.length ? Number(f.length) : routeLengthKm(f && f.route)*1000) / Math.max(1,grad.length)) / 10) * 10;
  const ups=grad.filter(p=>p>0), downs=grad.filter(p=>p<0);
  const upMax=ups.length?Math.max(...ups):0;
  const dnMax=downs.length?Math.abs(Math.min(...downs)):0;
  const scale=Math.max(upMax,dnMax,1);
  const H=52;   // must match .cc-grad height in map.css
  const upH=dnMax?Math.max(10,Math.round(H*(upMax/(upMax+dnMax)))):H;
  const dnH=H-upH;
  const bars=grad.map((p,i)=>{
    const h=Math.max(3,Math.round((Math.abs(p)/scale)*(p<0?dnH:upH)));
    const cell=p<0
      ? `<span class="cc-grad-dn" style="height:${h}px;background:${gradColor(p)}"></span>`
      : `<span class="cc-grad-up" style="height:${h}px;background:${gradColor(p)}"></span>`;
    // Tooltip carries where as well as how steep.
    const from=(i*binM/1000), to=((i+1)*binM/1000);
    // Miles need an extra decimal or the first mile reads "0.1–0.1".
    const binDec = 'mi' === uDistUnit() ? 2 : 1;
    const where=`${uKmValue(from,binDec)}–${uKm(to,binDec)}`;
    return `<span class="cc-grad-col" style="--up:${upH}px;--dn:${dnH}px" title="${where} · ${Number(p)}%">${cell}</span>`;
  }).join('');
  /* Distance ticks so a long strip is readable. */
  const totalKm=(grad.length*binM)/1000;
  // Tick spacing follows the labelled unit, not a relabelled kilometre grid.
  const total=uKmValue(totalKm);
  const tickKm=total<=1.5?0.5:(total<=6?1:Math.ceil(total/6));
  const ticks=[];
  for(let k=tickKm;k<total-0.05;k+=tickKm){
    // Drop a tick that would crowd the end label.
    if(total-k < tickKm*0.55) continue;
    ticks.push(`<span class="cc-grad-tick" style="left:${(k/total*100).toFixed(2)}%">${k%1?k.toFixed(1):k}</span>`);
  }
  const axis=`<div class="cc-grad-axis"><span class="cc-grad-tick cc-grad-tick-0">0</span>${ticks.join('')}<span class="cc-grad-tick cc-grad-tick-end">${uKm(totalKm, total<10?1:0)}</span></div>`;
  /* Steep-window width travels with the figure (docs/specs/climb-elevation.md §5). */
  const steepW = (f && f.steepWindowM) ? Number(f.steepWindowM) : 100;
  /* Strip opens the full profile (docs/specs/climb-elevation.md §4b). */
  return `<div class="cc-elev-cap">${tpl(D.gradProfile||'Gradient profile · per {b} · avg {a}% · steepest {w} {m}%', {a:avg, m:max, b:uM(binM), w:uM(steepW)})}</div>
    <button type="button" class="cc-grad-open" data-cc-profile aria-label="${escPend(D.openProfile||'Open the full profile')}" title="${escPend(D.openProfile||'Open the full profile')}">
      <div class="cc-grad${dnMax?' has-descent':''}" style="--up:${upH}px;--dn:${dnH}px">${bars}</div>${axis}
    </button>`;
}
function elevSvg(elev){
  const w=300,h=64,pad=3,min=Math.min(...elev),max=Math.max(...elev),rng=Math.max(1,max-min);
  const xy=elev.map((e,i)=>[pad+i/(elev.length-1)*(w-2*pad), h-pad-((e-min)/rng)*(h-2*pad)]);
  const line=xy.map(p=>p[0].toFixed(1)+','+p[1].toFixed(1)).join(' ');
  return `<svg class="cc-elev" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" role="img" aria-label="${D.elevAria||'Elevation profile'}">
    <polygon points="${pad},${h-pad} ${line} ${w-pad},${h-pad}" fill="rgba(255,90,31,.16)"/>
    <polyline points="${line}" fill="none" stroke="#FF5A1F" stroke-width="1.6"/></svg>`;
}
// Content-only body: no open-state, halo, focus, or sheet snap (coverage enrich-later).
/* Poll for the Commons photo this drawer is waiting on, and swap it in when it
   lands. The slot itself is what says whether to keep going: once the rider
   opens something else it is gone from the DOM, and the poll stops by itself. */
function startPhotoWatch(name){
  const slot = document.querySelector('#drawerBody [data-photo-ref]');
  if(!slot) return;
  const ref = slot.getAttribute('data-photo-ref');
  const find = () => document.querySelector(`#drawerBody [data-photo-ref="${CSS.escape(ref)}"]`);
  watchCommonsPhoto(ref,
    p => {
      const el = find();
      if(!el) return;
      el.outerHTML = commonsPhotoHtml(p, name);
      /* The gallery's click handler is bound once, when the body is rendered,
         from photoList(f). This photo arrives after that and is not in the
         list, so without re-binding here the image simply does not open
         (reported 2026-08-25). One photo, so index 0 and no thumbnails. */
      const img = document.querySelector('#drawerBody .cc-d-photo > img');
      if(img) img.addEventListener('click', () => openLightbox([p], 0, name || ''));
    },
    () => { const el = find(); if(el) el.remove(); },
    { cancelled: () => !find() });
}
export function renderDrawerBody(layer, f){
  document.getElementById('drawerBody').innerHTML = buildRecord(layer, f);
  startPhotoWatch(f.name);
  if(layer.key==='experience' && f.id!=null) hydrateRouteCommunity(f.id);
  if(CC_CONFIRMABLE.has(layer.key) && f.id!=null) hydrateItemConfirm(f.id);
  /* Pending shape: show After first; fit so a moved summit is on screen. */
  const pShape = f.pending && f.pending.shape;
  setPendingShape(pShape || null);
  if(pShape){
    const first = pShape.after ? 'after' : 'before';
    if(showPendingShape(pShape, first)) fitPendingShape(pShape, first);
    /* Reviewing A: turn the surface skin on so the stretch has roads around it. */
    if(f.pending.letter === 'A' && surfaceTilesConfigured() && !surfaceTilesVisible()){
      setSurfaceTiles(true);
      const btn = document.getElementById('ovSurface');
      if(btn){ btn.hidden = false; btn.classList.add('on'); }
    }
  } else {
    clearPendingShape();
  }
  // Pending edits fetch the target item's history; brand-new submissions do not.
  if(f.pending){
    if('new' !== f.pending.type && f.pending.itemId!=null) loadItemHistory(f.pending.itemId);
  } else if(f.id!=null){
    loadItemHistory(f.id);
    loadMine(f);
    if(wantsHiddenPhotos(f.letter || layer.letter, f, window.CC_IS_CURATOR)) loadHiddenPhotos(layer, f);
  }
  const pl = photoList(f);
  const mainImg = document.querySelector('#drawerBody .cc-d-photo > img');
  const cap = document.getElementById('cc-d-cap');
  let cur = 0;
  function show(i){ cur=i;
    if(mainImg){ mainImg.src=safeHref(pl[i].sm);
      if(pl[i].lg){ mainImg.srcset = `${safeHref(pl[i].sm)} 520w, ${safeHref(pl[i].lg)} 1400w`; mainImg.sizes = '(max-width: 560px) 100vw, 480px'; }
      else { mainImg.removeAttribute('srcset'); mainImg.removeAttribute('sizes'); } }
    if(cap) cap.innerHTML=photoCap(pl[i]);
    document.querySelectorAll('#drawerBody .cc-d-thumb').forEach((t,k)=>t.classList.toggle('on',k===i)); }
  if(mainImg && pl.length) mainImg.addEventListener('click', ()=>openLightbox(pl, cur, f.name));
  document.querySelectorAll('#drawerBody [data-cc-profile]').forEach(
    b => b.addEventListener('click', () => openClimbProfile(f)),
  );
  document.querySelectorAll('#drawerBody .cc-d-thumb').forEach(t=>t.addEventListener('click', ()=>show(+t.dataset.i)));
  document.querySelectorAll('#drawerBody .cc-city').forEach(a=>{
    a.addEventListener('click', e=>{ e.preventDefault(); openCity(a.dataset.city); });
    a.addEventListener('mouseenter', ()=>{ const c=CITIES[a.dataset.city]; if(c) highlightAt(c.ll); });
    a.addEventListener('mouseleave', clearHighlight);
  });
  /* Moderation buttons are delegated in initCommunity() — per-element listeners die on re-render. */
  // docs/specs/photo-uploads.md §5 — submitted photos open at lg.
  const modPhotos = (f.pending && Array.isArray(f.pending.photos)) ? f.pending.photos : [];
  if(modPhotos.length) document.querySelectorAll('#drawerBody .cc-mod-photo-zoom').forEach(btn=>{
    btn.addEventListener('click', ()=>openLightbox(modPhotos, +btn.dataset.modPhoto || 0, f.pending.title || f.name));
  });
}
export function openDrawer(layer, f){
  // docs/specs/route-domain.md §7 — while picking corrections, do not also open the drawer.
  if(isPicking()) return;
  clearRevealPin();   
  clearSelectedCoverageIcon();   
  invalidateCoverageDrawer(); bumpPlaceReq();   // Invalidate in-flight coverage POI detail and town-card nearby; this render supersedes them.
  if(layer.key==='experience'){
    const i=layer.features.indexOf(f);
    if(i>=0 && map.getLayer('experience-'+i)) highlightRoute('experience-'+i);
    clearHighlight();                    // routes read as the wide line halo, not a point halo
  } else {
    clearRouteHighlight();
    clearRouteSelection();
    // Selected surface segment lights up as a shape so its length is visible.
    if(layer.key==='surface' && f.geom && Array.isArray(f.geom.path) && f.geom.path.length>1) showSurfaceSelection(f.geom.path);
    else clearSurfaceSelection();
    // Pulsing selection halo on the clicked point (curated and OSM).
    /* Halo offset onto the pin (anchor bottom). Flat OSM dots (no id) stay centred. Climbs: ring the foot. */
    const hlAt = f.route ? f.route[0] : (f.geom && f.geom.ll);   
    const isPin = !!(f.cur || f.pending || f.id != null);
    highlightAt(hlAt, isPin ? [0,-16] : [0,0]);
  }
  renderDrawerBody(layer, f);
  const d=document.getElementById('drawer'); d.classList.add('open'); d.classList.remove('folded'); d.setAttribute('aria-hidden','false');
  syncMapWrap();   // a new record always arrives unfolded, and the toolbar steps aside
  d.focus({preventScroll:true});   // move focus into the panel (not the close X — avoids a focus ring on tap/click open)
  if(window.innerWidth<=820) sheet.reset();          // land at half; desktop untouched
}

// pulsing highlight marker — show where a hovered list item / town sits on the map
let hlMarker=null;
export function highlightAt(ll, offset){
  if(!ll){ return clearHighlight(); }
  if(!hlMarker){ const el=document.createElement('div'); el.className='cc-highlight'; hlMarker=new maplibregl.Marker({element:el,anchor:'center'}); }
  hlMarker.setOffset(offset||[0,0]).setLngLat([ll[1],ll[0]]).addTo(map);
}
export function clearHighlight(){ if(hlMarker) hlMarker.remove(); }
// docs/specs/map-and-search.md §12 — reveal pin for a non-drawn search hit in Curated; never force Everything.
let _revealMarker=null;
export function clearRevealPin(){ if(_revealMarker){ _revealMarker.remove(); _revealMarker=null; } }
export function revealPinAt(layer, ll){
  clearRevealPin();
  // A hit nobody has confirmed, drawn as ours with the "?" and the pulse.
  const el=pinEl(layer, {rung:1, custody:'ours'});
  el.classList.add('reveal');
  _revealMarker=new maplibregl.Marker({element:el, anchor:'bottom'}).setLngLat([ll[1],ll[0]]).addTo(map);
}
/* The map wrapper mirrors the drawer's state, because the top-right toolbar
   lives in the map and has to know to step aside. A class rather than :has(),
   so the rule is one selector a person can find and a test can assert. */
function syncMapWrap(){
  const wrap = document.querySelector('.map-wrap');
  if(!wrap) return;
  const d = document.getElementById('drawer');
  const open = !!d && d.classList.contains('open');
  const folded = open && d.classList.contains('folded');
  wrap.classList.toggle('drawer-open', open && !folded);
  wrap.classList.toggle('drawer-folded', folded);
}

/* Fold the drawer to a handle, or bring it back.
   Deliberately not closeDrawer(): that drops the selection, the highlight and
   any pending overlay, so glancing at the map underneath used to cost the
   rider the record they were reading (reported 2026-08-25). */
export function foldDrawer(folded){
  const d = document.getElementById('drawer');
  if(!d || !d.classList.contains('open')) return;
  d.classList.toggle('folded', folded);
  const btn = document.getElementById('drawerFold');
  if(btn) btn.setAttribute('aria-expanded', folded ? 'false' : 'true');
  syncMapWrap();
}

export function closeDrawer(){
  // Closing mid-pick tears the picking session down (no orphaned map handler).
  if(isPicking()) cancelPicking();
  // Race-guard: close invalidates in-flight coverage POI detail and town-card nearby.
  invalidateCoverageDrawer(); bumpPlaceReq();
  const d=document.getElementById('drawer'); d.classList.remove('open','folded'); d.setAttribute('aria-hidden','true');
  const foldBtn=document.getElementById('drawerFold'); if(foldBtn) foldBtn.setAttribute('aria-expanded','true');
  syncMapWrap();
  clearHighlight();
  clearSelectedCoverageIcon();                        // remove the selected coverage POI's persistent icon overlay
  clearRevealPin();
  clearRouteHighlight();
  clearRouteSelection();   // the OSM corridor highlight (routes-tiles.js), not the K layer above
  clearSurfaceSelection();
  clearCorrections();
  clearPendingShape();                               // drop the before/after climb overlay with the card that owns it
  setPendingShape(null);
  setPendingContext(null);
  sheet.clear();                                     // drop snap classes + inline transform for the next open
}
// Close: X and scrim (tap the dimmed area above the mobile sheet).
export function initDrawerChrome(){
  document.getElementById('drawerClose').onclick=closeDrawer;
  const fold = document.getElementById('drawerFold');
  if(fold) fold.onclick = () => foldDrawer(!document.getElementById('drawer').classList.contains('folded'));
  document.getElementById('drawerScrim').onclick=closeDrawer;   

  /* Delegated: rows rebuild every open. Click and keyboard (touch has no hover). */
  const drawer = document.getElementById('drawer');
  const toggleWhy = el => {
    const row = el.closest('li');
    const why = row && row.querySelector('.cc-d-why');
    if (!why) return;
    why.hidden = !why.hidden;
    el.setAttribute('aria-expanded', why.hidden ? 'false' : 'true');
  };
  drawer.addEventListener('click', e => {
    const badge = e.target.closest('.m.as');
    if (badge) { e.stopPropagation(); toggleWhy(badge); }
  });
  drawer.addEventListener('keydown', e => {
    const badge = e.target.closest && e.target.closest('.m.as');
    if (badge && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); toggleWhy(badge); }
  });
}
