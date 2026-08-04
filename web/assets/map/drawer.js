// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The item drawer: the registry-driven attribute rows, the OSM/water card
   builders, the record HTML, the async change-history section, the toast, the
   elevation/gradient strips, and the open/close lifecycle with its selection
   halo and reveal pin.
   Extracted from map.js by the module split.

   This is the keystone of the split: nine of these functions were injected into
   seven other modules while it lived in the entry, so most of those deps
   objects empty out here. It was also the only extraction whose source was not
   one contiguous range — five disjoint blocks, mapped in §9 before the cut.

   Cycles with render.js, coverage.js, sheet.js, lightbox.js and places.js are
   expected and safe (§4.1): every cross-module reference is called at runtime,
   never at module evaluation.

   Nothing is injected any more: every module this one reaches into has landed,
   so initDrawer() is gone and only initDrawerChrome() remains (§9). */
import { I18N, D, tpl, trVal, sourceLabel, DIFF_LABELS } from './i18n.js';
import { escPend, safeHref, stars, slug, txtOn, gradColor, DIFF_PURPLE, ccUrl, attachPhotos, haversine } from './util.js';
import { map } from './map-init.js';
import { CATALOG, CITIES } from './catalog.js';
import { pinEl } from './icons.js';
import { sheet } from './sheet.js';
import { openLightbox } from './lightbox.js';
import { highlightRoute, clearRouteHighlight } from './render.js';
import { clearSelectedCoverageIcon, invalidateCoverageDrawer } from './coverage.js';
import { isPicking, cancelPicking } from './picking.js';
import { openCity, bumpPlaceReq } from './places.js';
import { CC_VOTABLE, CC_CONFIRMABLE, routeCommunityPanel, hydrateRouteCommunity,
         hydrateItemConfirm, setPendingShape } from './community.js';
import { showPendingShape, fitPendingShape, clearPendingShape } from './pending-shape.js';
import { clearCorrections } from './corrections.js';

// Injected by initDrawer() until their owning modules exist (see the header).

// C1-T3: race-guard token for the drawer's async "Recent changes" fetch —
// bumped on every openDrawer() call so a slow response from a since-replaced
// drawer never paints stale history over whatever is open now.
let _historyReq = 0;

