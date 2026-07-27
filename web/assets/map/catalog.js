// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* The catalogue: the A-K layer table every other module reads, the derived
   lookups, the towns index, and the two pieces of view state that key off it
   (which layers are active, and which view mode is showing).
   Extracted from map.js by 2026-07-26-map-js-module-split-design.md §5.

   CATALOG is built at module scope — the second exception §4.2 allows, for the
   same reason as `map`: every module needs it before any init() runs, and the
   catalog-fetch gate guarantees the labels it reads are present. Its `features`
   arrays are filled later, by the entry, from the CC_* payloads.

   `mode` is deliberately NOT exported as a binding. An importer would get a
   read-only copy of a value that changes when the rider flips the view mode, so
   it is read through mode() and written through setMode() — the owner-module
   rule from §2. `active` and `layerByKey` are mutable containers rather than
   rebindable variables, so they export directly. */
import { LAYER_L10N } from './i18n.js';
import { escPend } from './util.js';

// One real, verified Ardennes example per catalog type (A–K). geom.ll = [lat,lng].
// record[] rows render in the detail drawer; omit any attribute we cannot verify.
export const CATALOG = [
  { key:'surface', letter:'A', label:LAYER_L10N.surface||'Road surface', color:'#4E8C84', icon:'▰', kind:'surface', exp:true, features:[] }
  ,{ key:'climbs', letter:'B', label:LAYER_L10N.climbs||'Climbs', color:'#6A2C8F', icon:'⛰', kind:'point', exp:true, features:[] }
  ,{ key:'water', letter:'C', label:LAYER_L10N.water||'Water & food', color:'#8FB6A8', icon:'💧', kind:'point', exp:false, features:[] }
  ,{ key:'services', letter:'D', label:LAYER_L10N.services||'Bike services', color:'#6b6f5e', icon:'⚙', kind:'point', exp:false, features:[] }
  ,{ key:'stays', letter:'E', label:LAYER_L10N.stays||'Where to sleep', color:'#B5532E', icon:'⛺', kind:'point', exp:true, features:[] }
  // F · Hazards — features filled below from window.CC_HAZARDS (the served
  // payload), region-stamped like every letter (region-scoping-design.md §7
  // Task A). The hardcoded demo fixture ("Exposed crosswind · Hautes Fagnes")
  // was retired in the 07-20 review round (rid-less client fixtures have no
  // honest place in a scope-filtered map); it now returns as a real seeded,
  // region-stamped SeedManualCatalogCommand row served through this path.
  ,{ key:'hazards', letter:'F', label:LAYER_L10N.hazards||'Hazards & conditions', color:'#C8923A', icon:'⚠', kind:'point', exp:false, features:[]}
  ,{ key:'transit', letter:'G', label:LAYER_L10N.transit||'Getting there', color:'#3E7D8C', icon:'🚆', kind:'point', exp:false, features:[] }
  ,{ key:'shelter', letter:'H', label:LAYER_L10N.shelter||'Shelter', color:'#9A8FB6', icon:'⛑', kind:'point', exp:false, features:[] }
  ,{ key:'scenic', letter:'I', label:LAYER_L10N.scenic||'Scenic views', color:'#2C5440', icon:'📷', kind:'point', exp:true, features:[] }
  ,{ key:'history', letter:'J', label:LAYER_L10N.history||'History & culture', color:'#6E5849', icon:'🏛', kind:'point', exp:true, features:[] }
  ,{ key:'experience', letter:'K', label:LAYER_L10N.experience||'Recommended routes', color:'#FF5A1F', icon:'★', kind:'line', exp:false, features:[] }
];

export const active = new Set(CATALOG.map(l => l.key));   // all layers (incl. K · Recommended routes) on by default
export const layerByKey = Object.fromEntries(CATALOG.map(l => [l.key, l]));

// A route's drawn path by item id. Lives here rather than with either caller
// because both picking.js and corrections.js need it and neither owns the
// catalogue it reads (§9 — a deviation from §4's table, which did not place it).
export function routePathById(id){
  const layer=layerByKey['experience']; if(!layer) return null;
  const f=layer.features.find(x=>String(x.id)===String(id));
  return f && f.geom && f.geom.path ? f.geom.path : null;
}

