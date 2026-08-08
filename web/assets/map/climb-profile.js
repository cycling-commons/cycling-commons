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

/* Bins narrower than this get no figure printed in them. Below roughly 30 px a
   two-digit gradient plus its % sign either overflows its column or has to
   shrink to a size nobody can read, and a chart of unreadable numbers is worse
   than a chart of none — the colour still carries the gradient. */
const MIN_PX_FOR_LABEL = 30;

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

  /* The gradient figures live in their OWN band under the silhouette, not
     inside it — which is what the published profiles do, and for a reason. A
     number printed inside its column needs the column to be tall enough to hold
     it, and the first bins of a long pass are 1-2% and a few pixels high, so
     Grimsel's opening "1" and "5" floated outside their own bars. A fixed band
     is always tall enough, always legible, and reads as a separate row of
     values rather than as labels that sometimes fit. */
  /* The viewBox aspect IS the panel's shape: the SVG keeps its ratio, so a
     wide, shallow box letterboxes inside a tall window and leaves empty paper
     above and below the chart. ~1.9:1 fills a normal laptop without stretching
     a gentle climb into something it is not. */
  const W=1180, H=620;
  const BAND=30;                    // the gradient-figure strip
  // Tight top (the panel header already names the climb) and side margins that
  // hold the altitude labels outside the plot, so they never cover a column.
  const padL=84, padR=84, padT=16, padB=BAND+46;
  const plotW=W-padL-padR, plotH=H-padT-padB;
  const base=padT+plotH;
  const x = m => padL + (m/totalM)*plotW;
  const y = metres => padT + plotH - ((metres - Math.min(...h))/spanM)*plotH;

  const colW = plotW/grad.length;
  const showNums = colW >= MIN_PX_FOR_LABEL;

  /* Each bin is a quadrilateral under the road, not a rectangle: the top edge
     follows the climb, so the bars together ARE the silhouette rather than a
     histogram standing under a separate line. */
  const cols = grad.map((g,i)=>{
    const x0=x(i*binM), x1=x((i+1)*binM), y0=y(h[i]), y1=y(h[i+1]);
    const fill=gradColor(g);
    const band = `<rect x="${x0.toFixed(1)}" y="${base.toFixed(1)}" width="${(x1-x0).toFixed(1)}" height="${BAND}"
        fill="${fill}" stroke="#fff" stroke-width="0.75"/>`;
    /* The unit travels with the figure. Without it the band is a row of bare
       numbers that could be gradients, distances or bin indexes, and the only
       thing saying otherwise is a caption at the bottom of the chart. */
    const label = showNums
      ? `<text x="${((x0+x1)/2).toFixed(1)}" y="${(base+BAND/2+4).toFixed(1)}" class="cc-cp-num" fill="${txtOn(fill)}">${g}%</text>`
      : '';
    return `<polygon points="${x0.toFixed(1)},${base.toFixed(1)} ${x0.toFixed(1)},${y0.toFixed(1)} ${x1.toFixed(1)},${y1.toFixed(1)} ${x1.toFixed(1)},${base.toFixed(1)}"
        fill="${fill}" fill-opacity="0.9" stroke="#fff" stroke-width="0.75"><title>${(i*binM/1000).toFixed(1)}–${((i+1)*binM/1000).toFixed(1)} km · ${g}%</title></polygon>${band}${label}`;
  }).join('');

  // Distance ruler, under the band.
  const totalKm=totalM/1000;
  const stepKm = totalKm<=3?0.5:(totalKm<=12?1:Math.ceil(totalKm/10));
  const axisY = base+BAND;
  const ticks=[];
  for(let k=stepKm;k<totalKm-0.001;k+=stepKm){
    const px=x(k*1000);
    ticks.push(`<line x1="${px.toFixed(1)}" y1="${axisY}" x2="${px.toFixed(1)}" y2="${axisY+6}" class="cc-cp-tick"/>
      <text x="${px.toFixed(1)}" y="${axisY+22}" class="cc-cp-axis" text-anchor="middle">${k%1?k.toFixed(1):k}</text>`);
  }
  /* The unit once, at the end of the ruler, rather than repeated on every tick
     — "3 km 6 km 9 km" is noise, and a bare row of numbers is ambiguous. The
     app is metric throughout (there is no imperial preference on User), so this
     is km, not a converted value. */
  ticks.push(`<text x="${(padL+plotW+10).toFixed(1)}" y="${axisY+22}" class="cc-cp-axis" text-anchor="start">km</text>`);

  /* The two altitudes, which is the pair `gain` alone cannot give you. Rows
     measured before footEle/summitEle existed simply do not get them — an
     invented sea level would be a number nobody measured, which is the exact
     failure this codebase keeps correcting. */
  const foot = f.footEle!=null ? Math.round(Number(f.footEle)) : null;
  const summit = f.summitEle!=null ? Math.round(Number(f.summitEle)) : null;
  const endLabels = (foot!=null && summit!=null)
    ? `<text x="${(padL-12)}" y="${(y(h[0])+4).toFixed(1)}" class="cc-cp-ele" text-anchor="end">${foot} m</text>
       <text x="${(W-padR+12)}" y="${(y(h[h.length-1])+4).toFixed(1)}" class="cc-cp-ele" text-anchor="start">${summit} m</text>`
    : '';

  const startMark=`<circle cx="${x(0).toFixed(1)}" cy="${y(h[0]).toFixed(1)}" r="5" class="cc-cp-pin"/>`;
  const endMark=`<circle cx="${x(totalM).toFixed(1)}" cy="${y(h[h.length-1]).toFixed(1)}" r="5" class="cc-cp-pin"/>`;

  const distLabel=`<text x="${(padL+plotW/2).toFixed(1)}" y="${H-8}" class="cc-cp-axis" text-anchor="middle">${totalKm.toFixed(totalKm<10?1:0)} km · ${D.gradPerBin ? D.gradPerBin.replace('{b}', binM) : `per ${binM} m`}</text>`;

  return `<svg class="cc-cp-svg" viewBox="0 0 ${W} ${H}" preserveAspectRatio="xMidYMid meet" role="img"
      aria-label="${escPend(f.name||'')} — ${totalKm.toFixed(1)} km, ${Math.round(climbM)} m">
    ${cols}<line x1="${padL}" y1="${base}" x2="${padL+plotW}" y2="${base}" class="cc-cp-base"/>
    ${startMark}${endMark}${endLabels}${ticks.join('')}${distLabel}
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
