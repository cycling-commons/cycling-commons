// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* A–K layer table, towns index, active layers, view mode.
   @see docs/specs/map-and-search.md §4 */
import { LAYER_L10N } from './i18n.js';
import { escPend } from './util.js';

// Draw order (render.js walks this). Reading order is catalogUtility/catalogVotable.
// `votable` mirrors ItemType::isVotable(); `exp` is the Curated-mode hide flag — they disagree (A vs K).
/* The category glyph per letter comes from ItemType::iconSet() (window.CC_TYPE_ICONS,
   injected by the map page): one set for the map, the desks and the account pages. */
export const TYPE_ICON = l => (((window.CC_TYPE_ICONS || {})[l] || {}).glyph) || '\u2022';
export const TYPE_SVG = l => (((window.CC_TYPE_ICONS || {})[l] || {}).svg) || '';
export const CATALOG = [
  /* A · Road surface is an OVERLAY, not a data layer: it answers "what is
     under my tyres on this road", which is a property of the map rather than a
     set of places on it. It had a row in Data layers AND a Surfaces switch in
     Map overlays, one word apart, and the two quietly handed work to each other
     through the curated-ref dedupe: a road could fall between them and vanish
     (owner-reported 2026-08-31). One control now, the overlay switch.
     `overlay: true` keeps it out of the layer list while render() still walks
     it. */
  { key:'surface', letter:'A', label:LAYER_L10N.surface||'Road surface', color:'#4E8C84', icon:TYPE_ICON('A'), kind:'surface', exp:true, votable:false, overlay:true, features:[] }
  ,{ key:'climbs', letter:'N', label:LAYER_L10N.climbs||'Climbs', color:'#6A2C8F', icon:TYPE_ICON('N'), kind:'point', exp:true, votable:true, features:[] }
  ,{ key:'water', letter:'B', label:LAYER_L10N.water||'Water & food', color:'#8FB6A8', icon:TYPE_ICON('B'), kind:'point', exp:false, votable:false, features:[] }
  ,{ key:'toilets', letter:'C', label:LAYER_L10N.toilets||'Public toilets', color:'#4E6E8C', icon:TYPE_ICON('C'), kind:'point', exp:false, votable:false, features:[] }
  ,{ key:'services', letter:'D', label:LAYER_L10N.services||'Bike services', color:'#6b6f5e', icon:TYPE_ICON('D'), kind:'point', exp:false, votable:false, features:[] }
  ,{ key:'stays', letter:'O', label:LAYER_L10N.stays||'Where to sleep', color:'#B5532E', icon:TYPE_ICON('O'), kind:'point', exp:true, votable:true, features:[] }
  ,{ key:'hazards', letter:'E', label:LAYER_L10N.hazards||'Hazards & conditions', color:'#C8923A', icon:TYPE_ICON('E'), kind:'point', exp:false, votable:false, features:[]}
  ,{ key:'transit', letter:'F', label:LAYER_L10N.transit||'Getting there', color:'#3E7D8C', icon:TYPE_ICON('F'), kind:'point', exp:false, votable:false, features:[] }
  ,{ key:'shelter', letter:'G', label:LAYER_L10N.shelter||'Shelter', color:'#9A8FB6', icon:TYPE_ICON('G'), kind:'point', exp:false, votable:false, features:[] }
  ,{ key:'scenic', letter:'P', label:LAYER_L10N.scenic||'Scenic views', color:'#2C5440', icon:TYPE_ICON('P'), kind:'point', exp:true, votable:true, features:[] }
  ,{ key:'history', letter:'Q', label:LAYER_L10N.history||'History & culture', color:'#6E5849', icon:TYPE_ICON('Q'), kind:'point', exp:true, votable:true, features:[] }
  ,{ key:'experience', letter:'R', label:LAYER_L10N.experience||'Recommended routes', color:'#FF5A1F', icon:TYPE_ICON('R'), kind:'line', exp:false, votable:true, features:[] }
];

export const active = new Set(CATALOG.map(l => l.key));
export const layerByKey = Object.fromEntries(CATALOG.map(l => [l.key, l]));

export function routePathById(id){
  const layer=layerByKey['experience']; if(!layer) return null;
  const f=layer.features.find(x=>String(x.id)===String(id));
  return f && f.geom && f.geom.path ? f.geom.path : null;
}

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
// docs/specs/security-architecture.md §4.2 — fail-closed if a payload name ever reaches this.
export const cityLink = name => `<a class="cc-city" data-city="${escPend(name)}">${escPend(name)}</a>`;

// Functions, not constants: the curator-only pending layer is pushed into CATALOG at runtime.
/* Everything that has a ROW in the layer list. An overlay has its own switch
   in Map overlays instead, so select-all must not reach it: toggling it from
   here would turn our items on while the overlay switch still read Off, which
   is the half-on state the one-control change exists to make impossible. */
export const catalogRows = () => CATALOG.filter(l => !l.overlay);
export const catalogUtility = () => CATALOG.filter(l => !l.votable && !l.pendingLayer && !l.overlay);
export const catalogVotable = () => CATALOG.filter(l => l.votable && !l.pendingLayer);
export const catalogModeration = () => CATALOG.filter(l => l.pendingLayer);

export const LETTER_KEY={B:'water',C:'toilets',D:'services',F:'transit',G:'shelter',O:'stays',P:'scenic',Q:'history'};
export const KEY_LETTER={water:'B',toilets:'C',services:'D',transit:'F',shelter:'G',stays:'O',scenic:'P',history:'Q'};

// docs/specs/map-and-search.md §4.2 — default Everything; Curated on an under-curated region is a near-empty map.
let _mode = 'all';
export const mode = () => _mode;
export function setMode(m){ _mode = m; }

// Anonymous visitor only; a logged-in rider's choice lives on the profile (shared-device).
export const MODE_LS_KEY = 'cc-map-mode';

/** docs/specs/map-and-search.md §4.2 — profile, then anonymous localStorage, then region defaultMode, then Everything. */
export function resolveInitialMode(prefs, scope, registry){
  const p = prefs || {};
  if(p.mapMode === 'curated') return 'curated';
  if(p.mapMode === 'confirmed') return 'confirmed';
  if(p.mapMode === 'everything') return 'all';
  // Logged-in 'auto' follows the region; only anonymous visitors read the device.
  if(!p.mapMode || p.mapMode === 'auto'){
    if(!p.authed){
      let stored = null;
      try { stored = localStorage.getItem(MODE_LS_KEY); } catch(e){ /* private mode */ }
      if(stored === 'curated' || stored === 'confirmed' || stored === 'all') return stored;
    }
  }
  if(scope && scope.kind === 'region' && scope.regionIds && scope.regionIds.length === 1){
    const r = (registry || []).find(x => x.id === scope.regionIds[0]);
    const m = r && r.defaultMode;
    if(m === 'curated' || m === 'confirmed') return m;
  }
  return 'all';
}
