# Task: Dzen Chat plugin for WordPress

Date: 2026-09-09. Status at that date: **WordPress client 0.1.0 implemented against
the proposed API contract**. Server implementation and joint acceptance remained
a separate stage. History, sources and hiding were built against the user's
clarifications without waiting for the server API.

This is a historical specification. Some proposals below were superseded by the
[current API contract](../contracts/dzen-chat-api.md), including versioned
transport, scopes, project status and conversation mutations. It does not
describe all capabilities available in the current release.

## Goal

A WordPress administrator connects a site to a Dzen Chat project through consent
at `chat.dzen.dev`, manages widgets and page triggers, and views conversation
history, indexing status and additional sources. Changes to public site content
reach the index automatically. WordPress displays project restrictions, and the
service enforces them.

Deliver an installable plugin ZIP, code and checks in
`git@github.com:dzenplatform/dzen-wordpress.git`, with the local checkout at
`/Users/xen/Dev/dzen/wordpress`.

## Context

The main checkout at `/Users/xen/Dev/dzen/chat` was inspected on 2026-09-09:

- `chat/routes.py`: public `POST /api/update`, the public widget and project
  administration routes.
- `chat/views/api/views.py`: update signature, one-time nonce, request limits,
  client source checks and queuing `crawl_page_task`.
- `chat/models/data.py`: `ApiClient`, `Source`, `WidgetIntegration`,
  `Page`, `Chat`, `ChatMsg`, and their project/source relationships.
- `chat/models/project.py`: projects and `owner`/`member` roles.
- `chat/views/projects/workspaces.py`: project and source creation.
- `chat/views/projects/views.py`: API client creation and source settings.
- `chat/views/projects/chats.py`: history lists and details in Dzen Chat.
- [Current integration contract and remaining requirements](../contracts/dzen-chat-api.md).
- WordPress APIs: `admin_url()`, `home_url()`, Options API,
  `wp_after_insert_post`, `transition_post_status`, `before_delete_post`
  and WP-Cron.

## Current behavior at specification time

The table describes the starting point. The client implementation and completed
checks are recorded in the [historical report](../verification/2026-09-09-wordpress-client.md).

| Area | Existing behavior | Missing for the plugin |
| --- | --- | --- |
| WordPress | Empty repository with origin configured | Code, admin screens, packaging and checks |
| Connection | Project API clients with client_id, encrypted secret and is_active | /auth/add, consent, one-time exchange and integration record |
| Index | POST /api/update verifies a signature and queues a URL for recrawling | Explicit deletion, event versions, completion confirmation and document listing |
| Widgets and history | Models and administrative HTML routes | A server-client API with restricted access |
| Triggers | Source.enable_triggers, Page.has_triggers and widget settings | An explicit policy for enabling triggers on an individual page |
| Billing | No project billing status in the inspected model or API handler | Authoritative project status and permitted-operation rules |

`202 queued` confirms acceptance, not completed indexing.
`ApiClient.is_active`, a paused source and a blocked project are different states.
The existing `/api/update` signs URL request fields; its signature cannot simply
be reused for arbitrary management methods. Production and the local UI were
not tested while preparing this specification.

## Target shape

### 1. First-version scope

Propose one Dzen Chat project per WordPress site, with multiple independently
revocable integrations per project. The first version targets a normal
WordPress installation. Multisite requires its own verification matrix;
connecting all network sites is not included automatically.

The user confirmed public `page` and `post` content only. Products belong to
a future integration. Drafts, private and password-protected content, other
post types, revisions and autosaves are not submitted.

### 2. Connection

1. An administrator with `manage_options` clicks **Connect Dzen Chat**. The
   POST uses a WordPress nonce. WordPress creates random `state` and PKCE
   verifier values bound to the current user, session and site installation.
2. The browser opens `https://chat.dzen.dev/auth/add` with `host`,
   `type=wordpress`, `site_url`, `redirect_uri`, `state`,
   `code_challenge` and `code_challenge_method=S256`. The host comes from
   configured `home_url()`, never an arbitrary Host header. Build the callback
   through `admin_url()`: `admin-post.php?action=dzen_chat_authorize`.
3. Dzen Chat preserves the request during login. It then shows the domain,
   WordPress and the access being granted, offering an accessible existing project
   or a new one. Consent uses a CSRF-protected POST. A GET creates no project,
   source or API client.
4. For a new project, the server uses the host as its name and creates the owner
   and `site_url` source. For an existing project, it finds the matching site
   source or adds it without duplication. These technical steps need no separate
   screens. Initial indexing starts after connection succeeds.
