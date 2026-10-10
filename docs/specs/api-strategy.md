<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# API Strategy: commercial access and the commons

**Status:** canonical reference · **Audience:** contributors to Cycling Commons
· some sections marked *proposed* pend an owner decision (§10).

This document owns the **business and access posture** of the public API: how
Commons data reaches third parties (including commercial products such as
RideWithGPS), what we charge for, and why letting commercial consumers use the
data does not undermine the project.

It does **not** restate the legal posture or the API's technical shape. Those
are owned elsewhere and cross-linked:

- **Licensing** (data ODbL/DbCL, code AGPL-3.0-only):
  [osm-data-architecture.md §3](osm-data-architecture.md).
- **API response shape** (reference-only default, optional hydrated endpoint)
  and **access terms** (API-only, scraping prohibited):
  [osm-data-architecture.md §7](osm-data-architecture.md).
- **Account/Commons data boundary** (what the API may never expose):
  [security-architecture.md](security-architecture.md),
  [account-and-auth.md](account-and-auth.md); its enforcement is owned by
  [public-api-personal-data-boundary.md](public-api-personal-data-boundary.md).

---

## 1. The thesis: the bytes are not the moat

Two license choices already made (osm-data-architecture.md §3) fix the shape of
everything here:

- **Data is open (ODbL/DbCL).** Anyone may use, redistribute, and build
  derivatives of the Commons data, with attribution and share-alike. We neither
  can nor want to make the data proprietary.
- **Code is free software (AGPL-3.0-only).** Anyone may read it, fork it, modify
  it and run it, commercially and in direct competition with us. The only thing
  the licence asks in return is reciprocity: a fork that is conveyed, or that is
  run as a network service, must offer its users the complete corresponding
  source under the same terms (AGPL section 13).

**Neither licence holds anything back, and the strategy has to be honest about
that.** There is no clause anywhere in this project that stops a rival standing
up its own Cycling Commons from our code and our data. The licences do not
protect the business, and were never chosen to. What they do is guarantee that
anything built on this work stays in the open, so a rival's improvements come
back to the commons instead of disappearing into a closed product.

Therefore the API is **not** a way to sell proprietary data, and it is not a way
to sell a licence. It is **the hosted, always-fresh, low-friction face of a
share-alike commons**. What a commercial consumer pays for is *operational
convenience and freshness*, never exclusive access to bytes.

### 1.1 Where the durable advantage actually sits

A fork gets the code on day one and a snapshot of the data on day one. What it
does not get, and cannot copy, is the thing that keeps producing:

- **The live data and its freshness.** A dump ages from the moment it is taken.
  What has value is the delta arriving every day, and that arrives here because
  the riders and curators are here.
- **The curation.** Coverage is only useful once a human has decided a tap is
  real, a hazard has cleared, a climb is measured correctly. That work is a
  standing cost a fork has to fund from scratch, and it is the difference
  between our rows and raw OSM.
- **The community.** The contributors, the moderators, the regional curators and
  their local knowledge. People, not files. They follow the project they trust,
  and they can leave, which is a real discipline on how we behave.
- **The name.** The marks are reserved
  ([TRADEMARK.md](../../TRADEMARK.md)); a fork must ship under its own name, so
  a rider always knows whether they are looking at *this* Commons with this
  data, this curation and this privacy promise.

None of that is a legal moat. It is an operational one, and it has to be earned
again every month. That is the correct incentive.

A commercial product consuming the API is a **feature, not a threat**: under
ODbL a consumer's derived *database* must itself stay open (§6), and under AGPL
a consumer who runs our code must publish their source. Consumption feeds the
commons rather than draining it.

## 2. "Open license" ≠ "open access to our servers"

These are independent and must never be conflated:

