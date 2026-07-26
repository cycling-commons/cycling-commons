# Cycling Commons — dev stack shortcuts.
# The compose file lives in developers/docker/; these targets just point at it
# so you can run `make up` / `make down` from the repo root.

# Executables (local)
DOCKER_COMP = docker compose -f developers/docker/compose.yaml

# Optional service selector: make logs c=pipeline / make sh c=pipeline

# Misc
.DEFAULT_GOAL = help
.PHONY        : help up down check-env map-refs start restart build rebuild logs ps sh up-routing up-storage up-all git-status wallonia-data wallonia-export divisions-data tools-test app-install app-serve app-test app-rector app-create-admin app-create-curator test-db-reset pipeline-test coverage-refresh region-probe region-scaffold course-data

help: ## Outputs this help screen
	@grep -E '(^[a-zA-Z0-9\./_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-18s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

## —— 🚲 Stack ————————————————————————————————————————————————————————————————
setup: ## First-time dev setup: start the stack, install deps, migrate, seed world data + demo users
	@$(DOCKER_COMP) up --build --force-recreate --detach --wait
	@echo "→ Installing PHP dependencies…"
	@$(DOCKER_COMP) exec -T app composer install --no-interaction --no-progress
	@echo "→ Publishing bundle web assets (EasyAdmin CSS/JS → public/bundles)…"
	@$(DOCKER_COMP) exec -T app php bin/console assets:install public --symlink --relative
	@echo "→ Running database migrations…"
	@$(DOCKER_COMP) exec -T app php bin/console doctrine:migrations:migrate --no-interaction
	@echo "→ Importing world reference data (continents, countries, subdivisions)…"
	@$(DOCKER_COMP) exec -T app php bin/console app:world:import
	@echo "→ Loading demo accounts (keeps the world tables)…"
	@$(DOCKER_COMP) exec -T app php bin/console doctrine:fixtures:load --no-interaction \
		--purge-exclusions=world_continent --purge-exclusions=world_country --purge-exclusions=world_subdivision
	@$(DOCKER_COMP) exec -T app php bin/console cache:clear
	@echo ""
	@echo "✔ Setup complete — open http://localhost:$${API_PORT:-8001}"
	@echo "  Demo logins (password: password1234):"
	@echo "    admin@example.test      ROLE_ADMIN   (2FA preset)"
	@echo "    moderator@example.test  ROLE_CURATOR (2FA preset)"
	@echo "    user@example.test       ROLE_USER    (public profile)"
	@echo "    anon@example.test       ROLE_USER    (private / anonymous)"

# `make setup` seeds world reference data and four demo accounts, nothing more:
# a fresh clone has zero rows in item / recommended_route / heat_point, and no
# coverage_poi TABLE at all (the coverage batch creates it, not a migration).
# Every runnable exercise in the GIS courses (wiki/developers/gis/ and
# wiki/developers/gis-beyond/) therefore comes back empty on a fresh clone.
# `course-data` fills that gap from data ALREADY COMMITTED to this repo, so it
# needs no network and no Geofabrik download:
#   1. app:catalog:seed-manual   — 24 hand-authored pins, letters B–J
#   2. the committed atlas/demo fixtures (ODbL/CC-BY), exported as catalog
#      import artifacts: 351 A surface segments, 289 C water points, 150 E
#      stays, 11 routes, 6,602 heat points. The climbs layer is deliberately
#      NOT exported — it resolves Wikidata Q-ids over the network and is the
#      one part of tools/wallonia/export.py that is not offline; the five
#      manual B pins cover climbs for the course instead.
#   3. the committed 847-byte OSM fixture, run through the real coverage batch
#      (`coverage-refresh regions=dev/fixture`) — 10 coverage_poi rows.
# Two honest gaps, both documented in the course itself:
#   * no `region` rows. Region boundaries come from Overture via
#     `make divisions-data` (network) and land in a gitignored out/ dir, so
#     item.region_id stays NULL and coverage rows keep country_code = NULL
#     (`dev/fixture` has no configured country by design, see
#     pipeline/coverage/load.py resolve_country).
#   * coverage_poi holds 10 rows, not a real country's ~375k. The chapter-5
#     Seq Scan → Index Scan contrast still flips at that size, but the
#     chapter-7 per-country layer table needs a real `make coverage-refresh`.
# NB the coverage step republishes the MinIO tile manifest. On a machine that
# already holds a real coverage index, re-run `make coverage-refresh` after
# this to point the manifest back at it.
COURSE_DATA_DIR = web/var/course-data
course-data: ## Seed the dataset the GIS course exercises query (offline; run once after `make setup`)
	@echo "→ Seeding the hand-authored demo pins (letters B–J)…"
	@$(DOCKER_COMP) exec -T app php bin/console app:catalog:seed-manual
	@echo "→ Exporting the offline catalog artifacts from the committed atlas/demo fixtures…"
	@mkdir -p $(COURSE_DATA_DIR)
	@PYTHONPATH=tools python3 -c "import pathlib; from wallonia import export; export.OUT = pathlib.Path('$(COURSE_DATA_DIR)'); export.write('surface.json', {'layer': 'surface', 'letter': 'A', 'features': export.surface_features()}); export.write('water.json', {'layer': 'water', 'letter': 'C', 'features': export.water_features()}); export.write('stays-pivot.json', {'layer': 'stays-pivot', 'letter': 'E', 'features': export.pivot_features()}); export.write('routes.json', export.routes_payload()); export.write('heat.json', export.heat_payload())"
	@echo "→ Importing them into the catalog…"
	@$(DOCKER_COMP) exec -T app php bin/console app:catalog:import $(patsubst web/%,%,$(COURSE_DATA_DIR))
	@echo "→ Building coverage_poi from the committed OSM fixture (no network)…"
	@$(MAKE) --no-print-directory coverage-refresh regions=dev/fixture pbf=tests/fixtures/mini.osm.pbf
	@echo ""
	@echo "✔ Course dataset ready — the exercises in wiki/developers/gis/ now return rows."

