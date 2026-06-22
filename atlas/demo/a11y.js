// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Accessibility helper — adds a "Skip to content" link as the first focusable
// element on every page and points it at the main content landmark. Pages here
// are hand-authored single files with no shared <head>, so this keeps the skip
// link DRY: one
//   <script src="a11y.js"></script>
// before </body> wires it up everywhere. The link is offscreen until focused.
(function a11y(){
  const css=document.createElement('style');
  css.textContent=`
  .skip-link{position:fixed;left:.6rem;top:-4rem;z-index:10000;
    background:var(--trail,#FF5A1F);color:var(--ink,#101E16);
    font-family:var(--mono,"Spline Sans Mono",monospace);font-weight:700;
    font-size:.74rem;letter-spacing:.08em;text-transform:uppercase;
    padding:.7rem 1.05rem;border-radius:0 0 7px 7px;text-decoration:none;
    box-shadow:0 6px 18px rgba(0,0,0,.3);transition:top .18s ease}
  .skip-link:focus{top:0;outline:2px solid var(--paper,#EFE6D4);outline-offset:2px}
  [data-skip-target]:focus{outline:none}`;
  document.head.appendChild(css);

  // Locate the main content: an existing <main>/[role=main], else the first
  // sibling after the nav, else the first <section>, else <body>.
  let target=document.querySelector('main, [role="main"]');
  if(!target){
    const nav=document.querySelector('nav, .topnav, .top');
    target=(nav&&nav.nextElementSibling)||document.querySelector('section')||document.body;
  }
  if(!target.id) target.id='main';
  target.setAttribute('tabindex','-1');
  target.setAttribute('data-skip-target','');

  const link=document.createElement('a');
  link.className='skip-link';
  link.href='#'+target.id;
  link.textContent='Skip to content';
  link.addEventListener('click',()=>{ const t=document.getElementById(target.id); if(t) t.focus(); });
  document.body.insertBefore(link,document.body.firstChild);
})();
