// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Skip-to-content link. Sibling walk skips announcement bars so a flash notice
// is never stamped id="main" (improve/add-climb wizards have no <main>).
(function a11y(){
  const T=window.ccT||function(k,fb){return fb;};
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

  const SKIP_OVER='[role="status"],[role="alert"],.cc-notice,script,style,template';
  let target=document.querySelector('main, [role="main"]')||document.getElementById('main');
  if(!target){
    const nav=document.querySelector('nav, .topnav, .top');
    let next=nav&&nav.nextElementSibling;
    while(next&&next.matches(SKIP_OVER)) next=next.nextElementSibling;
    target=next||document.querySelector('section')||document.body;
  }
  if(!target.id) target.id='main';
  target.setAttribute('tabindex','-1');
  target.setAttribute('data-skip-target','');

  const link=document.createElement('a');
  link.className='skip-link';
  link.href='#'+target.id;
  link.textContent=T('skip','Skip to content');
  link.addEventListener('click',()=>{ const t=document.getElementById(target.id); if(t) t.focus(); });
  document.body.insertBefore(link,document.body.firstChild);

  // Off-site links in a new tab with rel=noopener noreferrer.
  for(const a of document.querySelectorAll('a[href]')){
    if(/^https?:$/.test(a.protocol) && a.hostname && a.hostname!==location.hostname){
      a.target='_blank';
      a.rel='noopener noreferrer';
    }
  }
})();
