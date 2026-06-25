# Cycling Commons — dev stack shortcuts.
# The compose file lives in developers/docker/; these targets just point at it
# so you can run `make up` / `make down` from the repo root.

# Executables (local)
DOCKER_COMP = docker compose -f developers/docker/compose.yaml

# Optional service selector: make logs c=pipeline / make sh c=pipeline

# Misc
.DEFAULT_GOAL = help
.PHONY        : help up down start restart build rebuild logs ps sh up-routing up-storage up-all git-status wallonia-data

help: ## Outputs this help screen
	@grep -E '(^[a-zA-Z0-9\./_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-18s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

## —— 🚲 Stack ————————————————————————————————————————————————————————————————
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

sh: ## Open a shell in a container (default api; make sh c=pipeline)
	@$(DOCKER_COMP) exec $(or $(c),api) sh

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

## —— 🗺️  Wallonia data ————————————————————————————————————————————————————————
wallonia-data: ## Harvest Wallonia OSM layers into atlas/demo/*-osm.js (one/some: make wallonia-data l="services")
	@PYTHONPATH=tools python3 -m wallonia.build_all $(l) --report

pivot-data: ## Harvest official Wallonia accommodation (Tourisme Wallonie, CC-BY) into atlas/demo/stays-pivot.js
	@PYTHONPATH=tools python3 -m wallonia.pivot --report
