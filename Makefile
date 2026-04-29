# Zenplate developer shortcuts. All test-related work runs in Docker so
# nothing needs to be installed on the host.

PHP     ?= 8.1
COMPOSE := PHP_VERSION=$(PHP) docker compose -f tests/docker-compose.yml

.PHONY: help test test-all test-security test-dox build shell clean

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

test: ## Run the full test suite (override PHP version with PHP=8.3)
	$(COMPOSE) run --rm test

test-all: ## Run the suite against PHP 8.1 through 8.5
	$(MAKE) test PHP=8.1
	$(MAKE) test PHP=8.2
	$(MAKE) test PHP=8.3
	$(MAKE) test PHP=8.4
	$(MAKE) test PHP=8.5

test-security: ## Run only the eval injection probes
	$(COMPOSE) run --rm test vendor/bin/phpunit -c tests/phpunit.xml \
		--filter SecurityProbeTest --display-warnings

test-dox: ## Run with the readable testdox reporter
	$(COMPOSE) run --rm test vendor/bin/phpunit -c tests/phpunit.xml --testdox

build: ## (Re)build the test image
	$(COMPOSE) build

shell: ## Open an interactive shell in the test container
	$(COMPOSE) run --rm --entrypoint sh test

clean: ## Remove built test images and the local phpunit cache
	-docker rmi zenplate-test:8.1 zenplate-test:8.2 zenplate-test:8.3 zenplate-test:8.4 zenplate-test:8.5 2>/dev/null
	-rm -rf .phpunit.cache .phpunit.result.cache
