// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Shared contributor-profile fixtures for the prototype.
 * Used by profile.html (public view) and settings.html (the edit interface).
 * Keyed by the slug the map drawer uses (slug of the uploader name).
 *
 * Demo fixture data only — NOT a schema, and nothing here persists. The edit
 * flow in settings.html is interface-and-flow only (mock save).
 *
 * Each profile:
 *   initials  avatar monogram        name     display name
 *   since     "contributing since"   region   "Wallonia, BE"
 *   bio       short tagline          links    {strava, website, osm}
 *   privacy   {public, showStats}    stats    [[value,label], ...]
 *   contribs  [{ic, type, t, m, tag, item, status, href}]
 *     type   short category chip (WATER / CLIMB / ...)
 *     item   edit-id for improve.html?item=<item>  (the existing item editor)
 *     status 'approved' | 'pending'                (curator-review state)
 *     href   public deep-link → map.html?feature=<t>
 */
window.CC_PROFILES = {
  'hanne-v': {
    initials:'HV', name:'Hanne V.', since:'2024', region:'Wallonia, BE',
    bio:'Rides the Ardennes most weekends. Maps water, surfaces and the quiet roads between the climbs.',
    links:{ strava:'https://www.strava.com/athletes/hanne', website:'', osm:'hanne_v' },
    privacy:{ public:true, showStats:true },
    stats:[ ['7','routes shared'], ['23','places improved'], ['2','climbs added'], ['58','confirmations'] ],
    contribs:[
      {ic:'★', type:'RIDE',  t:'Spa · Sankt Vith', m:'128 km roundtrip GPX · shared with elevation', tag:'K · Quality rides', item:'ride', status:'approved'},
      {ic:'💧', type:'WATER', t:'Public fountain · Stavelot', m:'Confirmed potable, year-round', tag:'C · Water & food', item:'water-fountain', status:'approved'},
      {ic:'⚠', type:'HAZARD',t:'Exposed crosswind · Hautes Fagnes', m:'Reported plateau wind & fog', tag:'F · Hazards', item:'exposed-crosswind-hautes-fagnes', status:'pending'},
      {ic:'⛰', type:'CLIMB', t:'Côte de Stockeu', m:'Corrected gradient & surface to "worn asphalt"', tag:'B · Climbs', item:'cote-de-stockeu', status:'pending'}
    ]
  },
  'lucas-r': {
    initials:'LR', name:'Lucas R.', since:'2025', region:'Liège, BE',
    bio:'Commutes by bike, fixes what he finds. Repair stations and service points are his thing.',
    links:{ strava:'', website:'', osm:'lucasr' },
    privacy:{ public:true, showStats:true },
    stats:[ ['4','routes shared'], ['9','places improved'], ['1','climbs added'], ['21','confirmations'] ],
    contribs:[
      {ic:'★', type:'RIDE',    t:'Spa · Côte des Hézalles', m:'Roundtrip GPX from Spa', tag:'K · Quality rides', item:'ride', status:'approved'},
      {ic:'⚙', type:'SERVICE', t:'Repair station · Malmedy', m:'Added tool list and 24/7 hours', tag:'D · Services', item:'repair-station-malmedy', status:'pending'}
    ]
  },
  'eddy-m': {
    initials:'EM', name:'Eddy M.', since:'2023', region:'Stavelot, BE',
    bio:'Born at the foot of the Stockeu. Knows every loop and every café between here and Malmedy.',
    links:{ strava:'https://www.strava.com/athletes/eddy', website:'eddysrides.be', osm:'eddy_m' },
    privacy:{ public:true, showStats:true },
    stats:[ ['11','routes shared'], ['31','places improved'], ['3','climbs added'], ['90','confirmations'] ],
    contribs:[
      {ic:'★', type:'RIDE',    t:'Rondje Super Stockeu', m:'Local loop over the Stockeu', tag:'K · Quality rides', item:'ride', status:'approved'},
      {ic:'🏛', type:'HISTORY', t:'Stavelot Abbey', m:'Added cycling link at the foot of the Stockeu', tag:'J · History & culture', item:'stavelot-abbey', status:'approved'}
    ]
  }
};
