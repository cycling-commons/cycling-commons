// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
/* Locale bundle + value translation for the map front end
.

   A leaf: imports nothing, so it evaluates before every other map module.
   Everything here is a pure read of the injected globals plus lookup helpers —
   no DOM, no MapLibre — which is what makes it requirable from node --test
   alongside web/tests/js/scope.test.cjs. */

// Locale bundle injected by the map shell (MapController::mapI18n via
// window.CC_I18N); every lookup keeps its English fallback so the map still
// works standalone. `D` is the drawer namespace; tpl() fills {name} slots.
export const I18N = window.CC_I18N || {};
export const LAYER_L10N = I18N.layers || {};
export const D = I18N.d || {};
export const tpl = (s, vars) => String(s).replace(/\{(\w+)\}/g, (m, k) => vars[k] != null ? vars[k] : m);
// Canonical stored value → localized label, merged over every field's
// choices map (CC_FIELD_SCHEMA) — for values rendered outside schemaRows
// (headlines, difficulty badge, surface mix). Per-field lookups stay exact.
export const VALUE_TR = {};
// One of the two module-scope side effects the split allows (§4.2): a pure
// derivation of an already-present global, single-assignment, needed by every
// importer before any init() runs.
Object.values(window.CC_FIELD_SCHEMA || {}).forEach(fs => (fs || []).forEach(f => { if (f.choices) Object.assign(VALUE_TR, f.choices); }));
export const trVal = v => VALUE_TR[v] || v;
// C1-T4 (spec W6): every served feature now carries `srcType` — the item's
// real ItemSource enum value (osm/pivot/wikidata/auto/user/manual), from
// CatalogProvider. This maps it to the plain-English label shown on the
// drawer's "Source ·" line, so a rider-added/edited item never reads as
// OpenStreetMap just because it happens to live in a bulk-OSM layer.
const SOURCE_LABELS = {
  osm:'OpenStreetMap', pivot:'Tourisme Wallonie (CC-BY)', wikidata:'Wikidata',
  auto:D.srcAuto||'Derived by the pipeline', user:D.srcRider||'Rider-contributed', manual:D.srcRider||'Rider-contributed',
  // Says HOW it arrived, never that anything was checked: the server never saw
  // the ride file (Dated/2026-08-09-scout-cc-tagger-plan.md §3). "Tagged while
  // riding" is the true and useful thing; "verified ride" would be neither.
  scout:D.srcScout||'Tagged while riding, with Scout'
};
export const sourceLabel = raw => SOURCE_LABELS[raw] || null;
/* Which provenances are a RIDER's, so a drawer never credits OpenStreetMap for
   somebody's own contribution. Named as a set rather than tested inline,
   because it was tested inline in four places and every one of them read
   `srcType==='user'||srcType==='manual'` — which silently omitted `scout`,
   the source that a tag dropped while riding actually carries. The owner's own
   scenic addition therefore read "Source · OpenStreetMap (tourism=viewpoint /
   natural=peak / waterway=waterfall)": the per-layer OSM fallback, on a place
   OSM has never heard of. Harvested and derived rows (osm/pivot/wikidata/auto)
   keep their real citation. */
const RIDER_SOURCES = new Set(['user', 'manual', 'scout']);
export const isRiderSource = raw => RIDER_SOURCES.has(raw);
// Climb difficulty 1-5 → localized label (the drawer's difficulty badge and the
// route filter share this scale); index 0 is the empty "unset" slot.
export const DIFF_LABELS=['','Easy','Moderate','Challenging','Hard','Very hard'].map(l=>l?trVal(l):l);
export const CC_SEASON_LABEL=Object.assign({spring:'Spring',summer:'Summer',autumn:'Autumn',winter:'Winter'}, I18N.seasons||{});
export const CC_BIKE_LABEL=I18N.bikes||{};
