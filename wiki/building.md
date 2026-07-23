<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Building the Commons

How the Cycling Commons is built and run — and how to help build it. This page is part *what
exists today* and part *where we're heading*, and it says which is which. Nothing here is sealed;
the stack was chosen in the open and keeps evolving in the open, and reasoned pushback is exactly
the kind of contribution we're looking for.

> **Status: working application, pre-launch.** The Commons is no longer a mock-up. A Symfony
> application backed by PostGIS now serves the map straight from the database, and the full
> contribution loop is real: accounts, adding and improving places, proposing routes, the
> moderator queue, voting. It runs from the repo today — the public launch is being prepared.
> The original clickable prototype that started the project is still in the repo (and still
> useful for a zero-install look at the rider flows).

## Run it locally

The whole stack runs in Docker — database, application, pipeline, prototype, and this wiki —
wired together the way production runs it. You need Docker Engine with Compose v2 and GNU make:

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
`http://localhost:8025` — nothing is ever really sent. Heavier pieces are opt-in profiles:
`make up-routing` adds a Valhalla routing engine, `make up-storage` adds S3-compatible MinIO.
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
| [`pipeline/`](https://github.com/cycling-commons/cycling-commons/tree/main/pipeline) | the Python/FastAPI geo pipeline (connectivity scaffold today; the geospatial jobs land here) |
| [`atlas/demo/`](https://github.com/cycling-commons/cycling-commons/tree/main/atlas/demo) | the original clickable prototype |
| [`developers/docker/`](https://github.com/cycling-commons/cycling-commons/tree/main/developers/docker) | the Docker dev stack — compose file, nginx configs, DB init |
| [`docs/specs/`](https://github.com/cycling-commons/cycling-commons/tree/main/docs/specs) | the canonical design specs — the contracts the code implements |
| [`tools/`](https://github.com/cycling-commons/cycling-commons/tree/main/tools) | data-harvest tooling (the Wallonia OSM/open-data harvest that seeded the first region) |
| [`wiki/`](index.md) | `wiki.cyclingcommons.org` — all reference documentation, including this page |
| [`LICENSE`](https://github.com/cycling-commons/cycling-commons/blob/main/LICENSE) | the platform code licence (PolyForm Shield 1.0.0) |
| [`licenses/`](https://github.com/cycling-commons/cycling-commons/tree/main/licenses) | the data, media & terms licences |

See the [README](https://github.com/cycling-commons/cycling-commons/blob/main/README.md) for the
one-paragraph version of the whole project.

## The stack — what's built, what's next

This started life as "current thinking"; most of it is now running code. Where something below is
still ahead of us, it says so — and if you have strong reasons for a different choice on the parts
still open, open an issue.

- **The spine — PostgreSQL + PostGIS.** **Live.** Climbs, points, and regions are geospatial *and*
  relational; "what's near me / within this region / in this bounding box" is a query; and an ODbL
  bulk export is just a constrained database dump. The whole OpenStreetMap world already runs on it.
  The application serves the map catalog straight from this database — there is no separate content
  store, and everything else plugs into it.
- **Render — MapLibre GL JS.** **Live** in both the application and the prototype, on the keyless
  [OpenFreeMap](https://openfreemap.org) basemap. The next step — worldwide OSM coverage rendered
  from **PMTiles** served as a single file from object storage (no tile server to run) — is
  **specified and pending implementation**; the contract is
  [`docs/specs/coverage-provider.md`](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/coverage-provider.md).
- **A polyglot split, with PostGIS as the contract.** The work divides cleanly by what it actually
  needs:
  - The **serving API and the everyday logic** — queries, the contribution write-path, the
    moderation queue, routes and voting, accounts and 2FA — is **built**, on **Symfony 7 LTS**
    behind nginx + PHP-FPM, where the maintainers are strongest. It lives in `web/` and reads the
    same PostGIS the pipeline writes.
  - The **Python pipeline** (`pipeline/`) owns everything that needs the geospatial/raster/routing
    stack — importing and syncing OpenStreetMap, sampling elevation and gradient from a DEM,
    snapping and routing with Valhalla, generating tiles, and building the open data exports. Today
    it is a FastAPI **scaffold** (health and database checks); the OSM import/sync and tile jobs
    are specified in
    [`docs/specs/osm-data-architecture.md`](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/osm-data-architecture.md)
    and the coverage spec above, while DEM elevation/gradient sampling, Valhalla snapping and
    routing, and the open-data exports are planned but not yet specified. Route and climb lines
    today arrive as rider-drawn or GPX-uploaded geometry (the contract is
    [`docs/specs/route-domain.md`](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/route-domain.md));
    auto-snapping and DEM-derived gradients stay pipeline territory once those jobs are specified.

  The rule of thumb: if a job needs a raster, a road graph, or OSM topology, it's Python; if it's
  aggregation, lifecycle, or CRUD over the database, it's the Symfony tier (the binding placement
  rule lives in
  [`docs/specs/dev-environment.md`](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/dev-environment.md)).
  Neither side owns the data — the database does.
- **The principle behind every choice: boring, non-proprietary, and self-hostable.** The Commons is
  built to outlive any single app and to be handed to an independent foundation (see
  [Governance](governance.md)). That rules out anything proprietary or lock-in-shaped: a future
  steward must be able to run the whole thing on commodity infrastructure, without us.

For depth, follow the links rather than re-reading it here: the full data model and which fields are
hand-entered vs `[auto]`-derived live in the [Data catalog](data-catalog.md), and the engineering
contracts — schema, moderation state machine, route domain, security architecture, dev environment —
are the canonical specs in
[`docs/specs/`](https://github.com/cycling-commons/cycling-commons/tree/main/docs/specs).

## Get involved

The Commons grows two ways, and both need people.

**With code.** The source is at
[github.com/cycling-commons/cycling-commons](https://github.com/cycling-commons/cycling-commons).
The platform code is **source-available under the [PolyForm Shield License 1.0.0](https://github.com/cycling-commons/cycling-commons/blob/main/LICENSE)** — read it, run it, self-host and modify it freely; the one limit is you can't use it to build a product that competes with the Commons. The open *data* (ODbL) and the query API ([access policy](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/osm-data-architecture.md)) are what you build on commercially. (Note: source-available is **not** OSI open-source — the data is open; the platform code is shared but non-compete.) The text of this wiki itself is [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/) — share and adapt it with attribution, same-licence.
Right now the most useful work is on the application itself and the first real slices of the Python
pipeline — the specs in
[`docs/specs/`](https://github.com/cycling-commons/cycling-commons/tree/main/docs/specs) are the
fastest way to orient. Propose changes by pull request; substantive design decisions belong in this
wiki and those specs so there's a single source of truth (see
[Contributing → *this repo*](contributing.md)). If a stack choice above looks wrong to you, that
conversation is welcome.

**With local knowledge.** The flows are real now; the value is real riders' knowledge of real
places — the climbs worth the detour, where the water is, which junction is dangerous, the finest
views. Telling us whether the flows match how you actually think about your roads is worth a lot.
How contribution works is described in [Contributing](contributing.md).

---

*New here? Start with the [Manifesto](manifesto.md), then the [Data catalog](data-catalog.md) and
[Governance](governance.md). This page is the practical companion: how it's built, and how to join.*
