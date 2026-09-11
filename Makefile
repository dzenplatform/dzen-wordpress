.PHONY: up setup test lint i18n-pot i18n-mo package package-test down
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
	docker compose run --rm cli eval-file wp-content/plugins/dzen-chat/tests/i18n-contracts.php

i18n-pot:
	docker compose run --rm --no-deps --volume "$(CURDIR):/plugin" --workdir /plugin cli i18n make-pot . languages/dzen-chat.pot --include=dzen-chat.php,src --domain=dzen-chat --package-name='Dzen Chat' --headers='{"Report-Msgid-Bugs-To":"https://github.com/dzenplatform/dzen-wordpress/issues"}'

i18n-mo:
	docker compose run --rm --no-deps --volume "$(CURDIR):/plugin" --workdir /plugin cli i18n make-mo languages

package:
	./bin/package.sh

package-test:
	python3 tests/package-checks.py

down:
	docker compose down
