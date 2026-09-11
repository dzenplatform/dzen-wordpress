# Task: Hide conversations in the Dzen Chat admin and API

Status: server specification; server implementation is outside the WordPress
change. Updated September 11, 2026 against the implemented public API.

## Goal

An administrator can hide a conversation from the main list and restore it
through a **Hidden** filter. WordPress and Dzen Chat show the same state.
Chats and messages remain on Dzen Chat as history associated with billing.

## Context

- `chat/models/data.py`: Chat, ChatMsg, project_id, messages and token accounting.
- `chat/views/projects/chats.py`: history_list, history_detail, filters,
  message preparation and answer sources.
- `chat/routes.py`: project history routes and the public API.
- [WordPress contract](../contracts/dzen-chat-api.md).

## Current behavior

GET /api/chats and GET /api/chats/{id}/messages work with a limit of up to 100,
without visibility filters, pagination or message sources. WordPress 0.4 uses
these reads. The inspected code has no hidden state or API to change it.

The user confirmed that conversations must not be deleted. The earlier
DELETE /chats/{id} proposal is cancelled. Do not create a local copy of chats
in WordPress.

## Target shape

Hiding is shared project state, not a personal administrator filter or deletion.
Existing conversations are visible by default. The server stores state, time,
actor and monotonically increasing visibility_version; server implementation
chooses the storage. Audit records distinguish Dzen Chat users from API clients.

### Dzen Chat admin

- The main list shows visible conversations. Filters: visible / hidden / all.
- Search, date/widget filters and pagination work together with visibility.
- Rows and detail screens offer **Hide** or **Restore to list**.
- A hidden detail remains accessible by direct URL with normal permissions and
  a **Hidden** label. Hiding does not end an active conversation or prevent new
  messages; new messages do not change visibility.
- Confirmation explains that messages and billing data are retained.
- List counts match the selected filter. Billing and overall usage metrics
  continue to include hidden chats.

### API used by WordPress

These are additions still to be implemented. Reuse the existing
client_id.client_secret Bearer authentication and /api/ prefix, without
versioning, new scopes or a separate signature scheme.

`GET /api/chats?visibility=visible|hidden|all&q=...&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&widget_code=...&cursor=...&limit=20`

Retain id, created_at, updated_at, visitor, widget_code and feedback.
Add next_cursor to list responses and hidden, hidden_at and visibility_version
to items. Title/preview/message_count may be added without changing existing
fields. Dates are UTC; date_from/date_to include the specified UTC days.
Sort stably by created_at and ID. Empty results are successful lists.

GET /api/chats/{id} returns the same fields and permitted metadata. Hiding alone
must not make a detail return 404.

`PATCH /api/chats/{id}/visibility`

~~~json
{"hidden": true, "expected_version": 3}
~~~

HTTP 200 returns id, hidden, hidden_at and the new visibility_version.
Restore with hidden=false. A version conflict returns HTTP 409 with the safe
code visibility_conflict; the client reloads state. A stale-version retry does
not mutate data. A repeated action after reloading creates no billing events
and changes no messages. Do not use DELETE or a toggle without an explicit
target state. If state already matches, a new request must not increment the
version without a visibility change.

Derive project_id/site access from credentials. Check every chat and source
against authorized access, including hidden history.

### Message sources for WordPress

WordPress shows answer sources with two actions: view safe text/an excerpt
inside its own screen, and follow an external original link. These methods
belong to the shared integration API. Hidden conversations retain source access
under the same permissions.

GET /api/chats/{id}/messages?cursor=...&limit=50 returns items and next_cursor.
Message fields: id, role (user/assistant), text, created_at, vote and sources.
Source fields: id (opaque reference for that message), title, url (public
HTTP(S) or null) and available.

GET /api/chats/{chat_id}/messages/{message_id}/sources/{reference_id} returns
id, title, url, content, content_kind (excerpt/document), available,
unavailable_reason when unavailable, and captured_at when known.

Content is plain authorized source text, not page HTML, an iframe, full_context
or a system prompt. Missing/revoked sources report unavailability explicitly.
External links must not expose private URLs or tokens. Label captured excerpts
as snapshots and current content as current. Validate the entire
chat → message → reference chain and current reading permission.

## Guard rails

- No DELETE of Chat/ChatMsg, token removal, billing changes, cascades or retention
  reductions as part of hiding.
- Do not exclude hidden chats from billing calculations, accounting exports or
  total usage.
- Do not store messages or source content in WordPress options, transients or DB.
- Do not reveal the existence or content of another project's/site's chats.
- Do not scrape Dzen Chat admin HTML or pass its user cookies.
- Preserve unrelated work; use a separate branch/worktree for server changes.

## Iterations

1. Agree DTO additions, state, actor and version; test existing API-client isolation.
2. Add server mutation and GET filter; test concurrent and repeated requests.
3. Add admin filters/actions; test search and direct details.
4. Verify sources, WordPress behavior and unchanged billing history.

## Verification

- Hiding removes a chat only from the visible list; hidden/all search finds it.
- Restoring brings it back; WordPress and Dzen Chat agree.
- Hide/restore leaves Chat/ChatMsg counts, token totals, billing events and costs
  unchanged. Compare before/after values in the test environment.
- New messages in hidden chats are saved and billed without making them visible.
- Two concurrent changes with one version produce one success and one conflict;
  stale retries do not mutate state. After reloading, the client shows the
  confirmed server state.
- Foreign project/site/message/source IDs and revoked clients are denied.
- Sources open as text in WordPress and through safe external links. A removed
  source has a clear result. Scripts and unsafe URLs never execute.
- 401/403/409/429/5xx must not look like successful hiding or trigger a local
  substitute state. Verify CSRF and permissions in both admin interfaces.

## Open questions

No blocking product questions remain. Visibility is shared across the project;
the server chooses storage and audit while preserving this contract.
Working reads and widgets in WordPress 0.4 do not complete this issue.
Acceptance of hiding, answer sources and full-history search remains open.
