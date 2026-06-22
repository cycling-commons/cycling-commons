// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Shared edit-item registry for the prototype.
 * One entry per editable feature, keyed by edit-id (a slug of the feature name,
 * or an explicit shared id like 'water-fountain' / 'ride' when several map
 * features point at the SAME edit item).  improve.html renders whichever entry
 * matches ?item=<id>, falling back to the service station.
 *
 * Design source of truth: docs/specs/edit-items/<LETTER>-*.md (one spec per type).
 * Demo fixture data only — NOT a schema. Keep this flat and readable.
 *
 * Each entry:
 *   ey      eyebrow line            name   <h1>
 *   icon    locator pin glyph       center [lng, lat] for the MapLibre pin
 *   co      coords / region line    tags   chip strings
 *   cur     current-details rows    [{k,v}]
 *   fields  "Fix details" inputs    [{label, type:'text'|'select'|'textarea', value?, opts?, placeholder?}]
 *   addFields "Add missing" inputs  (type-specific — same shape as fields)
 *   upload  'track' → rides also get a GPX/FIT upload tab (photo stays available)
 */
window.CC_EDIT = {

  /* ---- A · Road surface --------------------------------------------------- */
  'road-surface': {
    ey:'Improve this segment', name:'Road surface · RAVeL L45', icon:'▰',
    center:[5.8895,50.3868], co:'◎ Stavelot–Coo · RAVeL L45, Wallonia, BE',
    tags:['draw','OSM','A · road surface'],
    cur:[ {k:'Surface',v:'Asphalt'}, {k:'Smoothness',v:'Excellent'}, {k:'Width',v:'3.0 m'},
          {k:'Traffic',v:'Car-free (RAVeL)'} ],
    fields:[
      {label:'Surface', type:'select', opts:['Asphalt','Concrete','Paving stones','Sett — pavé','Compacted','Fine gravel','Gravel','Ground']},
      {label:'Smoothness', type:'select', opts:['Excellent','Good','Intermediate','Bad','Very bad']},
      {label:'Width (m)', value:'3.0'},
      {label:'Traffic', type:'select', opts:['Quiet','Moderate','Busy','Car-free (RAVeL)']},
      {label:'Note', type:'textarea', placeholder:'e.g. resurfaced in 2025, or pavé through the village'}
    ],
    addFields:[
      {label:'Lit at night?', type:'select', opts:['Unknown','Yes','No']},
      {label:'Segregated from cars?', type:'select', opts:['Unknown','Yes','No']},
      {label:'Seasonal closure?', type:'select', opts:['None','Winter','Forestry']}
    ]
  },

  /* ---- B · Climbs (linear) ------------------------------------------------ */
  'cote-de-la-redoute': {
    ey:'Improve this climb', name:'Côte de la Redoute', icon:'⛰',
    center:[5.69924,50.49222], co:'◎ 50.492°N 5.699°E · Aywaille, Wallonia, BE',
    tags:['draw','OSM','B · climbs'],
    cur:[ {k:'Length',v:'2.0 km'}, {k:'Average gradient',v:'8.4%'}, {k:'Max gradient',v:'~20% (mid ramp)'},
          {k:'Surface',v:'Asphalt'}, {k:'Traffic',v:'Quiet'} ],
    fields:[
      {label:'Name', value:'Côte de la Redoute'},
      {label:'Surface', type:'select', opts:['Smooth asphalt','Asphalt','Worn asphalt','Cobbles','Gravel']},
      {label:'Average gradient (%)', value:'8.4'},
      {label:'Max gradient (%)', value:'20'},
      {label:'Anything to correct?', type:'textarea', placeholder:"e.g. the foot starts at the bridge, not the square"}
    ],
    addFields:[
      {label:'Water on climb?', type:'select', opts:['Unknown','Yes','No']},
      {label:'Hairpins (count)', placeholder:'e.g. 3'},
      {label:'Shade / exposure', type:'select', opts:['Unknown','Wooded','Exposed']}
    ]
  },
  'mur-de-huy': {
    ey:'Improve this climb', name:'Mur de Huy', icon:'⛰',
    center:[5.24874,50.51426], co:'◎ 50.514°N 5.249°E · Huy, Wallonia, BE',
    tags:['draw','OSM','B · climbs'],
    cur:[ {k:'Length',v:'1.3 km'}, {k:'Average gradient',v:'9.3%'}, {k:'Max gradient',v:'~26% (Chapelle hairpin)'},
          {k:'Surface',v:'Asphalt'}, {k:'Traffic',v:'Busy (town climb)'} ],
    fields:[
      {label:'Name', value:'Mur de Huy'},
      {label:'Surface', type:'select', opts:['Asphalt','Smooth asphalt','Worn asphalt','Cobbles']},
      {label:'Average gradient (%)', value:'9.3'},
      {label:'Max gradient (%)', value:'26'},
      {label:'Anything to correct?', type:'textarea', placeholder:'What is wrong or out of date?'}
    ],
    addFields:[
      {label:'Water on climb?', type:'select', opts:['Unknown','Yes','No']},
      {label:'Hairpins (count)', placeholder:'e.g. 3'},
      {label:'Shade / exposure', type:'select', opts:['Unknown','Wooded','Exposed']}
    ]
  },
  'cote-de-stockeu': {
    ey:'Improve this climb', name:'Côte de Stockeu', icon:'⛰',
    center:[5.93247,50.39148], co:'◎ 50.391°N 5.932°E · Stavelot, Wallonia, BE',
    tags:['draw','OSM','B · climbs'],
    cur:[ {k:'Length',v:'~1.0 km'}, {k:'Average gradient',v:'9%+'}, {k:'Surface',v:'Asphalt, worn'},
          {k:'Traffic',v:'Quiet'} ],
    fields:[
      {label:'Name', value:'Côte de Stockeu'},
      {label:'Surface', type:'select', opts:['Worn asphalt','Asphalt','Smooth asphalt','Cobbles']},
      {label:'Average gradient (%)', value:'9'},
      {label:'Max gradient (%)', value:'20'},
      {label:'Anything to correct?', type:'textarea', placeholder:'What is wrong or out of date?'}
    ],
    addFields:[
      {label:'Water on climb?', type:'select', opts:['Unknown','Yes','No']},
      {label:'Hairpins (count)', placeholder:'e.g. 3'},
      {label:'Shade / exposure', type:'select', opts:['Unknown','Wooded','Exposed']}
    ]
  },
  'cote-de-la-roche-aux-faucons': {
    ey:'Improve this climb', name:'Côte de la Roche-aux-Faucons', icon:'⛰',
    center:[5.54831,50.55573], co:'◎ 50.556°N 5.548°E · Boncelles, Wallonia, BE',
    tags:['draw','OSM','B · climbs'],
    cur:[ {k:'Length',v:'1.5 km'}, {k:'Average gradient',v:'9%'}, {k:'Surface',v:'Asphalt'},
          {k:'Traffic',v:'Moderate'} ],
    fields:[
      {label:'Name', value:'Côte de la Roche-aux-Faucons'},
      {label:'Surface', type:'select', opts:['Asphalt','Smooth asphalt','Worn asphalt']},
      {label:'Average gradient (%)', value:'9'},
      {label:'Max gradient (%)', value:'11'},
      {label:'Anything to correct?', type:'textarea', placeholder:'What is wrong or out of date?'}
    ],
    addFields:[
      {label:'Water on climb?', type:'select', opts:['Unknown','Yes','No']},
      {label:'Hairpins (count)', placeholder:'e.g. 3'},
      {label:'Shade / exposure', type:'select', opts:['Unknown','Wooded','Exposed']}
    ]
  },

  /* ---- C · Water & food — TWO fountains share this ONE edit item ---------- */
  'water-fountain': {
    ey:'Improve this water point', name:'Public fountain', icon:'💧',
    center:[5.9300,50.3957], co:'◎ Stavelot & Coo · Wallonia, BE',
    tags:['tap','OSM','C · water & food'],
    cur:[ {k:'Type',v:'Public fountain'}, {k:'Potable',v:'Yes (public supply)'}, {k:'Seasonal',v:'Year-round'} ],
    fields:[
      {label:'Type', type:'select', opts:['Public fountain','Drinking tap','Cemetery tap','Café — refill point']},
      {label:'Potable?', type:'select', opts:['Yes (public supply)','Unsigned — use judgement','No / non-potable']},
      {label:'Seasonal availability', type:'select', opts:['Year-round','Summer only','Frost-shut in winter','Unknown']},
      {label:'Note for riders', type:'textarea', placeholder:'e.g. low flow, or hard to spot behind the church'}
    ],
    addFields:[
      {label:'Bottle-fill friendly?', type:'select', opts:['Unknown','Yes','No']},
      {label:'Cost', type:'select', opts:['Free','Customers only']}
    ]
  },

  /* ---- D · Bike services (default / no-param fallback) -------------------- */
  'repair-station-malmedy': {
    ey:'Improve this place', name:'Repair station · Malmedy', icon:'⚙',
    center:[6.027,50.426], co:'◎ 50.426°N 6.027°E · Wallonia, BE',
    tags:['tap','OSM','D · services'],
    cur:[ {k:'Type',v:'Public repair station'}, {k:'Pump',v:'Presta + Schrader'}, {k:'Tools',v:'Hex, screwdrivers'},
          {k:'Hours',v:'24/7'} ],
    fields:[
      {label:'Name', value:'Repair station · Malmedy'},
      {label:'Pump valve', type:'select', opts:['Presta + Schrader','Presta only','Schrader only','No pump']},
      {label:'Opening hours', value:'24/7'},
      {label:'Tools available', value:'Hex keys, screwdrivers', placeholder:'e.g. chain tool, work stand'},
      {label:'Anything to correct?', type:'textarea', placeholder:"What's wrong or out of date?"}
    ],
    addFields:[
      {label:'Work stand?', type:'select', opts:['Unknown','Yes','No']},
      {label:'Chain tool?', type:'select', opts:['Unknown','Yes','No']},
      {label:'E-bike charging?', type:'select', opts:['Unknown','Yes','No']}
    ]
  },

  /* ---- E · Where to sleep ------------------------------------------------- */
  'cyclist-friendly-gite-ambleve-valley': {
    ey:'Improve this stay', name:'Cyclist-friendly gîte · Amblève valley', icon:'⛺',
    center:[5.6200,50.4500], co:'◎ 50.450°N 5.620°E · near Aywaille, BE',
    tags:['stay','community','E · where to sleep'],
    cur:[ {k:'Type',v:'Gîte / guesthouse'}, {k:'Secure bike storage',v:'Yes'}, {k:'Area',v:'Amblève valley'} ],
    fields:[
      {label:'Name', value:'Cyclist-friendly gîte · Amblève valley'},
      {label:'Secure bike storage', type:'select', opts:['Yes — locked room','Yes — garage/shed','On request','No']},
      {label:'Drying / washing for kit', type:'select', opts:['Unknown','Yes','No']},
      {label:'Booking link', placeholder:'https://…'},
      {label:'Note for riders', type:'textarea', placeholder:'What makes it good for cyclists?'}
    ],
    addFields:[
      {label:'Pets allowed?', type:'select', opts:['Unknown','Yes','No']},
      {label:'Meals / breakfast?', type:'select', opts:['Unknown','Yes','No']},
      {label:'Tools to borrow?', type:'select', opts:['Unknown','Yes','No']}
    ]
  },

  /* ---- F · Hazards & conditions ------------------------------------------- */
  'exposed-crosswind-hautes-fagnes': {
    ey:'Update this hazard', name:'Exposed crosswind · Hautes Fagnes', icon:'⚠',
    center:[6.0700,50.5160], co:'◎ 50.516°N 6.070°E · Hautes Fagnes plateau, BE',
    tags:['report','safety','F · hazards'],
    cur:[ {k:'Type',v:'Crosswind / fog'}, {k:'Severity',v:'Moderate'}, {k:'Seasonal',v:'Worst autumn/winter'} ],
    fields:[
      {label:'Hazard type', type:'select', opts:['Crosswind / fog','Ice / frost','Loose surface / gravel','Flooding','Roadworks','Other']},
      {label:'Severity', type:'select', opts:['Low','Moderate','High']},
      {label:'When is it worst?', type:'select', opts:['Autumn / winter','Year-round','After rain','Windy days']},
      {label:'Still present?', type:'select', opts:['Yes — confirmed today','Reduced','Gone — clear now']},
      {label:'What did you see?', type:'textarea', placeholder:'Describe the conditions'}
    ],
    addFields:[
      {label:'Alternative / detour', placeholder:'e.g. drop to the valley road'},
      {label:'Time of day', type:'select', opts:['Any','Morning','Afternoon','Evening']}
    ]
  },

  /* ---- G · Getting there -------------------------------------------------- */
  'aywaille-station': {
    ey:'Improve this place', name:'Aywaille station', icon:'🚆',
    center:[5.6770,50.4730], co:'◎ 50.473°N 5.677°E · line 42, BE',
    tags:['tap','OSM + SNCB','G · getting there'],
    cur:[ {k:'Type',v:'Railway station'}, {k:'Line',v:'L42 · Liège – Luxembourg'}, {k:'Bikes on train',v:'With supplement (SNCB)'} ],
    fields:[
      {label:'Bikes on board', type:'select', opts:['Allowed with supplement','Allowed, free','Restricted at peak','Not allowed']},
      {label:'Step-free access', type:'select', opts:['Unknown','Yes','No']},
      {label:'Bike parking at station', type:'select', opts:['Unknown','Covered racks','Open racks','None']},
      {label:'Note for riders', type:'textarea', placeholder:'e.g. which platform for the climbs'}
    ],
    addFields:[
      {label:'Lift / ramp?', type:'select', opts:['Unknown','Yes','No']},
      {label:'Bike ticket needed?', type:'select', opts:['Unknown','Yes','No']}
    ]
  },

  /* ---- H · Shelter & emergency ------------------------------------------- */
  'shelter-baraque-michel': {
    ey:'Improve this place', name:'Shelter · Baraque Michel', icon:'⛑',
    center:[6.0500,50.5020], co:'◎ 50.502°N 6.050°E · Hautes Fagnes, BE',
    tags:['tap','OSM','H · shelter & emergency'],
    cur:[ {k:'Type',v:'Refuge / chapel shelter'}, {k:'Use',v:'Wind/rain refuge'}, {k:'Where',v:'Baraque Michel'} ],
    fields:[
      {label:'Shelter type', type:'select', opts:['Refuge / chapel','Bus shelter','Café (seasonal)','Picnic hut']},
      {label:'Always accessible?', type:'select', opts:['Yes — open structure','Daytime only','Seasonal','Unknown']},
      {label:'Water nearby?', type:'select', opts:['Unknown','Yes','No']},
      {label:'Note for riders', type:'textarea', placeholder:'How useful is it in bad weather?'}
    ],
    addFields:[
      {label:'Bench / seating?', type:'select', opts:['Unknown','Yes','No']},
      {label:'Phone signal?', type:'select', opts:['Unknown','Yes','No']}
    ]
  },

  /* ---- I · Scenic views --------------------------------------------------- */
  'signal-de-botrange': {
    ey:'Improve this viewpoint', name:'Signal de Botrange', icon:'◬',
    center:[6.0940,50.5010], co:'◎ 50.501°N 6.094°E · 694 m, BE',
    tags:['tap','OSM','I · scenic views'],
    cur:[ {k:'Type',v:'Viewpoint / high point'}, {k:'Elevation',v:'694 m — highest in Belgium'},
          {k:'The 700 m step',v:'A 6 m stone stair (Butte Baltia, 1923) reaches exactly 700 m'},
          {k:'Tower',v:'Stone Baltia tower (1934) — climbable'},
          {k:'Setting',v:'Hautes Fagnes nature reserve · Waimes, Liège'},
          {k:'What you see',v:'Hautes Fagnes moorland — Belgium\'s largest reserve'} ],
    fields:[
      {label:'Name', value:'Signal de Botrange'},
      {label:'Type', type:'select', opts:['Viewpoint / high point','Monument','Heritage site','Nature reserve']},
      {label:'Access for bikes', type:'select', opts:['Roadside','Short walk','Path only']},
      {label:'What can you see?', value:'Hautes Fagnes moorland panorama'},
      {label:'Anything to add?', type:'textarea', placeholder:'A useful tip about this spot'}
    ],
    addFields:[
      {label:'Best light / time', type:'select', opts:['Any','Morning','Golden hour','Sunset']},
      {label:'Bench?', type:'select', opts:['Unknown','Yes','No']}
    ]
  },

  /* ---- J · History & culture --------------------------------------------- */
  'stavelot-abbey': {
    ey:'Improve this place', name:'Stavelot Abbey', icon:'🏛',
    center:[5.9290,50.3950], co:'◎ 50.395°N 5.929°E · Stavelot, BE',
    tags:['tap','OSM','J · history & culture'],
    cur:[ {k:'Type',v:'Abbey / heritage site'}, {k:'Founded',v:'651 (Benedictine)'},
          {k:'Cycling link',v:'Foot of the Côte de Stockeu'} ],
    fields:[
      {label:'Name', value:'Stavelot Abbey'},
      {label:'Type', type:'select', opts:['Heritage site','Museum','Monument','Religious site']},
      {label:'Bike parking', type:'select', opts:['Unknown','Yes','No']},
      {label:'Anything to add?', type:'textarea', placeholder:'A useful tip about this spot'}
    ],
    addFields:[
      {label:'Opening hours', placeholder:'e.g. 10:00–18:00'},
      {label:'Entry fee?', type:'select', opts:['Free','Paid','Unknown']},
      {label:'Cycling story / link', placeholder:'A heritage note worth riding past for'}
    ]
  },

  /* ---- K · Quality rides — every contributed GPX shares this ONE edit item */
  'ride': {
    ey:'Edit this ride', name:'Contributed ride', icon:'★',
    center:[5.8636,50.4920], co:'◎ Roundtrip from Spa · Wallonia, BE',
    tags:['GPX','contributed','K · quality rides'],
    upload:'track',   // rides get a GPX/FIT track upload tab (photo stays available too)
    cur:[ {k:'Shape',v:'Roundtrip — from Spa'}, {k:'Source',v:'Contributed GPX (GPS track only)'} ],
    fields:[
      {label:'Ride name', placeholder:'e.g. Spa · Sankt Vith'},
      {label:'Difficulty', type:'select', opts:['Gentle','Moderate','Hard','Very hard']},
      {label:'Best season', type:'select', opts:['Spring','Summer','Autumn','Winter','Any']},
      {label:'Dominant surface', type:'select', opts:['Asphalt','Mixed','Gravel']},
      {label:'Note for riders', type:'textarea', placeholder:'What is this loop like?'}
    ],
    addFields:[
      {label:'Quietness rating (1–5)', type:'select', opts:['1','2','3','4','5']},
      {label:'Scenic rating (1–5)', type:'select', opts:['1','2','3','4','5']},
      {label:'Cycling-friendliness (1–5)', type:'select', opts:['1','2','3','4','5']},
      {label:'Suitable bike types', type:'select', opts:['Road','Gravel','MTB','E-bike','Any']},
      {label:'Handbike-friendly?', type:'select', opts:['Unknown','Yes','No']},
      {label:'Gradient-limited?', type:'select', opts:['No','≤6%','≤9%']},
      {label:'Best direction', type:'select', opts:['Clockwise','Counter-clockwise','Either']}
    ]
  }
};
