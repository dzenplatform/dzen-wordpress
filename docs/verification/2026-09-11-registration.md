# WordPress 0.2.0 authorization

Based on Codex task `01a08b55-5ca1-76e2-ad7f-e5eae6e72135` and the implemented
Dzen Chat handlers at that date. The main project code was not changed.
This is a historical report; subsequent releases connect management APIs.

## Changes

The /auth/add redirect sends type=wordpress, HTTPS site_url, an in-site
callback, state and an S256 code_challenge. On return, WordPress checks state,
administrator session, attempt expiry, site and service origin. The one-time
code is exchanged through JSON POST /auth/exchange/.

Accept the actual client_id, client_secret and site_source_id fields.
The existing sodium-backed WordPress store encrypts credentials with
autoload=false. Connection no longer requires invented project/integration IDs.
The code is removed from the visible URL; replaying the callback sends no
second exchange request.

A successful exchange confirms authorization without calling the future
/api/v1/integration. Widgets, history, indexing and source status were not adapted
in this iteration. Their prepared code remained, but new connections did not
enable them or submit indexing events. Local key removal is explicitly
distinguished from revocation in Dzen Chat.

## Results

- PHP lint and 101 checks: 48 previous contract checks, 17 widget checks and
  36 new checks in tests/registration-contracts.php.
- The new checks cover exact endpoint/JSON, PKCE, missing response fields,
  HTTP 302/400, network failure, invalid JSON, state/session/origin/TTL, code
  replay, nonce, HTTPS/path prefix, encryption and no future-API calls.
- A real in-app browser flow passed: WordPress → local Dzen Chat → create a
  separate project → callback → exchange → authorized WordPress. Connection
  survived reload.
- Reconnecting to the same project issued a new client and preserved
  site_source_id. WordPress inspection confirmed encrypted secret, disabled
  autoload, no synthetic API loaded and an empty indexing queue.

The live test used https://local.dzenchat.com and
https://local.dzenchat.com:8869, not production. Neither credentials nor exchange
codes are included in this report or Git. A separate local.dzenchat.com test
project was created with source https://local.dzenchat.com:8869/ and left
connected for review.

## Reproduce the live test

A separate Compose project preserves real registration independently of
synthetic make test / make setup. At the time, both WordPress configurations
used port 8868, so only one could run at once. The current README places the
fixture on 8870, allowing both to coexist.

Local Caddy on this machine already has a trusted local.dzenchat.com certificate.
Copy the public CA with container-readable permissions; certificate verification
stays enabled.

~~~sh
docker compose stop
mkdir -p output/caddy-registration
install -m 644 /opt/homebrew/var/lib/caddy/pki/authorities/local/root.crt output/caddy-registration/dzen-local-ca.crt
docker compose -p dzen-wordpress-registration -f compose.yaml -f compose.registration.yaml up -d db wordpress
caddy run --config tests/Caddyfile.registration --adapter caddyfile
~~~

Run the last command in a separate terminal. The test proxy uses an existing
certificate and must restart after renewal. Proxy storage is isolated in
output/caddy-registration; main Caddy configuration autosave is disabled.
On the first run, before storage was isolated, Caddy automatically cleaned
three sets of long-expired certificates from the default
~/Library/Application Support/Caddy directory.

For a new volume:

~~~sh
docker compose -p dzen-wordpress-registration -f compose.yaml -f compose.registration.yaml run --rm cli core install --url=https://local.dzenchat.com:8869 --title='WordPress Dzen integration' --admin_user=dzen_test --admin_password=local-dzen-test-8868 --admin_email=wordpress-registration@example.org --skip-email
docker compose -p dzen-wordpress-registration -f compose.yaml -f compose.registration.yaml run --rm cli plugin activate dzen-chat
~~~

This is a dedicated test WordPress account. Open
https://local.dzenchat.com:8869/wp-admin/admin.php?page=dzen-chat in the in-app
browser, sign in and click **Connect Dzen Chat**. Production defaults to
https://chat.dzen.dev; only the development overlay sets the local origin.

The dist/dzen-chat-0.2.0.zip archive contains the plugin without fixtures,
Compose or local certificates. Production deployment and management APIs were
not tested in this iteration.
