.PHONY: help up down build shell test lint lint-fix analyse migrate fresh seed cache-clear pg-check pg-upgrade prod-build prod-build-octane prod-logs-api prod-shell-api prod-up prod-down deploy backup prod-pg-check prod-pg-upgrade load-tokens load-seed load-test load-test-mature load-test-game load-test-practice load-test-battle load-test-mixed load-test-webhook zap-baseline

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

prod-build: ## Build production app, app-api (stage of APP_SERVER: fpm|octane, see .env) and nginx images
	$(PROD_DC) build --pull app app-api nginx

prod-build-octane: ## Build the Octane (FrankenPHP) image of app-api regardless of .env
	APP_SERVER=octane $(PROD_DC) build --pull app-api

prod-logs-api: ## Follow the logs of the app-api container
	$(PROD_DC) logs -f --tail=100 app-api

prod-shell-api: ## Shell in the app-api container
	$(PROD_DC) exec app-api sh

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
# K6_NET=host lets the container reach a staging on this very machine (Linux). K6_INSECURE_SKIP_TLS_VERIFY=true: a staging
# with a self-signed or private-CA certificate (k6 is told to skip the check; never for a public host).
K6_RUN        = docker run --rm -i $(if $(K6_NET),--network $(K6_NET)) --user "$$(id -u):$$(id -g)" \
	-v "$(CURDIR)/$(K6_DIR):/scripts:ro" -v "$(abspath $(K6_OUT)):/out" \
	$(if $(wildcard $(TOKENS_FILE)),-v "$(abspath $(TOKENS_FILE)):/data/load-tokens.json:ro") \
	-e BASE_URL="$(BASE_URL)" -e TARGET_VUS="$(TARGET_VUS)" -e HOLD -e THINK_SCALE -e CARDS_PER_SESSION -e WEBHOOK_RPS \
	-e TARGET_PAIRS -e PRACTICE_MODE -e BATTLE_MODE -e MIX_TRAINING -e MIX_GAME -e MIX_PRACTICE -e MIX_BATTLE \
	-e TELEGRAM_URL_SECRET -e TELEGRAM_HEADER_SECRET -e K6_INSECURE_SKIP_TLS_VERIFY $(K6_IMAGE)

# The heavier scenarios default to a smaller size than the 1250 learners of the hot paths: TARGET_VUS from the command line wins.
K6_RUN_300   = $(subst TARGET_VUS="$(TARGET_VUS)",TARGET_VUS="$(if $(filter command line,$(origin TARGET_VUS)),$(TARGET_VUS),300)",$(K6_RUN))

load-tokens: ## Write TARGET_VUS test learners and their JWTs to TOKENS_FILE (local, testing, staging only)
	@mkdir -p "$(dir $(TOKENS_FILE))"
	@umask 077; tmp="$(TOKENS_FILE).tmp.$$$$"; \
	  if $(LOAD_ARTISAN) load:tokens --count=$(TARGET_VUS) --output=- > "$$tmp"; then mv "$$tmp" "$(TOKENS_FILE)"; \
	  else cat "$$tmp" >&2; rm -f "$$tmp"; echo "load:tokens failed, $(TOKENS_FILE) left as it was"; exit 1; fi
	@chmod 600 "$(TOKENS_FILE)"
	@echo "Tokens in $(TOKENS_FILE): live tokens, remove the file after the run"

load-test: ## k6: learner hot paths up to TARGET_VUS (BASE_URL=https://staging..., TOKENS_FILE=...): p95 < 300 ms, no 5xx
	@test -n "$(BASE_URL)" || { echo "BASE_URL is required, e.g. make load-test BASE_URL=https://staging.example.com"; exit 1; }
	@test -f "$(TOKENS_FILE)" || { echo "No $(TOKENS_FILE): run 'make load-tokens' first"; exit 1; }
	@mkdir -p "$(abspath $(K6_OUT))" && chmod a+rwx "$(abspath $(K6_OUT))"
	$(K6_RUN) run --summary-export=/out/twa-hot-paths-summary.json /scripts/twa-hot-paths.js

