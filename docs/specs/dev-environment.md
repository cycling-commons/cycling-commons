<!-- SPDX-License-Identifier: AGPL-3.0-only -->

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
not of the code, but only if you know that.

## 1. Orchestration: Docker Compose only

The entire dev environment is one Compose project,
`cycling-commons-dev` ([`developers/docker/compose.yaml`](../../developers/docker/compose.yaml)),
driven from the repo root via the `Makefile` (`make setup` / `make up` /
`make down`; `DOCKER_COMP = docker compose -f developers/docker/compose.yaml`).

Rejected alternatives (settled decisions, do not re-propose):

- **No Dev Containers.** Editor-agnostic Compose mirrors how the app deploys.
- **No FrankenPHP, no `symfony serve`.** Serving is **nginx → PHP-FPM →
  Symfony → PostGIS** in dev *and* prod. Dev mirrors the production request
  path exactly ([`developers/docker/nginx/app.conf`](../../developers/docker/nginx/app.conf)
  is the dev vhost; dev-environment.md §9 for prod topology).
- **No Kubernetes/Helm, no production compose.** Prod is provisioned
  server-side (dev-environment.md §9); the compose file is the dev stack only.

App code lives at repo root (`web/` Symfony, `pipeline/` FastAPI,
`atlas/demo/` static prototype, `wiki/` MkDocs); all Docker glue lives in
`developers/docker/`.

## 2. Service table

From `developers/docker/compose.yaml`. Host ports resolve as
`${VAR:-compose-default}`; the committed
[`developers/docker/.env.example`](../../developers/docker/.env.example)
overrides some defaults to avoid collisions with commonly-running local
services, and both values are listed where they differ.

**Every published port binds `${BIND_ADDR}`, which defaults to `127.0.0.1`.**
The stack used to bind `0.0.0.0`, so joining any café or office network
published a PostGIS with a two-letter fallback password, a MinIO with its
console, and an unauthenticated pipeline API to everyone on it (security scan
2026-08-25). Loopback is the right default because everything here is either
reached from this machine or reached container-to-container over the compose
network, which port publishing has nothing to do with. Set `BIND_ADDR=0.0.0.0`
in `developers/docker/.env` when you deliberately want a phone or a colleague
to reach the stack, and change the passwords first.

`POSTGRES_PASSWORD` and `MINIO_ROOT_PASSWORD` use `${VAR:?message}` rather than
a fallback value, so a missing `.env` stops the stack with a readable error
instead of quietly booting a database whose password is `cc`. Both are set in
`.env.example`, so `cp .env.example .env` is still the whole setup step.

