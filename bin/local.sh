#!/bin/sh
# Start the real local integration, preserving its database and authorization.
set -eu

cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"

for dzen_command in docker caddy curl; do
    if ! command -v "$dzen_command" >/dev/null 2>&1; then
        printf 'Missing requirement: %s. See README > Local verification.\n' "$dzen_command" >&2
        exit 1
    fi
done
if ! docker info >/dev/null 2>&1; then
    printf 'Docker is unavailable. Start Docker Desktop and run make local again.\n' >&2
    exit 1
fi

# These are the certificates used by tests/Caddyfile.registration and the local
# Dzen Chat service. Do not generate a new CA or disable certificate checks.
dzen_caddy_data=/opt/homebrew/var/lib/caddy
dzen_ca="$dzen_caddy_data/pki/authorities/local/root.crt"
for dzen_tls_file in "$dzen_ca" \
    "$dzen_caddy_data/certificates/local/local.dzenchat.com/local.dzenchat.com.crt" \
    "$dzen_caddy_data/certificates/local/local.dzenchat.com/local.dzenchat.com.key"; do
    if [ ! -r "$dzen_tls_file" ]; then
        printf 'Missing local TLS file: %s\nStart the local Dzen Chat HTTPS environment first.\n' "$dzen_tls_file" >&2
        exit 1
    fi
done
if ! curl --fail --silent --show-error --max-time 10 --output /dev/null https://local.dzenchat.com/; then
    printf 'The local Dzen Chat HTTPS service is unavailable. Start it and run make local again.\n' >&2
    exit 1
fi

mkdir -p output/caddy-registration
if ! cmp -s "$dzen_ca" output/caddy-registration/dzen-local-ca.crt; then
    install -m 644 "$dzen_ca" output/caddy-registration/dzen-local-ca.crt
fi

compose() {
    # Ignore fixture project/port settings inherited from a test command.
    DZEN_WORDPRESS_PORT=8868 docker compose -p dzen-wordpress-registration \
        -f compose.yaml -f compose.registration.yaml --profile cron "$@"
}

compose up -d --wait db wordpress
dzen_attempt=0
until compose exec -T wordpress test -f /var/www/html/wp-config.php; do
    dzen_attempt=$((dzen_attempt + 1))
    if [ "$dzen_attempt" -ge 30 ]; then
        printf 'WordPress initialization timed out. Inspect the registration Compose logs.\n' >&2
        exit 1
    fi
    sleep 1
done

if ! compose run --rm cli core is-installed; then
    compose run --rm cli core install \
        --url=https://local.dzenchat.com:8869 --title='WordPress Dzen integration' \
        --admin_user=dzen_test --admin_password=local-dzen-test-8868 \
        --admin_email=wordpress-registration@example.org --skip-email
    printf 'Created local WordPress. Login: dzen_test / local-dzen-test-8868\n'
fi
if ! compose run --rm cli plugin is-active dzen-chat; then
    compose run --rm cli plugin activate dzen-chat
fi
compose up -d --wait cron

printf '\nWordPress: https://local.dzenchat.com:8869/\n'
printf 'Plugin:    https://local.dzenchat.com:8869/wp-admin/admin.php?page=dzen-chat\n'
printf 'Connect Dzen Chat in the plugin admin if this is a new site.\n'
if curl --fail --silent --max-time 5 --output /dev/null https://local.dzenchat.com:8869/wp-login.php; then
    printf 'HTTPS is already available; keeping the running proxy.\n'
else
    printf 'Starting HTTPS. Keep this terminal open; Ctrl+C stops the proxy and preserves site data.\n'
    exec caddy run --config tests/Caddyfile.registration --adapter caddyfile
fi
