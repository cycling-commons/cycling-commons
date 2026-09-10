// SPDX-License-Identifier: AGPL-3.0-only
/* Pins, cluster bubbles, minted tile icons. Idempotent hasImage guards. */
import { map } from './map-init.js';
import { layerByKey, TYPE_SVG, KEY_LETTER } from './catalog.js';
import { escPend, txtOn } from './util.js';

/* THE kind glyphs (KindIcons::set(), window.CC_KIND_ICONS): what a pin IS
   within its category. The pin grammar (data-provider-hierarchy.md §6.3):
   kind lives in the glyph, state is two shared badges, everything else is
   drawer content. The same paths mint the tile icons below and draw the DOM
   pins and both legends, so nothing here may define a kind on its own. */
export const KIND_ICONS = window.CC_KIND_ICONS || {};
const kindDef = (letter, kind) => (KIND_ICONS[letter] || {})[kind] || null;
/* Every tile icon is minted twice, plain and with the "?" badge, because a
   symbol layer cannot compose a badge at render time the way a DOM pin can.
   `-q` is the badged twin (data-provider-hierarchy.md §6.7). */
export function kindImageId(letter, kind, badge){
  return 'kind-'+letter.toLowerCase()+'-'+kind+(badge?'-q':'');
}
/* A tile point has a witness when its `cd` (OSM check_date, YYYY-MM-DD) is
   on or after the cutoff the shell computed from map.confirmation_stale_months
   (window.CC_WITNESS_CUTOFF). Fail closed: no cutoff, no date, or a date
   that is not a date keeps the badge. Rung 8 is the only rung a coverage
   point can reach without a rider. */
export function hasWitness(cd, cutoff){
  return typeof cd==='string' && typeof cutoff==='string' && /^\d{4}-\d{2}-\d{2}$/.test(cd) && cd>=cutoff;
}
export const witnessCutoff = () => (typeof window.CC_WITNESS_CUTOFF==='string' ? window.CC_WITNESS_CUTOFF : undefined);
/* The badge on a minted icon: the same ochre disc and mono "?" the DOM pin
   wears (map.css .cc-pin.q::after), top-right of the 24-box. */
function drawBadge(x, S){
  const r=4.6, cx=24-r-0.4, cy=r+0.4;
  x.beginPath(); x.arc(cx,cy,r,0,Math.PI*2); x.fillStyle='#C8923A'; x.fill();
  x.lineWidth=0.8; x.strokeStyle='rgba(20,22,14,.55)'; x.stroke();
  x.fillStyle='#14160E'; x.font='700 7.2px ui-monospace,Menlo,Consolas,monospace'; x.textAlign='center'; x.textBaseline='middle';
  x.fillText('?', cx, cy+0.4);
}
// Resolve the two colour tokens against a category colour.
function kindFill(token, color){
  if(token==='@cat') return color;
  if(token==='@ink') return txtOn(color)==='#fff' ? '#fff' : '#14160e';
  return token;
}

/* The water half of letter B, in one rule for every path a pin's facts can
   arrive by: tile properties (`potable` yes/no/absent, `food`), the detail
   response (`osmPotable`, `osmFood`), and a rider's own record (`potable` in
   the form vocabulary, `type`). A non-potable tap is a different KIND of
   thing, not a broken one, so it is a glyph and not a colour. */
export const WATER_KINDS=['tap','no','unk','food','food_water'];
const yes = v => v===true || v==='true' || v===1 || v==='1' || v==='yes';
export function waterKind(p){
  p=p||{};
  const food = yes(p.food) || p.osmFood===true || p.type==='Café — refill point';
  let pot;
  /* Rider vocabulary wins, including its "Unknown": somebody looked and
     nobody can say, which outranks a stale OSM tag. Un… is tested BEFORE the
     No branch and matched on those two letters alone. That covers "Unknown"
     and also the pre-2026-09-10 spelling ("Unsigned…", still on tiles
     published before the rename), so neither is painted as a bad tap. */
  if(typeof p.potable==='string' && /^(Yes|No|Un)/.test(p.potable)){
    pot = /^Un/.test(p.potable) ? undefined : p.potable.startsWith('Yes');
  } else if(p.osmPotable!==undefined){
    pot = p.osmPotable;
  } else if(yes(p.potable)){
    pot = true;
  } else if(p.potable==='no'){
    // Tiles published before 2026-09-04 carry a boolean `potable`, whose
    // `false` covered both "tagged not drinkable" and "nobody said": those
    // read as unknown until the detail response hydrates the tags.
    pot = false;
  }
  if(food) return pot===true ? 'food_water' : 'food';
  return pot===true ? 'tap' : pot===false ? 'no' : 'unk';
}
/* The two shared state badges, meaning the same in every category (§6.4: a
   fact earns a pin channel only when it changes whether a rider goes there
   NOW). `warn` = not usable right now; `hours` = there, but not always. */
