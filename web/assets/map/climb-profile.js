// SPDX-License-Identifier: AGPL-3.0-only
/* Full climb profile popup (docs/specs/map-and-search.md §4.3a,
   docs/specs/climb-elevation.md §6). Numbers come from `grad`/`binM`/`footEle`/
   `summitEle` — nothing is re-measured here. */
import { escPend, gradColor, txtOn } from './util.js';
import { D, tpl } from './i18n.js';
import { uKm, uM, uElev, uKmValue, uDistUnit } from './units.js';
import { verticalSpan } from './profile-scale.js';
import { metresAlong, windowSpan } from './profile-marks.js';

/* Below this, a two-digit % either overflows or shrinks unreadably. */
const MIN_PX_FOR_LABEL = 30;

export function canProfile(f){
  return !!(f && Array.isArray(f.grad) && f.grad.length);
}

/* Cumulative height at each bin edge, derived from the same gradients the drawer strip draws. */
function heights(grad, binM){
  const out=[0];
  let h=0;
  for(const g of grad){ h += (g/100)*binM; out.push(h); }
  return out;
}

export function profileSvg(f){
  const grad = f.grad.map(Number);
  const binM = Number(f.binM) || Math.round((Number(f.length)||0)/Math.max(1,grad.length)) || 100;
  const totalM = grad.length*binM;
  const h = heights(grad, binM);

  const climbM = Math.max(...h) - Math.min(...h);
  const spanM = verticalSpan(climbM, totalM);

  /* Figures live in their own band: early bins are too short to hold a label.
     ViewBox ~1.9:1 so the SVG fills a laptop without stretching a gentle climb. */
  const W=1180, H=620;
  const BAND=30;                    // gradient-figure strip
  /* Marker positions in metres from the foot (docs/specs/climb-elevation.md §6d). */
  const steepAtM = f.steep ? metresAlong(f.route, f.steep.at) : null;
  const riderAtM = f.steepPoint ? metresAlong(f.route, f.steepPoint.at) : null;
  const STEEP_ROOM = steepAtM!=null ? 36 : 0, RIDER_ROOM = riderAtM!=null ? 30 : 0;
  // Side margins hold altitude labels outside the plot; the top holds the marker labels.
  const padL=84, padR=84, padT=16+STEEP_ROOM+RIDER_ROOM, padB=BAND+46;
  const plotW=W-padL-padR, plotH=H-padT-padB;
  const base=padT+plotH;
  const x = m => padL + (m/totalM)*plotW;
  const y = metres => padT + plotH - ((metres - Math.min(...h))/spanM)*plotH;

  const colW = plotW/grad.length;
  const showNums = colW >= MIN_PX_FOR_LABEL;

  /* Each bin is a quadrilateral under the road: the top edge IS the silhouette. */
  // Miles need the extra decimal: a 100 m bin is 0.06 mi.
  const binDec = 'mi' === uDistUnit() ? 2 : 1;
  const cols = grad.map((g,i)=>{
    const x0=x(i*binM), x1=x((i+1)*binM), y0=y(h[i]), y1=y(h[i+1]);
    const fill=gradColor(g);
    const band = `<rect x="${x0.toFixed(1)}" y="${base.toFixed(1)}" width="${(x1-x0).toFixed(1)}" height="${BAND}"
        fill="${fill}" stroke="#fff" stroke-width="0.75"/>`;
    const label = showNums
      ? `<text x="${((x0+x1)/2).toFixed(1)}" y="${(base+BAND/2+4).toFixed(1)}" class="cc-cp-num" fill="${txtOn(fill)}">${g}%</text>`
      : '';
    return `<polygon points="${x0.toFixed(1)},${base.toFixed(1)} ${x0.toFixed(1)},${y0.toFixed(1)} ${x1.toFixed(1)},${y1.toFixed(1)} ${x1.toFixed(1)},${base.toFixed(1)}"
        fill="${fill}" fill-opacity="0.9" stroke="#fff" stroke-width="0.75"><title>${uKmValue(i*binM/1000, binDec)}–${uKm((i+1)*binM/1000, binDec)} · ${g}%</title></polygon>${band}${label}`;
  }).join('');

  // Ruler spaced in the unit it is labelled in (positions stay a fraction of the total).
  const totalKm=totalM/1000;
  const totalDisp=uKmValue(totalKm);
  const stepKm = totalDisp<=3?0.5:(totalDisp<=12?1:Math.ceil(totalDisp/10));
  const axisY = base+BAND;
  const ticks=[];
  for(let k=stepKm;k<totalDisp-0.001;k+=stepKm){
    const px=padL+(k/totalDisp)*plotW;
    ticks.push(`<line x1="${px.toFixed(1)}" y1="${axisY}" x2="${px.toFixed(1)}" y2="${axisY+6}" class="cc-cp-tick"/>
      <text x="${px.toFixed(1)}" y="${axisY+22}" class="cc-cp-axis" text-anchor="middle">${k%1?k.toFixed(1):k}</text>`);
  }
  /* Unit once at the end of the ruler (docs/specs/account-and-auth.md §9). */
  ticks.push(`<text x="${(padL+plotW+10).toFixed(1)}" y="${axisY+22}" class="cc-cp-axis" text-anchor="start">${uDistUnit()}</text>`);

  /* Foot and summit altitudes — omitted when unmeasured, never invented. */
  const foot = f.footEle!=null ? Math.round(Number(f.footEle)) : null;
  const summit = f.summitEle!=null ? Math.round(Number(f.summitEle)) : null;
  const endLabels = (foot!=null && summit!=null)
    ? `<text x="${(padL-12)}" y="${(y(h[0])+4).toFixed(1)}" class="cc-cp-ele" text-anchor="end">${uElev(foot)}</text>
       <text x="${(W-padR+12)}" y="${(y(h[h.length-1])+4).toFixed(1)}" class="cc-cp-ele" text-anchor="start">${uElev(summit)}</text>`
    : '';

  const startMark=`<circle cx="${x(0).toFixed(1)}" cy="${y(h[0]).toFixed(1)}" r="5" class="cc-cp-pin"/>`;
  const endMark=`<circle cx="${x(totalM).toFixed(1)}" cy="${y(h[h.length-1]).toFixed(1)}" r="5" class="cc-cp-pin"/>`;

  /* Road height at any distance, between the bin edges. */
  const roadAt = m => {
    const i = Math.min(grad.length-1, Math.max(0, Math.floor(m/binM)));
    return h[i] + (h[i+1]-h[i])*Math.min(1, Math.max(0, (m - i*binM)/binM));
  };
  // A label stays clear of the altitude labels in the side margins.
  const labelX = px => Math.min(padL+plotW-90, Math.max(padL+90, px));

  /* The steepest 250 m: the road drawn heavy over the window, a bracket above
     it and the figure the catalogue publishes. Same purple as its map marker. */
  let steepMark = '';
  if(steepAtM!=null){
    const winM = Number(f.steepWindowM) || 250;
    const [a, b] = windowSpan(steepAtM, winM, totalM);
    const road = [];
    for(let m=a; m<b; m+=Math.max(1, binM/4)) road.push(`${x(m).toFixed(1)},${y(roadAt(m)).toFixed(1)}`);
    road.push(`${x(b).toFixed(1)},${y(roadAt(b)).toFixed(1)}`);
    const top = Math.min(y(roadAt(a)), y(roadAt(b))) - 12;
    const pct = String(f.steep.pct || f.maxGradient || '').replace(/%$/, '');
    const label = `▲ ${tpl(D.steepOver || '{v} over {w}', {v: pct ? `${pct}%` : '', w: uM(winM)})}`;
    /* The label clears the road under its whole width, not only under the
       bracket: the road keeps rising to the right of the window. 13 px mono
       is about 8 px a character. */
    const lx = labelX((x(a)+x(b))/2), half = label.length*4 + 6;
    let roadTop = top;
    for(let px = lx-half; px <= lx+half; px += 4){
      const m = Math.min(totalM, Math.max(0, (px-padL)/plotW*totalM));
      roadTop = Math.min(roadTop, y(roadAt(m)) - 4);
    }
    const ly = Math.min(top, roadTop) - 8;
    steepMark = `<g class="cc-cp-steep"><title>${escPend(D.steepest||'Steepest pitch')} · ${escPend(label)}</title>
      <polyline points="${road.join(' ')}" class="cc-cp-steep-road"/>
      <path d="M${x(a).toFixed(1)},${(top+6).toFixed(1)} V${top.toFixed(1)} H${x(b).toFixed(1)} V${(top+6).toFixed(1)}" class="cc-cp-steep-br"/>
      <text x="${lx.toFixed(1)}" y="${ly.toFixed(1)}" class="cc-cp-steep-t" text-anchor="middle">${escPend(label)}</text></g>`;
  }

  /* The rider's steepest point: a dashed amber line from the road to a label
     in the top row, above the measured window's label, so the two never meet. */
  let riderMark = '';
  if(riderAtM!=null){
    const m = Math.min(totalM, riderAtM), px = x(m);
    const sp = f.steepPoint;
    const pct = sp.pct ? `${String(sp.pct).replace(/%$/, '')}%` : '';
    const text = `⬗ ${pct || D.riderRamp || 'ramp'}`;
    const tip = [D.riderSteepest||'Steepest point, marked by a rider', pct, sp.note].filter(Boolean).join(' · ');
    riderMark = `<g class="cc-cp-rider"><title>${escPend(tip)}</title>
      <line x1="${px.toFixed(1)}" y1="${(y(roadAt(m))-4).toFixed(1)}" x2="${px.toFixed(1)}" y2="26" class="cc-cp-rider-l"/>
      <circle cx="${px.toFixed(1)}" cy="${y(roadAt(m)).toFixed(1)}" r="4" class="cc-cp-rider-p"/>
      <text x="${labelX(px).toFixed(1)}" y="20" class="cc-cp-rider-t" text-anchor="middle">${escPend(text)}</text></g>`;
  }

  const distLabel=`<text x="${(padL+plotW/2).toFixed(1)}" y="${H-8}" class="cc-cp-axis" text-anchor="middle">${uKm(totalKm, totalDisp<10?1:0)} · ${D.gradPerBin ? D.gradPerBin.replace('{b}', uM(binM)) : `per ${uM(binM)}`}</text>`;

  return `<svg class="cc-cp-svg" viewBox="0 0 ${W} ${H}" preserveAspectRatio="xMidYMid meet" role="img"
      aria-label="${escPend(f.name||'')} — ${uKm(totalKm)}, ${uElev(Math.round(climbM))}">
    ${cols}<line x1="${padL}" y1="${base}" x2="${padL+plotW}" y2="${base}" class="cc-cp-base"/>
    ${riderMark}${steepMark}${startMark}${endMark}${endLabels}${ticks.join('')}${distLabel}
  </svg>`;
}

