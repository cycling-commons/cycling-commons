# Dockerized development stack — design

**Date:** 2026-06-19
**Status:** approved (pending spec review)
**Goal:** one reproducible, full-stack dev environment every contributor runs identically —
`docker compose up` and the whole Commons stack (PHP↔PostGIS↔Python + frontend + wiki) is live.

## Source of truth for the stack

`wiki/building.md` → "Where we're heading (the stack)":
- **PostgreSQL + PostGIS** — the spine; everything plugs into it
- **Symfony (PHP)** — serving API + everyday logic; reads/writes PostGIS
- **Python pipeline** — geospatial/raster/routing (OSM, DEM, Valhalla, tiles, exports)
- **MapLibre + PMTiles** — frontend (already in `atlas/demo/`)
- Principle: **boring, open, self-hostable**

## Key decisions (settled with user)

- **Orchestration:** Docker **Compose** (editor-agnostic, mirrors deploy). No Dev Containers,
  no FrankenPHP, no `symfony serve` — see [[serving-nginx-not-frankenphp]].
- **API serving:** **nginx + PHP-FPM** (dev mirrors prod). Path: `nginx → php-fpm → Symfony → PostGIS`.
- **Heavy/data services are opt-in** via Compose profiles; their large data stays out of git.
- **Valhalla uses a prebuilt tilebuild the developer downloads** (no in-container tile build);
  the **DEM** is likewise a downloaded directory mounted into the pipeline. Paths are `.env` vars.

## Layout

```
developers/docker/
  compose.yaml
  .env.example                # ports, db creds, DATA paths (committed; real .env is gitignored)
  README.md                   # quick reference (top README links here)
  db/init/01-postgis.sql      # CREATE EXTENSION postgis; dev schema seed (minimal)
  api/Dockerfile              # php:8.3-fpm + extensions + composer
  api/nginx.conf              # nginx vhost fronting php-fpm
  pipeline/Dockerfile         # python:3.12-slim + uvicorn/fastapi
  atlas/demo/                       # (served by stock nginx image, mounts ../../atlas/demo)
api/                          # Symfony 7 app (scaffold)
pipeline/                     # FastAPI app (scaffold)
```

App code lives at repo root (`api/`, `pipeline/`); all Docker glue lives in `developers/docker/`.

## Services

| Service | Image / build | Host port | Notes |
|---|---|---|---|
| `db` | `postgis/postgis:18-3.6` | 5432 | named volume `cc_pgdata`; init SQL enables PostGIS; healthcheck `pg_isready` |
| `api` | build `developers/docker/api` (php:8.3-fpm) + nginx | 8001 | mounts `../../api`; Doctrine→PostGIS; `composer install` at build; healthcheck `/health` |
| `pipeline` | build `developers/docker/pipeline` (python:3.12) | 8002 | mounts `../../pipeline` + DEM dir; `uvicorn --reload`; healthcheck `/health` |
| `atlas` | `nginx:alpine` | 8099 | mounts `../../atlas/demo` read-only; matches current dev port |
| `wiki` | build (python + mkdocs pinned) | 8000 | mounts `../../wiki`; `mkdocs serve -a 0.0.0.0:8000` |

**Profiles (off by default):**
- `routing` → `valhalla/valhalla` mounting `${VALHALLA_TILES}` (downloaded prebuilt), port 8003
- `storage` → `minio/minio` (S3 for PMTiles), ports 9000/9001, named volume

`api` and `pipeline` depend_on `db` (condition: service_healthy).

## Scaffolds (minimal but functional — they must actually boot and connect)

**`api/` (Symfony 7):**
- `composer.json` pinned (symfony/framework-bundle, doctrine-bundle, doctrine/dbal)
- `.env` with `DATABASE_URL` pointing at the `db` service (postgresql, PostGIS-aware)
- Routes: `GET /health` → `{status:"ok"}`; `GET /api/db-check` → runs `SELECT postgis_version()` via DBAL and returns it (proves nginx→fpm→Symfony→PostGIS works end to end)
- `public/index.php` front controller; nginx points here

**`pipeline/` (FastAPI):**
- `requirements.txt` (fastapi, uvicorn, psycopg[binary])
- `app/main.py`: `GET /health` → ok; `GET /db` → `SELECT postgis_version()` via psycopg (proves pipeline→PostGIS); a stub `GET /dem` reporting whether the DEM mount is present
- DB + DEM path from env

## Configuration (`.env.example`)

Ports (`SITE_PORT`, `WIKI_PORT`, `API_PORT`, `PIPELINE_PORT`), DB creds
(`POSTGRES_DB/USER/PASSWORD`), and data paths (`VALHALLA_TILES`, `DEM_DIR`) with safe
local defaults. Committed as `.env.example`; developers copy to `.env` (gitignored).

## README addition

A **"Developers — Docker dev stack"** section: prerequisites (Docker + Compose v2),
`cp developers/docker/.env.example developers/docker/.env`, `docker compose up`, the URL table,
how to enable `--profile routing`/`storage`, and where to drop the downloaded Valhalla tiles + DEM.

## Error handling / DX

- Every service has a healthcheck; `depends_on: service_healthy` so `api`/`pipeline` wait for DB.
- Code is bind-mounted for hot reload (Symfony cache + uvicorn `--reload`; mkdocs live reload).
- Heavy data is opt-in and mounted, never copied into images or committed.
- `.gitignore`: add `developers/docker/.env` and any local data dirs.

## Out of scope (YAGNI)

- Real OSM import / DEM sampling / tile generation logic (pipeline is a connectivity scaffold).
- Production compose (TLS, secrets manager, replicas) — this is the **dev** stack.
- CI wiring. Dev Containers. Kubernetes/Helm.

## Files added

`developers/docker/**`, `api/**` (Symfony scaffold), `pipeline/**` (FastAPI scaffold),
README section, `.gitignore` entries. No changes to `atlas/demo/` or `wiki/` content.
