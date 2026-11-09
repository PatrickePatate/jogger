.PHONY: dev up down test lint stan agent-test

dev:
	composer run dev

up:
	docker compose -f docker/compose/docker-compose.dev.yml up -d --wait
	@docker compose -f docker/compose/docker-compose.dev.yml exec -T postgres sh -c \
		"psql -U jogger -d jogger -tAc \"SELECT 1 FROM pg_database WHERE datname='jogger_test'\" | grep -q 1 || createdb -U jogger jogger_test"

down:
	docker compose -f docker/compose/docker-compose.dev.yml down

test:
	php artisan config:clear --ansi
	vendor/bin/phpunit

lint:
	vendor/bin/pint --test

stan:
	vendor/bin/phpstan analyse --memory-limit=1G

agent-test:
	@if [ -d agent ]; then cd agent && go vet ./... && go test ./...; else echo "agent/ not created yet"; fi