up: ## Start the dev stack in detached mode (recreates stale containers)
	@$(DOCKER_COMP) up --detach
	@$(MAKE) --no-print-directory check-env || true

# `up` deliberately does NOT --build: a transient apt failure while fetching a
# base package would then take the whole stack down, which is a worse failure
# than the drift below. So detect the drift instead of forcing a rebuild.
#
# The drift that motivated this (2026-07-26): an app image built before e8007c4
# kept php's default upload_max_filesize=2M, while the repo declares a 15M GPX
# limit. Every GPX over 2 MB failed, and the map said "Choose a GPX file first."
# — a message that reads like a product bug, not a stale image. Non-fatal by
# design: it is a warning about the environment, never a gate.
check-env: ## Warn when the running app image no longer matches the repo's Dockerfile
	@declared=$$(grep -oE "maxSize: '[0-9]+M'" web/src/Form/ProposeRouteType.php | grep -oE '[0-9]+' | head -1); \
	actual=$$($(DOCKER_COMP) exec -T app php -r 'echo (int) ini_get("upload_max_filesize");' 2>/dev/null); \
	if [ -n "$$actual" ] && [ -n "$$declared" ] && [ "$$actual" -lt "$$declared" ]; then \
	  printf '\033[33m⚠  php upload_max_filesize is %sM but this repo declares a %sM GPX limit.\033[0m\n' "$$actual" "$$declared"; \
	  printf '   The app image predates the Dockerfile that raises it. Run: \033[1mmake build && make up\033[0m\n'; \
	fi

down: ## Stop the stack and remove containers (keeps the data volume)
	@$(DOCKER_COMP) down --remove-orphans

start: build up ## Build the images then start the stack

restart: down up ## Recreate the stack from scratch (down + up)

## —— 🐳 Docker ———————————————————————————————————————————————————————————————
build: ## Build the images (pulls newer base images)
	@$(DOCKER_COMP) build --pull

rebuild: ## Build the images from scratch (no cache)
	@$(DOCKER_COMP) build --pull --no-cache

logs: ## Follow logs for all services (or one: make logs c=pipeline)
	@$(DOCKER_COMP) logs --tail=100 --follow $(c)

ps: ## Show the status of the stack
	@$(DOCKER_COMP) ps

sh: ## Open a shell in a container (default app; make sh c=pipeline)
	@$(DOCKER_COMP) exec $(or $(c),app) sh