export function stateOf(p, now){
  p=p||{};
  if(p.condition==='Out of order' || p.condition==='Closed') return 'warn';
  if(p.availability==='Daytime only' || p.availability==='Ask or behind a gate') return 'hours';
  // A seasonal closure earns the clock only in the months it is shut: the
  // badge answers "can I use it NOW" (§6.4), and every Dutch register tap is
  // frost-shut in winter, so a year-round clock would mark all of them and
  // mean nothing. Frost-shut: November to March. Summer only: October to April.
  const m=(now||new Date()).getMonth()+1;
  if(p.seasonal==='Frost-shut in winter' && (m>=11 || m<=3)) return 'hours';
  if(p.seasonal==='Summer only' && (m>=10 || m<=4)) return 'hours';
  return null;
}
const CLOCK_SVG='<svg viewBox="0 0 24 24" width="9" height="9" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="3"/><path d="M12 7v5.5l3.5 2.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>';
export function stateBadgeHtml(state){
  if(state==='warn') return '<b class="cc-st warn">!</b>';
  if(state==='hours') return '<b class="cc-st hours">'+CLOCK_SVG+'</b>';
  return '';
}

// Inline SVG of one kind, for DOM pins and HTML surfaces.
export function kindSvg(letter, kind, color, size){
  const d=kindDef(letter, kind); if(!d) return '';
  const s=size||16;
  if(d.paths){
    const paths=d.paths.map(p=>`<path d="${p.d}" fill="${kindFill(p.fill, color)}"${p.stroke?` stroke="${p.stroke}" stroke-width="${p.width||1.5}" stroke-linejoin="round"`:''}/>`).join('');
    return `<svg class="cc-kind" viewBox="0 0 24 24" width="${s}" height="${s}" aria-hidden="true">${paths}</svg>`;
  }
  return escPend(d.glyph||'');
}

// Mint every path-drawn kind as a map image, `kind-<letter>-<kind>` and its
// badged twin `-q`, in the same 24-box (×2) miniIcon() uses, so kinds and
// discs share one size ramp.
export function mintKindIcons(){
  const S=2, D=24*S;
  Object.keys(KIND_ICONS).forEach(letter=>{
    const key=Object.keys(KEY_LETTER).find(k=>KEY_LETTER[k]===letter);
    const color=((key && layerByKey[key])||{}).color||'#6b6f5e';
    Object.keys(KIND_ICONS[letter]).forEach(kind=>{
      const d=KIND_ICONS[letter][kind]; if(!d.paths) return;
      [false,true].forEach(badge=>{
        const id=kindImageId(letter, kind, badge); if(map.hasImage(id)) return;
        const cv=document.createElement('canvas'); cv.width=D; cv.height=D; const x=cv.getContext('2d');
        x.scale(S,S);
        d.paths.forEach(p=>{
          const path=new Path2D(p.d);
          x.fillStyle=kindFill(p.fill, color); x.fill(path);
          if(p.stroke){ x.lineWidth=p.width||1.5; x.lineJoin='round'; x.strokeStyle=p.stroke; x.stroke(path); }
        });
        if(badge) drawBadge(x, S);
        map.addImage(id,{width:D,height:D,data:new Uint8Array(x.getImageData(0,0,D,D).data.buffer)},{pixelRatio:S});
      });
    });
  });
}

// Text glyphs for the service kinds, from the same registry.
const svcGlyph = k => ((kindDef('D', k)||{}).glyph) || '';
export const SERVICE_GLYPH={shop:svcGlyph('shop')||'⚙', station:svcGlyph('station')||'⚒', pump:svcGlyph('pump')||'⊕'};
// Emoji camera flattens to a rounded box under the white-icon filter; draw the shape.
export const CAMERA_PATH=TYPE_SVG('P');   // ItemType::svgPath(), via window.CC_TYPE_ICONS
const cameraSvg=(fill,size)=>`<svg viewBox="0 0 24 24" width="${size||15}" height="${size||15}" aria-hidden="true"><path fill-rule="evenodd" fill="${fill}" d="${CAMERA_PATH}"/></svg>`;
export const scenicGlyph=size=>cameraSvg('currentColor', size);

