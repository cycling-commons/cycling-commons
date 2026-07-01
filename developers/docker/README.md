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
| Postgres   | `localhost:5433`                 | PostGIS 18 (host port 5433 to avoid a local 5432; `.env` for creds) |

The API and pipeline ship only **connectivity scaffolding** (health + DB-version endpoints) —
real endpoints and the geo pipeline get built on top.

## Dev mail (Mailpit)

All outbound email from the Symfony app (registration confirmation, password-reset links, 2FA
setup) is caught by the **bundled Mailpit** — no real mail is sent in local development. The app
talks to it over the internal network (`mailpit:1025`), so no host SMTP port is published; only
the web inbox is exposed, at **<http://localhost:8025>**.

Already running a Mailpit on `:8025` (e.g. a shared instance across projects)? Either move this
one's inbox aside:

```
MAILPIT_UI_PORT=8026   # in developers/docker/.env
```

…or skip the bundled one and point the app at yours:

```
MAILER_DSN=smtp://host.docker.internal:1025
```

## Bootstrap accounts

After `docker compose up` and with Symfony migrations applied, create users from the repo root:

```sh
make app-create-admin email=you@example.com    # ROLE_ADMIN
make app-create-curator email=you@example.com  # ROLE_CURATOR (for /moderate)
```

Both commands prompt securely for the password (input hidden, never visible on screen or in shell history).

Curators who have not yet enrolled in 2FA are redirected to `/2fa/setup` on first login to a protected page — enrolment is required before `/moderate` is accessible.

## Seed sample accounts and a dev moderation queue

```sh
docker compose exec app php bin/console doctrine:fixtures:load
```

> **Warning: `doctrine:fixtures:load` PURGES the entire database before seeding.** Only run this against a local/dev DB.

Loads `curator@example.test` (ROLE_CURATOR, 2FA preset) and `rider@example.test` (ROLE_USER), plus a sample moderation queue at `/moderate`.

## Contribution and moderation pages

| Page | URL | Auth |
|------|-----|------|
| Contribute hub | http://localhost:8001/contribute | public |
| Add a climb | http://localhost:8001/add-climb | ROLE_USER |
| Improve a place | http://localhost:8001/improve | ROLE_USER |
| Vote | http://localhost:8001/vote | ROLE_USER |
| Moderate queue | http://localhost:8001/moderate | ROLE_CURATOR + 2FA |

**Note:** contribution and moderation form submissions are currently stubbed — they issue a `CC-…` receipt and write a log line but do not persist data. This is an explicit seam; real persistence arrives with the future data-API spec.

## How it's wired

```
browser ──> atlas (nginx, static)            :8099
browser ──> wiki  (mkdocs serve)             :8000
browser ──> web (nginx) ──> app (php-fpm, Symfony) ──────┐
browser ──> pipeline (FastAPI) ─────────────────────────┼─> db (PostGIS) :5432
                                                         ┘
```

Source folders (`atlas/demo/`, `wiki/`, `web/`, `pipeline/`) are bind-mounted, so edits reload live.
The web app's `vendor/` lives in a named volume so the bind mount doesn't hide the installed
dependencies; after changing `web/composer.json`, run `docker compose exec app composer install`
(or `docker compose build api`).

**Local bundles (when they land):** develop them with Composer **path repositories**, not manual
symlinks. Because `vendor/` is a named volume, a host-side symlink into `web/vendor/` isn't visible
inside the container. Instead declare the package in `web/composer.json`
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
