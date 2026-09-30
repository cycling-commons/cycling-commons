// SPDX-License-Identifier: AGPL-3.0-only
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

/* The towns the demo routes name, and the instant quick-picks the town search
   lists before Photon answers (docs/specs/map-and-search.md §7.2). Each
   carries its OpenStreetMap element, the ref a Photon hit carries, so it
   opens the one town card every town gets (docs/specs/map-and-search.md
   §6.5): Wikipedia and Wikidata in the reader's language, the curators' own
   text, and the "!" that reports it. `ll` is the point the card lands on and
   measures "nearby" from. */
export const CITIES = {
  'Spa':{ll:[50.4920,5.8636], osm:'relation/2422528'},
  'Stavelot':{ll:[50.3957,5.9300], osm:'relation/2409290'},
  'Vielsalm':{ll:[50.2833,5.9167], osm:'relation/2449235'},
  'Sankt Vith':{ll:[50.2811,6.1267], osm:'relation/2433430'},
  'Francorchamps':{ll:[50.4532,5.9528], osm:'relation/19071311'},
  'Coo':{ll:[50.3892,5.8847], osm:'node/12804713960'},
  'Sart':{ll:[50.5174,5.9335], osm:'relation/19255163'},
  'Jalhay':{ll:[50.5560,5.9700], osm:'relation/2400889'},
  'Stoumont':{ll:[50.4050,5.8000], osm:'relation/2422527'},
  'Chevron':{ll:[50.3823,5.7315], osm:'node/737588485'},
  'La Gleize':{ll:[50.4150,5.8500], osm:'relation/19160156'},
  'Trois-Ponts':{ll:[50.3700,5.8730], osm:'relation/2436185'},
  'Tiège':{ll:[50.5212,5.9097], osm:'node/860608364'},
  'Namur':{t:'City', ll:[50.4674,4.8720], osm:'relation/1405439'},
  'Liège':{t:'City', ll:[50.6451,5.5736], osm:'relation/1681788'},
  'Charleroi':{t:'City', ll:[50.4109,4.4447], osm:'relation/2113725'},
  'Mons':{t:'City', ll:[50.4542,3.9563], osm:'relation/1949374'},
  'Tournai':{t:'City', ll:[50.6071,3.3892], osm:'relation/2162970'},
  'Arlon':{t:'City', ll:[49.6839,5.8113], osm:'relation/2431399'},
  'Bastogne':{t:'City', ll:[50.0028,5.7186], osm:'relation/2426390'},
  'Dinant':{t:'City', ll:[50.2605,4.9118], osm:'relation/2268360'},
  'Verviers':{t:'City', ll:[50.5911,5.8625], osm:'relation/2396836'},
  'Huy':{t:'City', ll:[50.5186,5.2393], osm:'relation/2002638'},
  'Marche-en-Famenne':{t:'City', ll:[50.2275,5.3450], osm:'relation/2437396'},
  'La Roche-en-Ardenne':{t:'City', ll:[50.1827,5.5765], osm:'relation/2566321'},
  'Wavre':{t:'City', ll:[50.7173,4.6122], osm:'relation/224757'},
  'Nivelles':{t:'City', ll:[50.5977,4.3270], osm:'relation/1149724'},
  'Malmedy':{t:'City', ll:[50.4259,6.0283], osm:'relation/2409000'}
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
