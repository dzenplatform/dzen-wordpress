# Dzen Chat for WordPress

Connect your WordPress site to [Dzen Chat](https://chat.dzen.dev), manage chat
widgets and keep public content available to your AI assistant.

**Version 0.5.1 uses the implemented public API and includes English and Russian
interfaces.** Authorize a site, manage real widgets, inspect indexed documents,
sources and knowledge files, and read conversations directly in WordPress.
Public pages and posts are submitted for reindexing after connection, publication
and changes. The complete update flow has been verified locally: edited content →
background queue → Dzen Chat index → assistant answer using the new information.

## Features

- Create, rename, enable or disable widgets and configure follow-up questions.
- Select a widget for the whole site, choose another for a page or post, or hide
  it on that page. Widget settings apply to the Dzen Chat project; placement
  applies to this WordPress site.
- Synchronize public pages and posts through a persistent background queue.
- Inspect document processing, open the original source or edit the matching
  WordPress post.
- View source status and knowledge file contents as escaped text.
- Read conversations, messages and feedback; filter by conversation or visitor
  ID, date and widget. Conversation text stays on the service.
- Use the English interface or the bundled Russian translation, following the
  administrator's WordPress language preference.

Changes made through the plugin refresh its widget cache immediately. Changes
made directly in Dzen Chat are refreshed within five minutes.
When the WordPress toolbar is visible, the chat window reserves space below it
so the close button and message composer remain accessible.

## Current API limits

- Document and conversation lists contain at most the latest 100 records.
  Filters apply to that set. A conversation displays its first 100 messages.
- Hiding/restoring conversations, full-history text search, individual answer
  sources, page trigger controls and billing status are not yet available.
- Deleting, unpublishing or changing the URL of a previously public post submits
  its old URL for another check. This **does not guarantee removal from
  retrieval**; an explicit server operation is still required.
- Products need a separate integration. The plugin submits only public
  `page`/`post` content. General crawling and additional sources are managed
  by the service.

See the [current API contract](docs/contracts/dzen-chat-api.md),
[0.4.0 live verification](docs/verification/2026-09-11-full-integration.md),
[implementation brief](docs/specs/2026-09-11-full-integration.md),
[authorization verification](docs/verification/2026-09-11-registration.md) and
[server conversation-visibility issue](https://github.com/xen/dzen.chat/issues/14).

## Installation

Download `dzen-chat-X.Y.Z.zip` from
[GitHub Releases](https://github.com/dzenplatform/dzen-wordpress/releases), then
open **Plugins → Add New → Upload Plugin** in WordPress and activate it.
For a manual update, upload the new ZIP and confirm replacement.

1. Open **Dzen Chat** and click **Connect Dzen Chat**.
2. Choose an existing project or create one at `chat.dzen.dev`.
3. Return to WordPress after authorization.
4. Open **Dzen Chat → Widgets**, enable a widget and click **Place on site**.
5. Open a public page to check the widget. Page and post overrides are in the editor.
6. Inspect **Index**, **Sources** and **Conversations** as content and chats arrive.

Requirements: WordPress 6.8+, PHP 8.2+, HTTPS, PHP sodium and strong WordPress
security keys. Synchronization needs working WP-Cron or a system scheduler.

## Language

Plugin source strings and repository documentation are in English. Russian
(`ru_RU`) is included in every installation ZIP.

In **Users → Profile → Language**, choose **Русский** for a Russian admin
interface or **English (United States)** for English. Install the WordPress
language pack through **Settings → General → Site Language** if Russian is not
yet available. A user who selects **Site Default** follows the site's language.
Languages without a bundled translation fall back to English.

Widget names, source titles, indexed content and conversation messages keep their
original language. The embedded widget and Dzen Chat website are rendered by the
service; this plugin's translation covers the WordPress interface.

See [translation maintenance](docs/internationalization.md) to add a language or
update the catalogs, and the [0.5.0 verification report](docs/verification/2026-09-11-internationalization.md)
for the English/Russian checks.

## Connection and data

Connection uses PKCE S256 and a one-time code:
`/auth/add` → WordPress callback → `/auth/exchange/`.
WordPress stores `client_id`, `client_secret` and `site_source_id` in an
encrypted option with autoload disabled. Server requests to `/api/` use the
Bearer token `client_id.client_secret` with HTTPS verification. Only the public
widget code reaches the visitor's browser. Plugin logs do not include exchange
codes, secrets or conversation text.

Connection schedules reconciliation of existing public pages and posts. This
also runs when upgrading from 0.3 or reactivating the plugin. Saving content
writes an event to a local queue without waiting for HTTP. The scheduler submits
URLs through `POST /api/update`; temporary failures are retried with a delay,
up to six attempts. HTTP 202 confirms acceptance; **Index** shows processing
separately. The overview provides a reconciliation and retry button.

The queue requires WP-Cron or an external scheduler. Local Compose includes a
`cron` profile that runs only plugin jobs. A successful API response does not
confirm the project's billing status. Removing credentials in WordPress is a
local action; revoke the API client in Dzen Chat. The plugin does not delete
chats or messages or store conversation text and source excerpts in its database.

## Builds and releases

The repository and its releases are public. Install the versioned ZIP attachment;
GitHub's automatic **Source code** archives contain development files and are
not installation packages. Each release ZIP has a SHA-256 checksum.

GitHub Releases do not enable automatic updates inside WordPress. That requires
a future updater or a WordPress.org listing. The `Update URI` header prevents
replacement by an unrelated directory plugin with the same name.

Pushes to `main`, pull requests and manual Actions runs check packaging, PHP
lint and WordPress contracts, then keep a ZIP artifact for 14 days. Pushing a
`vX.Y.Z` tag additionally creates a GitHub Release after the checks pass.
Existing releases are not overwritten.

To release:

1. Update `Version` and `DZEN_CHAT_VERSION` in `dzen-chat.php`, plus
   `Stable tag` and the changelog in `readme.txt`. Use `X.Y.Z`.
2. Update and compile the [translation catalogs](docs/internationalization.md).
3. Commit and push the changes; wait for a successful workflow.
4. Create and push the matching tag, for example `v0.5.1`.
5. Download the checked ZIP and checksum from Releases.

Build locally with `make package-test && make package` (Python 3 and Git).
Version 0.5.1 produces `dist/dzen-chat-0.5.1.zip`. Only Git-tracked runtime files
and `src/`, `assets/` and `languages/` are packaged. Stage new runtime and
language files before building. Fixtures, Docker configuration, local
certificates and development documentation are excluded.

## Local verification

Synthetic checks and the real connection use **separate volumes**. Never run
fixture setup or contract tests against the real connection; the scripts check
the local environment and isolated MU API.

The fixture uses WordPress 6.8.2 / PHP 8.3 on port 8870:

~~~sh
COMPOSE_PROJECT_NAME=dzen-wordpress-widget-tests DZEN_WORDPRESS_PORT=8870 make up
COMPOSE_PROJECT_NAME=dzen-wordpress-widget-tests DZEN_WORDPRESS_PORT=8870 docker compose run --rm cli core install --url=http://127.0.0.1:8870 --title='Dzen Chat contract tests' --admin_user=dzen_test --admin_password=local-dzen-test-8870 --admin_email=wordpress-fixture@example.org --skip-email
COMPOSE_PROJECT_NAME=dzen-wordpress-widget-tests DZEN_WORDPRESS_PORT=8870 docker compose run --rm cli plugin activate dzen-chat
COMPOSE_PROJECT_NAME=dzen-wordpress-widget-tests DZEN_WORDPRESS_PORT=8870 make test
~~~

Core installation and plugin activation are needed only for a new volume.
In this environment, **Dzen Chat · test** opens a demonstration panel without AI
answers. The fixture is excluded from the installation ZIP and does not prove
service behavior.

The real local connection uses the `dzen-wordpress-registration` Compose
project, HTTP port 8868 and an HTTPS proxy on 8869. The trusted local CA and first
registration are described in the
[authorization report](docs/verification/2026-09-11-registration.md).

~~~sh
docker compose -p dzen-wordpress-registration -f compose.yaml -f compose.registration.yaml --profile cron up -d db wordpress cron
~~~

Open the [local WordPress admin](https://local.dzenchat.com:8869/wp-admin/admin.php?page=dzen-chat)
and [local Dzen Chat](https://local.dzenchat.com).
The overlay changes only the development address and trusted CA; it does not
substitute API responses. Regular installations use `https://chat.dzen.dev`.
This version has not been deployed to production.

Repository: `git@github.com:dzenplatform/dzen-wordpress.git`.
Development checkout: `/Users/xen/Dev/dzen/wordpress`.
