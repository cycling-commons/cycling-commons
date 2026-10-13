// SPDX-License-Identifier: AGPL-3.0-only
// Active-event calendar for .cc-coord / hero theme. Classics before stage races
// so a classic inside a Grand Tour window still wins. Gravel Worlds 2026 is
// confirmed (owner 2026-10-13); the other Worlds and Unbound dates are
// placeholders.
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
    {key:'vuelta',  name:'Vuelta a España',outer:'#E8332A', inner:'#8C0B14', icon:'🔴', finish:{label:'FINISH · MADRID', lat:40.42, lng:-3.70}, windows:[['2026-08-22','2026-09-13']]},
    {key:'road-worlds',name:'UCI Road Worlds', rainbow:true, icon:'🌈', finish:{label:'FINISH · MONTRÉAL', lat:45.50, lng:-73.57}, windows:[['2026-09-20','2026-09-27']]},
    {key:'lombardia',name:'Il Lombardia',  outer:'#FCA5A5', inner:'#B91C1C', icon:'🍂', finish:{label:'FINISH · COMO', lat:45.81, lng:9.08}, windows:[['2026-10-10','2026-10-10']]},
    {key:'gravel-worlds',name:'UCI Gravel Worlds', rainbow:true, icon:'🌈', finish:{label:'FINISH · NICE', lat:43.70, lng:7.27}, windows:[['2026-10-10','2026-10-11']]},
    {key:'track-worlds',name:'UCI Track Worlds', rainbow:true, icon:'🌈', finish:{label:'FINISH · SANTIAGO', lat:-33.45, lng:-70.67}, windows:[['2026-10-20','2026-10-25']]}
  ];
  const RAINBOW = ['#0B4EA2', '#E2001A', '#141414', '#FFE500', '#009A44'];
  // What the header coordinate shows when no race is on: a famous climb from
  // anywhere in the world, a different one each day and the same one for
  // everybody that day (owner 2026-10-13). Ordered so that neighbouring days
  // jump between continents. The owner's agenda API is meant to replace this
  // calendar later. Before this script runs, the templates show Hoorn
  // (N 52.64 E 5.06), where Cycling Commons started.
  const DEFAULT_PLACES = [
    {label:'MONT VENTOUX, FRANCE', lat:44.17, lng:5.28},
    {label:'HALEAKALA, HAWAII', lat:20.71, lng:-156.25},
    {label:'WULING PASS, TAIWAN', lat:24.14, lng:121.28},
    {label:'SANI PASS, SOUTH AFRICA', lat:-29.59, lng:29.29},
    {label:'PASSO DELLO STELVIO, ITALY', lat:46.53, lng:10.45},
    {label:'MOUNT LEMMON, ARIZONA', lat:32.44, lng:-110.79},
    {label:'MOUNT BULLER, AUSTRALIA', lat:-37.15, lng:146.44},
    {label:'JEBEL HAFEET, UAE', lat:24.06, lng:55.78},
    {label:'ALPE D\u2019HUEZ, FRANCE', lat:45.09, lng:6.07},
    {label:'MOUNT WASHINGTON, NEW HAMPSHIRE', lat:44.27, lng:-71.30},
    {label:'MOUNT FUJI SUBARU LINE, JAPAN', lat:35.39, lng:138.73},
    {label:'TEIDE, TENERIFE', lat:28.27, lng:-16.64},
    {label:'COL DU TOURMALET, FRANCE', lat:42.91, lng:0.15},
    {label:'MUUR VAN GERAARDSBERGEN, BELGIUM', lat:50.77, lng:3.88},
    {label:'ALTO DE L\u2019ANGLIRU, SPAIN', lat:43.22, lng:-5.93},
    {label:'BOX HILL, ENGLAND', lat:51.25, lng:-0.31}
  ];

  // A window's dates are calendar days where the rider is, so both ends are
  // read as local time. A bare 'YYYY-MM-DD' parses as UTC midnight, which in
  // Europe is two hours into the race day and never matched it.
  const dayStart = (s) => new Date(s + 'T00:00:00');
  const dayEnd = (s) => new Date(s + 'T23:59:59');

  function pickRace() {
    const now = new Date();
    for (const r of RACES) for (const [s, e] of r.windows) {
      if (now >= dayStart(s) && now <= dayEnd(e)) return {r, status:'on', date:dayStart(s)};
    }
    let next = null, nd = null;
    for (const r of RACES) for (const [s] of r.windows) {
      const d = dayStart(s); if (d > now && (!nd || d < nd)) { next = r; nd = d; }
    }
    if (next) return {r:next, status:'next', date:nd};
    const first = RACES.slice().sort((a, b) => dayStart(a.windows[0][0]) - dayStart(b.windows[0][0]))[0];
    return {r:first, status:'next', date:dayStart(first.windows[0][0])};
  }

  // The place for a day: the race whose window it is in, else the default
  // place for that calendar day (the day count since 1970, in local time).
  function placeFor(now) {
    const pick = pickRace();
    if (pick.status === 'on') return pick.r.finish;
    const day = Math.floor((Date.UTC(now.getFullYear(), now.getMonth(), now.getDate())) / 86400000);
    return DEFAULT_PLACES[day % DEFAULT_PLACES.length];
  }

  function fmtCoord(lat, lng) {
    const ns = lat >= 0 ? 'N' : 'S', ew = lng >= 0 ? 'E' : 'W';
    return `${ns} ${Math.abs(lat).toFixed(2)}° · ${ew} ${Math.abs(lng).toFixed(2)}°`;
  }

  window.CC = Object.assign(window.CC || {}, {RACES, RAINBOW, DEFAULT_PLACES, pickRace, placeFor, fmtCoord});

  function paintCoord() {
    const els = document.querySelectorAll('.cc-coord');
    if (!els.length) return;
    const f = placeFor(new Date());
    const txt = fmtCoord(f.lat, f.lng);
    els.forEach(el => { el.textContent = txt; });
  }
  if (document.readyState !== 'loading') paintCoord();
  else document.addEventListener('DOMContentLoaded', paintCoord);
})();
