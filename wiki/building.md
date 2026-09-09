<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Building the Commons

How the Cycling Commons is built and run — and how to help build it. This page is part *what
exists today* and part *where we're heading*, and it says which is which. Nothing here is sealed;
the stack was chosen in the open and keeps evolving in the open, and reasoned pushback is exactly
the kind of contribution we're looking for.

> **Status: working application, pre-launch.** A Symfony application backed by PostGIS serves
> the map straight from the database, and the full contribution loop is real: accounts, adding
> and improving places, proposing routes, rider confirmations, the moderator queue. It runs from
> the repo today; the public launch is being prepared. The clickable prototype the project
> started from is in the repo too, useful for a zero-install look at the rider flows.

## Run it locally

The whole stack runs in Docker: database, Redis, application, a Messenger worker, ClamAV,
pipeline, prototype, and this wiki, wired together the way production runs it. You need Docker
Engine with Compose v2 and GNU make:

<!-- CODE-ILLUSTRATIVE shell commands to clone and boot the dev stack, not our code -->
```sh
git clone https://github.com/cycling-commons/cycling-commons.git
cd cycling-commons

make setup    # builds the stack, installs deps, runs migrations, seeds world data + demo users

# then open http://localhost:8001/map
```

`make setup` seeds world reference data and four demo logins, which is enough to browse the app but
leaves the catalog tables empty. If you want data in them — and you do if you are working through
the [GIS course](developers/gis/index.md), whose exercises all query real rows — run
`make course-data` once afterwards. It seeds ~800 catalog items, 11 routes and a small OpenStreetMap
coverage cache entirely from files committed to this repository: no network, no map download.