5. The server returns `code` and `state` to the exactly validated callback.
   Propose a five-minute code lifetime. Store its hash with the project,
   integration type, site, callback and PKCE challenge.
6. WordPress checks state, session and administrator permission, then exchanges
   code plus verifier server-to-server for `client_id` and `client_secret`.
   Redemption is atomic: at most one of two concurrent requests succeeds.
7. WordPress stores credentials and requests integration status. Show
   **Connected** only after checking project, site and access in the response.
   For a restricted project, show connection and restrictions separately.

~~~mermaid
sequenceDiagram
    actor Admin as Administrator
    participant WP as WordPress
    participant Chat as Dzen Chat
    Admin->>WP: Connect
    WP-->>Admin: Redirect with host, state and PKCE challenge
    Admin->>Chat: /auth/add, login and project consent
    Chat-->>Admin: Return code and state
    Admin->>WP: Callback
    WP->>Chat: Exchange code and verifier
    Chat-->>WP: client_id, client_secret and access
    WP->>Chat: Check integration and project status
    Chat-->>WP: Confirmed state
    WP-->>Admin: Connection and available features
~~~

Allow only HTTPS callbacks on the registered site's origin and expected
WordPress path, including subdirectory installations. Normalize domains and
ports; reject wildcards and arbitrary redirects. Separate public/admin domains
need a distinct design. Production crawling must reject private addresses and
unsafe redirects. Configure local testing separately with TLS verification.
Exact callbacks and PKCE follow [RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html).

The callback loads no external resources and uses `Cache-Control: no-store`
and `Referrer-Policy: no-referrer`. Clear its query through a redirect after
processing. Application logs and analytics must not receive the code, verifier
or secret. Verify callback query redaction separately on the test web server.
Cancellation, expiration or a lost exchange response gives an explicit result
and reconnect action. Never reissue a redeemed code. Reconnecting the same
installation revokes an unfinished client.

### 3. WordPress storage

- Store non-secret settings and identifiers in per-site options. Although
  `client_id` is not a password, expose it only to server code and the admin.
  Never return the secret through REST, HTML or JavaScript.
- Store `client_secret` in an encrypted per-site option with
  `autoload=false`. Propose authenticated encryption using
  `sodium_crypto_secretbox`, a random nonce for every write and a versioned
  ciphertext format. The plugin provides encryption; Options API does not.
- Derive the key through HKDF from strong `AUTH_KEY` and `SECURE_AUTH_KEY`
  values in WordPress configuration, with a Dzen Chat purpose and installation
  UUID. Validate sodium and real configuration keys; stop connection with a
  clear error when missing. Do not edit `wp-config.php` automatically or
  store the key beside the ciphertext.
- `wp_salt()` alone does not guarantee secret material is outside the
  database: WordPress also permits database-backed keys. Encryption protects a
  standalone database copy when keys are elsewhere; it cannot protect against
  a plugin executing PHP in the same installation.
- Changing WordPress keys requires reconnection. Changing the URL or cloning
  the site stops submission until the new site is authorized. A copied queue
  must not operate as the original installation.
- Store short-lived connection data separately with a TTL checked on every
  read. Page policies use post meta; the queue uses a separate table.
- **Disconnect integration** revokes the dedicated client and removes local
  credentials. If the service is unavailable, mark revocation incomplete and
  offer retry or revocation in Dzen Chat. Deactivation stops widget insertion
  and background jobs but keeps settings. Uninstall removes local secrets and
  data, without deleting the remote project or sources.

