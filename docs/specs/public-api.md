<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Public API — endpoint contract and integration guide

**Status:** canonical reference · **Audience:** contributors to Cycling Commons,
and third-party developers integrating the API · some numbers and the write-auth
model are marked *proposed* pending owner decisions (§9).

This document owns the **technical shape** of the public read API: the data we
serve, the two transports (data-only vector tiles and JSON/GeoJSON REST), the
auth and metering mechanics, versioning, attribution-in-responses, and a worked
consumer integration. It is the endpoint contract that
[api-strategy.md](api-strategy.md) ("any future endpoint contract belongs
alongside osm-data-architecture.md §7") and
[osm-data-architecture.md §7](osm-data-architecture.md) defer to.

It **consumes unchanged** and does not restate:

- **Licensing** (data ODbL/DbCL, media CC BY-SA, code PolyForm Shield):
  [osm-data-architecture.md §3](osm-data-architecture.md).
- **Reference-only vs. hydrated policy** and **access terms** (API-only,
  scraping prohibited): [osm-data-architecture.md §7](osm-data-architecture.md).
- **Pricing tiers and business posture** (self-serve, passive BD, what we charge
  for): [api-strategy.md §4–§5](api-strategy.md).
- **Account/Commons boundary** (what the API may never expose):
  [security-architecture.md](security-architecture.md),
  [account-and-auth.md](account-and-auth.md).

Write/contribution (routing third-party edits back through moderation) is
**designed here but scoped phase-2** — the read API ships first; consumer apps
bringing new information in comes later.

---

## 1. What we serve — the public data model

The API exposes the **Commons layer only**. The account layer — email, IP logs,
password hashes, moderation internals — is **never** reachable through any
endpoint or tile ([osm-data-architecture.md §8](osm-data-architecture.md)). This
is enforced at the serialization boundary: public responses are built from
dedicated public DTOs that carry no personal data, not from internal entities —
and below that, at the database itself: the API reads through a dedicated
Postgres role that has no grant on any account table
([public-api-personal-data-boundary.md](public-api-personal-data-boundary.md)
owns the full enforcement stack).

| Product | Content | Category | Default response |
|---------|---------|----------|------------------|
| **Coverage POIs** | catalogue items C, D, E, G, H, I, J ([osm-data-architecture.md §5](osm-data-architecture.md)) | 1 (community) + 2 (curated) | reference-only: `osm_ref` + our added fields |
| **Curated / own items** | our enrichment, verification state, provenance | 2 | full (it is ours) |
| **Routes** | the K route domain — geometry, surfaces, ratings ([route-domain.md](route-domain.md)) | 3 | full + GPX |
| **Climbs (B), Hazards (F)** | our own generated data | 3 | full |
| **Coverage polygons / regions** | administrative regions + coverage/adoption stats | ours | full |
| **Catalogue metadata** | letters A–L, `serviceKind`, vocabularies, tiers | ours | full |

**Reference-only is the default** ([osm-data-architecture.md
§7](osm-data-architecture.md)): coverage responses return the `osm_ref`s we hold
coverage for plus our additions (category 2) and our own data (category 3) in
full. They do **not** republish OSM's tags or geometry as ours; a consumer
hydrates OSM detail from the `osm_ref` against OSM directly.

Because Cycling Commons is ODbL, an **optional hydrated response** is permitted
and offered as a developer convenience: `?hydrate=osm` merges our added fields
with the cached OSM subset we hold ([osm-data-architecture.md
§5](osm-data-architecture.md)). All OSM-derived responses — reference-only or
hydrated — carry `© OpenStreetMap contributors` (§5).

Curated and community records are **deduplicated by `osm_ref`** so a curated OSM
object appears once, as curated, and every feature is marked with its `tier`
(community vs. curated/verified) so consumers can rank and style accordingly
([osm-data-architecture.md §8](osm-data-architecture.md)).

## 2. How we serve it — two transports

The API is split by workload. Dense ambient map layers travel as **vector
tiles** (the only viable transport for millions of coverage points); precise,
low-volume lookups — search, single-item detail, metadata, write — travel as
**REST**. The split mirrors the internal architecture (`coverage.pmtiles` +
`/map/coverage/*`), so the public API is largely a keyed, versioned,
contract-stable face over artifacts we already build, not a new subsystem.

### 2.1 Data-only vector tiles

Our tiles hold **data, never styling**. A tileset carries vector *features* —
point/line/polygon geometry plus attribute properties — and nothing about
colour, icon, width, or z-order. "How the map looks" lives in the consumer's
MapLibre style, which binds style layers to our source-layers and paints them
however it wants. This is what makes the tiles reusable across consumers with
different looks (§7).

**Artifacts.** `coverage.pmtiles` (coverage POIs + coverage polygons) on the
Cycling Commons tiles bucket behind the nginx-fronted tiles host, served by HTTP byte-range;
the artifact and infra already exist ([coverage-provider.md](coverage-provider.md),
[osm-data-architecture.md §5](osm-data-architecture.md)). Route tiles
(`routes.pmtiles`) exist since 2026-08-13 and the PoC serves them to external
consumers unkeyed, discovered through `/v1/map-config` (see the PoC note in
§2.2); whether they stay unkeyed at v1 remains the §9 decision. Browsers never
read the storage bucket directly: every tile URL points at the nginx-fronted
tiles host, where caching and cross-origin headers are controlled.

**The tile schema is the contract.** Each tileset publishes a **TileJSON**
(`minzoom`, `maxzoom`, `bounds`, `attribution`, and `vector_layers` with each
source-layer's `fields`). The TileJSON *is* the stable interface a consumer's
style binds to. Initial source-layers:

| Source-layer | Geometry | Key fields |
|--------------|----------|------------|
| `coverage_poi` | point | `letter`, `serviceKind`, `osm_ref`, `tier`, `name` |
| `coverage_region` | polygon | `region_id`, `name`, coverage/adoption stats |
| `routes` *(when built)* | line | `route_id`, `name`, `surface`, rating summary |

Field names and source-layer names are stable within a tile schema version
(§6). We also publish an **optional reference MapLibre style JSON** — our exact
look — that a consumer may adopt whole or override per layer. The style is a
convenience; it is never part of the data contract.

### 2.2 REST (JSON / GeoJSON)

All REST lives under a `/v1` prefix. Spatial collections are GeoJSON
`FeatureCollection`s; metadata is plain JSON. Endpoints re-expose existing
internal behaviour (`/map/coverage/*`, `catalog.json`) behind the public
contract.

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/v1/map-config` | **implemented (PoC)**, the consumer bootstrap: routes-tiles URL + source-layer contract, route style groups, category table (A–M), attribution string |
| GET | `/v1/catalog` | item-type catalogue (letters A–L, `serviceKind`, vocabularies, tiers) — the labels and rendering metadata a consumer needs |
| GET | `/v1/search?bbox=&q=&letter=&tier=&limit=&cursor=` | search / browse coverage → GeoJSON `FeatureCollection` (reference-only) |
| GET | `/v1/items/{id}` (`?hydrate=osm`) | single-item detail: our enrichment, provenance, verification state |
| GET | `/v1/coverage/counts?bbox=` | per-letter counts for chips and summaries |
| GET | `/v1/regions` · `/v1/regions/{id}` | coverage polygons + stats (the REST twin of the `coverage_region` tile layer) |
| GET | `/v1/regions/{id}/best` | the curated best-of for a region — the public face of the rankings domain ([route-domain.md](route-domain.md)); response composition is an open decision (§9) |
| GET | `/v1/routes?bbox=` · `/v1/routes/{id}` · `/v1/routes/{id}.gpx` | the K route domain + GPX download |
| POST | `/v1/contributions` **(phase-2, §8)** | write path → the existing submission / moderation loop |

**PoC status (2026-08-18).** Two endpoints are implemented and live in the
app: `/v1/map-config` (routes tiles, coverage tiles, category table with the
Best of flag, attribution) and the `/v1/search` subset `bbox` + optional
`letter` + optional `tier` + `limit` (`q`/`hydrate`/`cursor` stay draft; `bbox`
spans at most 10x10 degrees; `letter` widened to A-M for M · public toilets;
absent letter = all letters in one response). The config carries enough for a
consumer to reproduce the map's three view modes (Best of / Confirmed /
Everything, §2.1 tiles + the tier filter + the `bestOf` category flag). Both are open read-only (no
keys yet, `security: []` in the OpenAPI), CORS `Access-Control-Allow-Origin: *`
(`PublicApiCorsSubscriber`), per-IP `public_api_read` limiter (120/min, own
pool), ETag + `public` caching with the explicit `^/v1/` PUBLIC_ACCESS entry in
security.yaml. Responses are built by `Api\V1\PublicItemsProvider` through the
`ItemFeature` DTO: the DTO half of the
[personal-data boundary](public-api-personal-data-boundary.md) is enforced, the
dedicated Postgres role half stays deferred. The consumer-facing explanation
lives at `wiki/developers/api/`.

**Conventions.** `bbox=minLon,minLat,maxLon,maxLat`. Collections paginate by
opaque `cursor` (not offset), capped by `limit`. Coordinates are WGS84
(EPSG:4326). Errors are JSON `{ error, message }` with conventional HTTP status
codes. Spatial collections are GeoJSON `FeatureCollection`s carrying three
foreign members: `licence`, `attribution`, and `next_cursor` (absent on the
last page). A hydrated response merges the cached OSM subset under each
feature's `properties.osm`.

### 2.3 Machine-readable contract

The normative machine-readable form of this REST surface is the design-first
OpenAPI 3.1 document at `web/public/api/openapi.yaml`, rendered as the
interactive reference at `/developers/api` (Redoc, self-hosted page,
English-only). The document is authored before the implementation — endpoint-
or field-level changes land in both places, and
`tests/Smoke/ApiReferenceTest.php` pins the `/developers` teaser page to the
contract so they cannot drift.

The developer-facing plain-language explanation (the two transports, both PoC
endpoints with worked requests, and a full consumer walk-through) lives on the
wiki under `wiki/developers/api/` (published at
wiki.cyclingcommons.org/developers/api/). The wiki teaches; this spec and the
OpenAPI document remain the contract of record.

## 3. Auth & metering — two mechanisms

The transport split forces two enforcement paths. A single issued key carries
both entitlements; how the key is checked differs by transport. Keys are
**self-serve** — a developer signs up in the Commons account shell and generates
a key without any sales contact ([api-strategy.md §5](api-strategy.md)).

- **REST.** App key in `Authorization: Bearer <key>` (or `?key=` for
  environments that cannot set headers). Metered per call at the application
  layer; responses carry `X-RateLimit-*` and monthly `X-Quota-*` headers. Quota
  ceilings map to the tiers in [api-strategy.md §4](api-strategy.md).
- **Tiles.** Key embedded in the tile URL (`.../{key}/coverage.pmtiles`) with a
  **referer allowlist** to deter key theft, metered as byte-range/tile requests
  at the **CDN edge**. A PMTiles byte-range cannot be gated in application code,
  so this is deliberately a separate, edge-enforced path — the established
  pattern for keyed tile products. Tile requests count toward the same monthly
  tier as a "requests" metric ([api-strategy.md §4](api-strategy.md)).

**Write** needs more than an app key (§8): a contribution must be attributable
to a Cycling Commons account for the moderation loop, so it requires a
user-scoped token, not just the consuming app's key.

## 4. Reference-only vs. hydrated — response detail

Restates nothing; points at the owning policy. The default is **reference-only**
for operational reasons (freshness, bandwidth, clean provenance), not a licence
constraint. The optional **hydrated** response (`?hydrate=osm`) is permitted
because we are ODbL and is offered as a convenience. The full rationale and the
constraint that OSM detail is otherwise obtained from OSM directly live in
[osm-data-architecture.md §7](osm-data-architecture.md).

## 5. Attribution in responses

Every OSM-derived tile and REST response carries `© OpenStreetMap contributors`;
Commons data carries `© Cycling Commons contributors (ODbL)`. Attribution is
delivered three ways so a consumer cannot miss it:

- TileJSON `attribution` (MapLibre renders it in the map's attribution control).
- An `X-Attribution` response header on REST responses.
- A documented **required attribution string** consumers must display, published
  alongside this contract.

Per [api-strategy.md §7](api-strategy.md) the reward for compliance is
visibility and goodwill; no attribution-policing machinery is built now, and
enforcement is reserved case-by-case.

## 6. Versioning & stability

- **REST** is versioned by the `/v1` path prefix. Additive changes (new
  endpoints, new optional fields) stay within `v1`. A breaking change ships as
  `/v2`, with `v1` kept through a deprecation window and `Sunset` headers on the
  deprecated routes.
- **Tiles** are versioned by the TileJSON `version` plus stable source-layer and
  field names (§2.1). A breaking schema change ships as a **new tileset URL**
  (e.g. a v2 artifact served alongside the v1 `coverage.pmtiles` through the
  deprecation window), so a consumer's bound style layers never break silently
  under them.

## 7. Consumer integration — worked example

A typical consumer runs MapLibre and already stacks a basemap, its own
administrative polygons, and its own points of interest on one map. Adding the
Cycling Commons layer is additive:

1. **Get an app key** — self-serve in the Commons account shell (§3).
2. **Add the data source** to the consumer's existing MapLibre style, beside its
   basemap and its own sources:

   ```js
   sources: {
     cc-coverage: { type: 'vector',
                    url: 'pmtiles://tiles.cyclingcommons.org/{key}/coverage.pmtiles' }
   }
   ```

   Read the TileJSON to discover source-layers and fields (§2.1).
3. **Add style layers** binding to `coverage_poi` / `coverage_region`. The
   consumer either authors its own look or imports the CC reference style and
   overrides per layer:

   ```js
   { id: 'cc-bike-services', source: 'cc-coverage', 'source-layer': 'coverage_poi',
     filter: ['==', ['get', 'letter'], 'D'],
     type: 'symbol', layout: { 'icon-image': 'app-bike-icon' } }
   ```
4. **Wire REST for interaction.** On map click or search, call
   `GET /v1/search` and `GET /v1/items/{id}` and render the result in the
   consumer's own drawer UI — the tiles draw the ambient layer, REST hydrates
   the detail.
5. **Show attribution.** Add the required Cycling Commons + OpenStreetMap
   attribution strings to the map's attribution control (§5).
6. **(Phase-2) Contribute back.** When a consumer's user adds or edits a spot
   that belongs in the Commons, `POST /v1/contributions` routes it into our
   moderation loop (§8).

## 8. Write / contribution API — phase-2 design

The write path (built later, "as frictionless as possible",
[api-strategy.md §6](api-strategy.md)) routes a third-party submission through
the **existing** contribution → moderation → retention machinery
([moderation-and-contribution.md](moderation-and-contribution.md)); it adds only
the API entry point, not a new lifecycle.

`POST /v1/contributions` accepts a submission for a catalogue item (a new item,
or an edit to an existing `osm_ref` / item id) and opens a submission exactly as
the in-app improve form does, subject to the same materialize-on-edit and
moderation rules ([osm-data-architecture.md §6](osm-data-architecture.md)).

**Decided — write-auth model (2026-07-31): per-app key + app-scoped author
reference.** The end user of a consuming app never needs a Commons account;
the app is the authenticated, accountable party. This extends the account
layer's identity model ([account-and-auth.md §9](account-and-auth.md) — the
stable opaque identifier *is* the identity; the display name is a non-unique
label) across the API boundary. It supersedes both models previously listed
here: OAuth user-delegation demanded a Commons account we explicitly do not
want to require, and a single per-app pseudo-author had no per-author
provenance — the app-scoped reference below restores exactly that.

- **The app authenticates.** Writes require a write-scoped key issued only to
  **registered partner apps** (an `api_app` registry: name, key hash, scopes,
  status) under a partner agreement covering consent enforcement,
  takedown/attribution-change relay, and moderation cooperation.
- **The author is an app-scoped reference.** Every contribution carries
  `author_ref` — an opaque, stable, per-user token the app generates (e.g. an
  HMAC of its internal user id). We cannot resolve it to a person and the app
  never sends us who it is; but it gives moderation continuity ("this
  author's 3rd contribution, 2 approved"), per-author rate limits, and
  per-author blocking without blocking the whole app.
- **The name is optional and non-unique.** `author_name` is a display string
  the end user chose to share, sanitized like any stranger-authored free text
  ([security-architecture.md §4](security-architecture.md)); absent, the
  contribution renders anonymous.
- **Consent travels with the call.** The app presents the same consent
  contract the site enforces (own work · CC BY-SA 4.0 for media
  ([photo-uploads.md §4](photo-uploads.md)); ODbL for data) and asserts it
  with the contract version in the request; the consent record is stored
  keyed to `api_app_id` + `author_ref`. The partner agreement is what makes
  that assertion binding.
- **Storage: a second origin, not a second lifecycle.** `submission`, media
  and consent rows gain `api_app_id` + `external_author_ref` +
  `external_author_name`; `user_id` becomes nullable with an
  exactly-one-origin CHECK (Commons user XOR partner app). Same queue, same
  retention, same materialize-on-edit rules; moderators see "via app X ·
  author #a1b2 (2 approved before)" plus the optional shared name. The
  external author fields sit behind the personal-data fence like the rest of
  the table ([public-api-personal-data-boundary.md
  §1.3](public-api-personal-data-boundary.md)).
- **Attribution resolves at render time, so it stays revocable**
  ([photo-uploads.md §5d](photo-uploads.md)): a user who deletes their
  account in the partner app has the app relay it; we blank the stored name
  and every already-downloaded copy of a file retroactively resolves
  anonymous — the same deletion promise Commons accounts get.
- **Abuse controls, outermost first:** per-app quotas → per-`author_ref`
  limiters → the moderation queue itself → key suspension as the big hammer.

## 9. Open decisions (pending owner)

- **Route tiles at v1** (§2.1): ship `routes.pmtiles` in v1, or keep routes
  REST/GeoJSON-only until a later version. *Resolved for the PoC (2026-08-18):
  the artifact exists and is served unkeyed via `/v1/map-config`; the open
  half is whether v1 keys it.*
- **Key/URL scheme for tiles** (§3): key path segment vs. signed URL, and the
  referer-allowlist policy.
- **Best-of response composition** (§2.2): what `/v1/regions/{id}/best`
  returns (routes only, or routes + top-voted items per letter) and how it
  relates to the in-app rankings pages. The endpoint itself is committed — it
  is advertised on `/developers` (aligned 2026-07-30).
- Pricing numbers, billable-unit definition, and bulk-export reconciliation are
  **inherited from [api-strategy.md §10](api-strategy.md)** and not reopened
  here.

## 10. Relationship to other specs

This is the technical contract layer: it sits **beneath**
[api-strategy.md](api-strategy.md) (business and access posture) and **beside**
[osm-data-architecture.md §7](osm-data-architecture.md) (data-serving policy),
and consumes the licensing, reference-only policy, pricing tiers, and account
boundary from those documents unchanged. Endpoint- and tile-level facts (schemas,
routes, auth mechanics, versioning) are owned **here**; business rationale stays
in api-strategy.md and data policy stays in osm-data-architecture.md.
