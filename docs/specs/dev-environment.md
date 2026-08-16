<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Development Environment & Platform Conventions

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

This document defines the development stack (Docker Compose services, ports,
volumes, gotchas), the serving architecture the dev stack mirrors, the
PHP↔Python job-placement rule, and the cross-cutting platform conventions:
asset pipeline, i18n, SPDX/licence gates, secret handling, and test-database
discipline. Operational how-to (quick start, common commands) lives in
[`developers/docker/README.md`](../../developers/docker/README.md); this
document records the *decisions* and their invariants.

Sibling contracts: data model in [catalog-data-model.md](catalog-data-model.md),
identity/2FA in [account-and-auth.md](account-and-auth.md), CSP/CSRF/limiters in
[security-architecture.md](security-architecture.md), the coverage pipeline in
[coverage-provider.md](coverage-provider.md), the map/search client contract in
[map-and-search.md](map-and-search.md), OSM policy in
[osm-data-architecture.md](osm-data-architecture.md).

---


## Running the suite after `cache:clear --env=dev`

`php bin/console cache:clear --env=dev` followed immediately by `bin/phpunit`
in the same container fails **two** EasyAdmin tests
(`AdminReadOnlyCrudsTest`) with *"The given DashboardController class is not a
valid Dashboard controller"*. The class is fine; the dashboard registry the
`AdminUrlGenerator` consults does not survive the clear.

Measured 2026-08-09: **3 out of 3 runs fail with the dev clear, 0 out of 11
without it.** Clearing the *test* cache afterwards (`--env=test`) makes it
green again, and so does simply not clearing before testing.

Worth knowing because of how it presents: two failures that vanish on a
re-run look exactly like a flaky suite, and re-running until green is how a
real regression gets waved through. This one is an artefact of the workflow,
not of the code — but only if you know that.

## 1. Orchestration: Docker Compose only

The entire dev environment is one Compose project,
`cycling-commons-dev` ([`developers/docker/compose.yaml`](../../developers/docker/compose.yaml)),
driven from the repo root via the `Makefile` (`make setup` / `make up` /
`make down`; `DOCKER_COMP = docker compose -f developers/docker/compose.yaml`).

Rejected alternatives (settled decisions, do not re-propose):

- **No Dev Containers** — editor-agnostic Compose mirrors how the app deploys.
- **No FrankenPHP, no `symfony serve`** — serving is **nginx → PHP-FPM →
  Symfony → PostGIS** in dev *and* prod. Dev mirrors the production request
  path exactly ([`developers/docker/nginx/app.conf`](../../developers/docker/nginx/app.conf)
  is the dev vhost; dev-environment.md §9 for prod topology).
- **No Kubernetes/Helm, no production compose** — prod is provisioned
  server-side (dev-environment.md §9); the compose file is the dev stack only.

App code lives at repo root (`web/` Symfony, `pipeline/` FastAPI,
`atlas/demo/` static prototype, `wiki/` MkDocs); all Docker glue lives in
`developers/docker/`.

## 2. Service table

From `developers/docker/compose.yaml`. Host ports resolve as
`${VAR:-compose-default}`; the committed
[`developers/docker/.env.example`](../../developers/docker/.env.example)
overrides some defaults to avoid collisions with commonly-running local
services — both values are listed where they differ.

