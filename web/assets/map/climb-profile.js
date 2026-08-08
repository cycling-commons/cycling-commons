// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The full climb profile, shown in a popup when the drawer's gradient strip is
   clicked.

   The strip in the drawer is a *glance*: 25 bars in 52 px, sized to sit beside
   the other rows without pushing them off screen. It answers "roughly what
   shape is this". It cannot answer the questions a rider actually asks before
   riding a col — where the hard part is, how high the top is, how much of the
   climb is above 8% — because there is no room in it for a vertical axis, a
   silhouette, or a number per bar.

   So this draws the same measurement at a size where those fit: the road's
   own silhouette, each bin filled in its gradient colour with the figure
   written inside it, the two altitudes labelled, and a distance ruler. It is
   the shape every published climb profile uses, and riders can read it without
   being taught anything.

   NOTHING IS RE-MEASURED HERE. Every number comes from `grad`, `binM`,
   `footEle` and `summitEle`, which ClimbProfiler wrote (climb-elevation.md §4).
   A chart that did its own arithmetic could disagree with the drawer's caption
   about the same climb, and then neither could be trusted. The one thing this
   derives is the cumulative height of each bin, which is the definition of the
   gradients it was handed, not a second opinion about them. */
import { escPend, gradColor, txtOn } from './util.js';
import { D } from './i18n.js';

/* Bins narrower than this get no number printed in them. Below roughly 22 px a
   two-digit figure either overflows its column or has to shrink to a size
   nobody can read, and a chart of unreadable numbers is worse than a chart of
   none — the colour still carries the gradient. */
const MIN_PX_FOR_LABEL = 22;

export function canProfile(f){
  return !!(f && Array.isArray(f.grad) && f.grad.length);
}

/* The cumulative height at each bin edge, in metres above the foot.

   Derived from the gradients rather than from a stored elevation array,
   because the gradients ARE what the drawer strip draws: deriving the
   silhouette from the same numbers guarantees the two charts describe one
   climb. (A stored per-sample elevation array would be more faithful to the
   road, and is the obvious upgrade if it is ever persisted — the shape below
   would then read it instead.) */
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

  // The vertical scale spans the climb's own range, floored so a gentle climb
  // is not stretched into an alp: a 40 m riser drawn full-height would look
  // exactly like Grimsel.
  const climbM = Math.max(...h) - Math.min(...h);
  const spanM = Math.max(climbM, 60);

  const W=920, H=380;
  // The side margins hold the two altitude labels, which sit OUTSIDE the plot
  // so they cannot cover the first and last columns' gradient figures.
  const padL=88, padR=88, padT=34, padB=64;
  const plotW=W-padL-padR, plotH=H-padT-padB;
  const x = m => padL + (m/totalM)*plotW;
  const y = metres => padT + plotH - ((metres - Math.min(...h))/spanM)*plotH;

  const colW = plotW/grad.length;
  const showNums = colW >= MIN_PX_FOR_LABEL;

  /* Each bin is a quadrilateral under the road, not a rectangle: the top edge
     follows the climb, so the bars together ARE the silhouette rather than a
     histogram standing under a separate line. */
  const cols = grad.map((g,i)=>{
    const x0=x(i*binM), x1=x((i+1)*binM), y0=y(h[i]), y1=y(h[i+1]);
    const base=padT+plotH;
    const fill=gradColor(g);
    const label = showNums
      ? `<text x="${((x0+x1)/2).toFixed(1)}" y="${(base-9).toFixed(1)}" class="cc-cp-num" fill="${txtOn(fill)}">${g}</text>`
      : '';
    return `<polygon points="${x0.toFixed(1)},${base.toFixed(1)} ${x0.toFixed(1)},${y0.toFixed(1)} ${x1.toFixed(1)},${y1.toFixed(1)} ${x1.toFixed(1)},${base.toFixed(1)}"
        fill="${fill}" stroke="#fff" stroke-width="0.75"><title>${(i*binM/1000).toFixed(1)}–${((i+1)*binM/1000).toFixed(1)} km · ${g}%</title></polygon>${label}`;
  }).join('');

  // Distance ruler: one tick per whole km, or per 500 m on a short climb.
  const totalKm=totalM/1000;
  const stepKm = totalKm<=3?0.5:(totalKm<=12?1:Math.ceil(totalKm/10));
  const ticks=[];
  for(let k=stepKm;k<totalKm-0.001;k+=stepKm){
    const px=x(k*1000);
    ticks.push(`<line x1="${px.toFixed(1)}" y1="${padT+plotH}" x2="${px.toFixed(1)}" y2="${padT+plotH+6}" class="cc-cp-tick"/>
      <text x="${px.toFixed(1)}" y="${padT+plotH+22}" class="cc-cp-axis" text-anchor="middle">${k%1?k.toFixed(1):k}</text>`);
  }

  /* The two altitudes, which is the pair `gain` alone cannot give you. Rows
     measured before footEle/summitEle existed simply do not get them — an
     invented sea level would be a number nobody measured, which is the exact
     failure this codebase keeps correcting. */
  const foot = f.footEle!=null ? Math.round(Number(f.footEle)) : null;
  const summit = f.summitEle!=null ? Math.round(Number(f.summitEle)) : null;
  const endLabels = (foot!=null && summit!=null)
    ? `<text x="${(padL-10)}" y="${(y(h[0])+4).toFixed(1)}" class="cc-cp-ele" text-anchor="end">${foot} m</text>
       <text x="${(W-padR+10)}" y="${(y(h[h.length-1])+4).toFixed(1)}" class="cc-cp-ele" text-anchor="start">${summit} m</text>`
    : '';

  const startMark=`<circle cx="${x(0).toFixed(1)}" cy="${y(h[0]).toFixed(1)}" r="5" class="cc-cp-pin"/>`;
  const endMark=`<circle cx="${x(totalM).toFixed(1)}" cy="${y(h[h.length-1]).toFixed(1)}" r="5" class="cc-cp-pin"/>`;

  const distLabel=`<text x="${(padL+plotW/2).toFixed(1)}" y="${H-14}" class="cc-cp-axis" text-anchor="middle">${totalKm.toFixed(totalKm<10?1:0)} km · ${D.gradPerBin ? D.gradPerBin.replace('{b}', binM) : `per ${binM} m`}</text>`;

  return `<svg class="cc-cp-svg" viewBox="0 0 ${W} ${H}" role="img"
      aria-label="${escPend(f.name||'')} — ${totalKm.toFixed(1)} km, ${Math.round(climbM)} m">
    <line x1="${padL}" y1="${padT+plotH}" x2="${padL+plotW}" y2="${padT+plotH}" class="cc-cp-base"/>
    ${cols}${startMark}${endMark}${endLabels}${ticks.join('')}${distLabel}
  </svg>`;
}

/* The popup. Its own overlay rather than the photo lightbox's: that one is a
   slideshow with prev/next and a credit line, and threading a chart through it
   would mean teaching it that some "photos" are not photos. */
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