| Service | Image / build | Ports (host → container) | Profile | Volumes / notes |
|---|---|---|---|---|
| `db` | `postgis/postgis:18-3.6` | `POSTGRES_PORT`: compose default **5432**, `.env.example` sets **5433** → 5432 | always | named volume `cc_pgdata:/var/lib/postgresql` (dev-environment.md §4); `./db/init` → `/docker-entrypoint-initdb.d` (first-init only); healthcheck `pg_isready` |
| `app` | build `web/` (`php:8.4-fpm` + `pdo_pgsql`, `gettext`, `intl`) | none published (FPM `:9000`, reached only by `web`) | always | bind `web/ → /app`; named volume `cc_api_vendor:/app/vendor` (dev-environment.md §5); cache/logs redirected to `/tmp` (`APP_CACHE_DIR`/`APP_LOG_DIR`) so the bind mount has no permission issues |
| `web` | `nginx:alpine` | `API_PORT` **8001** → 80 | always | bind `web/ → /app:ro`; `./nginx/app.conf` → nginx vhost; healthcheck hits `http://127.0.0.1/health` (IPv4 literal, because alpine resolves `localhost` to `::1` but nginx listens IPv4-only) |
| `pipeline` | build `pipeline/` (`python:3.12-slim`, non-root uid 1000) | `PIPELINE_PORT` **8012** → 8000 (compose default and `.env.example` now agree; it was 8002, which collided with `ELEVATION_URL`'s Europe Valhalla) | always | bind `pipeline/ → /app`; `DEM_DIR` (default `./data/dem`) → `/data/dem:ro`; healthcheck `/health`; **the FastAPI scaffold runs only because compose asks for it** via an explicit `command:`, because the image itself is a batch worker with no service default |
| `atlas` | `nginx:alpine` | `ATLAS_PORT` **8099** → 80 | always | bind `atlas/demo/ → html:ro`; `./nginx/atlas.conf` serves with `Cache-Control: no-store` so edits always show |
| `wiki` | build `developers/docker/wiki/Dockerfile` (python + pinned mkdocs) | `WIKI_PORT`: compose default **8000**, `.env.example` sets **8013** → 8000 | always | binds `mkdocs.yml`, `wiki/`, `overrides/` read-only; live reload |
| `mailpit` | `axllent/mailpit` | `MAILPIT_UI_PORT` **8025** → 8025 (UI only; SMTP is internal-network `mailpit:1025`, no host SMTP port) | always | bundled so the stack is self-contained; collision with a shared host Mailpit → change `MAILPIT_UI_PORT` or set `MAILER_DSN=smtp://host.docker.internal:1025` |
| `worker` | same build + env as `app`, **as `www-data`** (`user:` in compose) | none | always | the async tier (media-storage-architecture.md §3): `messenger:consume async` over the Redis-stream transport (`MESSENGER_TRANSPORT_DSN`, default `redis://redis:6379/cc_messages`); `stop_grace_period: 90s` sits above the slowest handler so a deploy never orphans a message mid-flight; THIS container gets the scanner env (`CLAMAV_TCP_ADDR=clamav:3310`, `CLAMAV_REQUIRED=1`), the web container keeps neither; waits for `clamav` healthy. **Rider photos do not appear without it**: the upload endpoint only quarantines and dispatches, so a stack started without `worker` leaves every photo stuck at "still checking" (`docker compose logs -f worker` is the first place to look). **Runs as `www-data`**: the image carries no `USER` because the php-fpm master must start as root to drop its own pool workers, and this service replaces that CMD with a plain console command, so it inherited the root fpm needed and never dropped it. It is also the container that unpacks stranger-supplied image bytes through Imagick (security scan 2026-08-25). Nothing it does needs root: cache and logs go to `/tmp` via `APP_CACHE_DIR`/`APP_LOG_DIR`, and `/app/vendor` is only read |
| `clamav` | `clamav/clamav:stable` | none (internal `clamav:3310`) | always | the release gate's scanner sidecar; `start_period: 180s` on the healthcheck is signature loading, not slowness, and the worker waits for healthy so a scan never races the signature load |
| `valhalla` | `ghcr.io/valhalla/valhalla:latest` | `VALHALLA_PORT` **8003** → 8002 | `routing` (opt-in) | `VALHALLA_TILES` (default `./data/valhalla`) → `/custom_files`, a **downloaded prebuilt** tile set, never built in-container (`use_tiles_ignore_pbf=True`, `force_rebuild=False`) |
| `minio` | `minio/minio:latest` | `MINIO_PORT` **9100** → 9000, `MINIO_CONSOLE_PORT` **9101** → 9001 (off MinIO defaults to avoid clashes) | `storage` (opt-in) | named volume `cc_minio:/data`; S3-compatible store for PMTiles, the dev mirror of the prod object-storage bucket ([coverage-provider.md](coverage-provider.md)) |
| `minio-media-bucket` | `minio/mc:latest` | none | `storage` (opt-in) | one-shot bucket bootstrap, exits immediately, `mb --ignore-existing` makes reruns free: creates `cc-media-eu` **with** the anonymous-read policy the browser needs, and `cc-media-private` **without one**. That asymmetry is the quarantine (`media-storage-architecture.md` §2.2): an unscanned upload sits in the private bucket world-unreadable until the worker's clean verdict physically moves derivatives into the public one. The coverage bucket is not created here - `pipeline/coverage/publish.py::ensure_bucket` already owns it |

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
  downloaded by the developer and mounted via `.env` path vars; the one opt-in
  profile (`make up-routing`) keeps Valhalla off the default `up`. MinIO and
  its two nginx fronts (`media-proxy`, `tiles-proxy`) are in the default `up`:
  the map's coverage layer and every rider photo read through them, so a stack
  without them looks broken in ways that read as product bugs.
- PHP upload ceilings for the dev container live in `web/Dockerfile`
  (`cc-uploads.ini`: `upload_max_filesize = 16M`, `post_max_size = 20M`) and
  in the nginx vhost (`client_max_body_size 16m`,
  `developers/docker/nginx/app.conf`), sized above the 15 MiB GPX proposal
  cap (`GpxParser::MAX_BYTES`) owned by [route-domain.md](route-domain.md).

### First-time setup

`make setup` is the one-command bootstrap and encodes steps that are *not*
covered by `docker compose up` alone: `composer install` into the vendor
volume, `assets:install public --symlink --relative` (EasyAdmin serves its core
CSS/JS from `public/bundles/`, not AssetMapper, and without this every `/admin`
page renders unstyled), migrations, `app:world:import` (world reference data is
seeded out-of-band, not by a migration), and `doctrine:fixtures:load` with
`--purge-exclusions` for the three `world_*` tables. Demo accounts and the 2FA
enrolment invariant for elevated fixtures are owned by
[account-and-auth.md](account-and-auth.md).

## 3. PostGIS is the spine, and the PHP↔Python boundary

One PostGIS database is the integration contract: Symfony (Doctrine) and the
Python pipeline (psycopg) both read and write it; neither calls the other's
HTTP API for data work.

**Job placement rule:** classify by what the computation *requires*, never by
where the job sits in a pipeline:

| Job needs | Belongs in |
|---|---|
| `GROUP BY` / averages / threshold-compare / joins / timestamp math / lifecycle & CRUD | **Symfony** (Doctrine reading PostGIS; recurring jobs via Scheduler/Messenger) |
| Rasters (rasterio/DEM), road graph (Valhalla), OSM topology (pyosmium), tiling (tippecanoe), geometry ops beyond PostGIS, trained ML models | **Python `pipeline`** |

Corollaries:

- `pandas` as the only "Python" dependency is a tell the job is really SQL and
  belongs in PHP.
- Consuming a geospatially-derived *column* (gradient, popularity) does not
  need the geo stack: producer Python, consumer PHP, and PostGIS is the handoff.
- Rule-vs-model jobs (rideability, spam scoring) start as PHP rules and move
  to Python only if they become real models.

The pipeline container is an **internal batch worker**, not a public serving
tier. Symfony serves all user-facing queries
([coverage-provider.md](coverage-provider.md)).

## 4. Database bootstrap and gotchas

**Extensions live outside migrations.** Doctrine migrations own the schema but
deliberately never `CREATE EXTENSION` (that needs superuser and is
environment-specific). PostGIS is enabled by
[`developers/docker/db/init/01-postgis.sql`](../../developers/docker/db/init/01-postgis.sql)
(`postgis`, `postgis_topology`), which Docker runs **only on first cluster
init**, i.e. only when the data volume is empty. Any database created later on
the same cluster (the test DB, a manual recreate) must have the extensions
enabled explicitly; `make test-db-reset` does this (dev-environment.md §8).

**PG18 volume mount path.** PG18-era images store data in a major-version
subdirectory (`PGDATA=/var/lib/postgresql/18/docker`). The `cc_pgdata` volume
therefore mounts at **`/var/lib/postgresql`** (the parent), *not*
`/var/lib/postgresql/data`. Mounting the old path loses persistence and
breaks future `pg_upgrade --link`. A pre-existing volume from an older PG major
cannot auto-upgrade: recreate it (`docker compose rm -sf db && docker volume rm
cycling-commons-dev_cc_pgdata && docker compose up -d db`); dev data is
re-seedable.

## 5. Container gotchas (known, recurring)

- **`vendor/` named volume.** `web/` is bind-mounted over `/app`, which would
  shadow the image's installed dependencies, so `vendor/` lives in the
  `cc_api_vendor` named volume. Consequences: after changing
  `web/composer.json`, run `docker compose exec app composer install` (a host
  `composer install` does not reach the container); host-side symlinks into
  `web/vendor/` are invisible in-container, so local bundles must use Composer
  **path repositories** with their source bind-mounted.
- **nginx exits 127 / "mount directory onto file".** On Docker Desktop/WSL2,
  a single-file bind mount (the `nginx/*.conf` files) can freeze a stale
  staging path into an existing container; `docker start` replays the broken
  mount forever. Fix: `docker compose up -d --force-recreate <svc>`, never a
  code change.
- **Recreating `app` gives it a new IP**, so also `--force-recreate web` so
  nginx re-resolves the FPM upstream (otherwise stale-upstream 502s).
- **EasyAdmin unstyled**: `public/bundles/` missing; run `assets:install`
  (baked into `make setup`, dev-environment.md §2). `public/bundles` is gitignored; don't commit
  the symlink.
- **EA CRUD pages need the `intl` C extension** in the container
  (`web/Dockerfile` installs it); `symfony/intl` (pure PHP) does not satisfy
  it, and host-run tests passing proves nothing about the container.
- **The php-fpm pool runs as the host user, not `www-data`.** The dev-only
  translation tool writes straight into the bind-mounted
  `web/translations/messages.<locale>.yaml` (translations.md §7.3), which
  needs directory write permission from the same uid that owns the checkout.
  `developers/docker/php-fpm-dev-user.conf`, mounted by the `app` service as
  `zz-dev-user.conf`, overrides the pool's `user`/`group` to
  `DEV_UID`/`DEV_GID` (default 1000; override in `developers/docker/.env`).
  `web/Dockerfile` keeps its php-fpm master root on purpose so it can drop
  each worker itself; this changes only what it drops *to*. `composer
  install`, `assets:install` and `app:region:scaffold` in the `Makefile`
  likewise run as `$(DEV_UID):$(DEV_GID)` via `docker compose exec --user`,
  for the same reason: `composer install` running as root is why
  `cc_api_vendor` and `public/bundles` used to end up root-owned on the host.
  A restart of the `app` service is required after changing `DEV_UID`/
  `DEV_GID`.

## 6. Web application architecture

- **PHP 8.4 + Symfony 7.4 LTS** (`web/composer.json`: `php >=8.4`,
  `symfony.require: 7.4.*`): newest battle-tested PHP, LTS support horizon,
  per the *boring / open / self-hostable* principle.
- **Hybrid rendering.** Content, auth, forms, contribution and moderation
  pages are server-rendered Twig. The map is a MapLibre JS client app booted
  from a thin Twig shell (`web/templates/map/index.html.twig` +
  `web/assets/map/*.js`). No JS framework. (client contract:
  [map-and-search.md](map-and-search.md))
- **AssetMapper, no Node.** Assets are served and versioned via
  `web/importmap.php` + AssetMapper; there is no `package.json`, no Vite/Webpack
  build step, and nothing for a future foundation to operate beyond PHP itself.
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
`AGPL-3.0-only` for code, `ODbL-1.0` for data fixtures.
Enforced by `web/tools/check-spdx.sh` (fails on any tracked `.php`/`.twig`
file under `src/`, `tests/`, `templates/` without one), run in `make app-test`
and CI (`.github/workflows/ci-app.yml`). A companion gate,
`web/tools/check-licenses.sh`, fails the build if any non-dev Composer
dependency carries terms that AGPL-3.0-only cannot absorb. **The rule inverted
on 2026-09-10.** Under the old licence it rejected every copyleft dependency;
now that the platform is itself copyleft, GPL, LGPL, MPL and AGPL dependencies
are all welcome, and what fails instead is the opposite case: GPL-2.0-only
(no upgrade path to v3), CDDL, EPL, OSL, SSPL, BUSL, Commons-Clause and
anything marked proprietary. The script's own header carries the reasoning
per licence, so read it before "fixing" the list.

### Secrets: committed placeholders + layered scanning

The repo is public; the convention is **committed placeholder files, gitignored
real values**:

- `web/.env` (committed) holds placeholders only (`APP_SECRET=dev-not-secret-change-me`,
  the local-docker `cc:cc@db` DSN); real values go in `web/.env.local`
  (gitignored) or deployment secrets. Same pattern for
  `developers/docker/.env.example` → `.env`.
- Tokens never live in committed assets: the Mapillary token, for instance, is
  env-injected via Twig, not a committed `config.js`.

Scanning is layered, front line first
([`.pre-commit-config.yaml`](../../.pre-commit-config.yaml),
[CONTRIBUTING.md](../../CONTRIBUTING.md)):

1. **pre-commit.** Gitleaks on staged changes; blocks the commit before it
   exists.
2. **pre-push.** `tools/gitleaks-prepush.sh` scans the outgoing commit range;
   catches secrets committed with `--no-verify`.
3. **CI backstop.** `.github/workflows/secret-scan.yml` re-scans full history
   with gitleaks + TruffleHog (`--only-verified`) on every push/PR.
4. **GitHub Push Protection.** The only *server-enforced* layer; enabled when
   the repo goes public (local hooks are bypassable, CI runs post-push).

`.gitleaks.toml` holds the allowlist (currently one false positive). The same
`pre-commit install` also enables the translation-parity hook
(dev-environment.md §7 i18n).

**The sign-off hook.** `pre-commit install` additionally wires a
`prepare-commit-msg` hook, `tools/signoff-prepare-commit-msg.sh`, which writes
the DCO `Signed-off-by:` trailer into every commit message. The trailer is the
inbound licence grant (CONTRIBUTING.md §2), and `.github/workflows/dco.yml`
refuses any pull-request commit without one that names that commit's own author.
The hook only adds the line; a hook runs on the machine of whoever chose to
install it, so the workflow stays the gate. It skips merge commits, does not
duplicate a trailer that is already there, adds ours beside a co-author's, and
refuses the commit outright when `user.name` or `user.email` is unset rather
than writing a trailer that names nobody.

### i18n (first written spec, and the contract)

Day-one internationalisation across **EN / FR / NL / DE / ES**:

- **Enabled locales:** `framework.default_locale: en`,
  `enabled_locales: [en, fr, nl, de, es]`
  (`web/config/packages/translation.yaml`).
- **Active locales, per deployment (`CC_ACTIVE_LOCALES`).** The list above is
  what the application is BUILT with: the catalogues that exist, are compiled
  and can be translated. It is compile-time and cannot vary per environment.
  Which of them a reader may actually reach is a separate, runtime question,
  answered by `CC_ACTIVE_LOCALES` (a comma-separated list, committed in
  `web/.env`, `.env.staging`, `.env.prod` and `.env.test` as all five) and
  read by the single service `App\Routing\ActiveLocales`.

  A language left out of it is invisible and unreachable: no language-menu
  entry, no `hreflang` line, no sitemap URL, not offered on `/translate` or in
  the account language setting, its `GET /i18n/{locale}` answers 404, and
  `App\EventSubscriber\LocaleSubscriber` answers 404 for every one of its
  prefixed paths. That is how a half-drafted catalogue stays translatable on
  dev while production serves only what is finished.

  Two rules hold whatever the variable says. **The default locale is always
  served**, because it is every unprefixed route and the source every
  translation is made from. **An empty or unrecognised value leaves only the
  default locale**: a typo takes the site down to English, which somebody
  notices within a page load, rather than quietly publishing the language that
  was meant to stay hidden.

  The routes themselves are unchanged: every prefix is still compiled in, so
  turning a language back on is a variable and a restart, never a deploy.
- **Path-prefix routing with clean EN.** English is served unprefixed; the
  other locales carry a path prefix (`/regions`, `/fr/regions`, `/nl/…`,
  `/de/…`, `/es/…`). The prefix map is the single constant
  `App\Routing\LocalePrefix::PATHS` (`web/src/Routing/LocalePrefix.php`),
  applied as a class-level `#[Route(LocalePrefix::PATHS)]` on localized
  controllers; Symfony generates one route per locale and sets `_locale` from
  the matched path. Keep the constant in sync with `enabled_locales`.
  Locale subdomains were rejected for the app.
- **One `messages` domain.** All user-facing strings are `|trans` keys in
  `web/translations/messages.{en,fr,nl,de,es}.yaml`, with no per-feature domains.
- **Validator-message rule:** `framework.validation.translation_domain` is set
  to `messages` (`web/config/packages/validator.yaml`), so every constraint
  message **must** be a catalogue key. Leaving any built-in default (English)
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
- **DeepL drafting, dev only** (translations.md §7.1): each developer supplies
  their own `DEEPL_API_KEY` in their own gitignored local environment
  override, never a shared secret. An absent or empty key means the feature is
  simply off, the same convention `SAFE_BROWSING_KEY` already uses above. It
  exists only on dev: the kernel environment must be `dev` too, and there is
  no flag that turns it on anywhere else.

  **Writing into the catalogue files needs a second variable**,
  `CC_CATALOGUE_WRITE=1`, also per developer and also empty in the committed
  `web/.env`. The key alone does not enable it and neither does `APP_ENV`:
  `web/.env` commits `APP_ENV=dev`, so a deployed box that lost its
  server-side override would otherwise write rider translations straight into
  a shipped source file (translations.md §7.1). Without the opt-in,
  `/translate/{id}` behaves exactly as it does in production.

  **Set either one and restart the app.** Environment values are
  container-cached, so editing the file and reloading the page shows nothing
  new and explains nothing.

  **Do not paste the profiler's curl command into a bug report.** On dev
  Symfony's traceable HTTP client records the full request options, so the
  DeepL key lands in `var/cache/dev/profiler/` and in the toolbar's copyable
  curl command for every draft request. That is normal for a dev profiler and
  nothing in the app should change to hide it, but the copied command carries
  a live credential.
- **In-site overlays** ([translations.md](translations.md)): YAML remains the
  shipped default and the parity gate still compares locale YAML to English.
  Approved overlays may lead for individual non-English keys at runtime.
  Run `php bin/console app:translations:sync` on deploy so `translation_entry`
  stays aligned with `messages.en.yaml`. Do not merge overlays back into
  locale YAML. `en` is now an overlay locale too, curator-edited in-site
  (`translations.md` §4.2): before hand-editing `messages.en.yaml`, run
  `php bin/console app:translations:english-export` on prod first, or the
  next sync overwrites any approved English overlay that pull request did
  not carry.

## 7a. Shared state: Redis (2026-08-08)

Production is a **cluster**: a load balancer in front of two nginx frontends
(one shared DB server behind them). Anything that has to agree across those
frontends therefore may not live on a node's disk. Before this, all of it did:

| State | On local disk it meant |
|---|---|
| Sessions (`handler_id: null`) | log in on node A, logged out on node B |
| Every rate limiter | counters per node, so every published limit was doubled |
| `media_urgent_breaker_limiter` | the **site-wide** auto-withhold budget promised by photo-uploads.md §6c was per node, and so was the emergency stop |
| `cache.pow_spent` | a proof-of-work challenge issued by one node could not be verified by the other, so the anonymous photo-report route failed intermittently, and it is the one route that must never bounce someone reporting a photo of themselves |

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

**The image needs `ext-redis`** (`pecl install redis`, in `web/Dockerfile`), and
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
  `php-cs-fixer --dry-run` + the SPDX, licence, and translation checks +
  the Node tests (`make scope-test`).
  CI (`.github/workflows/ci-app.yml`) runs the same tool chain against a
  `postgis/postgis:18-3.6` service container, plus an advisory Rector pass.
- **The push runs that same gate (2026-09-01).** `tools/app-gate-prepush.sh`
  is a pre-push hook fired by any outgoing change under `web/`, and it runs
  every `make app-test` command, cheapest first, so a formatting slip fails in
  seconds instead of after the suite. `staging` deploys on push, so the push
  is the last moment to stop a red gate. Bypass with `git push --no-verify`;
  the next red CI run is the price.
- **CI seeds the catalogue into the test database, a local run may not.**
  `ci-app.yml` runs `app:translations:sync --env=test` before the suite, so
  `translation_entry` holds every key and `/translate` paginates at 25 rows
  (`CatalogueBrowser::PER_PAGE`). A local test database with few rows shows
  every seeded key on page one, so a list test can pass locally and fail in
  CI. A list test must pin its rows with a `q=` filter rather than trust the
  page-one window. Match CI locally with
  `php bin/console app:translations:sync --env=test`.
- **Every local gate now has a workflow (2026-08-24).** Four ran only on
  developer machines, so a green pull request could still ship a broken
  coverage contract, an untranslated key, or a map module that throws
  `ReferenceError` on boot:
  - `make pipeline-test` → `.github/workflows/ci-pipeline.yml`
    (coverage-provider.md §7).
  - `web/tools/check-translations.sh` → a step in `ci-app.yml`. It was in
    `make app-test` from the start, and
    `tools/check-translations-precommit.sh` told contributors without php on
    PATH that "CI will enforce parity" while nothing did.
  - `make map-refs` → a step in `ci-app.yml`, run from the repo root. Because
    it invokes a root Makefile target, `Makefile` joined `ci-app.yml`'s
    trigger paths.
  - `web/tests/browser/map-smoke.js` stays a manual console protocol on
    purpose; see the note in that file's header.
- **The test suite refuses non-`_test` databases.** `web/tests/bootstrap.php`
  hard-stops unless the `DATABASE_URL` database name ends in `_test`, because
  a real environment variable (like the compose-provided dev-DB DSN in the
  `app` container) beats every `.env*` file including `.env.test`. To run
  phpunit inside the container, pass `-e DATABASE_URL=…/cyclingcommons_test`
  explicitly.
- **`APP_ENV=test` needs passing too, for the same reason** (measured
  2026-08-24). The compose `app` container exports `APP_ENV=dev`, and a real
  environment variable also beats `phpunit.dist.xml`'s
  `<server name="APP_ENV" force="true">`. Without it the kernel boots in `dev`,
  `framework.test` is false, and **every** `WebTestCase` errors with *"You
  cannot create the client used in functional tests if the framework.test
  config is not set to true"*, which reads like a broken config file rather
  than a missing flag. The working invocation is:

  ```
  docker exec -e APP_ENV=test \
    -e DATABASE_URL="postgresql://cc:cc@db:5432/cyclingcommons_test?serverVersion=18&charset=utf8" \
    cycling-commons-dev-app-1 php -d memory_limit=1G bin/phpunit
  ```

  `-d memory_limit=1G` is the second half: the container's php.ini caps CLI
  memory at 128 MB and `GpxParserTest::testRejectsMoreThanFiftyThousandPoints`
  builds a 50,000-point track, so the run dies with a fatal error two thirds of
  the way through. CI does not hit either problem: `setup-php` sets no
  `APP_ENV` and raises the CLI memory limit.
- **`make test-db-reset`** drops and rebuilds `cyclingcommons_test`. It exists
  because the DAMA transaction wrapper only guards phpunit-managed runs, so the
  test DB accumulates stray committed rows from out-of-band runs (symptom:
  unique-key violations on re-run). A clean rebuild needs three things
  migrations alone don't cover: (1) `CREATE EXTENSION postgis,
  postgis_topology` (the first-init script never re-runs, dev-environment.md
  §4), (2) migrations,
  (3) `app:world:import` (world reference data is seeded out-of-band, and
  moderator-area tests validate country codes against it).

## 9. Schema documentation: every table says what it is for

Open any table in DBeaver, pgAdmin or `psql \d+` and its `Comment` box says
what the rows are. That is a Postgres `COMMENT ON TABLE`, applied by
`bin/console app:schema:comment-tables` (`App\Command\SchemaCommentTablesCommand`).

**Where the text comes from.** A Doctrine-mapped table takes the first
paragraph of its entity class docblock, so the explanation lives beside the
columns it explains and cannot drift from them. Everything else, the Python
pipeline's `coverage_*` tables, the caches built by hand-written migrations,
and the tables a bundle creates for us, is listed in `config/table_comments.yaml`.
A YAML entry always wins, which is the escape hatch for an entity whose
docblock opens with something that reads badly on its own.

**Why a command and not a migration.** Doctrine accepts
`#[ORM\Table(options: ['comment' => ...])]` but only emits it inside a
CREATE TABLE, and the DBAL 4 comparator ignores comments entirely, so
`doctrine:migrations:diff` produces nothing for a table that already exists.

**When to run it.** It is idempotent, so a needless run costs one statement
per table.

- after `doctrine:migrations:migrate`, on every environment;
- after any coverage harvest, because the pipeline's `CREATE TABLE` drops the
  comment along with the table.

**The gate.** `App\Doctrine\TableComments::missing()` lists tables in the
database that nothing explains, `--check` turns that into a non-zero exit, and
`web/tests/Doctrine/TableCommentsTest.php` fails the suite on it. A new table
therefore arrives with an explanation or the build says so. The only exempt
table is PostGIS's own `spatial_ref_sys`.

## Open questions

- **CONTRIBUTING.md Mailpit paragraph is stale**: it states "the stack does
  **not** bundle its own Mailpit" and points at a host instance, but
  `developers/docker/compose.yaml` bundles a `mailpit` service (the app's
  default `MAILER_DSN` is `smtp://mailpit:1025`). The compose file is the
  contract; CONTRIBUTING.md needs updating to describe the bundled service.
  The `MAILER_DSN` comment in `web/.env` carries the same stale claim.
- **`atlas` service retirement**: the static prototype container remains in
  the compose file; its removal is decided when the HTML demo (`atlas/demo/`,
  old `main`) is finally swept post-go-live.
- **`php:8.4-fpm` base image floats** (its Debian variant has silently jumped
  majors before); pinning a specific variant for reproducibility is an open
  consideration, not a decision.
- **TLS termination point** (LB with SNI certs vs TCP-passthrough to nginx) is
  undecided, and recorded as open in [operations.md](operations.md) §6.
