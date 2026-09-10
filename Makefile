.PHONY: up setup test lint package down
up:
	docker compose up -d db wordpress

setup:
	docker compose run --rm cli eval-file wp-content/plugins/dzen-chat/tests/setup.php

lint:
	docker compose exec -T wordpress sh -c 'find wp-content/plugins/dzen-chat/src -name "*.php" -exec php -l {} \;'

test: lint
	docker compose run --rm cli eval-file wp-content/plugins/dzen-chat/tests/contracts.php
	docker compose run --rm cli eval-file wp-content/plugins/dzen-chat/tests/widget-contracts.php
	docker compose run --rm cli eval-file wp-content/plugins/dzen-chat/tests/registration-contracts.php

package:
	mkdir -p dist
	./bin/package.sh

down:
	docker compose down
