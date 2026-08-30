// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The landing page's own behaviour: the nav-height custom property, the
   scroll reveal, and the race colouring of the hero contours.

   A file rather than an inline block, so the landing page carries no CSP nonce
   and can therefore be held in a shared cache (docs/specs/page-caching.md
   §3.2). Nothing here reads server data, which is why it moved out cleanly.

   Deferred: it runs after the document is parsed and after races.js has set
   window.CC, both of which it needs. */
  /* Publish the nav's real height so the hero can be exactly one screenful
     below it (see --nav-h in the stylesheet above). Measured rather than
     written down: the bar's height comes from its content — the brand, the
     account chip, the tagline under it, and whether any of that wraps — so a
     constant would be right on one viewport and wrong on the next. Observed,
     not just read once, because the burger collapse changes it mid-session. */
  (function(){
    const nav=document.querySelector('.topnav');
    if(!nav) return;
    const publish=()=>document.documentElement.style.setProperty('--nav-h', nav.getBoundingClientRect().height+'px');
    publish();
    if(window.ResizeObserver) new ResizeObserver(publish).observe(nav);
    else window.addEventListener('resize', publish);
  })();

  // staggered reveal on scroll
  const io=new IntersectionObserver((es)=>{es.forEach(e=>{if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target)}})},{threshold:.18});
  document.querySelectorAll('.rv:not(.in)').forEach(el=>io.observe(el));

  // race calendar + helpers live in races.js (loaded site-wide from base for
  // the header coordinate tagline) — guard so a failed load can't throw here
  // rainbow=true → UCI World Championships (rainbow jersey)
  const CC=window.CC;
  if(CC){
    const {RAINBOW, pickRace}=CC;
    const applyRace=(r)=>{
      const hero=document.querySelector('.hero');
      const paths=document.querySelectorAll('.hero .hero-contours path');
      if(r.rainbow){
        paths.forEach((p,i)=>{ const c=RAINBOW[(RAINBOW.length-1-i+RAINBOW.length)%RAINBOW.length]; p.style.stroke=c; p.style.opacity=c==='#141414'?'1':'.85'; });
      } else {
        paths.forEach(p=>{ p.style.stroke=''; p.style.opacity=''; });
        hero.style.setProperty('--ride-outer', r.outer);
        hero.style.setProperty('--ride-inner', r.inner);
      }
    };
    applyRace(pickRace().r);
  }