## —— 🗂️  Workspace ———————————————————————————————————————————————————————————
git-status: ## Show git status of this repo + all sibling repos in the workspace
	@echo "Checking git status for workspace repositories..."
	@for repo in ../*/ ; do \
		if [ -d "$$repo/.git" ]; then \
			STATUS=$$(cd "$$repo" && git status --porcelain); \
			UNPUSHED=$$(cd "$$repo" && git log @{u}..HEAD 2>/dev/null || echo ""); \
			if [ -n "$$STATUS" ] || [ -n "$$UNPUSHED" ]; then \
				echo "\033[33m=======================================\033[0m"; \
				echo "\033[32mRepository: $$repo\033[0m"; \
				if [ -n "$$STATUS" ]; then \
					echo "\033[31mUncommitted changes:\033[0m"; \
					(cd "$$repo" && git status -s); \
				fi; \
				if [ -n "$$UNPUSHED" ]; then \
					echo "\033[36mUnpushed commits:\033[0m"; \
					(cd "$$repo" && git log --oneline @{u}..HEAD 2>/dev/null); \
				fi; \
				echo ""; \
			fi \
		fi \
	done
	@echo "✅ Check complete."

## —— 🧩 Opt-in profiles ——————————————————————————————————————————————————————
up-routing: ## Start the stack + Valhalla (needs prebuilt tiles in ./data/valhalla)
	@$(DOCKER_COMP) --profile routing up --detach

up-storage: ## Start the stack + MinIO (S3-compatible, ports 9100/9101)
	@$(DOCKER_COMP) --profile storage up --detach

up-all: ## Start the stack + every opt-in profile (routing + storage)
	@$(DOCKER_COMP) --profile routing --profile storage up --detach

## —— 🌐 Symfony web app ——————————————————————————————————————————————————————————
app-install: ## install PHP deps for the Symfony app
	cd web && composer install

app-serve: ## run the Symfony app locally at http://127.0.0.1:8010
	cd web && php -S 127.0.0.1:8010 -t public

app-test: ## run the app test suite + static analysis + gates
	cd web && php bin/phpunit && vendor/bin/phpstan analyse --no-progress && vendor/bin/psalm --no-cache && vendor/bin/php-cs-fixer fix --dry-run --diff && ./tools/check-spdx.sh && ./tools/check-licenses.sh && ./tools/check-translations.sh
	$(MAKE) scope-test

scope-test: ## run the map scope-model Node smoke tests (no deps — node:test ships with Node ≥18)
	node --test web/tests/js/*.test.cjs

map-refs: ## check no web/assets/map module still references a binding map.js owns (split gate)
	@# Syntax-check as ES MODULES, which is how the browser loads them. Plain
	@# `node --check foo.js` parses a .js file as CommonJS in this repo (no
	@# package.json), and that parse accepted a module with a duplicated
	@# top-level `let` — so the loop reported clean on a file the browser would
	@# have refused outright. Copying to .mjs is what forces the module parse.
	@tmp=$$(mktemp -d) || exit 1; \
	  for f in web/assets/map/*.js; do cp $$f $$tmp/$$(basename $$f .js).mjs || exit 1; done; \
	  for f in $$tmp/*.mjs; do node --check $$f || { rm -rf $$tmp; exit 1; }; done; \
	  rm -rf $$tmp; echo "clean: every map module parses as an ES module"
	python3 web/tests/browser/check-module-refs.py

app-rector: ## apply Rector refactors (advisory; review the diff before committing)
	cd web && vendor/bin/rector process

# The test DB accumulates stray committed rows from out-of-band runs (the DAMA
# transaction wrapper only guards phpunit-managed runs) — symptom: unique-key
# violations like display_name_canonical=(curator). A clean rebuild needs THREE
# things migrations alone don't cover: (1) the PostGIS extensions — the docker
# init script (developers/docker/db/init/01-postgis.sql) only runs on first
# cluster init, never on a same-cluster drop/recreate; (2) app:world:import —
# world reference data is seeded out-of-band, not by a migration, and
# moderator-area tests validate country codes against it; (3) migrations.
TEST_DB_URL = postgresql://cc:cc@db:5432/cyclingcommons_test?serverVersion=18&charset=utf8
test-db-reset: ## Drop + rebuild the test DB (PostGIS ext, migrations, world data) — fixes stale-row test failures
	@echo "→ Dropping and recreating cyclingcommons_test…"
	@$(DOCKER_COMP) exec -T -e DATABASE_URL='$(TEST_DB_URL)' app php bin/console doctrine:database:drop --force --if-exists
	@$(DOCKER_COMP) exec -T -e DATABASE_URL='$(TEST_DB_URL)' app php bin/console doctrine:database:create
	@echo "→ Enabling PostGIS + pg_trgm extensions…"
	@$(DOCKER_COMP) exec -T db psql -U cc -d cyclingcommons_test -c "CREATE EXTENSION IF NOT EXISTS postgis; CREATE EXTENSION IF NOT EXISTS postgis_topology; CREATE EXTENSION IF NOT EXISTS pg_trgm;"
	@echo "→ Running migrations…"
	@$(DOCKER_COMP) exec -T -e DATABASE_URL='$(TEST_DB_URL)' app php bin/console doctrine:migrations:migrate --no-interaction
	@echo "→ Importing world reference data…"
	@$(DOCKER_COMP) exec -T -e DATABASE_URL='$(TEST_DB_URL)' app php bin/console app:world:import
	@echo "✔ Test DB rebuilt — run the suite with: make app-test (or docker exec -e APP_ENV=test … php bin/phpunit)"

app-create-admin: ## Bootstrap an admin user: make app-create-admin email=you@example.com  (prompts for password)
	cd web && php bin/console app:user:create --role=ROLE_ADMIN $(email)

app-create-curator: ## Bootstrap a curator user: make app-create-curator email=you@example.com  (prompts for password)
	cd web && php bin/console app:user:create --role=ROLE_CURATOR $(email)

## —— 🗺️  Wallonia data ————————————————————————————————————————————————————————
wallonia-data: ## Harvest Wallonia OSM layers into atlas/demo/*-osm.js (one/some: make wallonia-data l="services")
	@PYTHONPATH=tools python3 -m wallonia.build_all $(l) --report

pivot-data: ## Harvest official Wallonia accommodation (Tourisme Wallonie, CC-BY) into atlas/demo/stays-pivot.js
	@PYTHONPATH=tools python3 -m wallonia.pivot --report

wallonia-export: ## Export catalog import artifacts (fixtures + cached harvest) to tools/wallonia/out/ — strict cached replay
	cd tools && python3 -m wallonia.export --strict-cache

divisions-data: ## Export region-<slug>.geojson from Overture divisions to tools/divisions/out/ (country: make divisions-data c="BE")
	cd tools && python3 -m divisions.export_divisions --country $(or $(c),BE) --out divisions/out

region-probe: ## Onboarding step 1: probe Overture subdivision areas (make region-probe c="NL" [subtypes="region,county"])
	cd tools && python3 -m divisions.probe_areas --country $(or $(c),NL) $(if $(subtypes),--subtypes $(subtypes))

region-scaffold: ## Onboarding step 2: emit region config + label stubs for review (make region-scaffold c="NL" [flags="--probe-areas"])
	@$(DOCKER_COMP) exec -T app php bin/console app:region:scaffold $(or $(c),NL) $(flags)

tools-test: ## Run the tools Python test suites (wallonia + divisions)
	cd tools && python3 -m pytest wallonia/tests divisions/tests -q

pipeline-test: ## Run the pipeline Python test suite in the pipeline container (contract + batch job units)
	@$(DOCKER_COMP) exec -T pipeline python -m pytest tests -q

wiki-check: ## Verify the wiki builds strict and its code excerpts still match the code they quote
	@.venv-wiki/bin/mkdocs build --strict
	@tools/check-wiki-spdx.sh
	@python3 tools/check-wiki-code-drift.py && echo "wiki: build strict OK, SPDX OK, code excerpts match source"

## —— 🧱 Coverage batch ————————————————————————————————————————————————————————
# Whole chain against the dev DB + MinIO (see developers/coverage-batch.md).
# Fixture run (no network): make coverage-refresh regions=dev/fixture pbf=tests/fixtures/mini.osm.pbf
# (pbf paths are as seen INSIDE the pipeline container, workdir /app = pipeline/)
# Bucket creds match the compose defaults; override MINIO_ROOT_USER/PASSWORD if you changed developers/docker/.env.
coverage-refresh: ## Refresh the coverage index + PMTiles (dev: Geofabrik → PostGIS → MinIO)
	@$(DOCKER_COMP) --profile storage up --detach --wait minio
	@$(DOCKER_COMP) run --rm \
		-e COVERAGE_S3_ENDPOINT=http://minio:9000 \
		-e COVERAGE_S3_KEY=$${MINIO_ROOT_USER:-ccadmin} \
		-e COVERAGE_S3_SECRET=$${MINIO_ROOT_PASSWORD:-ccadminsecret} \
		-e COVERAGE_PUBLIC_BASE_URL=http://localhost:9100/cc-maps \
		$(if $(regions),-e COVERAGE_REGIONS=$(regions)) \
		$(if $(pbf),-e COVERAGE_PBF_PATH=$(pbf)) \
		pipeline python -m coverage.run