load-seed: ## Staging: mature due cards, practice pilot and friend pairs for the test learners (after load-tokens; LOAD_SCENARIO_WORDS, LOAD_SCENARIO_COURSE)
	$(LOAD_ARTISAN) db:seed --class=LoadScenarioSeeder --force

# The scenarios beyond the hot paths (tests/load/k6/README.md). Run `make load-tokens` and `make load-seed` first. Each has its own
# default size (TARGET_VUS 300 / TARGET_PAIRS 100 for the heavier paths, 1250 for training and the mix).
define K6_SCENARIO
	@test -n "$(BASE_URL)" || { echo "BASE_URL is required, e.g. make $(1) BASE_URL=https://staging.example.com"; exit 1; }
	@test -f "$(TOKENS_FILE)" || { echo "No $(TOKENS_FILE): run 'make load-tokens' first"; exit 1; }
	@mkdir -p "$(abspath $(K6_OUT))" && chmod a+rwx "$(abspath $(K6_OUT))"
endef

load-test-mature: ## k6: training with mature cards (reverse / audio / typed, the path with distractors); TARGET_VUS=1250
	$(call K6_SCENARIO,load-test-mature)
	$(K6_RUN) run --summary-export=/out/twa-training-mature-summary.json /scripts/twa-training-mature.js

load-test-game: ## k6: the game Caravan, a run from start to end; TARGET_VUS=300
	$(call K6_SCENARIO,load-test-game)
	$(K6_RUN_300) run --summary-export=/out/game-caravan-summary.json /scripts/game-caravan.js

load-test-practice: ## k6: practice, one session of PRACTICE_MODE (pairs|collocation|sentence|dictation); TARGET_VUS=300
	$(call K6_SCENARIO,load-test-practice)
	$(K6_RUN_300) run --summary-export=/out/practice-session-$(or $(PRACTICE_MODE),collocation)-summary.json /scripts/practice-session.js

load-test-battle: ## k6: battle, TARGET_PAIRS pairs (default 100) with the 5/3/2 s poll beat
	$(call K6_SCENARIO,load-test-battle)
	$(K6_RUN) run --summary-export=/out/battle-summary.json /scripts/battle.js

load-test-mixed: ## k6: the mixed profile (training / game / practice / battle shares MIX_*) for TARGET_VUS active learners
	$(call K6_SCENARIO,load-test-mixed)
	$(K6_RUN) run --summary-export=/out/mixed-profile-summary.json /scripts/mixed-profile.js

load-test-webhook: ## k6: Telegram webhook (BASE_URL, env TELEGRAM_URL_SECRET and TELEGRAM_HEADER_SECRET): p95 < 300 ms
	@test -n "$(BASE_URL)" || { echo "BASE_URL is required"; exit 1; }
	@mkdir -p "$(abspath $(K6_OUT))" && chmod a+rwx "$(abspath $(K6_OUT))"
	$(K6_RUN) run --summary-export=/out/telegram-webhook-summary.json /scripts/telegram-webhook.js

zap-baseline: ## OWASP ZAP baseline of the public pages (BASE_URL=https://staging..., TARGETS="/ /admin/login"): exit 1 on High
	@test -n "$(BASE_URL)" || { echo "BASE_URL is required, e.g. make zap-baseline BASE_URL=https://staging.example.com"; exit 1; }
	BASE_URL="$(BASE_URL)" $(if $(TARGETS),TARGETS="$(TARGETS)") $(if $(ZAP_IMAGE),ZAP_IMAGE="$(ZAP_IMAGE)") $(if $(ZAP_NET),ZAP_NET="$(ZAP_NET)") bash tests/security/zap/run-baseline.sh
