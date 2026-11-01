.PHONY: help up down build shell test lint lint-fix analyse migrate fresh seed cache-clear pg-check pg-upgrade prod-build prod-up prod-down deploy backup prod-pg-check prod-pg-upgrade

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

cache-clear: ## Clear Laravel caches
	$(ARTISAN) optimize:clear

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
