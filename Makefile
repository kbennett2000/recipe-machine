# Recipe Machine — dev workflow.
#
# Targets are thin wrappers around docker compose so the day-to-day
# "I changed a thing; what now?" workflow doesn't require remembering
# the right flags. Run `make` with no args for the help text.

.PHONY: help dev rebuild test parity lint fresh reindex assets shell down clean fonts

# Default — print available targets.
help:
	@echo "Recipe Machine dev targets:"
	@echo "  make dev        Start the container in the background"
	@echo "  make rebuild    Rebuild the image (after Dockerfile / dep changes) and restart"
	@echo "  make assets     Rebuild Vite CSS/JS bundle on the host (npm run build)"
	@echo "  make test       Run the full PHPUnit suite"
	@echo "  make parity     Run only the PHP↔JS formatter parity test"
	@echo "  make reindex    Rebuild the SQLite cache from recipes/*.md"
	@echo "  make fresh      Stop + reset DB + reapply migrations + reindex"
	@echo "  make shell      Open a shell inside the running container"
	@echo "  make down       Stop the container"
	@echo "  make clean      Down + rm container image (keep DB + recipes)"

# Ensure the SQLite file the bind mount expects actually exists on the host.
database/database.sqlite:
	@mkdir -p database
	@touch database/database.sqlite

dev: database/database.sqlite
	docker compose up -d

rebuild: database/database.sqlite
	docker compose build app
	docker compose up -d
	docker compose exec app composer install --no-interaction --quiet
	docker compose exec app php artisan migrate --force

assets:
	npm run build

test:
	docker compose exec app ./vendor/bin/phpunit

parity:
	docker compose exec app ./vendor/bin/phpunit tests/Feature/IngredientFormatParityTest.php

reindex:
	docker compose exec app php artisan recipes:reindex --print-progress

fresh: database/database.sqlite
	docker compose exec app php artisan migrate:fresh --force
	docker compose exec app php artisan recipes:reindex

shell:
	docker compose exec app bash

down:
	docker compose down

clean:
	docker compose down
	docker image rm recipe-machine:dev || true
