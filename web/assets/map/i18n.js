// SPDX-License-Identifier: AGPL-3.0-only
/* Locale bundle + value translation for the map front end.
   A leaf: imports nothing. Pure reads of injected globals plus lookup helpers. */

// Locale bundle from MapController::mapI18n (window.CC_I18N); English fallbacks
// so the map still works standalone. `D` is the drawer namespace; tpl() fills {name}.
export const I18N = window.CC_I18N || {};
export const LAYER_L10N = I18N.layers || {};
export const D = I18N.d || {};
export const tpl = (s, vars) => String(s).replace(/\{(\w+)\}/g, (m, k) => vars[k] != null ? vars[k] : m);
// Canonical stored value → localized label, merged over every field's choices
// (headlines, difficulty badge, surface mix). Per-field lookups stay exact.
export const VALUE_TR = {};
Object.values(window.CC_FIELD_SCHEMA || {}).forEach(fs => (fs || []).forEach(f => { if (f.choices) Object.assign(VALUE_TR, f.choices); }));
export const trVal = v => VALUE_TR[v] || v;
// srcType (ItemSource) → drawer "Source ·" label, so a rider-added item never
// reads as OpenStreetMap just because it lives in a bulk-OSM layer.
const SOURCE_LABELS = {
  osm:'OpenStreetMap', wikidata:'Wikidata',
  // `manual` is a SeedManualCatalogCommand row — stays in RIDER_SOURCES so it
  // never falls back to OSM, but the label must not claim a rider added it.
  auto:D.srcAuto||'Derived by the pipeline', user:D.srcRider||'Rider-contributed', manual:D.srcManual||'Hand-curated',
  // Says HOW it arrived, never that anything was checked: the server never saw
  // the ride file (docs/specs/moderation-and-contribution.md (Scout intake)).
  scout:D.srcScout||'Tagged while riding, with Scout'
};
export const sourceLabel = raw => SOURCE_LABELS[raw] || null;
/* Rider provenances — include `scout`. Harvested/derived rows keep their citation. */
const RIDER_SOURCES = new Set(['user', 'manual', 'scout']);
export const isRiderSource = raw => RIDER_SOURCES.has(raw);
// Climb difficulty 1-5 → localized label; index 0 is unset.
export const DIFF_LABELS=['','Easy','Moderate','Challenging','Hard','Very hard'].map(l=>l?trVal(l):l);
export const CC_SEASON_LABEL=Object.assign({spring:'Spring',summer:'Summer',autumn:'Autumn',winter:'Winter'}, I18N.seasons||{});
export const CC_BIKE_LABEL=I18N.bikes||{};