| | Governs | Set by |
|---|---|---|
| **Data licence** (ODbL/DbCL) | what someone may *do* with data they hold | osm-data-architecture.md §3 |
| **Code licence** (AGPL-3.0-only) | what someone may *do* with the software they hold, including running it as a rival service | osm-data-architecture.md §3 |
| **Access** (ToS, quotas, no-scraping) | *how they may obtain it from us* | osm-data-architecture.md §7 + this doc |

Open-licensed data can sit behind a no-scraping rule. This is exactly how OSM
itself operates (ODbL data, but you use planet/Geofabrik dumps, not live
scraping), and how our own ingestion already works
([coverage-provider.md](coverage-provider.md)). Because scraping the live
service is prohibited, we **must** provide sanctioned non-scraping channels to
obtain the open data. Those channels are §3.

## 3. Distribution channels

There are exactly two sanctioned ways to obtain Commons data. Both are
non-scraping; scraping the site/tiles/endpoints outside these remains prohibited
(osm-data-architecture.md §7).

**Status today.** The bulk export (§3.1) is built. The metered API (§3.2) is
not; what is live is a free, unkeyed, read-only proof of concept of the API
([public-api.md §2.2](public-api.md)): `/v1/map-config` and `/v1/search`,
declared `security: []` in `web/public/api/openapi.yaml`, open to anonymous
callers (`^/v1/` is `PUBLIC_ACCESS` in `web/config/packages/security.yaml`)
and throttled per IP address to 120 requests a minute (the `public_api_read`
limiter in `web/config/packages/rate_limiter.yaml`). `/v1/search` already
answers a worldwide name search (`q`), adds routes on request
(`routes=include`) and gives each hit its region's public slug
(`region_id`). API keys and quota tiers (§3.2, §4) remain proposed.

### 3.1 Periodic bulk export: free, open, ODbL *(built 2026-10-09)*

A published, downloadable snapshot of the non-personal Commons dataset. One
deliberate download, self-hosted by the consumer. **This is how a third party
self-hosts without touching our live service.** It is the ODbL-honest
baseline and satisfies the "the data is genuinely open" promise.

Key lever: **ODbL obliges us to license openly *if* we distribute. It does not
force us to distribute *live* or *fresh*.** The free export is therefore
deliberately **periodic (stale)**; live freshness is the paid product (§3.2,
§4).

**As built.** `app:export:build` (`App\BulkExport\BulkExportBuilder`) writes
one snapshot; the worker host runs it weekly from a timer
([operations.md §1](operations.md)). The page is `/developers/export`
(`data_export`, five localised paths), linked from `/developers`; the files
are served under `/data/export/` in no language, by nginx straight from the
bucket, and PHP serves only the page and the `latest` redirects. `/developers` shows the
`latest` download link only once a snapshot is published
(`bulk_export_published()`, `App\Twig\BulkExportExtension`); before that it
says the first export is on its way, so no page of ours links to a 404.

- **Files.** `places.geojson.gz` and `routes.geojson.gz`: gzip-compressed
  GeoJSON `FeatureCollection`s in WGS84, one feature per line, with the
  foreign members `licence` (`ODbL-1.0`), `attribution` and
  `generated_at`. A file's `attribution` is the string on `/developers`
  (`BulkExportBuilder::ATTRIBUTION`) followed by `Source credits:` and the
  registry credit of every source with a row in that file, each once, ours
  first: a CC BY credit such as "Tourisme Wallonie (CC-BY)" travels inside
  the file that holds those rows, not only in the manifest. The file is
  written in two passes (features to a scratch file, then the head and the
  features behind it), so the credits are the sources actually written.
  Coordinates carry six decimals. Beside them `manifest.json`: `format`
  (1), `snapshot` (the UTC build stamp, `20261009T133900Z`),
  `generated_at`, the dataset `licence` (ODbL 1.0, contents DbCL 1.0),
  `attribution`, one `sources` entry per source (registry name, licence,
  licence code, attribution, homepage, place and route counts), `left_out`
  (per source, why and how many), and `files` (name, content type, feature
  count, bytes, sha256).
