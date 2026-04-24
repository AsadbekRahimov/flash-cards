.PHONY: help up down build shell test lint lint-fix analyse migrate fresh seed cache-clear prod-build prod-up prod-down deploy backup

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

# -----------------------------------------------------------------------------
# Production targets
# -----------------------------------------------------------------------------
PROD_DC ?= docker compose -f docker-compose.prod.yml --env-file .env

prod-build: ## Build production app image
	$(PROD_DC) build --pull app

prod-up: ## Start production stack
	$(PROD_DC) up -d

prod-down: ## Stop production stack (keeps volumes)
	$(PROD_DC) down

deploy: ## Full production deploy (pull, build, migrate, cache, restart)
	./deploy.sh

backup: ## Run backup:run inside production app
	$(PROD_DC) exec -T app php artisan backup:run
