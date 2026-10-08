<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Edit spec — D · Bike services

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** D · Bike services
- **Map depiction:** the service kind's glyph (shop ⚙, station ⚒, pump ⊕; `KindIcons`), colour #6b6f5e
- **Editable:** yes · the `/improve` wizard (add, improve, materialize-on-edit)
- **Lifecycle:** *utility / coverage* — verified (≥ X community confirmations) then shown; **never votable, never best-of** (value is completeness). Lives in **Everything** mode. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
Shops, public repair stations, pumps and e-bike charging — the places riders reach for when something breaks or runs flat.

## Service kinds — shop / station / pump

Every D item carries a `serviceKind ∈ {shop, station, pump}` attribute
(`App\Catalog\ServiceKind`). The split itself — which OSM selectors map to which
kind, and why the distinction is a data fact rather than presentation — is owned
by [../osm-data-architecture.md](../osm-data-architecture.md) §5; this section
records only the edit-flow consequences.

**Stamped by the harvest, editable in the wizard** (owner 2026-09-08: a
rider's own repair stand "should be a hammer and pick", not the shop's cog).
`serviceKind` is stamped by the pipeline: `ServiceKind::fromOsmTags()` at
harvest, with `ServiceKind::fromLegacyLabel()` as the import-time fallback for
older export artifacts (`ImportCatalogCommand`). Every system-filled value has
its wizard field, so the wizard's first field after the name is **Type**, a
keyed select (`CatalogField::selectKeyed()`: stored `shop|station|pump`, shown
as Bike shop / Repair stand / Pump, translated through the field schema's
choice labels), on the edit and the add form alike. On the drawer it is the
Type row (`schemaRows()`). The intake validates the value through the form's
choice list (`serviceKind` is a registry field, not an `AttributeVocabulary`
extra). Pinned by
`ImproveTest::testABikeServiceOffersItsKindAndStoresTheKey`.

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

The default preselects only when a rider adds a NEW place (`ImproveType`,
`add_mode`). On a place that exists with no stored value the select shows its
empty first option, like every optional select in the place forms, so saving
the form to fix something else never stores an answer nobody gave (owner
2026-10-01). The drawer states the assumed `24/7` for an unmanned kind
(below).

**Drawer presentation** (`web/assets/map/drawer.js`, glyphs in `web/assets/map/icons.js`): when a station/pump has no
stored `openingHours`, the drawer states the assumed default as a read-only
"Opening hours · 24/7" value row instead of an "add" prompt (`schemaRows`'
`fixed` option); a stored value always wins. The localized kind label takes
precedence over the raw OSM `t` value for the drawer's Type row + headline, and
each kind renders a distinct marker glyph (shop ⚙, the layer icon, station ⚒,
pump ⊕; `KindIcons` on the server, `SERVICE_GLYPH` in `icons.js`), on both the unverified symbol-layer discs and the
confirmed/curated DOM pins.

## Read view (drawer "current details")
- Type
- Pump
- Tools
- Hours

## Edit form
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Name | input | `[edit]` |
| Type | select(Bike shop / Repair stand / Pump), stored `serviceKind` `shop` / `station` / `pump` | `[OSM]` |
| Website | url | `[edit]` |
| Pump valve | select(Presta + Schrader / Presta only / Schrader only / No pump) | `[OSM]` |
| Opening hours | select(Unknown / 24/7 / See website) | `[edit]` |
| Tools available | input | `[edit]` |
| Still as mapped? | select(As mapped / Out of order / Closed / Not there anymore) | `[tap]` |
| Anything to correct? | textarea | `[edit]` |

> **Opening hours — why a 3-option select, not free text:** specific
> weekly hours change without notice and we can't verify them, so the Commons only
> records what stays true: `24/7`, or `See website` (point riders at the source),
> and defaults to `Unknown`. Same treatment on
> [Q-history-culture](Q-history-culture.md).

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Work stand? | select(Unknown / Yes / No) | `[edit]` |
| Chain tool? | select(Unknown / Yes / No) | `[edit]` |
| E-bike charging? | select(Unknown / Yes / No) | `[edit]` |

### Report a problem
Not built: per-type reasons are not offered. A rider reports a place through the content report ([../content-reports.md](../content-reports.md)).

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Implementation
- **Production:** OSM `amenity=bicycle_repair_station` / `shop=bicycle` /
  `amenity=compressed_air`, served as coverage-cached POIs
  ([../osm-data-architecture.md](../osm-data-architecture.md) §5) + materialize-on-edit
  curation (osm-data-architecture.md §6).

The **Website** field is keyed `web` to reuse the shared "Website" drawer row
(same as Stays).
