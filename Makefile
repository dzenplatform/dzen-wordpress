.PHONY: up setup test lint package package-test down
up:
	docker compose up -d db wordpress

setup:
	docker compose run --rm cli eval-file wp-content/plugins/dzen-chat/tests/setup.php

lint:
	docker compose exec -T wordpress sh -c 'set -e; for file in wp-content/plugins/dzen-chat/*.php wp-content/plugins/dzen-chat/src/*.php; do php -l "$$file"; done'

test: lint
	docker compose run --rm cli eval-file wp-content/plugins/dzen-chat/tests/contracts.php
	docker compose run --rm cli eval-file wp-content/plugins/dzen-chat/tests/widget-contracts.php
	docker compose run --rm cli eval-file wp-content/plugins/dzen-chat/tests/registration-contracts.php

package:
	./bin/package.sh

package-test:
	python3 tests/package-checks.py

down:
	docker compose down