- **What a place carries.** Exactly the properties of the API's
  `ItemFeature` (`id`, `letter`, `name`, `tier`, the trust envelope,
  `region_id`), plus `country`, `osm_ref` and `source`. A route carries its
  whole line with `id`, `letter` (`R`), `name`, `tier`, `distance_m`,
  `ascent_m`, `region_id` and `source`. No `attributes`, no photos.
- **What it is not.** The OpenStreetMap coverage the map also shows (`coverage_poi`,
  by far the largest part of the map, and the surface and route layers) is not republished: the pages say
  so (`data_export.osm_body`) and link the wiki guide that gives the exact OpenStreetMap
  filters (`wiki/developers/api/openstreetmap-data.md`, `pipeline/osm_filters.py`). The
  copy calls the export the Commons catalogue, never "the whole map" (owner 2026-10-10).
- **Which rows.** What `/v1/search` serves: items in a served state
  (`unverified`, `verified`), minus untouched OSM coverage rows
  (`CoverageRetirement`) and places reported gone (`GoneRows`), and served
  recommended routes that are not in Trash. The row as approved: an edit still
  waiting in `submission` is not applied to `item`, so it is not exported.
  Submitted, rejected, retired and trashed rows never are.
- **Nothing personal** ([public-api-personal-data-boundary.md §1.5](public-api-personal-data-boundary.md)):
  no user id, display name, email, IP hash, proposer, curator note,
  moderation state or free-text attribute. Pinned by
  `tests/BulkExport/BulkExportBuilderTest.php`, which seeds those values and
  fails if any of them reaches a file.
- **Per source, per licence.** Every row is filed under one `source`:
  `cycling-commons` for our own rows (`user`, `scout`, `manual`, `auto`,
  ODbL), `osm` and `wikidata` for the mirrored copies (licensed by their
  registry rows: ODbL and CC0), and the registry key for an `authority` row
  (`item.provider_id`). A source travels only when its licence passes the
  licence test of [data-source-register.md §1](data-source-register.md)
  (`App\BulkExport\BulkExportLicences`: ODbL, CC0, PDDL, PDM, public domain,
  CC BY 4.0 and the attribution-only government licences). CC BY-SA, NC, ND,
  per-photo, Copernicus and every unknown code stay out, and so does an
  authority row whose registry row is gone. Left-out rows are counted in the
  manifest and on the page. On 2026-10-09 that sends `osm`, `wikidata`,
  `wallonie-pivot` (CC BY 4.0, attribution "Tourisme Wallonie (CC-BY)") and
  `rivm-drinkwater` (PDM) out with our own rows, and leaves nothing out.
- **OpenStreetMap-derived data.** A curated OSM copy travels with
  `source: osm` and its `osm_ref`, under the ODbL with `© OpenStreetMap
  contributors`, never as ours ([osm-data-architecture.md §3.3, §7](osm-data-architecture.md)).
  Route lines are traced on OSM ways; the dataset attribution names
  OpenStreetMap for that reason.
