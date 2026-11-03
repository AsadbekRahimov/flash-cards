.PHONY: help up down build shell test lint lint-fix analyse migrate fresh seed cache-clear pg-check pg-upgrade prod-build prod-up prod-down deploy backup prod-pg-check prod-pg-upgrade load-tokens load-test load-test-webhook zap-baseline

DC      ?= docker compose
APP     ?= $(DC) exec app
ARTISAN ?= $(APP) php artisan

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}'

up: ## Start all containers in background
	$(DC) up -d

down: ## Stop and remove containers
	$(DC) down

build: ## Rebuild app image
	$(DC) build --no-cache app

shell: ## Open bash inside app container
	$(APP) bash

test: ## Run Pest test suite
	$(APP) ./vendor/bin/pest

lint: ## Run Pint in check-only mode
	$(APP) ./vendor/bin/pint --test

lint-fix: ## Auto-fix code style with Pint
	$(APP) ./vendor/bin/pint

analyse: ## Run PHPStan (Larastan) at configured level
	$(APP) ./vendor/bin/phpstan analyse --memory-limit=1G

migrate: ## Run pending migrations
	$(ARTISAN) migrate

fresh: ## Drop & re-run all migrations with seeders
	$(ARTISAN) migrate:fresh --seed

seed: ## Run database seeders
	$(ARTISAN) db:seed

cache-clear: ## Clear Laravel and Filament caches (components, Blade icons)
	$(ARTISAN) optimize:clear
	$(ARTISAN) filament:optimize-clear

pg-check: ## Show the PostgreSQL volumes of the dev stack (exit 1 = data still in the 16 volume)
	bash docker/postgres/upgrade.sh --check

pg-upgrade: ## Move dev PostgreSQL data from the 16 volume into the 18+ layout (docs/DEVOPS_GUIDE.md §0)
	bash docker/postgres/upgrade.sh

# -----------------------------------------------------------------------------
# Production targets
# -----------------------------------------------------------------------------
PROD_DC ?= docker compose -f docker-compose.prod.yml --env-file .env

prod-build: ## Build production app and nginx images
	$(PROD_DC) build --pull app nginx

prod-up: ## Start production stack
	$(PROD_DC) up -d

prod-down: ## Stop production stack (keeps volumes)
	$(PROD_DC) down

deploy: ## Full production deploy (pull, build, migrate, cache, restart)
	bash deploy.sh

backup: ## Run backup:run inside production app
	$(PROD_DC) exec -T app php artisan backup:run

prod-pg-check: ## Show the PostgreSQL volumes of the production stack (the same check deploy.sh runs)
	bash docker/postgres/upgrade.sh --prod --check

prod-pg-upgrade: ## Move production PostgreSQL data from the 16 volume into the 18+ layout (stops the app for a few minutes)
	bash docker/postgres/upgrade.sh --prod

# -----------------------------------------------------------------------------
# Staging checks (docs/STAGING_PHASE2_CHECKLIST.md §7.2-7.3): k6 and ZAP run from Docker images, nothing is installed.
# The owner runs them against a staging; APP_ENV=production refuses to issue the tokens.
# -----------------------------------------------------------------------------
K6_IMAGE     ?= grafana/k6:1.0.0
K6_DIR       ?= tests/load/k6
TOKENS_FILE  ?= $(K6_DIR)/tokens.json
K6_OUT       ?= $(K6_DIR)/results
BASE_URL     ?=
TARGET_VUS   ?= 1250
# Where `load:tokens` runs: the app container of the staging stack (override for a dev stack: LOAD_ARTISAN="$(ARTISAN)" with -T).
LOAD_ARTISAN ?= $(PROD_DC) exec -T app php artisan
# K6_NET=host lets the container reach a staging on this very machine (Linux).
K6_RUN        = docker run --rm -i $(if $(K6_NET),--network $(K6_NET)) --user "$$(id -u):$$(id -g)" \
	-v "$(CURDIR)/$(K6_DIR):/scripts:ro" -v "$(CURDIR)/$(K6_OUT):/out" \
	$(if $(wildcard $(TOKENS_FILE)),-v "$(abspath $(TOKENS_FILE)):/data/load-tokens.json:ro") \
	-e BASE_URL="$(BASE_URL)" -e TARGET_VUS="$(TARGET_VUS)" -e HOLD -e THINK_SCALE -e CARDS_PER_SESSION -e WEBHOOK_RPS \
	-e TELEGRAM_URL_SECRET -e TELEGRAM_HEADER_SECRET $(K6_IMAGE)

load-tokens: ## Write TARGET_VUS test learners and their JWTs to TOKENS_FILE (local, testing, staging only)
	@mkdir -p "$(dir $(TOKENS_FILE))"
	@umask 077; tmp="$(TOKENS_FILE).tmp.$$$$"; \
	  if $(LOAD_ARTISAN) load:tokens --count=$(TARGET_VUS) --output=- > "$$tmp"; then mv "$$tmp" "$(TOKENS_FILE)"; \
	  else rm -f "$$tmp"; echo "load:tokens failed, $(TOKENS_FILE) left as it was"; exit 1; fi
	@chmod 600 "$(TOKENS_FILE)"
	@echo "Tokens in $(TOKENS_FILE): live tokens, remove the file after the run"

load-test: ## k6: learner hot paths up to TARGET_VUS (BASE_URL=https://staging..., TOKENS_FILE=...): p95 < 300 ms, no 5xx
	@test -n "$(BASE_URL)" || { echo "BASE_URL is required, e.g. make load-test BASE_URL=https://staging.example.com"; exit 1; }
	@test -f "$(TOKENS_FILE)" || { echo "No $(TOKENS_FILE): run 'make load-tokens' first"; exit 1; }
	@mkdir -p "$(K6_OUT)" && chmod a+rwx "$(K6_OUT)"
	$(K6_RUN) run --summary-export=/out/twa-hot-paths-summary.json /scripts/twa-hot-paths.js

load-test-webhook: ## k6: Telegram webhook (BASE_URL, env TELEGRAM_URL_SECRET and TELEGRAM_HEADER_SECRET): p95 < 300 ms
	@test -n "$(BASE_URL)" || { echo "BASE_URL is required"; exit 1; }
	@mkdir -p "$(K6_OUT)" && chmod a+rwx "$(K6_OUT)"
	$(K6_RUN) run --summary-export=/out/telegram-webhook-summary.json /scripts/telegram-webhook.js

zap-baseline: ## OWASP ZAP baseline of the public pages (BASE_URL=https://staging..., TARGETS="/ /admin/login"): exit 1 on High
	@test -n "$(BASE_URL)" || { echo "BASE_URL is required, e.g. make zap-baseline BASE_URL=https://staging.example.com"; exit 1; }
	BASE_URL="$(BASE_URL)" $(if $(TARGETS),TARGETS="$(TARGETS)") $(if $(ZAP_IMAGE),ZAP_IMAGE="$(ZAP_IMAGE)") bash tests/security/zap/run-baseline.sh