// Same flatten-to-box problem as the camera; side profile, not the pictogram pair.
export const TOILET_PATH=TYPE_SVG('C');   // ItemType::svgPath(), via window.CC_TYPE_ICONS
const toiletSvg=(fill,size)=>`<svg viewBox="0 0 24 24" width="${size||15}" height="${size||15}" aria-hidden="true"><path fill-rule="evenodd" fill="${fill}" d="${TOILET_PATH}"/></svg>`;
export const toiletGlyph=size=>toiletSvg('currentColor', size);
// The mountain emoji flattens to one plain triangle; draw twin peaks instead (owner 2026-08-25).
export const MOUNTAIN_PATH=TYPE_SVG('N');   // ItemType::svgPath(), via window.CC_TYPE_ICONS
const mountainSvg=(fill,size)=>`<svg viewBox="0 0 24 24" width="${size||15}" height="${size||15}" aria-hidden="true"><path fill="${fill}" d="${MOUNTAIN_PATH}"/></svg>`;
export const climbGlyph=size=>mountainSvg('currentColor', size);
// One glyph for every HTML surface (drawer head, rail, badges, nearby groups):
// the type's drawn path (every type has one since 2026-09-09, owner: "no
// coloured icons"; the rail's route row still showed the old star), the
// escaped text glyph only if a path is somehow missing.
export function layerGlyph(layer, size){
  const d=TYPE_SVG((layer||{}).letter||'');
  if(d) return `<svg viewBox="0 0 24 24" width="${size||15}" height="${size||15}" aria-hidden="true"><path fill-rule="evenodd" fill="currentColor" d="${d}"/></svg>`;
  return escPend((layer||{}).icon||'');
}
// The small disc of a gross provider; `suffix` keeps per-kind cache ids
// distinct, `badge` mints the "?" twin (`-q`).
export function miniIcon(key, glyph, suffix, badge){
  const id='mini-'+key+(suffix?('-'+suffix):'')+(badge?'-q':'');
  if(map.hasImage(id)) return id;
  const layer=layerByKey[key], color=(layer||{}).color||'#6b6f5e';
  const r=parseInt(color.slice(1,3),16),g=parseInt(color.slice(3,5),16),b=parseInt(color.slice(5,7),16);
  const dark=(0.299*r+0.587*g+0.114*b)<150;
  const S=2, D=24*S, R=D/2;
  const cv=document.createElement('canvas'); cv.width=D; cv.height=D; const x=cv.getContext('2d');
  x.beginPath(); x.arc(R,R,R-2.5*S,0,Math.PI*2);
  x.fillStyle=color; x.fill();
  x.lineWidth=1.6*S; x.strokeStyle='rgba(20,22,14,.85)'; x.stroke();
  const drawn = !glyph && (key==='scenic' ? CAMERA_PATH : key==='toilets' ? TOILET_PATH : key==='climbs' ? MOUNTAIN_PATH : null);
  if(drawn){
    const side=13*S, sc=side/24;
    x.save();
    x.translate(R-side/2, R-side/2);
    x.scale(sc, sc);
    x.fillStyle=dark?'#fff':'#14160e';
    x.fill(new Path2D(drawn), 'evenodd');
    x.restore();
  } else {
    const gc=document.createElement('canvas'); gc.width=D; gc.height=D; const gx=gc.getContext('2d');
    gx.font=`${12.5*S}px "Apple Color Emoji","Noto Color Emoji","Segoe UI Emoji","Noto Sans Symbols2",system-ui,sans-serif`;
    gx.textAlign='center'; gx.textBaseline='middle';
    gx.fillText(glyph || (layer||{}).icon||'•', R, R+1*S);
    const gd=gx.getImageData(0,0,D,D), gp=gd.data;
    for(let i=0;i<gp.length;i+=4){ if(gp[i+3]>25){ gp[i]=dark?255:20; gp[i+1]=dark?255:22; gp[i+2]=dark?255:14; gp[i+3]=255; } }
    gx.putImageData(gd,0,0); x.drawImage(gc,0,0);
  }
  if(badge){ x.save(); x.scale(S,S); drawBadge(x, S); x.restore(); }
  map.addImage(id,{width:D,height:D,data:new Uint8Array(x.getImageData(0,0,D,D).data.buffer)},{pixelRatio:S});
  return id;
}

export function clusterEl(layer, count){
  const d=document.createElement('div');
  d.className='cc-cluster'; d.style.setProperty('--c', layer.color); d.textContent=count;
  return d;
}

