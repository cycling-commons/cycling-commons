# Cycling Commons — Docker dev stack

One reproducible environment for every contributor. `docker compose up` brings up the whole
stack — database, API, pipeline, frontend, and wiki — wired together exactly as it deploys.

## Prerequisites

- Docker Engine + Docker Compose v2 (`docker compose version`)

## Quick start

```sh
cd developers/docker
cp .env.example .env          # adjust ports/creds if you like
docker compose up --build     # first run builds the images
```

Then open:

| Service    | URL                              | What it is |
|------------|----------------------------------|------------|
| Frontend   | http://localhost:8099            | the MapLibre prototype (`atlas/demo/`) |
| Wiki       | http://localhost:8000            | MkDocs Material (`wiki/`), live reload |
| API        | http://localhost:8001/health     | Symfony health check |
| API ↔ DB   | http://localhost:8001/api/db-check | proves nginx → php-fpm → Symfony → PostGIS |
| Pipeline   | http://localhost:8002/health     | FastAPI health check |
| Pipe ↔ DB  | http://localhost:8002/db         | proves pipeline → PostGIS |
| Postgres   | `localhost:5433`                 | PostGIS 16 (host port 5433 to avoid a local 5432; `.env` for creds) |

The API and pipeline ship only **connectivity scaffolding** (health + DB-version endpoints) —
real endpoints and the geo pipeline get built on top.

## How it's wired

```
browser ──> atlas (nginx, static)            :8099
browser ──> wiki  (mkdocs serve)             :8000
browser ──> api_web (nginx) ──> api (php-fpm, Symfony) ──┐
browser ──> pipeline (FastAPI) ─────────────────────────┼─> db (PostGIS) :5432
                                                         ┘
```

Source folders (`atlas/demo/`, `wiki/`, `api/`, `pipeline/`) are bind-mounted, so edits reload live.
The API's `vendor/` lives in a named volume so the bind mount doesn't hide the installed
dependencies; after changing `api/composer.json`, run `docker compose exec api composer install`
(or `docker compose build api`).

**Local bundles (when they land):** develop them with Composer **path repositories**, not manual
symlinks. Because `vendor/` is a named volume, a host-side symlink into `api/vendor/` isn't visible
inside the container. Instead declare the package in `api/composer.json`
(`{ "type": "path", "url": "../foo-bundle" }`), make sure its source is bind-mounted into the
container, and `composer install` wires it up.

## Opt-in profiles (heavy; off by default)

These need data you download yourself — it never enters git.

**Routing — Valhalla** (consumes a *prebuilt* tile set; no multi-hour build):
```sh
# put your downloaded Valhalla tiles in the path set by VALHALLA_TILES (default ./data/valhalla)
docker compose --profile routing up
# → http://localhost:8003 (Valhalla HTTP API)
```

**Storage — MinIO** (S3-compatible, for PMTiles):
```sh
docker compose --profile storage up
# → http://localhost:9100 (API)  ·  http://localhost:9101 (console)
```

The **DEM** directory (`DEM_DIR`, default `./data/dem`) is mounted into the pipeline at
`/data/dem` even without a profile; drop your downloaded DEM there and check
http://localhost:8002/dem.

## Common commands

```sh
docker compose up -d            # background
docker compose logs -f api      # follow one service
docker compose exec db psql -U cc cyclingcommons
docker compose down             # stop (keeps the db volume)
docker compose down -v          # stop and wipe volumes (fresh database)
```