// Towns referenced by routes — each links to a place on the map + a city info card.
// ll=[lat,lng]; info is a short blurb (in production auto-found from Wikidata/Wikipedia or user-added).
export const CITIES = {
  'Spa':{ll:[50.4920,5.8636], wiki:'https://en.wikipedia.org/wiki/Spa,_Belgium', info:'The thermal town that gave the word "spa" its name; start of these loops and gateway to Spa-Francorchamps.'},
  'Stavelot':{ll:[50.3957,5.9300], wiki:'https://en.wikipedia.org/wiki/Stavelot', info:'Abbey town grown around its Benedictine abbey (651), at the foot of the Côte de Stockeu.'},
  'Vielsalm':{ll:[50.2833,5.9167], wiki:'https://en.wikipedia.org/wiki/Vielsalm', info:'Ardennes town on the Salm river — gravel and cross-country country.'},
  'Sankt Vith':{ll:[50.2811,6.1267], wiki:'https://en.wikipedia.org/wiki/Sankt_Vith', info:'Hub of the eastern Ardennes, in the German-speaking Community of Belgium.'},
  'Francorchamps':{ll:[50.4350,5.9710], wiki:'https://en.wikipedia.org/wiki/Francorchamps', info:'Village beside the Spa-Francorchamps racing circuit, on the high road south of Spa.'},
  'Coo':{ll:[50.3892,5.8847], wiki:'https://en.wikipedia.org/wiki/Coo,_Belgium', info:'Hamlet of Stavelot known for the Cascade de Coo waterfall and Plopsa Coo park.'},
  'Sart':{ll:[50.5200,5.8800], wiki:'https://en.wikipedia.org/wiki/Jalhay', info:'Sart-lez-Spa, a village of Jalhay on the plateau north of Spa.'},
  'Jalhay':{ll:[50.5560,5.9700], wiki:'https://en.wikipedia.org/wiki/Jalhay', info:'Municipality on the edge of the Hautes Fagnes, by the Gileppe dam.'},
  'Stoumont':{ll:[50.4050,5.8000], wiki:'https://en.wikipedia.org/wiki/Stoumont', info:'Hilly Amblève-valley municipality of steep Ardennes lanes.'},
  'Chevron':{ll:[50.4200,5.7600], wiki:'https://en.wikipedia.org/wiki/Stoumont', info:'Village of Stoumont in the Amblève valley.'},
  'La Gleize':{ll:[50.4150,5.8500], wiki:'https://en.wikipedia.org/wiki/La_Gleize', info:'Amblève-valley village of Stoumont, known for its WWII history (a preserved King Tiger tank).'},
  'Trois-Ponts':{ll:[50.3700,5.8730], wiki:'https://en.wikipedia.org/wiki/Trois-Ponts', info:'"Three bridges" — confluence of the Amblève and Salm, on the LBL roads.'},
  'Tiège':{ll:[50.5300,5.8900], wiki:'https://en.wikipedia.org/wiki/Jalhay', info:'Hamlet of Sart/Jalhay on the plateau above Spa.'},
  // major Wallonia cities (t:'City') — searchable anchors to fly to; far-west ones have no Commons data nearby yet
  'Namur':{t:'City', ll:[50.4674,4.8720], wiki:'https://en.wikipedia.org/wiki/Namur', info:'Capital of Wallonia, where the Sambre meets the Meuse beneath its citadel.'},
  'Liège':{t:'City', ll:[50.6451,5.5736], wiki:'https://en.wikipedia.org/wiki/Li%C3%A8ge', info:'Largest city of eastern Wallonia, on the Meuse — start of Liège–Bastogne–Liège.'},
  'Charleroi':{t:'City', ll:[50.4109,4.4447], wiki:'https://en.wikipedia.org/wiki/Charleroi', info:'Former industrial hub on the Sambre, heart of the Pays Noir.'},
  'Mons':{t:'City', ll:[50.4542,3.9563], wiki:'https://en.wikipedia.org/wiki/Mons', info:'Capital of Hainaut, a UNESCO-listed belfry town.'},
  'Tournai':{t:'City', ll:[50.6071,3.3892], wiki:'https://en.wikipedia.org/wiki/Tournai', info:'Among the oldest cities in Belgium, on the Scheldt near the French border.'},
  'Arlon':{t:'City', ll:[49.6839,5.8113], wiki:'https://en.wikipedia.org/wiki/Arlon', info:'Capital of Luxembourg province, in the far south-east.'},
  'Bastogne':{t:'City', ll:[50.0028,5.7186], wiki:'https://en.wikipedia.org/wiki/Bastogne', info:'Ardennes town famed for the WWII Battle of the Bulge, on the LBL roads.'},
  'Dinant':{t:'City', ll:[50.2605,4.9118], wiki:'https://en.wikipedia.org/wiki/Dinant', info:'Meuse-valley town under a clifftop citadel; birthplace of Adolphe Sax.'},
  'Verviers':{t:'City', ll:[50.5911,5.8625], wiki:'https://en.wikipedia.org/wiki/Verviers', info:'Wool-trade town on the Vesdre, gateway to the Hautes Fagnes.'},
  'Huy':{t:'City', ll:[50.5186,5.2393], wiki:'https://en.wikipedia.org/wiki/Huy', info:'Meuse town below the Mur de Huy, the Flèche Wallonne finish.'},
  'Marche-en-Famenne':{t:'City', ll:[50.2275,5.3450], wiki:'https://en.wikipedia.org/wiki/Marche-en-Famenne', info:'Hub of the Famenne, between the Condroz and the Ardennes.'},
  'La Roche-en-Ardenne':{t:'City', ll:[50.1827,5.5765], wiki:'https://en.wikipedia.org/wiki/La_Roche-en-Ardenne', info:'Castle town in a bend of the Ourthe, deep in the Ardennes.'},
  'Wavre':{t:'City', ll:[50.7173,4.6122], wiki:'https://en.wikipedia.org/wiki/Wavre', info:'Capital of Walloon Brabant, on the Dyle.'},
  'Nivelles':{t:'City', ll:[50.5977,4.3270], wiki:'https://en.wikipedia.org/wiki/Nivelles', info:'Brabant town around its Romanesque collegiate church.'},
  'Malmedy':{t:'City', ll:[50.4259,6.0283], wiki:'https://en.wikipedia.org/wiki/Malmedy', info:'East-cantons town below the Hautes Fagnes, near the Stavelot roads.'}
};
// Defense in depth: the name is escaped even though today's callers only
// pass RIDE_CITIES constants — if a payload value ever reaches this, it
// must not break out of the attribute or element context.
export const cityLink = name => `<a class="cc-city" data-city="${escPend(name)}">${escPend(name)}</a>`;