function pinGlyph(layer, props){
  if(layer.key==='services' && props && props.serviceKind) return SERVICE_GLYPH[props.serviceKind] || layer.icon;
  return layer.icon;
}
/* The two axes of the marker grammar (data-provider-hierarchy.md §6.7).
   Border answers who keeps the record, badge answers whether anybody has
   stood there. One channel each: a mark that carries two meanings is the
   thing §6.5 forbids. `rung` and `custody` arrive on every served point,
   computed once in PHP (ItemEvidenceResolver); this file never derives them,
   so a pin and the API can never disagree. NO_WITNESS mirrors
   EvidenceRung::NO_WITNESS and marker-grammar.test.cjs pins the two together. */
const NO_WITNESS=[1,2,3,4,5,6,7,9];
export function borderFor(custody){
  return custody==='specialty' ? 'dashed' : custody==='gross' ? 'disc' : 'solid';
}
export function badgeFor(rung){
  return (rung==null || NO_WITNESS.includes(rung)) ? '?' : '';
}
// The classes a pin wears for the two axes; [] is a solid paper border and no badge.
export function pinClasses(props){
  props=props||{};
  const out=[], border=borderFor(props.custody);
  if(border!=='solid') out.push(border);
  const rung = props.rung!=null ? props.rung : (props.v ? 10 : undefined);
  if(badgeFor(rung)) out.push('q');
  return out;
}
export function pinEl(layer,props){
  const d=document.createElement('div');
  // docs/specs/moderation-and-contribution.md §10.1a — stale ring only; no freshness key → no ring.
  const stale = props && props.freshness && props.freshness.state==='stale';
  // A pending pin is moderation chrome: the red border already says "not
  // accepted yet", and evidence starts once it is. No badge on top of it.
  const grammar = layer.pendingLayer ? pinClasses(props).filter(c=>c!=='q') : pinClasses(props);
  d.className=['cc-pin', ...grammar, layer.pendingLayer?'pending':'', stale?'stale':''].filter(Boolean).join(' '); d.style.setProperty('--c',layer.color);
  const white = txtOn(layer.color)==='#fff';
  // The shared state badges ride on top of any category's pin (§6.4).
  const badge = stateBadgeHtml(stateOf(props));
  if(layer.key==='scenic'){ d.innerHTML=`<span>${cameraSvg(white?'#fff':'#20241c')}</span>`+badge; return d; }
  if(layer.key==='toilets'){ d.innerHTML=`<span>${toiletSvg(white?'#fff':'#20241c')}</span>`+badge; return d; }
  if(layer.key==='climbs'){ d.innerHTML=`<span>${mountainSvg(white?'#fff':'#20241c')}</span>`+badge; return d; }
  // Water & food: the kind IS the glyph, drawn from the registry, not the 💧.
  if(layer.key==='water'){ d.innerHTML=`<span class="kd">${kindSvg('B', waterKind(props), layer.color, 17)}</span>`+badge; return d; }
  d.innerHTML=`<span${white?' style="filter:brightness(0) invert(1)"':''}>${pinGlyph(layer, props)}</span>`+badge; return d;
}

// Tile icon-image id for the selected-coverage overlay (survives cluster
// hide): the same plain-or-badged choice the layer expression makes.
export function coverageIconId(key, tp){
  tp=tp||{};
  const badge = !hasWitness(tp.cd, witnessCutoff());
  if(key==='water') return kindImageId('B', waterKind(tp), badge);
  if(key==='services'){
    if(tp.kind==='station') return miniIcon('services', SERVICE_GLYPH.station, 'station', badge);
    if(tp.kind==='pump') return miniIcon('services', SERVICE_GLYPH.pump, 'pump', badge);
    return miniIcon('services', undefined, undefined, badge);
  }
  return miniIcon(key, undefined, undefined, badge);
}
/* icon-size stops at z8/z13/z18 for a coverage icon. Every disc (every
   category, and the food half of letter B) shares one ramp; the drop is
   narrower than a disc in the same 24-box, so it rides a slightly larger one
   to keep the height the drops always had. */
export const DISC_SIZES=[0.42,0.7,0.95];
export const DROP_SIZES=[0.46,0.76,1.09];
export function covIconSizes(key, tp){
  if(key!=='water') return DISC_SIZES;
  const k=waterKind(tp);
  return (k==='food' || k==='food_water') ? DISC_SIZES : DROP_SIZES;
}