| Service | Image / build | Ports (host → container) | Profile | Volumes / notes |
|---|---|---|---|---|
| `db` | `postgis/postgis:18-3.6` | `POSTGRES_PORT`: compose default **5432**, `.env.example` sets **5433** → 5432 | always | named volume `cc_pgdata:/var/lib/postgresql` (dev-environment.md §4); `./db/init` → `/docker-entrypoint-initdb.d` (first-init only); healthcheck `pg_isready` |
| `app` | build `web/` (`php:8.4-fpm` + `pdo_pgsql`, `gettext`, `intl`) | none published (FPM `:9000`, reached only by `web`) | always | bind `web/ → /app`; named volume `cc_api_vendor:/app/vendor` (dev-environment.md §5); cache/logs redirected to `/tmp` (`APP_CACHE_DIR`/`APP_LOG_DIR`) so the bind mount has no permission issues |
| `web` | `nginx:alpine` | `API_PORT` **8001** → 80 | always | bind `web/ → /app:ro`; `./nginx/app.conf` → nginx vhost; healthcheck hits `http://127.0.0.1/health` (IPv4 literal — alpine resolves `localhost` to `::1` but nginx listens IPv4-only) |
| `pipeline` | build `pipeline/` (`python:3.12-slim`, non-root uid 1000) | `PIPELINE_PORT` **8012** → 8000 (compose default and `.env.example` now agree; it was 8002, which collided with `ELEVATION_URL`'s Europe Valhalla) | always | bind `pipeline/ → /app`; `DEM_DIR` (default `./data/dem`) → `/data/dem:ro`; healthcheck `/health`; **the FastAPI scaffold runs only because compose asks for it** via an explicit `command:` — the image itself is a batch worker with no service default |
| `atlas` | `nginx:alpine` | `ATLAS_PORT` **8099** → 80 | always | bind `atlas/demo/ → html:ro`; `./nginx/atlas.conf` serves with `Cache-Control: no-store` so edits always show |
| `wiki` | build `developers/docker/wiki/Dockerfile` (python + pinned mkdocs) | `WIKI_PORT`: compose default **8000**, `.env.example` sets **8013** → 8000 | always | binds `mkdocs.yml`, `wiki/`, `overrides/` read-only; live reload |
| `mailpit` | `axllent/mailpit` | `MAILPIT_UI_PORT` **8025** → 8025 (UI only; SMTP is internal-network `mailpit:1025`, no host SMTP port) | always | bundled so the stack is self-contained; collision with a shared host Mailpit → change `MAILPIT_UI_PORT` or set `MAILER_DSN=smtp://host.docker.internal:1025` |
| `worker` | same build + env as `app` | none | always | the async tier (media-storage-architecture.md; plan task 1): `messenger:consume async` over the Redis-stream transport (`MESSENGER_TRANSPORT_DSN`, default `redis://redis:6379/cc_messages`); `stop_grace_period: 90s` sits above the slowest handler so a deploy never orphans a message mid-flight; THIS container gets the scanner env (`CLAMAV_TCP_ADDR=clamav:3310`, `CLAMAV_REQUIRED=1`), the web container keeps neither; waits for `clamav` healthy |
| `clamav` | `clamav/clamav:stable` | none (internal `clamav:3310`) | always | the release gate's scanner sidecar; `start_period: 180s` on the healthcheck is signature loading, not slowness — the worker waits for healthy so a scan never races the signature load |
| `valhalla` | `ghcr.io/valhalla/valhalla:latest` | `VALHALLA_PORT` **8003** → 8002 | `routing` (opt-in) | `VALHALLA_TILES` (default `./data/valhalla`) → `/custom_files` — a **downloaded prebuilt** tile set, never built in-container (`use_tiles_ignore_pbf=True`, `force_rebuild=False`) |
| `minio` | `minio/minio:latest` | `MINIO_PORT` **9100** → 9000, `MINIO_CONSOLE_PORT` **9101** → 9001 (off MinIO defaults to avoid clashes) | `storage` (opt-in) | named volume `cc_minio:/data`; S3-compatible store for PMTiles — dev mirror of the prod object-storage bucket ([coverage-provider.md](coverage-provider.md)) |

Named volumes: `cc_pgdata`, `cc_api_vendor`, `cc_minio`.

### Environment contract

- The committed `developers/docker/.env.example` (ports, DB creds, data paths)
  is copied to a **gitignored** `developers/docker/.env`
  (`.gitignore` line `developers/docker/.env`). All values have safe local
  defaults; no secret is required to run the stack.
- `db`, `web`, and `pipeline` carry **healthchecks**; `app` and `pipeline` gate
  on `depends_on: db: condition: service_healthy` so they never race the
  database (`app` additionally waits for `mailpit` via `service_started`).
- **Code is bind-mounted for hot reload** (Symfony dev cache, `uvicorn
  --reload`, mkdocs live reload, static nginx mounts).
- **Heavy data never enters git or images.** Valhalla tiles and the DEM are
  downloaded by the developer and mounted via `.env` path vars; the opt-in
  profiles (`make up-routing` / `make up-storage` / `make up-all`) keep them
  off the default `up`.
- PHP upload ceilings for the dev container live in `web/Dockerfile`
  (`cc-uploads.ini`: `upload_max_filesize = 16M`, `post_max_size = 20M`) and
  in the nginx vhost (`client_max_body_size 16m`,
  `developers/docker/nginx/app.conf`) — sized above the 15 MiB GPX proposal
  cap (`GpxParser::MAX_BYTES`) owned by [route-domain.md](route-domain.md).

### First-time setup

`make setup` is the one-command bootstrap and encodes steps that are *not*
covered by `docker compose up` alone: `composer install` into the vendor
volume, `assets:install public --symlink --relative` (EasyAdmin serves its core
CSS/JS from `public/bundles/`, not AssetMapper — without this every `/admin`
page renders unstyled), migrations, `app:world:import` (world reference data is
seeded out-of-band, not by a migration), and `doctrine:fixtures:load` with
`--purge-exclusions` for the three `world_*` tables. Demo accounts and the 2FA
enrolment invariant for elevated fixtures are owned by
[account-and-auth.md](account-and-auth.md).

## 3. PostGIS is the spine — and the PHP↔Python boundary

One PostGIS database is the integration contract: Symfony (Doctrine) and the
Python pipeline (psycopg) both read and write it; neither calls the other's
HTTP API for data work.

**Job placement rule** — classify by what the computation *requires*, never by
where the job sits in a pipeline:

| Job needs | Belongs in |
|---|---|
| `GROUP BY` / averages / threshold-compare / joins / timestamp math / lifecycle & CRUD | **Symfony** (Doctrine reading PostGIS; recurring jobs via Scheduler/Messenger) |
| Rasters (rasterio/DEM), road graph (Valhalla), OSM topology (pyosmium), tiling (tippecanoe), geometry ops beyond PostGIS, trained ML models | **Python `pipeline`** |

Corollaries:

- `pandas` as the only "Python" dependency is a tell the job is really SQL and
  belongs in PHP.
- Consuming a geospatially-derived *column* (gradient, popularity) does not
  need the geo stack — producer Python, consumer PHP; PostGIS is the handoff.
- Rule-vs-model jobs (rideability, spam scoring) start as PHP rules and move
  to Python only if they become real models.

The pipeline container is an **internal batch worker**, not a public serving
tier — Symfony serves all user-facing queries
([coverage-provider.md](coverage-provider.md)).

## 4. Database bootstrap and gotchas

**Extensions live outside migrations.** Doctrine migrations own the schema but
deliberately never `CREATE EXTENSION` (that needs superuser and is
environment-specific). PostGIS is enabled by
[`developers/docker/db/init/01-postgis.sql`](../../developers/docker/db/init/01-postgis.sql)
(`postgis`, `postgis_topology`) — which Docker runs **only on first cluster
init**, i.e. only when the data volume is empty. Any database created later on
the same cluster (the test DB, a manual recreate) must have the extensions
enabled explicitly; `make test-db-reset` does this (dev-environment.md §8).

**PG18 volume mount path.** PG18-era images store data in a major-version
subdirectory (`PGDATA=/var/lib/postgresql/18/docker`). The `cc_pgdata` volume
therefore mounts at **`/var/lib/postgresql`** (the parent), *not*
`/var/lib/postgresql/data` — mounting the old path loses persistence and
breaks future `pg_upgrade --link`. A pre-existing volume from an older PG major
cannot auto-upgrade: recreate it (`docker compose rm -sf db && docker volume rm
cycling-commons-dev_cc_pgdata && docker compose up -d db`); dev data is
re-seedable.

## 5. Container gotchas (known, recurring)

- **`vendor/` named volume.** `web/` is bind-mounted over `/app`, which would
  shadow the image's installed dependencies — so `vendor/` lives in the
  `cc_api_vendor` named volume. Consequences: after changing
  `web/composer.json`, run `docker compose exec app composer install` (a host
  `composer install` does not reach the container); host-side symlinks into
  `web/vendor/` are invisible in-container — local bundles must use Composer
  **path repositories** with their source bind-mounted.
- **nginx exits 127 / "mount directory onto file".** On Docker Desktop/WSL2,
  a single-file bind mount (the `nginx/*.conf` files) can freeze a stale
  staging path into an existing container; `docker start` replays the broken
  mount forever. Fix: `docker compose up -d --force-recreate <svc>` — never a
  code change.
- **Recreating `app` gives it a new IP** — also `--force-recreate web` so
  nginx re-resolves the FPM upstream (otherwise stale-upstream 502s).
- **EasyAdmin unstyled** — `public/bundles/` missing; run `assets:install`
  (baked into `make setup`, dev-environment.md §2). `public/bundles` is gitignored; don't commit
  the symlink.
- **EA CRUD pages need the `intl` C extension** in the container
  (`web/Dockerfile` installs it); `symfony/intl` (pure PHP) does not satisfy
  it, and host-run tests passing proves nothing about the container.

## 6. Web application architecture

- **PHP 8.4 + Symfony 7.4 LTS** (`web/composer.json`: `php >=8.4`,
  `symfony.require: 7.4.*`) — newest battle-tested PHP, LTS support horizon,
  per the *boring / open / self-hostable* principle.
- **Hybrid rendering.** Content, auth, forms, contribution and moderation
  pages are server-rendered Twig. The map is a MapLibre JS client app booted
  from a thin Twig shell (`web/templates/map/index.html.twig` +
  `web/assets/map/*.js`). No JS framework. (client contract:
  [map-and-search.md](map-and-search.md))
- **AssetMapper, no Node.** Assets are served and versioned via
  `web/importmap.php` + AssetMapper; there is no `package.json`, no Vite/Webpack
  build step — nothing for a future foundation to operate beyond PHP itself.
  The one exception to AssetMapper serving is EasyAdmin's own assets
  (dev-environment.md §5).
- **gzip is configured in the vhost**, not PHP
  (`developers/docker/nginx/app.conf`): `gzip_types` covers
  `application/json`, `application/geo+json`, `application/javascript`,
  `text/css`, `image/svg+xml` with `gzip_min_length 1024`, `gzip_comp_level 5`,
  `gzip_vary on`. Rationale: the map catalog JSON is ~1.15 MB raw and
  compresses ~8–10× (~130 KB); HTML is compressed by default but JSON/assets
  are not. Prod vhosts must carry the equivalent directives.
- Only the front controller executes: nginx routes everything through
  `public/index.php` and returns 404 for any other `.php` path.

## 7. Platform conventions

### SPDX headers

Every code file carries an SPDX header as its first meaningful line:
`LicenseRef-PolyForm-Shield-1.0.0` for code, `ODbL-1.0` for data fixtures.
Enforced by `web/tools/check-spdx.sh` (fails on any tracked `.php`/`.twig`
file under `src/`, `tests/`, `templates/` without one), run in `make app-test`
and CI (`.github/workflows/ci-app.yml`). A companion gate,
`web/tools/check-licenses.sh`, fails the build if any non-dev Composer
dependency carries a copyleft licence incompatible with Shield-licensed
top-level code.

### Secrets: committed placeholders + layered scanning

The repo is public; the convention is **committed placeholder files, gitignored
real values**:

- `web/.env` (committed) holds placeholders only (`APP_SECRET=dev-not-secret-change-me`,
  the local-docker `cc:cc@db` DSN); real values go in `web/.env.local`
  (gitignored) or deployment secrets. Same pattern for
  `developers/docker/.env.example` → `.env`.
- Tokens never live in committed assets — e.g. the Mapillary token is
  env-injected via Twig, not a committed `config.js`.

Scanning is layered, front line first
([`.pre-commit-config.yaml`](../../.pre-commit-config.yaml),
[CONTRIBUTING.md](../../CONTRIBUTING.md)):

1. **pre-commit** — gitleaks on staged changes; blocks the commit before it
   exists.
2. **pre-push** — `tools/gitleaks-prepush.sh` scans the outgoing commit range;
   catches secrets committed with `--no-verify`.
3. **CI backstop** — `.github/workflows/secret-scan.yml` re-scans full history
   with gitleaks + TruffleHog (`--only-verified`) on every push/PR.
4. **GitHub Push Protection** — the only *server-enforced* layer; enabled when
   the repo goes public (local hooks are bypassable, CI runs post-push).

`.gitleaks.toml` holds the allowlist (currently one false positive). The same
`pre-commit install` also enables the translation-parity hook
(dev-environment.md §7 i18n).

### i18n (first written spec — the contract)

Day-one internationalisation across **EN / FR / NL / DE / ES**:

- **Enabled locales:** `framework.default_locale: en`,
  `enabled_locales: [en, fr, nl, de, es]`
  (`web/config/packages/translation.yaml`).
- **Path-prefix routing with clean EN.** English is served unprefixed; the
  other locales carry a path prefix (`/regions`, `/fr/regions`, `/nl/…`,
  `/de/…`, `/es/…`). The prefix map is the single constant
  `App\Routing\LocalePrefix::PATHS` (`web/src/Routing/LocalePrefix.php`),
  applied as a class-level `#[Route(LocalePrefix::PATHS)]` on localized
  controllers; Symfony generates one route per locale and sets `_locale` from
  the matched path. Keep the constant in sync with `enabled_locales`.
  Locale subdomains were rejected for the app.
- **One `messages` domain.** All user-facing strings are `|trans` keys in
  `web/translations/messages.{en,fr,nl,de,es}.yaml` — no per-feature domains.
- **Validator-message rule:** `framework.validation.translation_domain` is set
  to `messages` (`web/config/packages/validator.yaml`), so every constraint
  message **must** be a catalogue key — leaving any built-in default (English)
  message on a constraint ships untranslated text to non-English users (see
  `App\Form\CatalogFieldConstraints` for the pattern of repointing built-in
  constraint messages at catalogue keys).
- **Parity gate:** `web/tools/check-translations.sh` fails if any non-default
  locale catalogue has missing or extra keys vs `messages.en.yaml`; runs in
  `make app-test` and as a pre-commit hook when translation files are staged.
- **Locale selection:** anonymous switching via
  `GET /i18n/{_locale}` (`App\Controller\LocaleController`, session-stored);
  authenticated users persist a `User.locale` preference which
  `LoginSuccessHandler` restores into the session and `SettingsController`
  updates.
- **ES has not had a native-speaker review.** Spanish was added on
  2026-08-08 and every string in `messages.es.yaml` was written in one pass
  by one translator; nobody on the project reads Spanish as a first language
  yet. The catalogue is complete and in parity, and it is enabled, but treat
  its copy as unreviewed until a Spanish-speaking rider has read it. Tracked
  in `docs/TODO.md`.

## 7a. Shared state — Redis (2026-08-08)

Production is a **cluster**: a load balancer in front of two nginx frontends
(one shared DB server behind them). Anything that has to agree across those
frontends therefore may not live on a node's disk. Before this, all of it did:

| State | On local disk it meant |
|---|---|
| Sessions (`handler_id: null`) | log in on node A, logged out on node B |
| Every rate limiter | counters per node, so every published limit was doubled |
| `media_urgent_breaker_limiter` | the **site-wide** auto-withhold budget promised by photo-uploads.md §6c was per node, and so was the emergency stop |
| `cache.media_pow` | a proof-of-work challenge issued by one node could not be verified by the other, so the anonymous photo-report route failed intermittently — the one route that must never bounce someone reporting a photo of themselves |

All of it now goes through Redis, addressed by a single `REDIS_URL`:

- `framework.session.handler_id: '%env(REDIS_URL)%'`
- `framework.cache.app: cache.adapter.redis`, provider `%env(REDIS_URL)%`
- every named pool in `rate_limiter.yaml` uses `adapter: cache.app` rather than
  naming an adapter itself, so it inherits whatever the environment's app pool
  is. That indirection is also what finally made the **test** override real: the
  pools used to name `cache.adapter.filesystem` outright, so
  `test/framework.yaml`'s `app: cache.adapter.array` never reached them and
  limiter counters persisted between phpunit runs. The suite needs no Redis, and
  got about five minutes faster when they stopped touching the filesystem.

**The image needs `ext-redis`** (`pecl install redis`, in `web/Dockerfile`) —
**a production host without it fails closed on the first request**, so it belongs
on the deploy checklist next to `ext-zip`.

The dev stack ships its own `redis` service, deliberately **not** published to a
host port so it cannot collide with a Redis you already run for something else.
If you have one, point at it instead in `developers/docker/.env`:

```
REDIS_URL=redis://host.docker.internal:6379
```

## 8. Testing discipline

- `make app-test` is the full local gate: `phpunit` + `phpstan` + `psalm` +
  `php-cs-fixer --dry-run` + the SPDX, licence, and translation checks.
  CI (`.github/workflows/ci-app.yml`) runs the same tool chain minus the
  translation-parity gate (see Open questions) against a
  `postgis/postgis:18-3.6` service container, plus an advisory Rector pass.
- **The test suite refuses non-`_test` databases.** `web/tests/bootstrap.php`
  hard-stops unless the `DATABASE_URL` database name ends in `_test` — because
  a real environment variable (like the compose-provided dev-DB DSN in the
  `app` container) beats every `.env*` file including `.env.test`. To run
  phpunit inside the container, pass `-e DATABASE_URL=…/cyclingcommons_test`
  explicitly.
- **`make test-db-reset`** drops and rebuilds `cyclingcommons_test`. It exists
  because the DAMA transaction wrapper only guards phpunit-managed runs — the
  test DB accumulates stray committed rows from out-of-band runs (symptom:
  unique-key violations on re-run). A clean rebuild needs three things
  migrations alone don't cover: (1) `CREATE EXTENSION postgis,
  postgis_topology` (the first-init script never re-runs, dev-environment.md
  §4), (2) migrations,
  (3) `app:world:import` (world reference data is seeded out-of-band, and
  moderator-area tests validate country codes against it).

## 9. Production topology and deploy

**Specified, pending implementation** (go-live has not happened; the dated
gate lives in `docs/TODO.md`).

Production rides a **shared Hetzner cluster** (not a single VPS):

- **Load balancer → 2 nginx/PHP frontend servers.** Cycling Commons is one
  more vhost + certificate on the shared nginx targets; TLS is either
  TCP-passthrough to nginx or LB-terminated with SNI certs (open choice).
- **1 database server, shared** with the other tenant — CC's PostGIS lives
  alongside it. This is a standing design constraint: user-facing map display
  must not generate per-pan DB queries (one reason static coverage tiles won —
  [coverage-provider.md](coverage-provider.md)).
- **1 worker server** running Valhalla and the Symfony Messenger workers — the
  natural home for scheduled batch jobs (e.g. the weekly coverage build).

**Deploy is a server-side concern.** The GitHub workflows
(`.github/workflows/deploy-staging.yaml`, `deploy-production.yaml`) contain no
build or deploy logic: each one's entire job is a single SSH invocation passing
the target environment name (`staging` / `production`) as the command — the
SSH forced-command pattern. All deploy scripts live server-side; the repo
carries none. Branch flow: `main` → `staging` → `production`, each deploy
branch auto-deploying on push (plus manual `workflow_dispatch`).

## Open questions

- **CONTRIBUTING.md Mailpit paragraph is stale**: it states "the stack does
  **not** bundle its own Mailpit" and points at a host instance, but
  `developers/docker/compose.yaml` bundles a `mailpit` service (the app's
  default `MAILER_DSN` is `smtp://mailpit:1025`). The compose file is the
  contract; CONTRIBUTING.md needs updating to describe the bundled service.
  The `MAILER_DSN` comment in `web/.env` carries the same stale claim.
- **CI does not run the translation-parity gate**: `ci-app.yml` invokes the
  individual tools but omits `tools/check-translations.sh` (CONTRIBUTING.md
  implies CI enforces it). Either add it to CI or correct the claim.
- **`atlas` service retirement**: the static prototype container remains in
  the compose file; its removal is decided when the HTML demo (`atlas/demo/`,
  old `main`) is finally swept post-go-live.
- **`php:8.4-fpm` base image floats** (its Debian variant has silently jumped
  majors before); pinning a specific variant for reproducibility is an open
  consideration, not a decision.
- **TLS termination point** (LB with SNI certs vs TCP-passthrough to nginx) is
  undecided — recorded as open in the prod-topology note above.
