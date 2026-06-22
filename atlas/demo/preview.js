// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Pre-launch preview marker. The Commons isn't live yet — the figures shown
// across the site (place counts, coverage %, leaderboards) are illustrative
// sample data, and the contributor/curator screens are design previews. This
// badge keeps that honest on every page. Self-contained: one
//   <script src="preview.js"></script>
// before </body> drops a small, fixed corner pill onto any page.
// Remove this script (and the marker disappears) once the Commons is live.
(function previewBadge(){
  const style=document.createElement('style');
  style.textContent=`
  .cc-preview{position:fixed;left:1rem;bottom:1rem;z-index:9998;
    display:flex;align-items:center;gap:.5rem;cursor:default;
    font-family:var(--mono,"Spline Sans Mono",monospace);
    color:var(--paper,#EFE6D4);background:rgba(16,30,22,.92);backdrop-filter:blur(6px);
    border:1px solid rgba(200,146,58,.55);border-radius:999px;padding:.4rem .75rem;
    box-shadow:0 4px 14px rgba(0,0,0,.25)}
  .cc-preview .dot{width:7px;height:7px;border-radius:50%;background:var(--ochre,#C8923A);
    box-shadow:0 0 0 0 rgba(200,146,58,.6);animation:cc-pulse 2.4s ease-out infinite;flex:none}
  .cc-preview b{font-size:.62rem;letter-spacing:.01em;color:var(--ochre,#C8923A)}
  .cc-preview span{font-size:.58rem;letter-spacing:.04em;color:rgba(239,230,212,.72)}
  @keyframes cc-pulse{0%{box-shadow:0 0 0 0 rgba(200,146,58,.5)}70%{box-shadow:0 0 0 7px rgba(200,146,58,0)}100%{box-shadow:0 0 0 0 rgba(200,146,58,0)}}
  @media(max-width:560px){.cc-preview{display:none}}   /* off touch UI — it overlaps the add-spot wizard's sticky Back/Next */
  @media(prefers-reduced-motion:reduce){.cc-preview .dot{animation:none}}`;
  document.head.appendChild(style);

  const el=document.createElement('div');
  el.className='cc-preview';
  el.setAttribute('role','note');
  el.title='An inspirational preview — the Cycling Commons isn’t live yet. Figures shown are illustrative sample data and the contributor/curator screens are design previews of how it will work.';
  el.innerHTML='<span class="dot"></span><b>Inspirational preview</b><span>sample data · not live yet</span>';
  document.body.appendChild(el);
})();