// Registry-driven attribute rows (spec: 2026-07-13-registry-driven-drawer-fields).
// CC_FIELD_SCHEMA[letter] is the server's per-type display-field list
// [{key,label,kind}] with labels already localised. For each field: a value
// row when set, otherwise a muted "add" prompt to the /improve edit-bridge.
// opts.skip = field keys a builder renders structurally (e.g. routes' difficulty
// badge) so they are not double-rendered here.
export function schemaRows(letter, src, id, opts){
  const schema = (window.CC_FIELD_SCHEMA || {})[letter] || [];
  const skip = (opts && opts.skip) || [];
  const fixed = (opts && opts.fixed) || {};   // key -> assumed default when UNSET: shown as a value row instead of an "add" prompt; a stored value wins
  /* opts.proposed: {key -> formatted value} from a pending submission. When
     present, those fields render the PROPOSED value instead of the item's
     current one, marked so it reads as a proposal rather than as fact. This is
     what makes the pending card's Before/After switch move the whole drawer and
     not just the map line: a curator compares against the item's own display,
     in place, rather than against a separate diff block (owner, 2026-08-03). */
  const proposed = (opts && opts.proposed) || null;
  const rows = [];
  schema.forEach(f=>{
    if(skip.indexOf(f.key) >= 0) return;
    if(proposed && Object.prototype.hasOwnProperty.call(proposed, f.key)){
      rows.push({label:f.label, value:proposed[f.key], changed:true});
      return;
    }
    const v = src[f.key];
    const has = Array.isArray(v) ? v.length > 0 : (v != null && v !== '');
    // Stored values are canonical English; f.choices (schema-provided, per
    // field) maps them to the rider's locale for display only.
    const cv = f.choices || {};
    const tv = x => cv[x] != null ? cv[x] : x;
    if(has){
      if(f.kind === 'multiselect'){
        const list = Array.isArray(v) ? v : [v];
        rows.push({label:f.label, html:true, value:list.map(t=>`<span class="cc-chip">${escPend(tv(t))}</span>`).join('')});
      } else if(f.kind === 'rating'){
        rows.push({label:f.label, value: /^[1-5]$/.test(String(v)) ? stars(Number(v)) : v});
      } else if(f.kind === 'url'){
        rows.push({label:f.label, value:String(v).replace(/^https?:\/\//,'').replace(/\/$/,''), links:[{label:D.visitSite||'Visit site', href:v}]});
      } else {
        rows.push({label:f.label, value:tv(v)});
      }
    } else if(fixed[f.key] != null){
      rows.push({label:f.label, value:tv(fixed[f.key])});
    } else if(id != null){
      const href = `/improve?item=${encodeURIComponent(id)}&type=${encodeURIComponent(letter)}&field=${encodeURIComponent(f.key)}`;
      rows.push({label:f.label, html:true, empty:true, value:`<a class="cc-d-add" href="${href}">＋ ${D.add||'add'}</a>`});
    }
  });
  return rows;
}
/* Fields a submission can change that have no DISPLAY row of their own, so the
   schema below carries no label for them: the climb editor's three geometry
   fields, and the correction note (display:false in the registry). Without
   these the diff read `grad`, `route`, `steep` at a curator. */
const OFF_SCHEMA_LABELS = {
  route: () => D.fRoute || 'Climb line',
  grad: () => D.fGrad || 'Gradient profile',
  steep: () => D.fSteep || 'Steepest ramp',
  correction: () => D.fCorrection || 'Anything to correct?',
};

/** A field's localised label from the display schema, or the raw name. */
export function fieldLabelFor(letter, name){
  const f = ((window.CC_FIELD_SCHEMA||{})[letter]||[]).find(x => x.key === name);
  if(f && f.label) return f.label;
  const off = OFF_SCHEMA_LABELS[name];
  return off ? off() : name;
}

/** {fieldName -> proposed value}, for schemaRows' `proposed` option. */
export function proposedMap(changes){
  const out = {};
  (changes||[]).forEach(c => { if(c && c.key != null) out[c.key] = c.now; });
  return out;
}

/* The pending submission's item context, kept so the Before/After switch can
   re-render it. Set when a pending card renders, cleared when the drawer
   closes. */
let _ctx = null;
export function setPendingContext(ctx){ _ctx = ctx || null; }

/**
 * Repaint the "this item" rows for one side of the switch.
 *
 * AFTER shows the proposed values in place of the current ones, marked; BEFORE
 * shows the item untouched. Repainting the same list — rather than revealing a
 * second one — is what makes the two states comparable: every row stays where
 * it was, so only the values move.
 */
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

/** The <li> markup for a list of record rows. Extracted so the pending card can
    render the target item's own rows with exactly the drawer's markup and
    exactly the drawer's escaping — a second, slightly different renderer is how
    an escaping rule gets forgotten in one of them. */
function recRowsHtml(recs){
  return recs.map(r => {
    const links = r.links ? ' ' + r.links.map(l=>`<a class="cc-d-link" href="${safeHref(l.href)}" target="_blank" rel="noopener">${escPend(l.label)} ↗</a>`).join('') : '';
    // r.html is the explicit trusted-markup channel (like r.links): honored
    // only for rows whose markup the builder constructs itself with EVERY
    // interpolation escPend-escaped (RIDE_CITIES city links, bike-type
    // chips). Raw payload values never take this path — they stay
    // escPend-escaped below (spec §13).
    return `<li class="${r.empty?'empty':''}${r.changed?' chg':''}"><span class="k">${escPend(r.label)}</span><span class="v${r.warn?' warn':''}">${r.html?r.value:escPend(r.value)}${r.method?`<span class="m">${r.method}</span>`:''}${links}</span></li>`;
  }).join('');
}

// drawer card for a generic bulk-OSM point — shared by the dot click handler and the confirmed pin
export function osmDrawer(layer, p, ll, src){
  const lbl=(layer||{}).label||D.place||'Place';
  const pivot=p.src==='pivot';   // official Tourisme Wallonie accommodation (CC-BY), not OSM
  // C1-T4 (W6): p.srcType is the item's real ItemSource value from CatalogProvider.
  // A rider-added/edited item (user/manual) must read as rider-contributed even
  // when served through a bulk-OSM layer — the per-fact "Type"/"Listed" method
  // tags below are unchanged (Phase C2 scope), only the headline + source line
  // are corrected here.
  const community = p.srcType==='user' || p.srcType==='manual';
  const originLbl = pivot?'Tourisme Wallonie':(community?sourceLabel(p.srcType):'OSM');
  // D · services carries a serviceKind (shop/station/pump) — when present, the localized
  // kind label takes precedence over the raw OSM p.t value for the type row + headline
  // (e.g. EN 'Repair station' → 'Self-service station'; FR/NL/DE get real translations
  // instead of the raw English t). Every other layer (and services items with no/unknown
  // serviceKind) falls back to today's p.t||lbl behaviour, unchanged.
  const kindLbl = p.serviceKind && ({shop:D.kindShop, station:D.kindStation, pump:D.kindPump}[p.serviceKind] || lbl);
  const typeLbl = kindLbl || p.t || lbl;
  let rec=[{label:D.type||'Type', value:typeLbl, method: pivot?'Tourisme Wallonie':'OSM'}];
  if(p.town && layer.letter!=='E') rec.push({label:D.town||'Town', value:p.town});  // stays' 'town' comes from the schema (labelled "Town / commune")
  // Province renders only when the item actually carries one (curated PIVOT/
  // harvest rows). Coverage POIs are Belgium-wide with region_id NULL by design
  // (coverage-provider.md §2) — the old 'Wallonia' fallback mislabelled every
  // Flanders POI, so no prov means no row until region attribution exists.
  if(p.prov) rec.push({label:D.province||'Province', value:p.prov});
  if(pivot) rec.push({label:D.listed||'Listed', value:D.officialRegistry||'Official Tourisme Wallonie registry', method:'official'});
  // The simulated demo Status/Rating rows are gone — simulated flags die
  // (map-and-search.md §12); their payload keys stay for byte-stability but
  // nothing reads them.
  if(p.web) rec.push({label:D.website||'Website', value:p.web.replace(/^https?:\/\//,'').replace(/\/$/,''), links:[{label:D.visitSite||'Visit site',href:p.web}]});
  // Registry-driven attribute rows (single source of truth = CatalogFormRegistry,
  // served as CC_FIELD_SCHEMA). Filled rows replace any structural row of the
  // same label (e.g. a curated 'Type' overriding the raw OSM one); unset fields
  // become "add" prompts. 'web' dedupes by the shared "Website" label below.
  // Stations/pumps are unmanned and inherently 24/7 (spec §5) — the /improve
  // form has no openingHours field for them, so instead of an "add" prompt
  // (which would deep-link to a field that doesn't exist) the drawer states
  // the implied fact: Opening hours · 24/7.
  const unmanned = p.serviceKind==='station' || p.serviceKind==='pump';
  const attrRows = schemaRows((layer||{}).letter, p, p.id, unmanned ? {fixed:{openingHours:'24/7'}} : undefined);
  const attrLabels = new Set(attrRows.filter(r=>!r.empty).map(r=>r.label));
  rec = rec.filter(r=>!attrLabels.has(r.label)).concat(attrRows);
  // source is shown once, in the bottom cc-d-src line (linkified there) — like every other drawer
  const d={name:p.n||p.t||lbl, headline:typeLbl+' · '+originLbl, cur:!!p.v, geom:{ll:[ll.lat,ll.lng]}, record:rec,
    source: pivot?'Tourisme Wallonie (TW) — CC-BY 4.0 · PIVOT / Géoportail de la Wallonie'
      :(community?sourceLabel(p.srcType):(src||sourceLabel(p.srcType)||'OpenStreetMap'))};
  if(p.id!=null) d.id=p.id;          // real DB item id — the edit-bridge's `?item=` target
  else if(p.ref) d.osmRef=p.ref;     // uncurated coverage POI — materialize-on-edit target
  if(p.desc) d.desc=p.desc;
  if(p.descTr) d.descTr=1;
  attachPhotos(d, p);
  return d;
}
// Per-country tap-water verification references for the water drawer's
// Verify row. Keyed by the POI's country (covProps' cc, off the tile scope
// token) — the pre-multi-country build hardcoded the Walloon pair for every
// fountain, which showed SWDE to riders in the Netherlands. Countries
// without a vetted reference get NO links; the row copy already says
// "check with the regional utility", which stays true everywhere.
const WATER_CHECK_LINKS={
  BE:[{label:'SWDE · Wallonia',href:'https://www.swde.be'},{label:'eaupotable.info',href:'https://eaupotable.info/nl/be-belgie'}],
  // Owner-vetted 2026-07-30: drinkwaterkaart.nl is the moderated NL tap-point
  // map (candidate partner). The authoritative dataset behind it is the
  // Nationaal Georegister drinkwatertappunten record — a pipeline SOURCE,
  // not a rider-facing link.
  NL:[{label:'Drinkwaterkaart · NL',href:'https://www.drinkwaterkaart.nl'}],
};
// drawer card for a water point — shared by the droplet click handler and the confirmed pin
export function waterDrawer(p, ll){
  // C2-T7 (spec §W2): 'potable'/'type' are WaterFood registry fields
  // (CatalogFormRegistry::for(WaterFood)) — when a rider has set them, they
  // take priority over the generic OSM-derived guess below (same
  // dedup-by-label rule as the other POI drawers/climbs).
  // The simulated middle branch (demo potable flag) is gone — simulated
  // flags die (map-and-search.md §12). v:1 is existence/verification, not a
  // potability statement: without a rider-set potable field the honest row
  // is the OSM-derived fallback — and that fallback must not claim
  // "drinkable" for a fountain OSM tags as NOT potable (p.osmPotable===false,
  // derived in covProps from the tile `potable` prop / tags.drinking_water).
  const potable = p.potable
    ? {label:D.potable||'Potable', value:trVal(p.potable)}
    : (p.osmPotable===false
        ? {label:D.potable||'Potable', value:D.potableOsmNo||'Tagged not drinkable in OSM — not utility-verified; avoid unless confirmed on the spot', method:'unverified'}
        : {label:D.potable||'Potable', value:D.potableOsm||'Tagged drinkable in OSM — not utility-verified; confirm on the spot', method:'unverified'});
  // C1-T4 (W6): see osmDrawer — a rider-added/edited water point isn't OSM.
  const community = p.srcType==='user' || p.srcType==='manual';
  const rec=[{label:D.type||'Type', value:(p.type||p.t) ? trVal(p.type||p.t) : (D.drinkingWater||'Drinking water'), method: p.type?undefined:'OSM'}, potable,
    {label:D.verify||'Verify', value:D.verifyWater||'Cross-check tap-water quality with the regional utility / fountain directory', links:WATER_CHECK_LINKS[p.cc]||[]}];
  // Registry-driven rows for the remaining WaterFood fields (seasonal/note/
  // bottleFill/cost). 'type' and 'potable' are rendered structurally above.
  rec.push(...schemaRows('C', p, p.id, {skip:['type','potable']}));
  const d={name:p.n||p.t||D.drinkingWater||'Drinking water', headline:(D.headlineDrinking||'drinking water')+' · '+(community?sourceLabel(p.srcType):'OSM'), cur:!!p.v, geom:{ll:[ll.lat,ll.lng]},
    record:rec,
    source: community?sourceLabel(p.srcType):'OpenStreetMap (amenity=drinking_water / drinking_water=yes)'};
  if(p.id!=null) d.id=p.id;          // real DB item id — the edit-bridge's `?item=` target
  else if(p.ref) d.osmRef=p.ref;     // uncurated coverage POI — materialize-on-edit target
  // Same photo handling as osmDrawer — waterDrawer never copied this over,
  // so a water point's importable photo attribute (e.g. a Wikimedia Commons
  // spring photo) silently never reached buildRecord()'s figure/lightbox.
  attachPhotos(d, p);
  return d;
}

function photoList(f){ return f.photos || (f.photo ? [f.photo] : []); }

// Let the device pick the variant instead of hard-wiring one
// (docs/specs/photo-uploads.md §5). Uploads and imported photos share the
// 520/1400 width convention, so this one helper upgrades every photo on the
// site. `orig` is deliberately absent: it is the reuse asset, not a display
// candidate.
export function srcsetAttrs(p, sizes){
  if(!p || !p.sm || !p.lg) return '';
  return `srcset="${safeHref(p.sm)} 520w, ${safeHref(p.lg)} 1400w" sizes="${sizes}"`;
}

// 'YYYY-MM' → a localized month, falling back to the raw value if anything
// about it is unexpected. Never a precise date: the day is deliberately not
// published.
function monthLabel(takenAt){
  const m = /^(\d{4})-(\d{2})$/.exec(String(takenAt||''));
  if(!m) return String(takenAt||'');
  const d = new Date(Number(m[1]), Number(m[2])-1, 1);
  // Shared formatter, so a capture month in the drawer matches every other date
  // the rider sees (account-and-auth.md §9).
  if(window.ccMonth) return window.ccMonth(d);
  try{
    return d.toLocaleDateString(document.documentElement.lang||undefined, {year:'numeric', month:'short'});
  }catch(e){ return String(takenAt); }
}

// p.photo is parsed straight from the importable photo attribute (review W1):
// credit/license/source text goes through escPend, and creditUrl/source
// through safeHref — same hardening r.links[].href already has.
//
// Two shapes share this caption. An imported photo has a credit and a Commons
// file page; a rider upload has neither guaranteed — an anonymous
// contributor's photo has an empty credit by design (the uploader rule), and
// no upload has a source page at all. Each part therefore renders only when it
// exists, so no caption ever claims a Wikimedia origin a photo does not have.
export function photoCap(p){
  const name = p.credit ? escPend(p.credit) : escPend(D.anonCredit||'Anonymous rider');
  const credit = p.creditUrl ? `<a href="${safeHref(p.creditUrl)}" target="_blank" rel="noopener">${name}</a>` : name;
  const license = p.license ? ` · <a href="${ccUrl(p.license)}" target="_blank" rel="noopener">${escPend(p.license)}</a>` : '';
  const source = p.source ? ` · <a href="${safeHref(p.source)}" target="_blank" rel="noopener">Wikimedia Commons ↗</a>` : '';
  // Month-granular capture date — public seasonal context
  // (docs/specs/photo-uploads.md §5).
  const taken = p.takenAt ? ` · ${escPend(monthLabel(p.takenAt))}` : '';
  return `© ${credit}${license}${source}${taken}`;
}
function buildRecord(layer, f){
  const cur = f.cur ? `<div class="cc-d-cur">▲ ${I18N.curated||'Curated best-of'}</div>` : '';
  const pl = photoList(f);
  // Same edit-bridge rule as the "Edit this item" link below (spec §6/§8):
  // the add-photo CTA only ever binds to the item's real DB id — no id, no
  // link (a name-slug guess is never a faithful target).
  const addPhoto = (f.id!=null && layer.key!=='experience') ? `<a class="cc-d-addphoto" href="/improve?item=${f.id}&name=${encodeURIComponent(f.name)}&type=${layer.letter}&add=photo" aria-label="Add a photo of ${escPend(f.name)}">
    <svg class="cc-ap-cam" viewBox="0 0 48 36" width="42" height="31" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2">
      <rect x="1.5" y="7.5" width="45" height="27" rx="4"/><path d="M16 7.5l3-4h10l3 4" stroke-linejoin="round"/><circle cx="24" cy="21.5" r="8"/><path d="M40.5 13h.01" stroke-width="3" stroke-linecap="round"/>
    </svg>
    <span class="cc-ap-t">${D.noPhoto||'No photo yet'}</span>
    <span class="cc-ap-b">＋ ${D.addPhoto||'Add the first photo'}</span>
  </a>` : '';
  const photo = pl.length ? `<figure class="cc-d-photo">
    <img src="${safeHref(pl[0].sm)}" ${srcsetAttrs(pl[0], '(max-width: 560px) 100vw, 480px')} alt="${escPend(f.name)}" data-i="0" />
    <figcaption id="cc-d-cap">${photoCap(pl[0])}</figcaption>
    ${/* Thumbs render ~64px: sm is already the right choice, and a srcset here
          would only invite the browser to download lg for nothing. */''}
    ${pl.length>1 ? `<div class="cc-d-thumbs">${pl.map((p,i)=>`<img class="cc-d-thumb${i===0?' on':''}" src="${safeHref(p.sm)}" data-i="${i}" alt="${escPend(f.name)} — photo ${i+1}" />`).join('')}</div>` : ''}
  </figure>` : addPhoto;
  let recs = f.record || [];
  if(layer.key==='climbs'){
    // Registry-driven (CC_FIELD_SCHEMA[B]): every climb attribute — including
    // the additional options (water on climb / hairpins / shade) the old
    // hand-list dropped — renders here, filled or as an "add" prompt.
    // Attributes flow via CatalogProvider::climbs() (CC_CLIMBS spreads
    // item.attributes onto the feature) -> layerByKey['climbs'].features -> f.
    // A filled schema row REPLACES any stale pre-baked f.record row of the same
    // label (wallonia harvest + hand-authored CATALOG fixtures) so an approved
    // edit is never shadowed by a duplicate.
    const attrRows = schemaRows('B', f, f.id);
    const attrLabels = new Set(attrRows.filter(r=>!r.empty).map(r=>r.label));
    recs = recs.filter(r=>!attrLabels.has(r.label)).concat(attrRows);
  }
  const rows = recRowsHtml(recs);
  const freshState = f.freshness ? ({fresh:D.freshFresh, ageing:D.freshAgeing, stale:D.freshStale}[f.freshness.state] || f.freshness.state) : '';
  const fresh = f.freshness
    ? `<div class="cc-d-fresh ${f.freshness.state}">${freshState} · ${D.lastConfirmed||'last confirmed'} ${f.freshness.lastConfirmed==='this season'?(D.thisSeason||'this season'):f.freshness.lastConfirmed}</div>` : '';
  // difficulty is always {score,label} now (P2-D1); typeof fallback is defensive only.
  const diffLabel = f.difficulty?.label ?? (typeof f.difficulty === 'string' ? f.difficulty : undefined);
  const diffScore = f.difficulty?.score ?? null;
  const diff = diffLabel
    ? `<div class="cc-diff" title="${D.difficulty||'Difficulty'} 1–5: ${DIFF_LABELS.slice(1).join(' · ')}">${D.difficulty||'Difficulty'}
        <div class="cc-diff-scale">${[1,2,3,4,5].map(n=>`<span class="cc-diff-dot${n===diffScore?' on':''}" style="--p:${DIFF_PURPLE[n]}" title="${n} · ${DIFF_LABELS[n]}">${n}</span>`).join('')}</div>
        <b class="cc-diff-lbl">${trVal(diffLabel)}</b></div>` : '';
  const elev = f.elev ? `<div class="cc-elev-cap">${D.elevation||'Elevation'} · ${Math.min(...f.elev)}–${Math.max(...f.elev)} m`
    + (f.gain?` · ${tpl(D.mClimbing||'{n} m climbing',{n:f.gain})}`:'') + ` <em>${D.fromGpx||'(from GPX)'}</em></div>` + elevSvg(f.elev) : '';
  const grad = f.grad ? gradStrip(f.grad, f) : '';
  // Length, measured off the drawn line. A climb had no length anywhere in the
  // drawer: it is not a form field (nobody types a climb's length, and a typed
  // one would disagree with the line on the map), and only a couple of seeded
  // climbs happened to mention it inside their free-text headline. Deriving it
  // from `route` means the number and the drawn climb can never disagree, and
  // every climb with a geometry gets one — including edited and new ones.
  const climbKm = routeLengthKm(f.route);
  const len = climbKm ? `<div class="cc-elev-cap">${D.climbLength||'Length'} · ${climbKm.toFixed(1)} km</div>` : '';
  const up = f.uploader
    ? (f.uploader.public
        ? `<div class="cc-up">${D.sharedBy||'Shared by'} <b>${escPend(f.uploader.name)}</b> · <a href="/profile?u=${slug(f.uploader.name)}">${D.viewProfile||'view profile'}</a></div>`
        : `<div class="cc-up">${D.sharedAnon||'Shared anonymously'}</div>`)
    : '';
  // The edit-bridge opens /improve bound to the item's real DB id, which
  // loads that exact item and prefills the form with its current values
  // (spec §6/§8) — no id, no edit link (a name-slug guess is never a
  // faithful target).
  const ell = f.geom && f.geom.ll;                    // [lat,lng] for point features
  let edit = '';
  if(f.id!=null){
    if(layer.key==='experience'){
      // Route domain v1 phase 3 (spec §7): the community panel. GPX download
      // stays; rode-it/vote/suggest render as a container filled async by
      // openDrawer's GET /routes/{id}/community (P3-D3) — riders never edit.
      edit = routeCommunityPanel(f.id, f.state);
    } else {
      const editQ = `item=${f.id}&name=${encodeURIComponent(f.name)}`
        + `&type=${layer.letter}`
        + (ell ? `&lat=${ell[0]}&lng=${ell[1]}` : '');
      edit = `<a class="cc-d-act edit" href="/improve?${editQ}">✎ ${D.editItem||'Edit this item'}</a>`;
      /* Direct "this pin is wrong" path — only when we know where it is, and
         NOT for climbs. A point item opens its edit form on a compact,
         view-only confirm map, so "Fix location" is what unlocks the pin;
         letter B has no such gate — the three-point editor is live the moment
         the form opens, with foot, summit and steepest all draggable. Both
         links would therefore land on the identical page, and a second door
         into one room reads as a second room (owner, 2026-08-03).
         editQ already carries lat/lng; fix=location opens the editor expanded. */
      if(ell && 'B' !== layer.letter){
        edit += `<a class="cc-d-act fixloc" href="/improve?${editQ}&fix=location">◎ ${D.fixLocation||'Fix location'}</a>`;
      }
    }
  } else if(f.osmRef){
    // Materialize-on-edit (osm-data-architecture.md §6): an uncurated
    // coverage POI is improved like any other place — the wizard opens with
    // the OSM name + location given, and submit CREATES the item (carrying
    // this ref, so the coverage twin dedupes away once it serves).
    const refQ = `ref=${encodeURIComponent(f.osmRef)}&type=${layer.letter}`
      + (ell ? `&lat=${ell[0]}&lng=${ell[1]}` : '');
    edit = `<a class="cc-d-act edit" href="/improve?${refQ}">✎ ${D.editItem||'Edit this item'}</a>`;
  }
  let moderate = '';
  if(layer.pendingLayer && f.pending){
    // §13: real submissions now flow here (not trusted fixtures) — HTML-escape
    // every interpolated submission field before it hits innerHTML (stored-XSS-
    // in-curator-session risk). s.title/s.who/s.when render via the shared
    // f.name/f.record path above (untouched — see MapController/Task 4).
    const s=f.pending;
    // Pending items carry their own catalog letter (A–K) + coords → a faithful edit link.
    // Every interpolation is encoded (review W33): the server serves lat/lng
    // numeric and letter as an enum, but this attribute context shouldn't
    // depend on that guarantee holding forever.
    // Edit-bridge binds to the TARGET catalog item (s.itemId), NOT the
    // submission id (s.id) — using s.id sent /improve a non-existent item id
    // and it fell through to the empty "pick a place" explainer. A brand-new
    // submission (no itemId yet — the place isn't in the catalog until it's
    // approved) has nothing to edit, so no link.
    edit = (s.itemId != null)
      ? `<a class="cc-d-act edit" href="/improve?type=${encodeURIComponent(s.letter)}&item=${encodeURIComponent(s.itemId)}&name=${encodeURIComponent(s.title)}&lat=${encodeURIComponent(s.lat)}&lng=${encodeURIComponent(s.lng)}">✎ ${D.editItem||'Edit this item'}</a>`
      : '';
    const body = s.body ? `<p class="cc-mod-body">${escPend(s.body)}</p>` : '';
    // "Proposed change" — what THIS submission wants to change, not the
    // item's history. Kept visually distinct from the history section below.
    // Gated on `now`, not on `was && now`. Requiring `was` hid the proposed
    // change completely whenever the field had no previous value — which is
    // the ENTIRE "+ add a missing field" funnel, the commonest contribution
    // there is. The curator then got a card naming an item and never naming
    // the change they were being asked to approve (owner-reported 2026-08-03).
    // The struck-out line appears only when there is something to strike out.
    /* One row per changed field, not one run-on sentence. `s.changes` carries
       {key, was, now}; the label comes from the same localised schema the item
       rows use, so the diff and the item read in the same words. Falls back to
       the joined strings for a submission whose payload predates this. */
    const chList = Array.isArray(s.changes) ? s.changes : [];
    const diff = chList.length
      ? `<div class="cc-mod-diff"><div class="cc-mod-diff-h">${D.proposedChange||'Proposed change'}</div>
          <dl class="cc-mod-chg">${chList.map(c=>`
            <dt>${escPend(fieldLabelFor(s.letter, c.key))}</dt>
            <dd>${c.was!=null?`<span class="was">${escPend(c.was)}</span><span class="arw">→</span>`:''}<span class="now">${escPend(c.now)}</span></dd>`).join('')}
          </dl></div>`
      : (s.now
        ? `<div class="cc-mod-diff"><div class="cc-mod-diff-h">${D.proposedChange||'Proposed change'}</div>${s.was?`<div class="cc-mod-was">${escPend(s.was)}</div>`:''}<div class="cc-mod-now">${escPend(s.now)}</div></div>`
        : '');
    /* A redrawn climb is not reviewable as coordinates. When the submission
       carries a shape, the card offers a before/after switch that redraws the
       line on the map — see pending-shape.js for why it is a switch and not
       two lines at once. Only rendered when there is a side to switch TO: a
       brand-new climb has no "before", and offering an empty one would be a
       control that lies. */
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
    // Moderation-UX (user request): "Everybody should always be able to see
    // the history of an item." A brand-new submission (type 'new') has no
    // prior item to have a history — say so plainly, no fetch needed. An
    // edit of an existing item (itemId set) fetches the same applied
    // change_history the normal item drawer shows (C1-T3), via openDrawer
    // below — reusing loadItemHistory/renderHistoryList so both views stay
    // in sync. escPend covers every interpolated value (see historyRow).
    // Pending photos with their harvested facts, each with a keep/drop tick
    // (docs/specs/photo-uploads.md §5). Default is ticked: approving the
    // submission approves its photos unless the curator says otherwise.
    // The thumbnail is a button, not part of the Keep label: a curator has to
    // be able to SEE the photo to answer the question this card asks (does it
    // show what it claims, is anybody identifiable in it), and a 120px crop
    // cannot answer that. Clicking it opens the same lightbox the public
    // gallery uses, at the `lg` variant. It sits outside the <label> on
    // purpose \u2014 inside one, every click to enlarge would also toggle Keep.
    const photos = Array.isArray(s.photos) ? s.photos : [];
    const modPhotos = photos.length ? `<div class="cc-mod-photos">${photos.map((p, i) => `
      <div class="cc-mod-photo">
        <button type="button" class="cc-mod-photo-zoom" data-mod-photo="${i}" aria-label="${escPend(D.photoOpen||'Open full size')}" title="${escPend(D.photoOpen||'Open full size')}">
          <img src="${safeHref(p.sm)}" alt="${escPend(D.photoAlt||'Submitted photo')}" loading="lazy" />
        </button>
        <span class="cc-mod-photo-meta">${escPend(
          (p.distanceM != null ? (D.photoDistance||'~{m} m from the pin').replace('{m}', String(p.distanceM)) : (D.photoNoGps||'No location in the file'))
          + (p.takenAt ? ' \u00b7 ' + p.takenAt : '')
        )}</span>
        <label class="cc-mod-photo-keep"><input type="checkbox" class="cc-mod-photo-cb" data-media="${escPend(p.id)}" checked /> ${escPend(D.photoKeep||'Keep')}</label>
      </div>`).join('')}</div>` : '';
    const modHist = 'new' === s.type
      ? `<div class="cc-d-hist cc-d-hist-initial"><h4 class="cc-d-hist-h">${D.history||'History'}</h4><p class="cc-mod-initial">${D.initialEntry||'Initial entry — new item'}</p></div>`
      : (s.itemId != null ? `<div class="cc-d-hist" id="cc-d-hist-slot" data-item="${s.itemId}"></div>` : '');
    // A needs-info submission is only ever here because a curator followed the
    // desk's link to it: it is off the map layer until the rider answers. Say
    // that, show the question that was asked, and show the answer if one came
    // — a card identical to a never-looked-at one would invite asking twice.
    const asked = ('needs_info' === s.status && s.asked)
      ? `<div class="cc-mod-asked"><span class="cc-mod-asked-h">${D.youAsked||'You asked'}</span> ${escPend(s.asked)}</div>` : '';
    const replied = s.riderReply
      ? `<div class="cc-mod-replied"><span class="cc-mod-asked-h">${D.riderReplied||'Rider replied'}</span> ${escPend(s.riderReply)}</div>` : '';
    /* WHAT is being changed, shown against WHAT the item already says.
       A curator who does not personally know the Côte de la Redoute was given
       a title, a pseudonym, an age and a region — and asked to approve. The
       proposed change alone is not enough either: "shade: Exposed" means
       nothing without the record it is being added to (owner-reported
       2026-08-03). So an edit now carries the target item's own rows, read
       from the catalogue the map has already loaded, rendered with the
       drawer's own row renderer and its escaping.

       Edits only: a `new` submission has no prior item, and its own values are
       the proposed change directly above. Resolved here rather than when
       CC_PENDING is built, because that runs before catalog-load has populated
       the layers — at drawer-open time the features are there. */
    let context = '';
    if('new' !== s.type && s.itemId != null){
      const lyr = CATALOG.find(l => l.letter === s.letter);
      const target = lyr && (lyr.features||[]).find(x => x.id != null && String(x.id) === String(s.itemId));
      if(target){
        /* Rendered for the side the switch is on, and re-rendered when it
           flips (renderPendingContext). AFTER shows the proposed values in
           place, highlighted; BEFORE shows the item exactly as it stands. That
           is the comparison a curator actually wants: the proposal against the
           item's own display, in its own words, rather than a separate diff
           block to reconcile by eye (owner, 2026-08-03). */
        setPendingContext({letter: s.letter, target, changes: chList});
        const ctxRows = recRowsHtml(schemaRows(s.letter, target, null, {proposed: proposedMap(chList)}));
        if(ctxRows) context = `<div class="cc-mod-ctx"><div class="cc-mod-ctx-h" data-ctx-h>${D.itemProposed||'This item, as proposed'}</div><ul class="cc-d-rec" data-ctx-rows>${ctxRows}</ul></div>`;
      }
    }
    const badge = 'needs_info' === s.status
      ? `<div class="cc-mod-badge waiting">? ${D.waitingOnRider||'Waiting on the rider'}</div>`
      : `<div class="cc-mod-badge">⚑ ${I18N.pendingReview||'Pending review'}</div>`;
    moderate = `<div class="cc-mod" data-id="${escPend(s.id)}">
      ${badge}${body}${diff}${shapeSwitch}${context}${asked}${replied}${modPhotos}
      <textarea class="cc-mod-note" placeholder="${D.modNotePh||'Optional note — a reason, or context…'}"></textarea>
      <div class="cc-mod-acts">
        <button class="cc-mod-btn approve" data-decision="approve">✓ ${D.approve||'Approve'}</button>
        <button class="cc-mod-btn info" data-decision="needs_info">? ${D.needsInfo||'Needs info'}</button>
        <button class="cc-mod-btn reject" data-decision="reject">✕ ${D.reject||'Reject'}</button>
      </div>
      <div class="cc-mod-preview">${D.modKeys||'A · approve · R · reject — recorded, not yet persisted.'}</div>
      ${modHist}
    </div>`;
  }
  // Only votable point types get the vote CTA. Utilities are confirmed, not
  // voted — the erroneous vote link used to show on water/services/etc.
  const vote = (f.cur && CC_VOTABLE.has(layer.key)) ? `<a class="cc-d-act" href="/vote">▲ ${D.voteRound||'Vote in this round'}</a>` : '';
  // Non-votable utilities carry a community confirmation panel (water:
  // potable/not-potable, others: "still here?"), hydrated async on open.
  const confirmPanel = (CC_CONFIRMABLE.has(layer.key) && f.id!=null)
    ? `<div class="cc-cf" data-item="${f.id}"><div class="cc-cf-body" data-cf-body></div><div class="cc-cf-login" hidden>${D.loginConfirm||'Log in to confirm'} · <a href="/login">${I18N.login||'Log in'}</a></div></div>`
    : '';
  const act = edit + vote;
  const desc = f.desc ? `<p class="cc-d-desc">${escPend(f.desc)}${f.descTr?` <span class="cc-d-tr">· auto-translated</span>`:''}</p>` : '';
  // C1-T3 (spec W5): an empty placeholder for the async "Recent changes"
  // section — openDrawer() fetches GET /map/item/{id}/history after this
  // HTML lands and fills #cc-d-hist-slot (buildRecord itself stays sync/pure,
  // no network calls here). Same edit-bridge id contract as `edit`/`addPhoto`
  // above: only real DB items (f.id!=null) get one. The curator's pending-
  // submission records (below) never carry f.id, so they never render this —
  // see the note on the pending branch for why that view doesn't get one either.
  const histSlot = f.id!=null ? `<div class="cc-d-hist" id="cc-d-hist-slot" data-item="${f.id}"></div>` : '';
  // Point the "OpenStreetMap" source link at the object's location on OSM (its
  // feature-query view) rather than the OSM homepage. The served OSM features
  // carry no element (node/way) id, so a coordinate query is the closest we can
  // deep-link to the exact object the rider is looking at.
  const osmHref = (f.geom && f.geom.ll)
    ? `https://www.openstreetmap.org/query?lat=${f.geom.ll[0]}&lon=${f.geom.ll[1]}#map=18/${f.geom.ll[0]}/${f.geom.ll[1]}`
    : 'https://www.openstreetmap.org';
  return `<span class="cc-d-type" style="--c:${layer.color};color:${txtOn(layer.color)}">${layer.icon} ${layer.label}</span>
    <div class="cc-d-name">${escPend(f.name)}</div>${cur}${photo}${desc}${diff}${elev}${len}${grad}
    <ul class="cc-d-rec">${rows}</ul>${fresh}${up}
    <div class="cc-d-src">${D.source||'Source'} · ${escPend(f.source).replace(/^(OpenStreetMap|OSM)/, `<a href="${osmHref}" target="_blank" rel="noopener" style="color:var(--glacier);text-decoration:underline;text-underline-offset:2px">$1</a>`).replace(/(Géoportail de la Wallonie)/, '<a href="https://geoportail.wallonie.be/catalogue/91721175-5f01-410c-8c78-37c1d1893ba2.html" target="_blank" rel="noopener" style="color:var(--glacier);text-decoration:underline;text-underline-offset:2px">$1</a>')}</div>${confirmPanel}${act}${moderate}${histSlot}`;
}
// C1-T3: renders one change_history row. Every interpolated value is
// user-contributed (old/new attribute values, and `who`/`when`/`changedAt`
// are server-derived but still passed through escPend for defense in depth)
// — same stored-XSS concern the §13 pending-submission fix addressed, so
// ALL FIVE fields go through escPend before hitting innerHTML.
// A gallery field's history value is a COUNT, not the gallery
// (docs/specs/photo-uploads.md §5 — ChangeHistoryView summarises it server-side,
// so the URLs never travel). "0 → 1" would read as a bug, so say what changed:
// "no photos → 1 photo".
function photoCount(n){
  const c = Number(n) || 0;
  if(c === 0) return D.photosNone || 'no photos';
  return c === 1 ? (D.photosOne || '1 photo')
                 : (D.photosMany || '{n} photos').replace('{n}', String(c));
}

/* `state` is OUR vocabulary — submitted / unverified / verified — and it was
   going straight into the rider-visible history, where "unverified" reads as a
   verdict on the place rather than as the step it is. The words below say what
   each state MEANS to a rider. The row stays historic either way: what is true
   now is the confirmation panel above it, which counts live. */
function stateWord(v){
  if(v === 'submitted') return D.stateSubmitted || 'waiting for review';
  if(v === 'unverified') return D.stateUnverified || 'on the map, not confirmed yet';
  if(v === 'verified') return D.stateVerified || 'confirmed by riders';
  if(v === 'rejected') return D.stateRejected || 'not accepted';
  return v;
}

function historyRow(h){
  const isEmpty = v => v===null || v===undefined || v==='';
  const isPhotoField = h.field === 'photos' || h.field === 'photo';
  if(h.field === 'state'){
    return `<li class="cc-h-row">
      <span class="cc-h-field">${escPend(D.stateField||'Status')}</span>
      <span class="cc-h-diff">${escPend(stateWord(h.oldValue))} → ${escPend(stateWord(h.newValue))}</span>
      <span class="cc-h-meta">${escPend(h.who)} · <time datetime="${escPend(h.changedAt)}">${escPend(h.when)}</time></span>
    </li>`;
  }
  const ov = isPhotoField ? escPend(photoCount(h.oldValue))
    : (isEmpty(h.oldValue) ? '—' : escPend(h.oldValue));
  const nv = isPhotoField ? escPend(photoCount(h.newValue))
    : (isEmpty(h.newValue) ? '—' : escPend(h.newValue));
  return `<li class="cc-h-row">
    <span class="cc-h-field">${escPend(h.field)}</span>
    <span class="cc-h-diff">${ov} → ${nv}</span>
    <span class="cc-h-meta">${escPend(h.who)} · <time datetime="${escPend(h.changedAt)}">${escPend(h.when)}</time></span>
  </li>`;
}
// Empty history (never-edited item) renders nothing — spec W5/C1-T3
// acceptance: "no changes yet" is silence, not a section.
function renderHistoryList(history){
  if(!Array.isArray(history) || !history.length) return '';
  return `<h4 class="cc-d-hist-h">${D.recentChanges||'Recent changes'}</h4><ul class="cc-d-hist-list">${history.map(historyRow).join('')}</ul>`;
}
// Fetches an item's change log (C1-T2's GET /map/item/{id}/history) and
// fills the drawer's history slot. Lazy/async on purpose — never blocks
// the drawer opening. Race-guarded: `myReq` is snapshotted from the shared
// `_historyReq` counter, which openDrawer() bumps on every call; if a newer
// drawer opened (or this one closed and another opened) before the response
// lands, `myReq` no longer matches and the stale response is dropped. Fetch
// failure is silent — history is an enhancement, not core drawer content.
function loadItemHistory(itemId){
  const myReq = ++_historyReq;
  fetch('/map/item/' + itemId + '/history')
    .then(r => r.ok ? r.json() : null)
    .catch(()=>null)   // network/HTTP failure — enhancement only, stays silent (no exception to report)
    .then(data => {
      if(myReq !== _historyReq || !data) return;   // stale response — a newer drawer has since opened
      const slot = document.getElementById('cc-d-hist-slot');
      if(!slot) return;                              // drawer content changed/closed under us
      // A render bug here must not be indistinguishable from "no history yet" —
      // only the fetch/network stage above is allowed to fail silently.
      try {
        slot.innerHTML = renderHistoryList(data.history);
      } catch(e){
        console.error('Recent-changes history failed to render', e);
      }
    });
}

/* `opts.center` lifts the toast to the middle of the screen. Reserved for
   moments that are worth a beat — a rider's confirmation being recorded — so
   the acknowledgement is somewhere their eyes already are, instead of a
   footnote at the bottom of a full-screen map. Routine chatter stays low. */
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

/* The bars are the SHAPE of the climb; the numbers beside them are the item's
   own stated facts.

   They used to be recomputed from the bars, which meant the same drawer showed
   two different answers: the Average/Max gradient rows read "8.4%" and "~20%
   (mid-climb ramp)" off the item's attributes, while this caption read "avg ~7%
   · max 16%" off a sampled array that had since been re-profiled from a redrawn
   route (owner-reported 2026-08-04). A reader cannot tell which one is the
   climb. One fact, one source: the item states it, this repeats it, and the
   computed values are only a fallback for a profile whose item says nothing.

   `(illustrative)` is gone. It was written when the numbers beside it were
   computed from sample bars, and it warned you not to trust them. They are the
   item's own stated avg/max now, so the warning had stopped describing
   anything true — it read as "these figures are made up" about figures a
   curator had approved (owner-reported twice, 2026-08-04).

   The BARS remain an approximation on some climbs: the manual seed hand-authors
   a profile, the editor samples one from real elevation, and nothing in the
   payload says which you are looking at. That is a provenance question, and
   the honest fix is a flag on the profile rather than a blanket disclaimer on
   the numbers. Until then the shape is offered without a claim about it. */
function gradStrip(grad, f){
  const barMax=Math.max(...grad);
  const stated=v=>{
    if(v==null || v==='') return null;
    const m=String(v).match(/-?\d+(\.\d+)?/);
    return m ? m[0] : null;
  };
  const avg = stated(f && f.avgGradient) ?? String(Math.round(grad.reduce((a,b)=>a+b,0)/grad.length));
  const max = stated(f && f.maxGradient) ?? String(barMax);
  /* Bars hang BELOW a baseline where the road descends.

     They used to be `height: 10 + (p/barMax)*30` with a 6px floor, which has no
     zero: a -15% bin rendered as a short bar pointing the same way as every
     climbing one, so a descent read as a gentle rise. On a climb that genuinely
     drops between two ramps - Roche-aux-Faucons loses 40 m in its middle - the
     chart told the opposite of the truth.

     Both directions share ONE scale, so a -12% bar is exactly as long as a +12%
     one. Scaling each side to its own extreme would make a shallow dip look as
     dramatic as the steepest ramp on the climb. */
  const ups=grad.filter(p=>p>0), downs=grad.filter(p=>p<0);
  const upMax=ups.length?Math.max(...ups):0;
  const dnMax=downs.length?Math.abs(Math.min(...downs)):0;
  const scale=Math.max(upMax,dnMax,1);
  const H=42;
  // Split the strip between the two directions in proportion to how far each
  // actually goes, so a climb with no descent keeps its full height.
  const upH=dnMax?Math.max(10,Math.round(H*(upMax/(upMax+dnMax)))):H;
  const dnH=H-upH;
  const bars=grad.map(p=>{
    const h=Math.max(3,Math.round((Math.abs(p)/scale)*(p<0?dnH:upH)));
    const cell=p<0
      ? `<span class="cc-grad-dn" style="height:${h}px;background:${gradColor(p)}"></span>`
      : `<span class="cc-grad-up" style="height:${h}px;background:${gradColor(p)}"></span>`;
    return `<span class="cc-grad-col" style="--up:${upH}px;--dn:${dnH}px" title="${p}%">${cell}</span>`;
  }).join('');
  return `<div class="cc-elev-cap">${tpl(D.gradProfile||'Gradient profile · avg ~{a}% · max {m}%', {a:avg, m:max})}</div>
    <div class="cc-grad${dnMax?' has-descent':''}" style="--up:${upH}px;--dn:${dnH}px">${bars}</div>`;
}
function elevSvg(elev){
  const w=300,h=64,pad=3,min=Math.min(...elev),max=Math.max(...elev),rng=Math.max(1,max-min);
  const xy=elev.map((e,i)=>[pad+i/(elev.length-1)*(w-2*pad), h-pad-((e-min)/rng)*(h-2*pad)]);
  const line=xy.map(p=>p[0].toFixed(1)+','+p[1].toFixed(1)).join(' ');
  return `<svg class="cc-elev" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" role="img" aria-label="${D.elevAria||'Elevation profile'}">
    <polygon points="${pad},${h-pad} ${line} ${w-pad},${h-pad}" fill="rgba(255,90,31,.16)"/>
    <polyline points="${line}" fill="none" stroke="#FF5A1F" stroke-width="1.6"/></svg>`;
}
// Content-only body render, shared by openDrawer and the coverage detail
// repaint (openCoverageDrawer's enrich-later step). (Re)writes #drawerBody
// + its content-scoped wiring/hydrations, and deliberately does NOT touch
// the open state, selection halo, focus or the mobile sheet snap — so a
// progressive-hydration repaint (the loadItemHistory/hydrateItemConfirm
// convention) never yanks the bottom sheet back to half or re-steals focus.
export function renderDrawerBody(layer, f){
  document.getElementById('drawerBody').innerHTML = buildRecord(layer, f);
  if(layer.key==='experience' && f.id!=null) hydrateRouteCommunity(f.id);
  if(CC_CONFIRMABLE.has(layer.key) && f.id!=null) hydrateItemConfirm(f.id);
  /* A pending climb edit: hand its shape to the before/after switch and draw
     the proposed side straight away. The curator is here to judge the
     PROPOSAL, so that is what the map shows first; "Before" is one click away.
     Fit to it too — a summit moved a kilometre can otherwise land off screen,
     and an overlay you cannot see is not a review. */
  const pShape = f.pending && f.pending.shape;
  setPendingShape(pShape || null);
  if(pShape){
    const first = pShape.after ? 'after' : 'before';
    if(showPendingShape(pShape, first)) fitPendingShape(pShape, first);
  } else {
    clearPendingShape();
  }
  // C1-T3: async "Recent changes" — see loadItemHistory for the race guard.
  // Pending (moderation) features carry no f.id; when they target a real
  // item (f.pending.itemId, i.e. an edit — never a brand-new submission,
  // which renders "Initial entry" synchronously above with no fetch) fetch
  // that item's history instead, into the same #cc-d-hist-slot rendered by
  // buildRecord's pending branch.
  if(f.pending){
    if('new' !== f.pending.type && f.pending.itemId!=null) loadItemHistory(f.pending.itemId);
  } else if(f.id!=null){
    loadItemHistory(f.id);
  }
  const pl = photoList(f);
  const mainImg = document.querySelector('#drawerBody .cc-d-photo > img');
  const cap = document.getElementById('cc-d-cap');
  let cur = 0;
  function show(i){ cur=i;
    if(mainImg){ mainImg.src=pl[i].sm;
      if(pl[i].lg){ mainImg.srcset = `${pl[i].sm} 520w, ${pl[i].lg} 1400w`; mainImg.sizes = '(max-width: 560px) 100vw, 480px'; }
      else { mainImg.removeAttribute('srcset'); mainImg.removeAttribute('sizes'); } }
    if(cap) cap.innerHTML=photoCap(pl[i]);
    document.querySelectorAll('#drawerBody .cc-d-thumb').forEach((t,k)=>t.classList.toggle('on',k===i)); }
  if(mainImg && pl.length) mainImg.addEventListener('click', ()=>openLightbox(pl, cur, f.name));
  document.querySelectorAll('#drawerBody .cc-d-thumb').forEach(t=>t.addEventListener('click', ()=>show(+t.dataset.i)));
  document.querySelectorAll('#drawerBody .cc-city').forEach(a=>{
    a.addEventListener('click', e=>{ e.preventDefault(); openCity(a.dataset.city); });
    a.addEventListener('mouseenter', ()=>{ const c=CITIES[a.dataset.city]; if(c) highlightAt(c.ll); });
    a.addEventListener('mouseleave', clearHighlight);
  });
  /* The moderation buttons are NOT bound here. They are delegated in
     initCommunity(), like every other community button, because this body is
     re-rendered often and a per-element listener does not survive that: the
     card comes back looking identical with buttons that do nothing, and a
     curator clicks Approve to no effect whatsoever (found 2026-08-03 — see
     moderation-and-contribution.md §7.3b). */
  // The submitted photos open in the same lightbox the public gallery uses,
  // at `lg` — a curator cannot judge a 120px crop (see the card markup).
  const modPhotos = (f.pending && Array.isArray(f.pending.photos)) ? f.pending.photos : [];
  if(modPhotos.length) document.querySelectorAll('#drawerBody .cc-mod-photo-zoom').forEach(btn=>{
    btn.addEventListener('click', ()=>openLightbox(modPhotos, +btn.dataset.modPhoto || 0, f.pending.title || f.name));
  });
}
export function openDrawer(layer, f){
  // Guard (spec §16 S1): while picking correction stretches, the route line
  // still carries its normal layer click handler (drawLine's map.on('click',
  // 'experience-'+i, ()=>openDrawer(...))) — a click meant to drop a picking
  // point would ALSO fire that handler and open/switch the drawer under the
  // rider's feet. Bail out here so picking clicks never re-open a drawer;
  // pickClick (bound separately) still gets the same click event and drops
  // the point normally.
  if(isPicking()) return;
  clearRevealPin();   // decision C: a new pick supersedes any reveal pin (call sites re-drop after)
  clearSelectedCoverageIcon();   // drop the previous coverage selection's icon overlay; openCoverageDrawer re-adds it right after this returns
  invalidateCoverageDrawer(); bumpPlaceReq();   // invalidate any in-flight coverage POI detail + town-card nearby fetch — this render supersedes them
  // Route selection emphasis: covers both the click path and the ?feature=
  // deep-link (both funnel through here). Layer id convention: the K line
  // layers are `experience-<feature index>` (see the drawLine call site).
  if(layer.key==='experience'){
    const i=layer.features.indexOf(f);
    if(i>=0 && map.getLayer('experience-'+i)) highlightRoute('experience-'+i);
    clearHighlight();                    // routes read as the wide line halo, not a point halo
  } else {
    clearRouteHighlight();
    // Pulsing selection halo on the clicked point — curated AND OSM — so the
    // selected place stands out; persists while the drawer is open and is
    // cleared by closeDrawer()/the next open. highlightAt(null) no-ops.
    // Confirmed items render as bottom-anchored teardrop pins whose icon sits
    // ~16px above the ground point, so raise the halo to ring the icon; flat
    // WebGL dots (unverified OSM) are centred on the point → no offset.
    // PENDING (moderation) pins are bottom-anchored teardrops too → same lift.
    // Climbs place their pin at the route START (foot), not geom.ll — the
    // halo must ring the pin the rider actually sees, not the centroid.
    const hlAt = f.route ? f.route[0] : (f.geom && f.geom.ll);   // [lat,lng] arrays both
    highlightAt(hlAt, (f.cur || f.pending) ? [0,-16] : [0,0]);
  }
  renderDrawerBody(layer, f);
  const d=document.getElementById('drawer'); d.classList.add('open'); d.setAttribute('aria-hidden','false');
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
// Reveal pin (07-15 decision C): picking a NON-DRAWN feature from search in
// Curated mode drops one temporary marker (community look + selection pulse)
// instead of force-switching the whole map to Everything. Cleared on the
// next pick (openDrawer clears it up front), on drawer close, and on a mode
// change.
let _revealMarker=null;
export function clearRevealPin(){ if(_revealMarker){ _revealMarker.remove(); _revealMarker=null; } }
export function revealPinAt(layer, ll){
  clearRevealPin();
  const el=pinEl(layer, false);
  el.classList.add('community','reveal');
  _revealMarker=new maplibregl.Marker({element:el, anchor:'bottom'}).setLngLat([ll[1],ll[0]]).addTo(map);
}
export function closeDrawer(){
  // If the rider closes the drawer (X / scrim / Escape) mid-pick, tear the
  // picking session down too — an orphaned map click handler + toolbar with
  // no drawer to return to would be a dead-end. Uncommitted points (this
  // session hasn't hit Done) are simply dropped; any previously-Done
  // stretches already live in _pickSegs and are untouched.
  if(isPicking()) cancelPicking();
  // Task 10 race convention: an explicit close invalidates any in-flight
  // coverage POI detail AND any in-flight town-card nearby fetch — without
  // this, openCoverageByRef (a FIRST opener, so the its-drawer-still-open
  // guard can't apply) would reopen a drawer the rider just dismissed when
  // the /map/coverage/poi/{ref} response lands, and a stale
  // /map/coverage/nearby response would resurrect a dismissed town card
  // (_placeReq bumps everywhere the coverage generation does — shared convention).
  invalidateCoverageDrawer(); bumpPlaceReq();
  const d=document.getElementById('drawer'); d.classList.remove('open'); d.setAttribute('aria-hidden','true');
  clearHighlight();
  clearSelectedCoverageIcon();                        // remove the selected coverage POI's persistent icon overlay
  clearRevealPin();
  clearRouteHighlight();
  clearCorrections();
  clearPendingShape();                               // drop the before/after climb overlay with the card that owns it
  setPendingShape(null);
  setPendingContext(null);
  sheet.clear();                                     // drop snap classes + inline transform for the next open
}
// Close affordances: the X and the scrim (tap the dimmed area above the mobile
// bottom sheet). A side effect, so the entry calls it where it used to sit (§4.2).
export function initDrawerChrome(){
  document.getElementById('drawerClose').onclick=closeDrawer;
  document.getElementById('drawerScrim').onclick=closeDrawer;   // tap the dimmed area above the bottom sheet to close
}
