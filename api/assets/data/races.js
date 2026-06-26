// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
// Cycling-event calendar — the single source of truth for the "active event"
// coordinate shown under the logo across the site, and the index hero's
// ride-line colour/finish. One-day classics are listed before stage races so a
// classic inside a Grand Tour window still wins. Each event defines a theme
// (outer/inner colour, time windows) + a finish location.
//
// Usage: include <script src="races.js"></script>. Any element with
// class="cc-coord" is auto-filled with the active event's finish coordinate.
// Other scripts can read window.CC = {RACES, RAINBOW, pickRace, fmtCoord}.
//
// NOTE: Worlds/Unbound dates & host cities are plausible placeholders for 2026 —
// confirm against the real calendars before launch.
(function () {
  const RACES = [
    {key:'cx-worlds', name:'UCI Cyclocross Worlds', rainbow:true, icon:'🌈', finish:{label:'FINISH · HULST', lat:51.28, lng:4.05}, windows:[['2026-01-31','2026-02-01']]},
    {key:'sanremo', name:'Milano–Sanremo', outer:'#7DD3FC', inner:'#0369A1', icon:'🌊', finish:{label:'FINISH · SANREMO', lat:43.82, lng:7.78}, windows:[['2026-03-21','2026-03-21']]},
    {key:'flanders',name:'Tour of Flanders',outer:'#FFE066', inner:'#C99A2E', icon:'🦁', finish:{label:'FINISH · OUDENAARDE', lat:50.85, lng:3.61}, windows:[['2026-04-05','2026-04-05']]},
    {key:'roubaix', name:'Paris–Roubaix',  outer:'#D7261E', inner:'#6E726B', icon:'🪨', finish:{label:'FINISH · ROUBAIX', lat:50.69, lng:3.18}, windows:[['2026-04-12','2026-04-12']]},
    {key:'amstel',  name:'Amstel Gold Race',outer:'#FCD34D',inner:'#D98A2B', icon:'🍺', finish:{label:'FINISH · VALKENBURG', lat:50.86, lng:5.83}, windows:[['2026-04-19','2026-04-19']]},
    {key:'liege',   name:'Liège–Bastogne–Liège',outer:'#93C5FD',inner:'#3D5BCC',icon:'🌧️',finish:{label:'FINISH · LIÈGE', lat:50.63, lng:5.57}, windows:[['2026-04-26','2026-04-26']]},
    {key:'unbound', name:'Unbound Gravel', outer:'#D6B98C', inner:'#8A5A2B', icon:'🌾', finish:{label:'FINISH · EMPORIA, KS', lat:38.40, lng:-96.18}, windows:[['2026-05-30','2026-05-30']]},
    {key:'giro',    name:'Giro d’Italia', outer:'#F06EAA', inner:'#C0397A', icon:'🌸', finish:{label:'FINISH · ROMA', lat:41.90, lng:12.50}, windows:[['2026-05-09','2026-05-31']]},
    {key:'tour',    name:'Tour de France', outer:'#F4D03F', inner:'#C9A227', icon:'🟡', finish:{label:'FINISH · PARIS', lat:48.87, lng:2.29}, windows:[['2026-07-04','2026-07-26']]},
    {key:'mtb-worlds',name:'UCI MTB Worlds', rainbow:true, icon:'🌈', finish:{label:'FINISH · CRANS-MONTANA', lat:46.31, lng:7.48}, windows:[['2026-08-29','2026-09-06']]},
    {key:'vuelta',  name:'Vuelta a España',outer:'#E06C5E', inner:'#B3261E', icon:'🔴', finish:{label:'FINISH · MADRID', lat:40.42, lng:-3.70}, windows:[['2026-08-22','2026-09-13']]},
    {key:'road-worlds',name:'UCI Road Worlds', rainbow:true, icon:'🌈', finish:{label:'FINISH · MONTRÉAL', lat:45.50, lng:-73.57}, windows:[['2026-09-20','2026-09-27']]},
    {key:'lombardia',name:'Il Lombardia',  outer:'#FCA5A5', inner:'#B91C1C', icon:'🍂', finish:{label:'FINISH · COMO', lat:45.81, lng:9.08}, windows:[['2026-10-10','2026-10-10']]},
    {key:'gravel-worlds',name:'UCI Gravel Worlds', rainbow:true, icon:'🌈', finish:{label:'FINISH · NICE', lat:43.70, lng:7.27}, windows:[['2026-10-17','2026-10-18']]},
    {key:'track-worlds',name:'UCI Track Worlds', rainbow:true, icon:'🌈', finish:{label:'FINISH · SANTIAGO', lat:-33.45, lng:-70.67}, windows:[['2026-10-20','2026-10-25']]}
  ];
  // UCI rainbow jersey stripes, in order: blue, red, black, yellow, green.
  const RAINBOW = ['#0B4EA2', '#E2001A', '#141414', '#FFE500', '#009A44'];

  // The race that's on now; else the next one up; else wrap to the season's first.
  function pickRace() {
    const now = new Date(); now.setHours(0, 0, 0, 0);
    for (const r of RACES) for (const [s, e] of r.windows) {
      if (now >= new Date(s) && now <= new Date(e + 'T23:59:59')) return {r, status:'on', date:new Date(s)};
    }
    let next = null, nd = null;
    for (const r of RACES) for (const [s] of r.windows) {
      const d = new Date(s); if (d > now && (!nd || d < nd)) { next = r; nd = d; }
    }
    if (next) return {r:next, status:'next', date:nd};
    const first = RACES.slice().sort((a, b) => new Date(a.windows[0][0]) - new Date(b.windows[0][0]))[0];
    return {r:first, status:'next', date:new Date(first.windows[0][0])};
  }

  function fmtCoord(lat, lng) {
    const ns = lat >= 0 ? 'N' : 'S', ew = lng >= 0 ? 'E' : 'W';
    return `${ns} ${Math.abs(lat).toFixed(2)}° · ${ew} ${Math.abs(lng).toFixed(2)}°`;
  }

  window.CC = Object.assign(window.CC || {}, {RACES, RAINBOW, pickRace, fmtCoord});

  // auto: fill every .cc-coord element with the active event's finish coordinate
  function paintCoord() {
    const els = document.querySelectorAll('.cc-coord');
    if (!els.length) return;
    const f = pickRace().r.finish;
    const txt = fmtCoord(f.lat, f.lng);
    els.forEach(el => { el.textContent = txt; });
  }
  if (document.readyState !== 'loading') paintCoord();
  else document.addEventListener('DOMContentLoaded', paintCoord);
})();
