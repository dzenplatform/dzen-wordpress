# Dzen Chat public API: WordPress 0.13 contract

Verified on September 11, 2026 against the running local service,
Swagger at `https://local.dzenchat.com/api-docs/` and `chat/api/` handlers.
Server task: `01a086d7-18f2-73e1-99b0-b59d123f2da5`.

This contract replaces the Integration API v1 proposal from version 0.1.
Routes have no version number, scopes or HMAC signature. Version 0.5 adds
localization without changing this API contract.

Version 0.7 uses the new `GET /api/sources/{id}/status` aggregate endpoint and
`edit_url` supplied by all widget responses. Source and widget browser links
use the configured service origin and never contain credentials. See
[widget editor links](widget-editor-links.md).

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
| GET /api/widgets/{id} | id, name, code, is_enabled, suggestions_enabled, trigger_templates, appearance, edit_url |
| PATCH /api/widgets/{id} | name, is_enabled, suggestions_enabled; confirmed fields in response |
| POST /api/update | JSON url only; HTTP 202 means crawling was queued |
| GET /api/pages?url={url}&source_id={id}&limit=1 | Exact URL lookup within an assigned source; filters apply before limit |
| GET /api/pages/{id} | id, url, title, source_id, status, status_error, index_status, has_triggers, updated_at |
| GET /api/pages/{id}/triggers | id, source_id, url, status, triggers, details_url; saved page triggers within an assigned source |
| GET /api/sources | Sources assigned to the client; no query parameters |
| GET /api/sources/{id} | id, title, url, is_paused, enable_triggers, blocked_reason, last_reindexed_at |
| GET /api/sources/{id}/status | Source metadata, counts, total and details_url; all pages, no list limit |
| GET /api/files | Knowledge base files; no query parameters |
| POST /api/files | JSON name (1–255 characters) and content; HTTP 201 with the created file |
| GET /api/files/{id} | id, name, content, status, status_error, size_bytes, updated_at |
| GET /api/chats?limit=100 | Most recently created conversations; limit 1–100 only |
| GET /api/chats/{id} | id, created_at, updated_at, visitor, widget_code, feedback, details_url |
| GET /api/chats/{id}/messages?limit=100 | First messages; id, role, text, created_at, vote, guardrail_triggered, guardrail_stage, suggested_actions, selected_suggested_action, suggestions_status |

Page, conversation and message lists currently have no cursor/offset.
Page lists support exact URL and source filters. Conversation lists have no
server search, date or widget filters. The plugin
applies conversation filters to the received 100 conversations and explains this
limit. The Index screen uses aggregate source counts instead of a document list. It does not claim a long conversation is fully loaded after 100 messages.

Displayed timestamps use the WordPress date/time formats, current locale and site
timezone. Conversation date filters apply to those same local calendar days,
not the UTC date substring. The API and stored timestamps are unchanged. Missing
or malformed dates show a dash and cannot match an active calendar filter.

Widgets use the real `/widget/{code}` loader. WordPress prints one
`<div id="chat-chat"></div>` before the footer script. The loader creates a
button and iframe; the iframe does not have to be inside the mount element.
Only public widget fields are cached. TTL is 300 seconds; error retries wait
60 seconds. Saving in WordPress updates state immediately.

Render only user/assistant messages with escaped text. Internal prompts are not
shown. The client blocks history deletion/modification before HTTP. Source
metadata, file content and original links are available inside WordPress, but
the messages API has no references for individual answers.

## Conversation suggestions and web navigation

Conversation list/detail and feedback responses include `details_url` pointing to
`{service_origin}/projects/{project_short_id}/history/{chat_id}`. It is browser
navigation using the normal Dzen Chat session, with no API credentials. WordPress
requires the configured HTTPS service origin and the same chat ID, without query
parameters, fragments or embedded credentials. The link appears to the right of
the conversation heading and remains available if message loading fails.

Message responses include only user/assistant messages, with these saved fields:

- `suggested_actions`: an array of follow-up suggestion strings.
- `selected_suggested_action`: the saved selected text, only if it belongs to
  that answer's suggestion list; otherwise `null`.
- `suggestions_status`: `available` for saved suggestions, `failed` for a saved
  generation error, or `none` when no suggestions were saved. `none` does not
  establish whether the feature was disabled at the time.

WordPress renders suggestions as escaped, read-only list items, marks the stored
visitor selection, and distinguishes generation failures from absent suggestions.
Opening history never generates new suggestions or modifies the conversation.
The API exposes neither `full_context`, internal system messages, nor raw provider
error details. Generation diagnostics are available in the Dzen Chat web admin.

## Saved page triggers in the editor

After locating the saved public URL within the registered source, the editor
requests `GET /api/pages/{id}/triggers`. The endpoint checks the active Bearer
client, page project and source assignment. Missing, foreign and unassigned pages
return the same HTTP 404. An invalid or revoked client receives HTTP 401.

The response includes `id`, `source_id`, `url`, `status`, `triggers` (items with
`key` and `text`) and `details_url` pointing to the same page in the web admin.
Its status follows the existing source policy and trigger normalization:

- `available`: saved page-specific triggers are available.
- `source_disabled`: the source has disabled triggers.
- `not_matched`: the page URL does not match the source trigger rules.
- `not_generated`: no page-specific triggers are available yet; the public widget
  may use its configured default templates.

