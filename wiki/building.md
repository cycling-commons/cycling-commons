# Building the Commons

How the Cycling Commons is built and run — and how to help build it. This page is part *what
exists today* and part *where we're heading*, and it says which is which. Nothing here is sealed;
the stack is being chosen in the open, and reasoned pushback is exactly the kind of contribution
we're looking for.

> **Status: clickable prototype.** Right now the Commons is a static demo of the rider-facing
> flows — the map, *add a climb*, voting, the moderator queue. There is **no backend, no database,
> and no stored data yet**. What you can run today shows what the Commons will *feel* like; the
> system that fills it with real data is still ahead of us. We're putting it in the open early to
> show the idea and find people to build it with — in code and in local knowledge.

## Run the demo locally

The prototype is plain HTML, CSS, and JavaScript in [`atlas/demo/`](https://github.com/cycling-commons/cycling-commons/tree/main/atlas/demo) — no build step, no
dependencies to install. Serve the folder over HTTP and open the landing page:

```sh
git clone https://github.com/cycling-commons/cycling-commons.git
cd cycling-commons/atlas/demo

php -S localhost:8000          # if you have PHP
# or
python3 -m http.server 8000    # if you have Python

# then open http://localhost:8000/index.html
```

**Serve it over `http://`, not `file://`.** The maps fetch the [OpenFreeMap](https://openfreemap.org)
basemap and use Photon/Nominatim for place search over the network, and opening pages directly from
disk (`file://`) breaks those requests on CORS. Run a local static server as above and the flows work
end to end. Offline, the map area degrades gracefully to a paper background — the wizard and the rest
of the flow still work.

From the landing page you can click through the whole prototype: the map and its filters, *add a
climb*, the voting round, the per-region pages, and the moderator queue.

**The full stack (for backend work).** The demo above is static and needs nothing installed. When
you start on the backend, there's a Docker dev environment in
[`developers/docker/`](https://github.com/cycling-commons/cycling-commons/tree/main/developers/docker)
that brings the whole stack up the way production will run it — PostgreSQL + PostGIS, the Symfony API
behind nginx + PHP-FPM, and the Python/FastAPI pipeline. Its
[README](https://github.com/cycling-commons/cycling-commons/blob/main/developers/docker/README.md)
has the one-command setup.

## What's in the repo

| Path | Becomes | Holds |
|------|---------|-------|
| [`atlas/demo/`](https://github.com/cycling-commons/cycling-commons/tree/main/atlas/demo) | `cyclingcommons.org` | the clickable prototype (this is what you run) |
| [`wiki/`](index.md) | `wiki.cyclingcommons.org` | all reference documentation — including this page |
| [`docs/`](https://github.com/cycling-commons/cycling-commons/tree/main/docs) | — | per-page design specs |
| [`LICENSE`](https://github.com/cycling-commons/cycling-commons/blob/main/LICENSE) | — | the platform code licence (PolyForm Shield 1.0.0) |
| [`licenses/`](https://github.com/cycling-commons/cycling-commons/tree/main/licenses) | — | the data, media & terms licences |

`atlas/demo/` sits under the `atlas/` umbrella: today it holds the static map demo; the future Symfony app (see the stack below) will live alongside it at `atlas/app/`.

See the [README](https://github.com/cycling-commons/cycling-commons/blob/main/README.md) for the one-paragraph version of the whole project.

## Where we're heading (the stack)

This is **current thinking, not a settled decision**. The shape below is what we're leaning toward
and why; if you have strong reasons for a different choice, open an issue.

- **The spine — PostgreSQL + PostGIS.** Climbs, points, and regions are geospatial *and* relational;
  we need "what's near me / within this region / in this bounding box" queries; and an ODbL bulk
  export is just a constrained database dump. The whole OpenStreetMap world already runs on it. This
  one isn't really up for debate — everything else plugs into it.
- **Render — MapLibre GL JS + PMTiles.** Keyless [OpenFreeMap](https://openfreemap.org) basemap to
  start, vector tiles served as a single file from object storage (no tile server to run), and
  elevation/contours sampled from an open DEM.
- **A polyglot split, with PostGIS as the contract.** The work divides cleanly by what it actually
  needs:
  - A **Python pipeline** for everything that needs the geospatial/raster/routing stack — importing
    and syncing OpenStreetMap, sampling elevation and gradient from a DEM, snapping and routing with
    Valhalla, generating tiles, and building the open data exports.
    Linear features show their provenance honestly: a climb line is either **handmade from OSM road
    geometry**, **auto-snapped via Valhalla**, or a **combination**, with the gradient always derived
    from the DEM. (The prototype's curated climbs are handmade — labelled "OSM roads · geometry
    handmade"; `add-climb.html` uses OSRM only for the live draw-a-route preview.)
  - A **serving API and the everyday logic** — queries, the contribution write-path, the seasonal
    vote tally, the freshness/decay lifecycle, the moderation queue — built on **Symfony**, where the
    maintainers are strongest. It reads the same PostGIS the pipeline writes.

  The rule of thumb: if a job needs a raster, a road graph, or OSM topology, it's Python; if it's
  aggregation, lifecycle, or CRUD over the database, it's the API tier. Neither side owns the data —
  the database does.
- **The principle behind every choice: boring, non-proprietary, and self-hostable.** The Commons is built to
  outlive any single app and to be handed to an independent foundation (see
  [Governance](governance.md)). That rules out anything proprietary or lock-in-shaped: a future
  steward must be able to run the whole thing on commodity infrastructure, without us.

For depth, follow the links rather than re-reading it here: the full data model and which fields are
hand-entered vs `[auto]`-derived live in the [Data catalog](data-catalog.md), and the public query
API (endpoints, attribution, rate limits) is sketched on the
[Developers page](https://cyclingcommons.org/developers.html).

## Get involved

The Commons grows two ways, and both need people.

**With code.** The source is at
[github.com/cycling-commons/cycling-commons](https://github.com/cycling-commons/cycling-commons).
The platform code is **source-available under the [PolyForm Shield License 1.0.0](https://github.com/cycling-commons/cycling-commons/blob/main/LICENSE)** — read it, run it, self-host and modify it freely; the one limit is you can't use it to build a product that competes with the Commons. The open *data* (ODbL) and the query API are what you build on commercially. (Note: source-available is **not** OSI open-source — the data is open; the platform code is shared but non-compete.)
Right now the most useful work is on the prototype itself — sharpening the rider flows — and, as the
backend starts, the first slices of the Python pipeline and the API. Propose changes by pull request; substantive design decisions belong in this wiki so there's
a single source of truth (see [Contributing → *this repo*](contributing.md)). If a stack choice above
looks wrong to you, that conversation is welcome.

**With local knowledge.** The demo shows the flows; the value is real riders' knowledge of real
places — the climbs worth the detour, where the water is, which junction is dangerous, the finest
views. Even before the backend exists, telling us whether the flows match how you actually think
about your roads is worth a lot. How contribution works once it's live is described in
[Contributing](contributing.md).

---

*New here? Start with the [Manifesto](manifesto.md), then the [Data catalog](data-catalog.md) and
[Governance](governance.md). This page is the practical companion: how it's built, and how to join.*
