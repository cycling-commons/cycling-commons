// SPDX-License-Identifier: AGPL-3.0-only
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

  /* The silo box's phone draft: the six apps fly into the Commons icon and
     that icon climbs into the emptied screen. The flight paths are measured,
     not written down, so they land on the icon whatever the fonts and the
     viewport did to the layout: --tx/--ty per app, --up for the dock. Measured
     again whenever the draft toggle shows the phone, since a hidden element
     has no geometry. */
  (function(){
    const silo=document.querySelector('.silo');
    if(!silo) return;
    const aim=()=>{
      const cc=silo.querySelector('.ph-cc'), grid=silo.querySelector('.ph-grid'), dock=silo.querySelector('.ph-dock');
      if(!cc||!cc.offsetParent) return;
      const c=cc.getBoundingClientRect();
      silo.querySelectorAll('.ph-app').forEach(a=>{
        const r=a.querySelector('.ph-ic').getBoundingClientRect();
        a.style.setProperty('--tx',(c.left+c.width/2-(r.left+r.width/2)).toFixed(1)+'px');
        a.style.setProperty('--ty',(c.top+c.height/2-(r.top+r.height/2)).toFixed(1)+'px');
      });
      // Layout offsets, not rects: the dock's own rise is a transform, so a
      // rect taken after it has risen would measure the climb as done.
      const gy=grid.offsetTop+grid.offsetHeight/2, dy=dock.offsetTop+dock.offsetHeight/2;
      dock.style.setProperty('--up',(gy-dy).toFixed(1)+'px');
    };
    aim();
    silo.querySelectorAll('.silo-pick').forEach(i=>i.addEventListener('change',()=>requestAnimationFrame(aim)));
    window.addEventListener('resize',aim);
    if(document.fonts&&document.fonts.ready) document.fonts.ready.then(aim);

    /* Replay: take `in` away, force a reflow, put it back. Every step of the
       sequence hangs off `.silo.in`, so the keyframes restart from the top -
       the same restart flipping the draft toggle already gets for free. */
    const replay=silo.querySelector('[data-silo-replay]');
    if(replay) replay.addEventListener('click',()=>{
      silo.classList.remove('in');
      void silo.offsetWidth;
      aim();
      silo.classList.add('in');
    });
  })();