References: [Options API](https://developer.wordpress.org/reference/functions/add_option/),
[wp_salt](https://developer.wordpress.org/reference/functions/wp_salt/),
[PHP sodium](https://www.php.net/manual/en/function.sodium-crypto-secretbox.php).
Check roles as well as [WordPress nonces](https://developer.wordpress.org/plugins/security/nonces/).

### 4. Administrative interface

| Dzen Chat section | Behavior |
| --- | --- |
| Overview | Project, connection, restrictions, last check, queue/errors, check/reconnect/disconnect actions |
| Widgets | Available widgets, creation, source selection within access, name/welcome/appearance, enablement and placement |
| Pages and triggers | Page search, index status, inherited/enabled/disabled policy and effective state with a reason |
| Conversations | Viewing, search, date/widget/visibility filters, messages/feedback, sources inside WordPress and externally, hide/restore |
| Sources | Main site and permitted additional sources, status/errors/counts, last/next update and Dzen Chat settings link |

Use native WordPress screens with server-side API requests, not an iframe of
the Dzen Chat admin. Widget settings live in Dzen Chat; local storage contains
placement choices and safe embed configuration. Propose one selected widget per
page with explicit rule precedence; other widgets can be created and assigned
to other pages. Identify manually embedded code during installation so the
administrator can remove duplicate scripts.

History stays on Dzen Chat. Deleting chats or editing messages is prohibited
because they are billing history. Hiding is shared, reversible project state
that does not change messages, tokens or charges. Hidden chats remain accessible
by filter and direct detail URL. Server admin and API work is described in
[issue #14](https://github.com/xen/dzen.chat/issues/14).

Triggers are existing Dzen Chat page triggers. Disabling them does not disable
the whole widget or automatically change `suggestions_enabled`. A local policy
change has pending/applied/error states. The policy must persist before the
document enters the index and be enforced by the public widget.

### 5. Content synchronization

Hooks record durable events without HTTP during editor saves. Read final content
after posts, taxonomies and metadata are saved. Cover the block editor, Classic
Editor, REST, WP-CLI and scheduled publication, using
[wp_after_insert_post](https://developer.wordpress.org/reference/hooks/wp_after_insert_post/).

| Event | Action |
| --- | --- |
| First publication or public content update | upsert with the current canonical URL |
| Slug, parent page or permalink structure change | Delete the old URL and process the new one; bulk changes schedule reconciliation |
| Draft/private/password protection, trash or permanent deletion | Explicit delete for previously synchronized content |
| Restore from trash | upsert only after checking current public visibility |
| Exclude a page/post in settings | Remove its indexed document and retain the exclusion |
| Autosave or revision | No index event |
| First connection | Batched reconciliation of existing public content with progress |

Reconciliation and integration events enumerate only public `page`/`post`
content. A new WordPress source must not bypass this rule through a product
sitemap or followed links. Do not automatically clear or reconfigure existing
independent project sources.

Document identity is `(integration_id, external_id)`, with a stable WordPress
post identifier. Events have a UUID, monotonically increasing object version,
action, current URL and previous URL. `post_modified` alone cannot order
changes. Respect the latest post state: retries and reordered delivery must not
restore deleted content. Normal source crawling must also respect tombstones.

The queue stores attempts, next attempt, a safe error and `operation_id`.
Separate object state stores the last confirmed URL and version. States are
pending, sending, accepted, completed, waiting for access and failed. Queue
write failures are visible; reconciliation recovers missed events.

Propose WP-Cron with bounded batches and a worker lock. Timeouts, 429 and temporary
5xx receive bounded retries with the same UUID/body, `Retry-After`, a log and
a terminal error. After HTTP 202, check the operation instead of recreating the
event. Restore access explicitly for 401/403; fix invalid data. Retain deletion
events while sending is stopped. This is an explicit proposed retry policy.

WP-Cron depends on site visits, so no fixed update time can be promised without
an external scheduler. Display delay and last run in settings. Document a
hosting scheduler for low-traffic sites. See
[WP-Cron documentation](https://developer.wordpress.org/plugins/cron/).

### 6. Project activity and data boundaries

The server returns client, project and source states separately, plus allowed
operations. WordPress displays the restriction reason and a relevant Dzen Chat
action. Paused indexing is not shown as successful synchronization. The service
defines billing rules; the plugin does not calculate debt.

Propose stopping new upserts and widget answers when a project is blocked.
Reading available status and removing withdrawn content should follow separate
server permissions. Read/delete policy during blocking and the billing status
source require agreement in the server task.

Cache only public configuration and state for a bounded time; propose five
minutes. Show unknown or expired status explicitly. The service enforces answer
restrictions even when the widget is embedded in a cached page. Administrative
keys never reach the widget.

Access is defined by the integration: its site, widgets and matching chats.
The original proposal used a separate scope for additional source observation,
listed during consent. Do not return source passwords or connection parameters.
Do not copy full history into WordPress or send WordPress user names/emails
by default.

### 7. Plugin structure

Separate bootstrap/hooks, admin controllers, authorization, credential storage,
API client, queue/worker, visibility/trigger rules and widget insertion.
Templates only render output. Use WordPress HTTP API with TLS verification,
bounded timeout and response size, and no signed-request redirects to another
host. Reference: [wp_safe_remote_request](https://developer.wordpress.org/reference/functions/wp_safe_remote_request/).

Before implementation, record the WordPress/PHP matrix and sodium dependency.
Propose WordPress 6.8+ and PHP 8.2+, testing the minimum profile and current stable
release. This is a support proposal, not a verified compatibility claim.
Prefix names, CSS and JavaScript, make strings translatable, and isolate the
admin interface from the public site's styling.

## Guard rails

- Preserve unrelated changes in the main Dzen Chat checkout. Server work uses
  a separate worktree; marketing-site changes are outside this task.
- Do not deploy or publish to WordPress.org during specification work.
- Do not add independent billing, another crawler or implicit API compatibility.
- Do not scrape admin HTML, copy Dzen Chat user cookies to WordPress, expose
  secrets in browsers or plaintext options, or store keys in plugin PHP files.
- Do not submit unpublished content, delete shared sources/projects on
  deactivation, or expose another site's chats when choosing a project.
- Products and WooCommerce require another integration; other content types
  are outside synchronization.
- Do not delete chats or edit messages. Hiding must not alter billing.
- Operator replies, full additional-source management and network-wide
  multisite require a separate scope decision.
- An API fixture, HTTP 202 or settings screenshot does not prove real indexing,
  data isolation or project blocking.

## Iterations

| Step | Deliverable | Checkpoint |
| --- | --- | --- |
| 0. Contract | Endpoints, DTOs, scopes, signature, billing status and open decisions agreed | Shared request/response examples; missing behavior explicit |
| 1. Installation | Bootstrap, menu, storage, local WordPress and ZIP build | Install/uninstall, permissions, keys and dependency errors checked |
| 2. Connection | /auth/add → callback → exchange → verification, disconnect | Existing/new projects connected locally; replay and foreign callbacks rejected |
| 3. Index | Queue, lifecycle, reconciliation, document/operation lists | Changes searchable; deleted content stays absent after crawling |
| 4. Widgets | Creation/settings, placement and trigger policies | Correct widgets on two pages; disabled policies actually block triggers |
| 5. Observation | History, sources, restrictions and agreed chat operations | Only authorized data; foreign sites/projects denied |
| 6. Acceptance | Full E2E, documentation and reproducible ZIP | Clean ZIP installation reproduces scenarios; versions and limits recorded |

## Verification

Success means the real WordPress → API → Dzen Chat queue → index → widget-answer
path on a prepared test environment. Each scenario has an ID, expectation and
saved evidence. Designing tests does not mean they have passed.

- **AUTH:** new/existing projects, login before consent, denial, expiry, code
  replay/race, incorrect state/verifier/callback and insufficient role. At most
  one exchange, correct project/source and verified status.
- **ISOLATION:** two sites in one project plus another project. Reject substituted
  widget/source/document/chat IDs; reading must match consent.
- **SECRET:** no client secret in HTML, JS, REST, debug logs or a database dump
  without configuration keys. Changed keys or damaged ciphertext produce clear
  errors. Subscribers/editors cannot manage the integration.
- **CONTENT:** publish/edit/slug/private/password/trash/delete/restore, child
  URLs and permalink changes. Inspect retrieval, not just document lists.
  Stale events and normal recrawling must not restore deleted content.
- **DELIVERY:** duplicates, reordering, timeout after acceptance, 429, stopped
  worker, no visitors and lost permissions. Preserve deletion, avoid false
  completion, converge after recovery.
- **UI:** widget management, different page triggers, paginated history and source
  errors in a browser. Cached pages cannot bypass server answer restrictions.
- **LIFECYCLE:** deactivate/reactivate/uninstall, move a site and restore backups.
  A copy cannot run as the original integration.

Failures include secret/foreign-history disclosure, indexing private content,
restoring a deleted URL, repeated credential issuance, bypassing restrictions,
lost events or an editor blocked by external HTTP.

Future verification commands should cover PHP lint/PHPCS, PHPUnit security and
queue contracts, WP-CLI publication/worker scenarios, browser E2E and Dzen Chat
API integration tests. Specification-stage checks cover completeness, document
links, Git remote and preservation of the neighboring project.

## Open questions

The server must define billing status and permissions under restrictions. The
original client uses explicit `allowed_operations` from the proposed contract
and does not calculate billing. Joint API verification is required before
production.

User clarifications were settled: viewing/search/filters, sources inside
WordPress and externally, hide/restore, no deletion. Synchronize public pages and
posts; products need a separate integration. The original client uses the
specified signature, requires PHP 8.2+/WordPress 6.8+, and was checked on PHP 8.3 /
WordPress 6.8.2. Network activation and a separate admin domain are unsupported.

The user asked to ignore the unfinished closing sentence in the original
request; it does not represent a missing requirement.
