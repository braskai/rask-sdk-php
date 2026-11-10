# Everything runs in Docker: PHP and Composer need not be installed.
#
#   make install           composer install
#   make generate          regenerate src/ from spec/openapi.json
#   make lint              composer validate, php -l, generated files match the contract, PHPStan at max
#   make test              PHPUnit: unit and acceptance, on PHP 8.2, against a harness it starts itself
#   make test PHP=8.5      the same on another PHP
#   make test-slow         also the two slow scenarios (about 3 minutes more)
#   make check             lint, then test on 8.2 and on the newest PHP
#   make smoke             examples/smoke.php against the harness

.DEFAULT_GOAL := help

PHP ?= 8.2
NEWEST_PHP ?= 8.5
HARNESS_DIR ?= ../rask-sdk-acceptance
HARNESS ?= rask-sdk-php-harness
export DOCKER_HOST ?= unix://$(HOME)/.orbstack/run/docker.sock

RUN = docker run --rm -u $(shell id -u):$(shell id -g) -v $(CURDIR):/app -w /app
PHP_RUN = $(RUN) php:$(PHP)-cli
COMPOSER_RUN = $(RUN) -e COMPOSER_HOME=/tmp/composer composer:2

.PHONY: help install generate lint test test-slow check smoke harness harness-stop

install:
	$(COMPOSER_RUN) install --no-interaction --no-progress

generate:
	$(PHP_RUN) php tools/generate.php

lint:
	$(COMPOSER_RUN) validate --strict --no-check-version
	$(PHP_RUN) sh -c 'find src tools tests examples -name "*.php" -print0 | xargs -0 -n 1 -P 8 php -l > /tmp/lint 2>&1 || { grep -v "^No syntax errors" /tmp/lint; exit 1; }'
	$(PHP_RUN) php tools/generate.php --check
	$(PHP_RUN) vendor/bin/phpstan analyse --no-progress --memory-limit=2G

harness:
	docker build -q -t rask-sdk-acceptance $(HARNESS_DIR)
	docker rm -f $(HARNESS) >/dev/null 2>&1 || true
	docker run -d --name $(HARNESS) rask-sdk-acceptance
	sleep 1

harness-stop:
	docker rm -f $(HARNESS) >/dev/null 2>&1 || true

# The tests share the harness container's network namespace, so its 127.0.0.1 and localhost are theirs too.
test: harness
	$(RUN) --network container:$(HARNESS) php:$(PHP)-cli vendor/bin/phpunit --no-progress; \
	code=$$?; docker rm -f $(HARNESS) >/dev/null 2>&1; exit $$code

test-slow: harness
	$(RUN) --network container:$(HARNESS) -e RUN_SLOW=1 php:$(PHP)-cli vendor/bin/phpunit --no-progress; \
	code=$$?; docker rm -f $(HARNESS) >/dev/null 2>&1; exit $$code

check: lint
	$(MAKE) test PHP=8.2
	$(MAKE) test PHP=$(NEWEST_PHP)

smoke: harness
	$(RUN) --network container:$(HARNESS) \
		-e RASK_API_KEY=raskai_test_key_AcceptanceHarnessTestKeyNotARealCredentialDoNotUs \
		-e RASK_BASE_URL=http://127.0.0.1:4010/custom/prefix/v3 \
		php:$(PHP)-cli php examples/smoke.php; \
	code=$$?; docker rm -f $(HARNESS) >/dev/null 2>&1; exit $$code

help:
	@sed -n '1,/^$$/p' Makefile | sed 's/^# \{0,1\}//'