This administrative GET reads existing data only. It does not discover pages,
schedule crawling, generate triggers or return response caches or a signed widget
page token. It does not return the widget's default templates as generated page
triggers. The plugin validates the page ID, source, URL, unique trigger keys and
the credential-free, same-origin page link.

The trigger list shares the editor indexing refresh and updates after a successful
save. A failure of the trigger request keeps the confirmed indexing state and
shows a separate trigger error. Draft, protected, excluded and undiscovered pages
have explicit empty states. WordPress renders trigger text as DOM text and does
not execute a trigger or save its text locally. Widget placement remains a separate
setting below the panel; hiding a widget does not prevent inspecting saved page
triggers. This version adds viewing only; trigger policy changes stay in Dzen Chat.

## Source indexing status

The Index screen reads `site_source_id` from the saved registration and requests
`GET /api/sources/{site_source_id}/status`. URL query parameters cannot select
another primary source. Version 0.13 also reads `GET /api/sources` and requests
the status of each additional assigned source. It does not expand client access
to every source in the project. Each source has its own progress bar and settings
link. The former Sources screen redirects to Index, including old file-detail links.
The server requires an active Bearer client, checks the source's
project and the client's source assignment, and aggregates all its pages.
Missing, foreign and unassigned sources return the same HTTP 404; an invalid or
revoked client receives HTTP 401. The endpoint does not modify source state.

Response example:

~~~json
{
  "id": "source-example",
  "title": "Website",
  "url": "https://wp-site.com/",
  "is_paused": false,
  "enable_triggers": false,
  "blocked_reason": null,
  "last_reindexed_at": null,
  "counts": {"errors": 2, "pending": 3, "processing": 1, "ready": 105, "excluded": 4},
  "total": 115,
  "details_url": "https://chat.dzen.dev/projects/project-example/sources/source-example"
}
~~~

`total` includes all five counts. Classification is shared with the Dzen Chat
Sources screen: exclusions take precedence, then errors, then the pending,
processing and ready stages for pages without an error. The colored progress
bar includes errors, pending, processing and ready; excluded pages have a
separate counter. Empty and excluded-only sources have a neutral bar.

The plugin validates nonnegative integer counts, their sum, source identity
and the exact source URL on its configured service origin. Invalid responses
and transport errors display an error instead of progress. Counts are fetched
on every Index load or **Refresh status** action and are not persisted.
Pause and restriction states remain visible alongside the counts. The details
link opens the actual source's settings under normal Dzen Chat web login.

## Document uploads and indexing

Index also lists project files from `GET /api/files`. The summary classifies each
file as pending (`crawler`), processing (`parsing`), ready (`ready`), or error
when `status_error` is nonempty. Errors take precedence over a raw `ready` state.
Each file opens escaped text within WordPress and its editor in Dzen Chat.
Source-list and individual source failures do not prevent loading the file list.

The upload form requires `manage_options`, `upload_files` and a valid WordPress
action nonce. It accepts one PHP HTTP upload with a `.txt`, `.md` or `.markdown`
extension, valid UTF-8 and no binary control bytes. Empty files are rejected.
The plugin caps bytes at 256 KiB or the WordPress upload limit if lower and strips
a leading UTF-8 BOM. It reads PHP's temporary upload without creating a media
attachment, public file or database copy, then sends `POST /api/files` with JSON
`name` and `content` through the existing authenticated transport.

HTTP 201 creates a file and queues the existing service indexing pipeline. The
plugin validates the response identity, status and exact submitted name/content
before showing upload acceptance. It never automatically retries an ambiguous
upload; administrators should check the list before uploading again. Server
indexing limits are separate from upload size: `status_error=too_big` is displayed
as an indexing error even if the response status is `ready`.

This API accepts text, not binary file attachments. PDF/Office conversion is not
implemented here. Files are processed as Markdown by Dzen Chat. The file editor
URL is derived from the project prefix of a validated source `details_url`, plus
`/files/{id}`; it contains no API credentials and uses normal Dzen Chat web login.
If no source supplies a valid project prefix, file content remains available in
WordPress without an invented external link.

## Per-page status in the editor

The editor loads `GET /api/pages?url={saved_permalink}&source_id={site_source_id}&limit=1`
through an authenticated WordPress AJAX handler. Filtering happens before the
limit, so an older page is still found. An empty list means that exact URL is
absent from the assigned source; a mismatched URL/source or malformed response
is an error. URL normalization matches `POST /api/update`.

Page list and detail responses include `index_status`: `pending`, `processing`,
`ready`, `errors` or `excluded`. Error and exclusion categories take precedence
over the raw processing stage, consistently with the source progress bar.

The WordPress handler accepts only a post ID, checks administrator and post-edit
permissions and a post-bound nonce, then reads the saved permalink and source
from WordPress. It never accepts an arbitrary API URL or sends credentials to
the browser. Drafts, private/password-protected content and local indexing
exclusions do not make a public page lookup. Previously public pages are not
reported as removed merely because their current WordPress state changed.

The panel loads asynchronously, refreshes on demand and after successful
Gutenberg saves, and keeps local queue status separate from remote indexing.
Only events for the current client and post are considered. Network/authorization
errors clear the prior display instead of reporting a page as absent or ready.
There is no background polling and no change to content submission or widget
placement. Unsaved edits are outside the status reported by the server.

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
Read source progress through GET /api/sources/{id}/status. Acknowledgment compares normalized
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
