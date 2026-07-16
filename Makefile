# Cycling Commons — dev stack shortcuts.
# The compose file lives in developers/docker/; these targets just point at it
# so you can run `make up` / `make down` from the repo root.

# Executables (local)
DOCKER_COMP = docker compose -f developers/docker/compose.yaml

# Optional service selector: make logs c=pipeline / make sh c=pipeline

# Misc
.DEFAULT_GOAL = help
.PHONY        : help up down start restart build rebuild logs ps sh up-routing up-storage up-all git-status wallonia-data wallonia-export tools-test app-install app-serve app-test app-rector app-create-admin app-create-curator test-db-reset pipeline-test

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

up: ## Start the dev stack in detached mode (recreates stale containers)
	@$(DOCKER_COMP) up --detach

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
	@echo "→ Enabling PostGIS extensions…"
	@$(DOCKER_COMP) exec -T db psql -U cc -d cyclingcommons_test -c "CREATE EXTENSION IF NOT EXISTS postgis; CREATE EXTENSION IF NOT EXISTS postgis_topology;"
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

tools-test: ## Run the tools/wallonia Python test suite
	cd tools && python3 -m pytest wallonia/tests -q

pipeline-test: ## Run the pipeline Python test suite in the pipeline container (contract + batch job units)
	@$(DOCKER_COMP) exec -T pipeline python -m pytest tests -q
