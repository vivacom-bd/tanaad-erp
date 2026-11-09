COMPOSE = docker compose -f infra/docker-compose.yml
API = $(COMPOSE) exec api

.PHONY: up down logs db migrate fresh rebuild test lint analyse check openapi web

up:            ## start the whole stack
	$(COMPOSE) up -d --build
down:
	$(COMPOSE) down
logs:
	$(COMPOSE) logs -f --tail=100
db:            ## start only Postgres and Redis (enough for local php + pest)
	$(COMPOSE) up -d mysql redis
migrate:
	cd apps/api && php artisan migrate
fresh:
	cd apps/api && php artisan migrate:fresh --seed
rebuild:       ## replay the ledger into every projection
	cd apps/api && php artisan ledger:rebuild
test:
	cd apps/api && ./vendor/bin/pest
lint:
	cd apps/api && ./vendor/bin/pint --test
analyse:
	cd apps/api && ./vendor/bin/phpstan analyse --memory-limit=1G
check: lint analyse test
openapi:       ## export docs/api/openapi.json (offline, via Scramble)
	cd apps/api && php artisan scramble:export --path=../../docs/api/openapi.json
web:
	NODE_DISABLE_COMPILE_CACHE=1 pnpm --filter @tanaad/web dev
