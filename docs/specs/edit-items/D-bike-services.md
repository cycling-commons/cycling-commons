<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Edit spec — D · Bike services

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** D · Bike services
- **Map depiction:** ⚙ pin, colour #6b6f5e
- **Edit-item id:** `repair-station-malmedy` in `atlas/demo/edit-items.js`, also the default/fallback edit item when `improve.html` gets no `?item=`
- **Editable:** yes · Frontend demo · 2026-06-18
- **Lifecycle:** *utility / coverage* — verified (≥ X community confirmations) then shown; **never votable, never best-of** (value is completeness). Lives in **Everything** mode. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
Shops, public repair stations, pumps and e-bike charging — the places riders reach for when something breaks or runs flat.

## Service kinds — shop / station / pump

Every D item carries a `serviceKind ∈ {shop, station, pump}` attribute
(`App\Catalog\ServiceKind`). The split itself — which OSM selectors map to which
kind, and why the distinction is a data fact rather than presentation — is owned
by [../osm-data-architecture.md](../osm-data-architecture.md) §5; this section
records only the edit-flow consequences.

**Derivation, never a form field.** `serviceKind` is stamped by the pipeline:
`ServiceKind::fromOsmTags()` at harvest, with `ServiceKind::fromLegacyLabel()`
as the import-time fallback for pre-split export artifacts
(`ImportCatalogCommand`). It is an import-allowed attribute key
(`App\Catalog\Import\AttributeVocabulary`, `'D' => ['serviceKind']`) but never a
rider/curator-editable field. Kind selection on a manual add is **specified,
pending implementation** — deferred until an add-new-bike-service flow exists
(osm-data-architecture.md §5).

**Kind-specific opening-hours default.** The registry field set is kind-aware:
`CatalogFormRegistry::for(ItemType::BikeServices, ?ServiceKind)` — the improve
controller resolves the kind from the item's stored attributes and passes it
through (`ContributeController`, `ImproveType`'s `service_kind` option). The
`openingHours` select (the 3-option `Unknown / 24/7 / See website` vocabulary —
see the rationale note under Fix details below) defaults per kind:

| Kind | `openingHours` default | Rationale |
|---|---|---|
| `shop` (and unknown/absent kind) | `Unknown` | staffed — hours are meaningful and unknown until told (`ServiceKind::hasOpeningHours()`) |
| `station` / `pump` | `24/7` preselected, **overridable** | unmanned — 24/7 is the default assumption, but some stations follow a host building's hours (e.g. inside a library) |

**Drawer presentation** (`web/assets/map/map.js`): when a station/pump has no
stored `openingHours`, the drawer states the assumed default as a read-only
"Opening hours · 24/7" value row instead of an "add" prompt (`schemaRows`'
`fixed` option); a stored value always wins. The localized kind label takes
precedence over the raw OSM `t` value for the drawer's Type row + headline, and
each kind renders a distinct marker glyph (shop ⚙ — the layer icon, station ⚒,
pump ⊕; `SERVICE_GLYPH`), on both the unverified symbol-layer discs and the
confirmed/curated DOM pins.

## Read view (drawer "current details")
- Type
- Pump
- Tools
- Hours

## Edit form  (`improve.html?item=repair-station-malmedy`)
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Name | input | `[edit]` |
| Website | url | `[edit]` |
| Pump valve | select(Presta + Schrader / Presta only / Schrader only / No pump) | `[OSM]` |
| Opening hours | select(Unknown / 24/7 / See website) | `[edit]` |
| Tools available | input | `[edit]` |
| Anything to correct? | textarea | `[edit]` |

> **Opening hours — why a 3-option select, not free text** (2026-07-15): specific
> weekly hours change without notice and we can't verify them, so the Commons only
> records what stays true — `24/7`, or `See website` (point riders at the source) —
> and defaults to `Unknown`. Migration `Version20260715120000` reset every
> previously-stored free-text value (letters D and J) to `Unknown`. Same treatment
> on [J-history-culture](J-history-culture.md).

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Work stand? | select(Unknown / Yes / No) | `[edit]` |
| Chain tool? | select(Unknown / Yes / No) | `[edit]` |
| E-bike charging? | select(Unknown / Yes / No) | `[edit]` |

### Report a problem
- Gone / closed · Wrong location · Wrong details · Duplicate

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Demo:** registry entry `repair-station-malmedy` in `atlas/demo/edit-items.js` (hand-picked fixture data).
- **Production:** OSM `amenity=bicycle_repair_station` / `shop=bicycle` /
  `amenity=compressed_air`, served as coverage-cached POIs
  ([../osm-data-architecture.md](../osm-data-architecture.md) §5) + materialize-on-edit
  curation (§6).

## Change note (2026-07-14)
Added a **Website** field (`web`, url) to the Fix-details form — a shop/repair place usually has its own site. Keyed `web` to reuse the shared "Website" drawer row (same as Stays).
