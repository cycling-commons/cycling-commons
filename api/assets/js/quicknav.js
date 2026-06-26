// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Page quick-nav (dev affordance) — a floating panel to jump between prototype
// pages. Self-contained: injects its own styles and markup, so a single
//   <script src="quicknav.js"></script>
// before </body> drops it onto any page. Colours fall back to literals when a
// page hasn't defined the atlas CSS vars. Remove or gate behind ?debug for prod.
(function quickNav(){
  const PAGES=[
    ['index.html','Landing'],['map.html','Map'],['region.html','Region atlas'],
    ['vote.html','Vote'],['contribute.html','Contribute'],['add-climb.html','Add a climb'],['improve.html','Improve a place'],
    ['login.html','Login & account'],['moderate.html','Moderate'],['developers.html','Developers'],
    ['licenses.html','Licensing'],['coverage.html','State of the Atlas'],
    ['contributors.html','Contributors'],['profile.html','Contributor profile'],['about.html','About'],['pages.html','All pages']
  ];

  const style=document.createElement('style');
  style.textContent=`
  .debug{position:fixed;bottom:1rem;right:1rem;z-index:9999;max-width:320px;
    color:var(--paper,#EFE6D4);font-family:var(--mono,"Spline Sans Mono",monospace);
    background:rgba(16,30,22,.94);backdrop-filter:blur(6px);
    border:1px dashed rgba(239,230,212,.4);border-radius:8px;padding:.7rem .8rem}
  .debug-h{font-size:.56rem;letter-spacing:.16em;color:var(--ochre,#C8923A);margin-bottom:.45rem}
  .debug select{width:100%;background:var(--ink,#101E16);color:var(--paper,#EFE6D4);
    border:1px solid rgba(239,230,212,.3);border-radius:4px;
    font-family:var(--mono,"Spline Sans Mono",monospace);font-size:.62rem;
    padding:.35rem;outline:none;cursor:pointer}
  @media(max-width:560px){.debug{display:none}}`;   /* dev affordance — keep it off touch UI (e.g. the add-spot wizard's sticky nav) */
  document.head.appendChild(style);

  const panel=document.createElement('div');
  panel.className='debug'; panel.id='debug';
  panel.innerHTML='<div class="debug-h">DEBUG · PAGES</div>'+
    '<select id="debugPages" aria-label="Go to page"></select>';
  document.body.appendChild(panel);

  const sel=panel.querySelector('#debugPages');
  const here=location.pathname.split('/').pop()||'index.html';
  PAGES.forEach(([file,label])=>{
    const o=document.createElement('option'); o.value=file; o.textContent=label;
    if(file===here) o.selected=true;
    sel.appendChild(o);
  });
  sel.addEventListener('change',()=>{ if(sel.value!==here) location.href=sel.value; });
})();
