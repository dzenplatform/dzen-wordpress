# Dzen Chat public API: WordPress 0.4 contract

Verified on September 11, 2026 against the running local service,
Swagger at `https://local.dzenchat.com/api-docs/` and `chat/api/` handlers.
Server task: `01a086d7-18f2-73e1-99b0-b59d123f2da5`.

This contract replaces the Integration API v1 proposal from version 0.1.
Routes have no version number, scopes or HMAC signature. Version 0.5 adds
localization without changing this API contract.

## Registration and transport

`GET /auth/add` receives `type=wordpress`, HTTPS `site_url`,
`redirect_uri`, `state`, `code_challenge` and
`code_challenge_method=S256`. The WordPress callback is
`/wp-admin/admin-post.php?action=dzen_chat_authorize` within the same site.
Check the admin session, state, attempt expiry, site URL and service origin.
Present the code only once.

`POST /auth/exchange/` accepts JSON fields `code`, `code_verifier` and
`redirect_uri`. Its HTTP 200 response contains `client_id`,
`client_secret` and `site_source_id`. Do not invent project or integration
IDs absent from the response. See the
[registration report](../verification/2026-09-11-registration.md).

Management requests use `Authorization: Bearer <client_id>.<client_secret>`
with the `/api` prefix. The server derives project and object access from the
client; WordPress sends no arbitrary project_id. WordPress verifies TLS, sends
no cookies, follows no redirects and limits responses to 2 MiB. The plugin
constructs request URLs instead of accepting them from server responses.
History and source contents are not cached in WordPress.

## Implemented methods used by the plugin

All IDs are opaque. Collections use `{items: [...]}`. A missing field or an
unvalidated response does not confirm a change.

| Method | Implemented contract |
| --- | --- |
| GET /api/widgets | All available widgets; no query parameters |
| POST /api/widgets | name only, 1–128 characters; HTTP 201 |
| GET /api/widgets/{id} | id, name, code, is_enabled, suggestions_enabled, trigger_templates, appearance |
| PATCH /api/widgets/{id} | name, is_enabled, suggestions_enabled; confirmed fields in response |
| POST /api/update | JSON url only; HTTP 202 means crawling was queued |
| GET /api/pages?limit=100 | Most recently updated pages; limit 1–100 only |
| GET /api/pages/{id} | id, url, title, source_id, status, status_error, has_triggers, updated_at |
| GET /api/sources | Sources assigned to the client; no query parameters |
| GET /api/sources/{id} | id, title, url, is_paused, enable_triggers, blocked_reason, last_reindexed_at |
| GET /api/files | Knowledge base files; no query parameters |
| GET /api/files/{id} | id, name, content, status, status_error, size_bytes, updated_at |
| GET /api/chats?limit=100 | Most recently created conversations; limit 1–100 only |
| GET /api/chats/{id} | id, created_at, updated_at, visitor, widget_code, feedback |
| GET /api/chats/{id}/messages?limit=100 | First messages; id, role, text, created_at, vote, guardrail_triggered, guardrail_stage |

Page, conversation and message lists currently have no cursor/offset.
There are no server filters for search, date, source or widget. The plugin
applies its available filters to the received 100 objects and explains this
limit. It does not claim a long conversation is fully loaded after 100 messages.

Widgets use the real `/widget/{code}` loader. WordPress prints one
`<div id="chat-chat"></div>` before the footer script. The loader creates a
button and iframe; the iframe does not have to be inside the mount element.
Only public widget fields are cached. TTL is 300 seconds; error retries wait
60 seconds. Saving in WordPress updates state immediately.

Render only user/assistant messages with escaped text. Internal prompts are not
shown. The client blocks history deletion/modification before HTTP. Source
metadata, file content and original links are available inside WordPress, but
the messages API has no references for individual answers.

## Content updates

WordPress submits only public, non-password-protected pages/posts. A new draft,
product or initially protected post creates no publication request.
Connection, upgrading from 0.3 and reactivation start reconciliation in batches
of 50 posts; the worker sends up to 10 events per pass.

Saving creates a local event without waiting for HTTP. The queue stores no page
text, only post/client IDs, URL, previous URL and revision. The historical
integration_id column remains for table compatibility; it now contains
client_id and only binds the queue. Events from a previous client are not sent
after reconnecting to another client.

Request body:

~~~json
{"url":"https://wp-site.com/example/"}
~~~

Acknowledgment:

~~~json
{"status":"ok","action":"queued","url":"https://wp-site.com/example/","final_url":null,"message":"Page update queued"}
~~~

WordPress's accepted state means the URL was submitted. This API has no
operation IDs, polling or deletion events. It uses the existing Dzen Chat crawler.
Read actual page processing through /api/pages. Acknowledgment compares normalized
URLs, including root trailing slash, default port, IDN and Unicode. A different
host, port, path or query does not confirm the original request.

Permalink changes submit both old and new URLs. Unpublishing, password
protection, trash and deletion submit the previously public URL for recrawling.
**This is not an exclusion command or a guarantee of removal from search.**
The server may process 404/soft-404 later. Deletion behavior still needs a
separate agreement and verification.

Temporary network failures, HTTP 429 and 5xx receive bounded delayed retries,
respecting Retry-After, up to six attempts. Accepted events are not polled again.
Persistent failures can be retried manually through reconciliation.
WP-Cron or a system scheduler must run WordPress jobs.

## Remaining requirements

| Requirement | Missing server capability |
| --- | --- |
| Hide/restore conversations | Reversible visibility, list filter and mutation; preserve messages, tokens and billing |
| Read answer sources inside WordPress | Message references/excerpts and authorized source text, checked against the conversation |
| Search the full history and index | Pagination, server filters and message continuation |
| Enable triggers for an individual page | Read/write URL policy, including before the document is indexed |
| Verify billing and project activity | Explicit server state, restriction reason and operation behavior |
| Remove withdrawn/deleted content from answers | Confirmed URL exclusion from retrieval and crawling, with restoration on republication |

Hiding and history sources are tracked in
[Dzen Chat #14](https://github.com/xen/dzen.chat/issues/14).
These are needed additions, not claims that endpoints exist. Agree exact paths
and DTOs in the server task. The file list currently returns every file's
content. Large knowledge bases need metadata-only listing and pagination:
the plugin rejects entire responses larger than 2 MiB to bound memory use.

A successful request, active API client or source with is_paused=false does not
prove billing status. The plugin does not infer payment from a plan name or the
absence of errors. Dzen Chat controls public answers itself.

## Verification boundaries

The [0.4.0 report](../verification/2026-09-11-full-integration.md) separates real
local Dzen Chat verification from isolated contract tests. Fixtures reproduce
only the listed response shapes; missing operations are not simulated.
Fixtures, certificates and the development overlay are excluded from ZIPs.
This iteration does not change the main Dzen Chat project or production.
