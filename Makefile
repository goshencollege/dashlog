.PHONY: up down restart bash migrate cc logs db-shell cert cert-install reset test-setup test

COMPOSE := $(if $(wildcard docker-compose.prod.yml),docker-compose.prod.yml,docker-compose.dev.yml)

# .env.test.local is a file target — Make skips the recipe if the file already exists.
# Delete .env.test.local to force regeneration (e.g. after running setup.sh again).

up:
	docker compose -f $(COMPOSE) up -d

down:
	docker compose -f $(COMPOSE) down

restart:
	docker compose -f $(COMPOSE) restart

bash:
	docker compose -f $(COMPOSE) exec app bash

migrate:
	docker compose -f $(COMPOSE) exec app php bin/console doctrine:migrations:migrate --no-interaction

cc:
	docker compose -f $(COMPOSE) exec app php bin/console cache:clear

logs:
	docker compose -f $(COMPOSE) logs -f

db-shell:
	docker compose -f $(COMPOSE) exec db sh -c 'mysql -u$$MYSQL_USER -p$$MYSQL_PASSWORD $$MYSQL_DATABASE'

reset:
	@if [ "$(COMPOSE)" = "docker-compose.prod.yml" ]; then \
		printf '\033[1;31m\nWARNING: PRODUCTION RESET\033[0m\n'; \
		printf 'This will destroy all volumes including the database.\n\n'; \
		printf 'Type "reset production" to confirm: '; read confirm; \
		if [ "$$confirm" != "reset production" ]; then printf 'Aborted.\n'; exit 1; fi; \
	else \
		printf 'Reset dev environment? This will destroy all volumes. [y/N] '; read confirm; \
		if [ "$$confirm" != "y" ] && [ "$$confirm" != "Y" ]; then printf 'Aborted.\n'; exit 1; fi; \
	fi
	docker compose -f $(COMPOSE) down -v
	$(MAKE) cert-install
	docker compose -f $(COMPOSE) up -d --build
	@echo "Waiting for app container…"
	@until docker compose -f $(COMPOSE) exec -T app php -r 'echo "ok";' 2>/dev/null | grep -q ok; do sleep 2; done
	docker compose -f $(COMPOSE) exec -T app php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	docker compose -f $(COMPOSE) exec -T app php bin/console messenger:setup-transports --no-interaction
	@if [ "$(COMPOSE)" != "docker-compose.prod.yml" ]; then \
		docker compose -f $(COMPOSE) exec -T app php bin/console doctrine:fixtures:load --no-interaction; \
	fi

.env.test.local:
	$(eval PASS := $(shell grep 'MYSQL_PASSWORD:' $(COMPOSE) | head -1 | sed 's/.*MYSQL_PASSWORD: //;s/[[:space:]].*//'))
	$(eval KEY  := $(shell grep 'APP_ENCRYPTION_KEY:' $(COMPOSE) | head -1 | sed 's/.*APP_ENCRYPTION_KEY: //;s/[[:space:]].*//;s/"//g'))
	@sed -e 's|{MYSQL_PASSWORD}|$(PASS)|g' -e 's|{APP_ENCRYPTION_KEY}|$(KEY)|g' .env.test.local.dist > .env.test.local
	@echo "Created .env.test.local"

test-setup: .env.test.local
	$(eval ROOT_PASS := $(shell grep 'MYSQL_ROOT_PASSWORD:' $(COMPOSE) | head -1 | sed 's/.*MYSQL_ROOT_PASSWORD: //;s/[[:space:]].*//'))
	docker compose -f $(COMPOSE) exec -T db mysql -u root -p$(ROOT_PASS) -e "CREATE DATABASE IF NOT EXISTS \`dashlog_test\`; GRANT ALL PRIVILEGES ON \`dashlog_test\`.* TO 'dash'@'%'; FLUSH PRIVILEGES;"
	docker compose -f $(COMPOSE) exec -T app php bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration

test: .env.test.local
	docker compose -f $(COMPOSE) exec -T app php vendor/bin/phpunit

cert:
	mkdir -p docker/ssl
	openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
	  -keyout docker/ssl/key.pem \
	  -out  docker/ssl/cert.pem \
	  -subj "/CN=dashlog.local" \
	  -addext "subjectAltName=DNS:dashlog.local,DNS:localhost,IP:127.0.0.1"
	$(MAKE) cert-install

cert-install:
	@PROJECT=$$(docker compose -f $(COMPOSE) config 2>/dev/null | awk '/^name:/{print $$2}'); \
	docker run --rm -v $${PROJECT}_ssl_certs:/ssl -v $(CURDIR)/docker/ssl:/src:ro alpine sh -c 'cp /src/cert.pem /src/key.pem /ssl/ && chmod 644 /ssl/*.pem'
	docker compose -f $(COMPOSE) restart nginx 2>/dev/null || true
