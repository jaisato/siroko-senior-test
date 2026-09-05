# Developer entry points. Everything runs inside the php container, so the only
# host requirement is Docker. `make help` lists the targets.
COMPOSE ?= docker compose
PHP     ?= $(COMPOSE) exec -T -u www-data php
IN_APP  ?= $(PHP) sh -c 'cd app && $(1)'

.DEFAULT_GOAL := help
.PHONY: help up down build logs install migrate fixtures test test-unit test-func stan cs cs-fix lint check sh

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

up: ## Start the stack (nginx :8080, MySQL, RabbitMQ, worker)
	$(COMPOSE) up -d --build

down: ## Stop the stack (keeps the volumes)
	$(COMPOSE) down

build: ## Rebuild the images
	$(COMPOSE) build

logs: ## Follow the logs of every service
	$(COMPOSE) logs -f

install: ## Install PHP dependencies
	$(call IN_APP,composer install)

migrate: ## Create the databases (dev + test) and run the migrations
	$(call IN_APP,php bin/console doctrine:database:create --if-not-exists)
	$(call IN_APP,php bin/console doctrine:migrations:migrate -n)
	$(call IN_APP,php bin/console doctrine:database:create --if-not-exists --env=test)
	$(call IN_APP,php bin/console doctrine:migrations:migrate -n --env=test)

fixtures: ## Load the demo data into the dev database
	$(call IN_APP,php bin/console doctrine:fixtures:load -n)

test: ## Run the whole PHPUnit suite against MySQL (including the `mysql` group)
	$(call IN_APP,DATABASE_URL="$$DATABASE_URL" php bin/phpunit --group default --group mysql)

test-unit: ## Run the domain and application tests (no database)
	$(call IN_APP,php bin/phpunit --testsuite unit)

test-func: ## Run the infrastructure/functional tests against MySQL
	$(call IN_APP,DATABASE_URL="$$DATABASE_URL" php bin/phpunit --testsuite infrastructure --group default --group mysql)

stan: ## Static analysis (PHPStan level 8)
	$(call IN_APP,php bin/console cache:warmup --env=dev && composer stan)

cs: ## Check the coding standard
	$(call IN_APP,composer cs)

cs-fix: ## Fix the coding standard
	$(call IN_APP,composer cs:fix)

lint: ## Lint the container, the YAML config and the Doctrine mapping
	$(call IN_APP,composer lint)

check: ## Everything CI runs: validate, cs, stan, lint, tests
	$(call IN_APP,php bin/console cache:warmup --env=dev && composer check)

sh: ## Shell into the php container
	$(COMPOSE) exec -u www-data php bash