Day to day: `make up` / `make down` start and stop the stack, `make logs` follows it, and
`make app-test` runs the application's test suite and static analysis. Outbound mail (registration
confirmation, password reset, account deletion) is caught by a bundled Mailpit at
`http://localhost:8025`; nothing is ever really sent. Redis carries sessions, the application
cache, the rate limiters and the Messenger transport; the `worker` service consumes the async
queue (Commons photo fetches, upload scanning and release); ClamAV scans every upload before it
reaches a public bucket. Heavier pieces are opt-in profiles: `make up-routing` adds a Valhalla
routing engine, `make up-storage` adds S3-compatible MinIO with the `media-proxy` and
`tiles-proxy` nginx fronts that mirror how production serves media and tiles.
The full service table, ports, and troubleshooting live in the stack's
[README](https://github.com/cycling-commons/cycling-commons/blob/main/developers/docker/README.md).

**The static prototype (zero install).** The original demo is plain HTML, CSS, and JavaScript in
[`atlas/demo/`](https://github.com/cycling-commons/cycling-commons/tree/main/atlas/demo) — no build
step, no dependencies. Serve the folder over HTTP and open the landing page:

<!-- CODE-ILLUSTRATIVE shell commands to serve the static prototype, not our code -->
```sh
cd cycling-commons/atlas/demo
php -S localhost:8000          # or: python3 -m http.server 8000
# then open http://localhost:8000/index.html
```

**Serve it over `http://`, not `file://`.** The maps fetch the [OpenFreeMap](https://openfreemap.org)
basemap and use Photon/Nominatim for place search over the network, and opening pages directly from
disk (`file://`) breaks those requests on CORS. Offline, the map area degrades gracefully to a paper
background — the flows still work. (The Docker stack also serves the prototype, at
`http://localhost:8099`.)

## What's in the repo

| Path | Holds |
|------|-------|
| [`web/`](https://github.com/cycling-commons/cycling-commons/tree/main/web) | the Symfony application — map, contribution, moderation, routes, accounts (this is what you run) |
| [`pipeline/`](https://github.com/cycling-commons/cycling-commons/tree/main/pipeline) | the Python geo pipeline: the per-country OpenStreetMap coverage harvest, the coverage, surface and signed-routes PMTiles, and the provider imports (its FastAPI app is only a health check) |
| [`atlas/demo/`](https://github.com/cycling-commons/cycling-commons/tree/main/atlas/demo) | the original clickable prototype |
| [`developers/docker/`](https://github.com/cycling-commons/cycling-commons/tree/main/developers/docker) | the Docker dev stack — compose file, nginx configs, DB init |
| [`docs/specs/`](https://github.com/cycling-commons/cycling-commons/tree/main/docs/specs) | the canonical design specs — the contracts the code implements |
| [`tools/`](https://github.com/cycling-commons/cycling-commons/tree/main/tools) | maintainer tooling: region onboarding from Overture (`divisions/`), the Copernicus GLO-30 elevation and Valhalla tile builds (`elevation/`, `valhalla/`), the Wikidata and Wikimedia climb and photo lookups (`wikimedia/`), the regions snapshot generator, the credits checker, the staging benchmarks, the Wallonia harvest, and the pre-push gate scripts |
| [`wiki/`](index.md) | `wiki.cyclingcommons.org` — all reference documentation, including this page |
| [`LICENSE`](https://github.com/cycling-commons/cycling-commons/blob/main/LICENSE) | the platform code licence (PolyForm Shield 1.0.0) |
| [`licenses/`](https://github.com/cycling-commons/cycling-commons/tree/main/licenses) | the data, media & terms licences |

See the [README](https://github.com/cycling-commons/cycling-commons/blob/main/README.md) for the
one-paragraph version of the whole project.

## The stack — what's built, what's next

Most of this is running code. Where something below is still ahead of us, it says so, and if you
have strong reasons for a different choice on the parts still open, open an issue.

- **The spine — PostgreSQL + PostGIS.** **Live.** Climbs, points, and regions are geospatial *and*
  relational; "what's near me / within this region / in this bounding box" is a query; and an ODbL
  bulk export is just a constrained database dump. The whole OpenStreetMap world already runs on it.
  The application serves the map catalog straight from this database — there is no separate content
  store, and everything else plugs into it.
- **Render — MapLibre GL JS.** **Live** in both the application and the prototype, on the keyless
  [OpenFreeMap](https://openfreemap.org) basemap, with worldwide OpenStreetMap coverage rendered
  from **PMTiles** served as single files from object storage (no tile server to run). The
  contract is
  [`docs/specs/coverage-provider.md`](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/coverage-provider.md).
- **A polyglot split, with PostGIS as the contract.** The work divides cleanly by what it actually
  needs:
  - The **serving API and the everyday logic** (queries, the contribution write-path, the
    moderation queue, routes and confirmations, accounts and 2FA) is **built**, on **Symfony 7 LTS**
    behind nginx + PHP-FPM, where the maintainers are strongest. It lives in `web/` and reads the
    same PostGIS the pipeline writes.
  - The **Python pipeline** (`pipeline/`) owns what needs the geospatial stack: importing
    OpenStreetMap coverage per country, building the coverage, surface and signed-routes PMTiles,
    and running the provider imports (`pipeline/coverage/`, `pipeline/providers/`). Its FastAPI
    app is only a health check; the jobs are batch commands. The contracts are
    [`docs/specs/osm-data-architecture.md`](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/osm-data-architecture.md)
    and the coverage spec above. The open-data exports are design, not built.
  - **Elevation and snapping** run on the project's own **Valhalla**, built on Copernicus GLO-30,
    and are called from PHP: `web/src/Elevation/` reads heights, snaps climb-editor lines to the
    road graph, and measures every climb's gradient profile (`app:climbs:recompute`). The contract
    is
    [`docs/specs/climb-elevation.md`](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/climb-elevation.md),
    partly built. Climb lines mostly arrive harvested, from Wikidata and OpenStreetMap through
    `tools/wikimedia/`, with a few hand-seeded or rider-drawn; routes arrive rider-drawn or
    GPX-uploaded (the contract is
    [`docs/specs/route-domain.md`](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/route-domain.md)).

  The rule of thumb: if a job needs a raster, a road graph, or OSM topology, it's Python or
  Valhalla; if it's aggregation, lifecycle, or CRUD over the database, it's the Symfony tier,
  which is also Valhalla's HTTP client (the binding placement rule lives in
  [`docs/specs/dev-environment.md`](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/dev-environment.md)).
  Neither side owns the data; the database does.
- **The principle behind every choice: boring, non-proprietary, and self-hostable.** The Commons is
  built to outlive any single app and to be handed to an independent foundation (see
  [Governance](governance.md)). That rules out anything proprietary or lock-in-shaped: a future
  steward must be able to run the whole thing on commodity infrastructure, without us.

For depth, follow the links rather than re-reading it here: the full data model and which fields are
hand-entered vs `[auto]`-derived live in the [Data catalog](data-catalog.md), and the engineering
contracts — schema, moderation state machine, route domain, security architecture, dev environment —
are the canonical specs in
[`docs/specs/`](https://github.com/cycling-commons/cycling-commons/tree/main/docs/specs).

## Third-party code and licences — the disciplines

The project combines four licence worlds — Shield-licensed platform code, ODbL
data, CC BY-SA media, and other people's software — and they stay composable
only because each inbound licence is matched to the one *work* it touches.
Four rules keep it that way:

**1 · Anything shipped to the browser must be permissive.** Everything under
`web/assets/lib/` (MapLibre, pmtiles, mapillary-js, Redoc, and Scout's FIT reader
`scout-fit.js`) is BSD or MIT.
Serving a file **is** distribution, so a copyleft (GPL-family) library there
would attach obligations to the page it ships with. Never vendor one. Each
vendored dist keeps its upstream copyright header, the licence texts live in
[`LICENSES/`](https://github.com/cycling-commons/cycling-commons/tree/main/LICENSES),
and `reuse lint` holds the mapping honest in CI.

**2 · The Composer tree is copyleft-gated in CI.** The platform source is
publicly distributed, so a GPL dependency *inside it* would demand the
combined work go GPL — incompatible with the Shield licence.
`web/tools/check-licenses.sh` fails the build on any
`GPL/LGPL/MPL/EUPL/OSL/CDDL/SSPL` dependency. Don't argue with the gate; pick
a permissive alternative.

**3 · GPL software is fine to *run* — the boundary is distribution.** PostGIS
(GPL-2.0) runs server-side and the app talks to it over SQL. A client across
a process boundary is not a derivative work, and GPL-2.0 has no network
clause, so operating it obliges nothing.
The line to respect: if you ever *modify* one and publish the modified build
(a Docker image counts), that modification is GPL. The app still isn't.

**4 · Required notices are not editable copy.** On
[/credits](https://cyclingcommons.org/credits), rows badged **required
notice** carry wording a source's licence mandates verbatim (OpenStreetMap's
ODbL line, the Copernicus DEM notice, Esri, Mapillary, per-photo Wikimedia
credits). Reword thank-yous freely; never touch a badged row's text.

Fonts, as the footnote: all three families are SIL OFL 1.1, which is designed for
exactly this use — self-hosted, embedded, aggregated with any code. The OFL's
two asks are that the licence accompanies the fonts (it does, in `LICENSES/`)
and that they are never sold on their own.

## External services — what the site calls, and on what terms

This table is the audit of every external call the site makes, kept current.
The rule behind it: **anything in a rider's hot path either has terms that
welcome production use, or it gets self-hosted.** That is why climb-editor
snapping runs on the project's own Valhalla behind `/contribute/route`, why
every third-party browser library is vendored under `web/assets/lib/` rather
than pulled from a CDN, and why the codebase makes no Nominatim calls at all:
its 1 req/s policy cannot be honoured from riders' browsers at scale, and the
boundary polygons it would answer with are served by the project itself.

| service | called from | their terms | our position |
|---|---|---|---|
| [OpenFreeMap](https://openfreemap.org/) | every map load (basemap tiles) | explicitly free for production, no key | credited |
| [Wikimedia Commons](https://commons.wikimedia.org/) | photo links in the drawers, and licence-checked copies fetched server-side into our own media store | hotlinking allowed and encouraged | per-photo credit + licence (required notice) |
| [Mapillary](https://www.mapillary.com/) | street-level tiles + viewer (client token) | display with attribution | attribution on the tile source and /credits |
| [Photon](https://github.com/komoot/photon) | search + base-location typeaheads | free, fair use, no guarantee | debounced and aborted; self-host if traffic grows |
| [Copernicus DEM GLO-30](https://dataspace.copernicus.eu/) | our own Valhalla's elevation tiles | verbatim credit notice **required** on derived products | Article 6(b) notice + 6(c) liability sentence on /credits |
| [Esri World Imagery](https://www.esri.com/) | the map's and the climb editor's satellite layer | licensed, key-authenticated | We hold an ArcGIS Location Platform key (public-application credential, static-basemap-tiles privilege only, referrer-restricted) and call the keyed `ibasemaps-api` endpoint. An endpoint answering without a token is not the same as a licence to use it |
| [Geofabrik](https://www.geofabrik.de/) · Overpass · Wikidata | pipeline and maintainer tools only — never riders | be polite | md5-checked downloads, identifying UAs, and the tools prefer the local PBF over live APIs |

Self-hosted and therefore *not* external: Valhalla (routing + elevation),
Photon-shaped search could join it later, the analytics endpoint
(analytics.bikecoders.life is first-party), the fonts, and every browser
library. The page's Content-Security-Policy is the enforcement seam: its
`script-src` names exactly one host beyond the page's own origin, that
first-party analytics endpoint, so a new external script dependency cannot
appear without editing the CSP, which is the moment to re-run this table's
test.

## Get involved

The Commons grows two ways, and both need people.

**With code.** The source is at
[github.com/cycling-commons/cycling-commons](https://github.com/cycling-commons/cycling-commons).
The platform code is **source-available under the [PolyForm Shield License 1.0.0](https://github.com/cycling-commons/cycling-commons/blob/main/LICENSE)** — read it, run it, self-host and modify it freely; the one limit is you can't use it to build a product that competes with the Commons. The open *data* (ODbL) and the query API ([access policy](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/osm-data-architecture.md)) are what you build on commercially. (Note: source-available is **not** OSI open-source — the data is open; the platform code is shared but non-compete.) The text of this wiki itself is [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/) — share and adapt it with attribution, same-licence.
Right now the most useful work is on the application itself and the Python pipeline; the specs in
[`docs/specs/`](https://github.com/cycling-commons/cycling-commons/tree/main/docs/specs) are the
fastest way to orient. Propose changes by pull request; substantive design decisions belong in this
wiki and those specs so there's a single source of truth (see
[Contributing → *this repo*](contributing.md)). If a stack choice above looks wrong to you, that
conversation is welcome.

**With local knowledge.** The flows are real now; the value is real riders' knowledge of real
places — the climbs worth the detour, where the water is, where to sleep after a long day, the finest
views. Telling us whether the flows match how you actually think about your roads is worth a lot.
How contribution works is described in [Contributing](contributing.md).

---

*New here? Start with the [Manifesto](manifesto.md), then the [Data catalog](data-catalog.md) and
[Governance](governance.md). This page is the practical companion: how it's built, and how to join.*