/* Own overlay: the photo lightbox is a slideshow, not a chart host. */
export function openClimbProfile(f){
  if(!canProfile(f)) return;
  const el=document.getElementById('climbProfile');
  if(!el) return;
  const gain = f.gain!=null ? `${Math.round(Number(f.gain))} m` : '';
  const avg = f.avgGradient ? String(f.avgGradient) : '';
  const max = f.maxGradient ? String(f.maxGradient) : '';
  const stats=[
    gain && `↑ ${gain}`,
    avg && `${D.avgShort||'avg'} ${avg}`,
    max && `${D.steepest||'Steepest pitch'} ${max}`,
  ].filter(Boolean).join(' · ');

  el.querySelector('.cc-cp-title').textContent = f.name || '';
  el.querySelector('.cc-cp-sub').textContent = stats;
  el.querySelector('.cc-cp-stage').innerHTML = profileSvg(f);
  el.classList.add('open');
  el.setAttribute('aria-hidden','false');
  const x=el.querySelector('.cc-cp-x');
  if(x) x.focus();
}

export function closeClimbProfile(){
  const el=document.getElementById('climbProfile');
  if(!el || !el.classList.contains('open')) return false;
  el.classList.remove('open');
  el.setAttribute('aria-hidden','true');
  return true;
}

export function isClimbProfileOpen(){
  const el=document.getElementById('climbProfile');
  return !!(el && el.classList.contains('open'));
}