- **Consistency.** All reads run in one read-only, repeatable-read
  transaction. The files go to storage first and `latest.json` last, so a
  reader never meets a pointer to a half-written snapshot. A build that fails
  after its first write deletes `exports/snapshots/<stamp>/` again before it exits
  (a failed delete is a warning; the next build's prune removes it). One
  build runs at a time: the builder holds a Postgres advisory lock
  (`pg_try_advisory_lock(hashtext(BulkExportBuilder::LOCK))`) for the whole
  build, and a second run exits 1 with "already running" and writes nothing.
  The repository has no Symfony Lock component; the database is the one thing
  every host that could start a build shares, which a file lock is not. A
  stamp that already holds a manifest is refused, because a published
  snapshot never changes.
- **Storage: a folder in an existing bucket** (owner 2026-10-10). The export
  has no bucket of its own. Everything it writes and reads sits under
  `exports/` in the bucket `DATA_EXPORT_BUCKET` names, the map tile bucket in
  production, reached with the media S3 client
  ([media-storage-architecture.md §2](media-storage-architecture.md)), whose
  credentials need write and delete rights there:

      exports/latest.json                            the newest manifest, written last
      exports/snapshots/<stamp>/manifest.json
      exports/snapshots/<stamp>/places.geojson.gz
      exports/snapshots/<stamp>/routes.geojson.gz

  `App\BulkExport\BulkExportStorage` lists, writes and deletes nothing outside
  `exports/` (pruning and the cleanup of a failed build included), and deletes
  a snapshot only by a well-formed stamp; pinned by
  `BulkExportBuilderTest::testTheExportTouchesNothingOutsideItsFolder` and
  `BulkExportStorageTest`. `DATA_EXPORT_BUCKET` empty or still the committed
  placeholder `replace-me` (`BulkExportStorage::UNSET_PLACEHOLDER`) means no
  bucket: nothing is read, nothing is logged, and the command refuses.
- **Object metadata.** Each object is written with the headers it is served
  with, so the proxy passes them through untouched:

  | Object | Content-Type | Cache-Control | Content-Disposition |
  |---|---|---|---|
  | `places.geojson.gz`, `routes.geojson.gz` | `application/gzip` | `public, max-age=604800, immutable` | `attachment; filename="cycling-commons-places-<stamp>.geojson.gz"` (and `routes`) |
  | `snapshots/<stamp>/manifest.json` | `application/json` | `public, max-age=604800, immutable` | none |
  | `latest.json` | `application/json` | `no-cache` | none |

  No Content-Encoding: the data files are gzip files to save, not JSON sent
  compressed. The S3 adapter forwards these as write options
  (`ContentType`, `CacheControl`, `ContentDisposition`); pinned by
  `BulkExportStorageTest`, which checks the PUT that reaches S3.
- **Serving: nginx, from the bucket.** Nobody but our own software addresses
  the bucket. nginx on each web frontend answers exactly
  `^/data/export/(\d{8}T\d{6}Z)/(places\.geojson\.gz|routes\.geojson\.gz|manifest\.json)$`
  from `exports/snapshots/$1/$2` in the bucket, before PHP: it passes
  Content-Type, Content-Length, ETag, Last-Modified, Cache-Control and
  Content-Disposition as stored, supports HEAD, Range (206) and conditional
  requests (304, against the bucket's ETag and the upload time), strips the
  storage's `x-amz-*` headers, sends the visitor's cookies and credentials
  nowhere, and turns the bucket's 403 or 404 into a plain 404 that names no
  bucket. Every other path under `/data/export/` goes to PHP. The app has no
  route for a snapshot's file
  (`BulkExportControllerTest::testTheAppHasNoRouteForASnapshotsFiles`);
  `BulkExportLatestController::fileUrl()` and the Twig function
  `bulk_export_file()` build the path for links. Dev runs the same location
  in `developers/docker/nginx/app.conf`, proxying MinIO's `cc-maps` bucket;
  production's lives in the frontends' nginx config.
- **The page and the `latest` links (PHP).** `/developers/export` reads
  `exports/latest.json` through the S3 client. `/data/export/latest/{file}`
  redirects (302, public, five minutes) to `/data/export/<stamp>/<file>`, the
  same URL shape as before, which nginx then serves; while nothing is
  published it answers 404 with the export page, which says the first
  snapshot is on its way (`BulkExportLatestController`).
- **Lookups.** `App\BulkExport\BulkExportCatalog` caches the newest manifest
  in the shared cache for ten minutes; the builder drops it once it has
  published (`forget()`). Storage that fails is remembered for a minute (the
  pages then say nothing is published) and logged as a warning at most once
  an hour. Nothing in PHP reads a snapshot's files.
- **Limiter: nginx's.** The download limit is an nginx `limit_req` zone on
  that location, keyed on the visitor's address and held in nginx's memory
  only (never in Redis, a database or a file); a refused request gets a 429.
  Every request to the location counts, whatever the file and whether GET,
  HEAD, a range or a conditional request. The number is never stated in
  public copy; the privacy notice names the export among the counters that
  use the address itself ([privacy-notice.md §2](privacy-notice.md)).
- **Retention.** The newest four snapshots that hold a manifest
  (`BulkExportBuilder::KEEP`, a month of weekly builds) are kept; each build
  deletes the older ones, and every manifest-less directory older than the
  snapshot it just published (what a build that died halfway left). A
  directory without a manifest never counts among the four. Pruning runs
  after the site has been told of the new snapshot; a pruning failure is a
  warning and does not fail the build.
- **Taking a snapshot down.** Delete `exports/snapshots/<stamp>/` from the
  bucket and rebuild if it was the newest. nginx answers from the bucket, so
  the site serves a 404 for it at once; copies already in browsers or
  downstream caches may live out their week (operations.md, "Taking a bulk
  export snapshot down").

**Cache lifetime, decided 2026-10-09.** A week (`max-age=604800`), not a
year, now carried as the objects' own Cache-Control. A takedown deletes a
snapshot from storage at once, but a copy downstream lives until it expires.
A week matches the build cadence, and a year bought nothing: a snapshot is
kept four weeks, a mirror fetches each snapshot once, and after the week a
matching `ETag` still costs a 304 and no bytes. `immutable` stays, since
nothing within the week should revalidate.

**Cadence and format, decided 2026-10-09.** Weekly, because the developer
page promised a periodic export without a number and weekly matches the
coverage harvest's rhythm. GeoJSON only: PMTiles already exist per country
for display (public-api.md §2.1), and a PostGIS dump would expose our schema,
which is not a contract. A CSV was not asked for by any spec and would split
route lines from their geometry.

### 3.2 Metered self-serve API: free tier plus paid quotas

The live, always-fresh service. Response shape and access terms are owned by
osm-data-architecture.md §7; this doc owns the **quota tiers and pricing**
(§4). Freshness is backed by materialize-on-edit
([osm-data-architecture.md §6](osm-data-architecture.md)). Coverage tiles ride
existing PMTiles/CDN infra. *Proposed*: the live API has no keys or quotas
yet, only the per-IP limit of the proof of concept (§3).

## 4. Pricing model: metered quota tiers *(proposed)*

Adopt a self-serve, metered, **requests-per-month** model (the proven
Thunderforest shape), not a bespoke/enterprise model. Illustrative tiers, with
the numbers pending §10:

| Tier | Shape | Includes |
|------|-------|----------|
| **Free** | low monthly request quota | full Commons coverage, periodic-fresh, no SLA |
| **Solo** | higher quota | live freshness, email support |
| **Business** | large quota | fresh/incremental exports (vs. the free stale dump), higher rate limits |
| **Large** | very high quota | SLA, priority support |

The billable unit is **requests per month** (API calls and/or coverage-tile
requests). We are *borrowing Thunderforest's pricing shape, not competing on
their axis*: Thunderforest sells tile **rendering**; we sell access to a **fresh
Commons delta** (routes, utilities, hazards, coverage) joined against OSM, which
no tile vendor holds.

**What we charge for:** freshness, throughput/quota, SLA, compliance/attribution
tooling, all of it operational convenience. **What we never charge for:** the
data itself (the periodic dump is always free and open, §3.1).

## 5. Business-development posture: passive, self-serve

We do **not** court big platforms. No outbound BD, no design-partner program, no
sales team. Publish the free dump, ship a self-serve keyed API with quota tiers,
and let consumers find it. (Today the dump is published weekly and only the
unkeyed proof of concept of the API is live, §3.) This matches a small team and is the owner's stated
intention. Inbound commercial use is welcome on the standard tiers; it is not
solicited.

## 6. Contribution-back: realistic expectations

The write/contribution API (routing user submissions back through the existing
contribution → moderation loop,
[moderation-and-contribution.md](moderation-and-contribution.md)) should be
built and made **as frictionless as possible**. But the business case must stand
up **without assuming competitors use it**:

- **Direct competitors will not** wire "contribute to Cycling Commons" into
  their UI, because they have no incentive to grow a rival's dataset. Do not
  model revenue or growth on their goodwill.
- **Realistic contributors:** our own app's users (we own that UX); aligned,
  non-competing consumers (bike-shop finders, tourism boards, clubs); and, for
  *data* derivatives, **ODbL share-alike**, which forces a consumer's derived
  database open even if they never add a single button.

So share-alike, not a contribution button, is the mechanism that makes
commercial consumption feed the commons. On the data side that is ODbL; on the
code side it is AGPL section 13, which turns running a modified Cycling Commons
as a website into an obligation to publish the modifications. The write API is a
convenience for friendly consumers, not a lever against competitors.

## 7. Attribution enforcement: posture

ODbL attribution is easy to violate quietly and hard to police at scale. Posture:

- **Default reward is visibility/goodwill**, not enforcement. Do not build
  process around policing attribution now.
- **Enforcement is reserved**, case-by-case, and scales with the violator's size
  and our legal appetite: small violators are not worth chasing; a large,
  blatant, worth-it case is a legal question weighed when it arises.

## 8. Account/Commons boundary: a hard rule

The public/commercial API exposes **only the Commons layer** (non-personal:
routes, utilities, hazards, coverage, our enrichment). It **never** exposes the
account layer: email, IP logs, password hashes, moderation internals. The
Commons dataset is non-personal, but the platform holds personal data at the
account level; the API boundary is where that separation is enforced. See
[security-architecture.md](security-architecture.md) and
[account-and-auth.md](account-and-auth.md);
[public-api-personal-data-boundary.md](public-api-personal-data-boundary.md)
owns the enforcement (grants, connection, deptrac, tests) and records which
parts are built.

## 9. Why a commercial consumer (e.g. RideWithGPS) is not a threat

Not because they are fenced out. They are not: the code is AGPL and the data is
ODbL, so a commercial consumer may take both. The reasons are these.

1. They obtain data via sanctioned channels (§3), never by scraping.
2. They **may** reuse our **code**, and the AGPL is what makes that acceptable.
   If they modify it and run it as a service, section 13 obliges them to offer
   their users the corresponding source, so their work returns to the commons.
   If they will not accept that, they write their own instead of taking ours.
3. Their derived **database** must stay open under ODbL share-alike (§6).
4. They must ship under their own name. The marks are reserved
   ([TRADEMARK.md](../../TRADEMARK.md)), so nobody can trade on our reputation
   while running their own version.
5. They pay us only if live freshness and throughput beat self-hosting the free
   periodic dump. That is a convenience sale, not a data sale, and it is the
   only sale on offer.
6. They never reach the account layer (§8).

The competitive set that matters is route/data platforms (Komoot, Bikemap,
RideWithGPS, cycle.travel) and the OSM ecosystem, not tile vendors. Against all
of them we compete on the same footing as anyone forking us: a **fresh Commons
delta**, curated by people who are here, that has to be re-earned rather than
defended by a clause.

## 10. Open decisions (pending owner)

- **Quota thresholds and prices** (§4): concrete request ceilings and €/month
  per tier.
- **Billable unit** (§4): API calls, tile requests, or a blended metric.
- **Fresh-export tier** (§4): is incremental/fresh bulk a paid tier feature, and
  at what freshness gap vs. the free dump?

## Relationship to other specs

This is the business/posture layer over the technical API contract. It consumes
osm-data-architecture.md §3 (licensing) and §7 (API shape + access terms)
unchanged, and delegates the account boundary to the security/account specs. The
*endpoint* contract (routes, quotas, auth keys, tile schema, versioning) is owned
by [public-api.md](public-api.md), not here.