// A-Z ordering for the layer list; CATALOG's own order is the catalogue's.
export const CATALOG_AZ=[...CATALOG].sort((a,b)=>a.letter<b.letter?-1:1);

// Letter <-> layer-key, both directions: coverage tiles are addressed by
// letter, the served layers by key.
export const LETTER_KEY={C:'water',D:'services',E:'stays',G:'transit',H:'shelter',I:'scenic',J:'history'};
export const KEY_LETTER={water:'C',services:'D',stays:'E',transit:'G',shelter:'H',scenic:'I',history:'J'};

// Which layers are drawn, and which of the two view modes is showing.
//
// The global default is 'all' (Everything), NOT 'curated'
// (2026-07-27-map-view-mode-default-design.md §2). Curated hides every
// non-curated item on the experiential layers, so on an under-curated region it
// shows a near-empty map while the rail counts hundreds of stays — a real
// new-user trap the owner hit ("Gelderland says 1488 where to sleep but I see
// 0/1488"). The honest default for a region that has not earned a best-of is to
// show the data. resolveInitialMode() below can still open Curated, but only
// when someone has actually said so.
let _mode = 'all';
export const mode = () => _mode;
export function setMode(m){ _mode = m; }

// localStorage key for an ANONYMOUS visitor's manual choice. A logged-in
// rider's choice goes to their profile instead (owner decision: shared devices
// must not leak one person's default to the next), so this key is only ever
// read when CC_PREFS says the viewer has no stored preference of their own.
export const MODE_LS_KEY = 'cc-map-mode';

/** Load-time precedence (2026-07-27-map-view-mode-default-design.md §5):
 *   1. the logged-in rider's saved profile mode, if not 'auto';
 *   2. an anonymous visitor's own earlier choice in localStorage;
 *   3. the ACTIVE region's curated_default, set by a moderator once the region
 *      passed the readiness threshold;
 *   4. Everything.
 * `scope` is the resolved active scope (CCScope.get()) and `registry` the
 * CC_REGIONS rows. Pure apart from the localStorage read; returns a toggle
 * token ('curated' | 'all'), never an enum value. */
export function resolveInitialMode(prefs, scope, registry){
  const p = prefs || {};
  if(p.mapMode === 'curated') return 'curated';
  if(p.mapMode === 'everything') return 'all';
  // Only anonymous visitors fall through to the device: a logged-in rider on
  // 'auto' has deliberately chosen to follow the region.
  if(!p.mapMode || p.mapMode === 'auto'){
    if(!p.authed){
      let stored = null;
      try { stored = localStorage.getItem(MODE_LS_KEY); } catch(e){ /* private mode */ }
      if(stored === 'curated' || stored === 'all') return stored;
    }
  }
  // A single named region can carry the flag; a country/Everywhere/My-area
  // scope spans many regions with no one answer, so it stays on Everything.
  if(scope && scope.kind === 'region' && scope.regionIds && scope.regionIds.length === 1){
    const r = (registry || []).find(x => x.id === scope.regionIds[0]);
    if(r && r.curatedDefault) return 'curated';
  }
  return 'all';
}
